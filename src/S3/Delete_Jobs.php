<?php
/**
 * Persistent remote deletion queue.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Durable remote-delete jobs for objects whose first delete failed.
 *
 * `delete_attachment` is a one-shot request: if S3 rejects the delete, the
 * attachment row (and therefore its object keys) is gone and nothing retries,
 * leaving an orphan that keeps billing. This store keeps the keys until S3
 * confirms the delete, independent of the attachment lifecycle.
 */
final class Delete_Jobs {

	/**
	 * Table schema version.
	 */
	public const VERSION = '1.2.2';

	/**
	 * Schema option name.
	 */
	public const OPTION = 'sakura_imgopt_s3_delete_schema';

	/**
	 * Give up after this many failed attempts.
	 */
	public const MAX_ATTEMPTS = 8;

	/** Normalize the saved profile id to the queue column format. */
	public static function normalize_profile_id( string $profile_id ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $profile_id ), 0, 40 );
	}

	/**
	 * Fingerprint of the storage target a job was created for.
	 *
	 * The worker compares this with the profile's current target before deleting:
	 * editing the bucket/endpoint of an existing profile in place must not turn a
	 * queued delete into a delete in the new bucket (audit A03).
	 *
	 * @param array<string,mixed> $profile Profile options.
	 * @return string
	 */
	public static function target_hash( array $profile ): string {
		$identity = array(
			'provider' => strtolower( trim( (string) ( $profile['provider'] ?? '' ) ) ),
			'bucket'   => trim( (string) ( $profile['bucket'] ?? '' ) ),
			'endpoint' => strtolower( trim( (string) ( $profile['endpoint'] ?? '' ) ) ),
			'region'   => strtolower( trim( (string) ( $profile['region'] ?? '' ) ) ),
		);

		return hash( 'sha256', (string) wp_json_encode( $identity ) );
	}

	/** Backoff: 60, 120, 240 ... capped at one hour. */
	public static function backoff( int $attempt ): int {
		return (int) min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, max( 0, $attempt - 1 ) ) ) );
	}

	/**
	 * Physical table name.
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$prefix      = is_object( $wpdb ) ? (string) ( $wpdb->prefix ?? '' ) : '';
		$this->table = $prefix . 'sio_s3_deletes';
	}

	/**
	 * Table name for diagnostics and tests.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sio_s3_deletes';
	}

	/**
	 * Create or upgrade the delete queue table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			profile_id varchar(40) NOT NULL DEFAULT '',
			object_key varbinary(1024) NOT NULL,
			object_key_hash binary(32) NOT NULL,
			target_hash char(64) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_error varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			lease_until datetime NULL,
			claim_token char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY object_key (profile_id,object_key_hash),
			KEY state_next (state,next_at),
			KEY profile_state (profile_id,state),
			KEY attachment_state (attachment_id,state)
		) {$charset};";

		/*
		 * Older installs were unique by key alone, so the same key in two
		 * profiles overwrote each other (audit A04). The index has to be
		 * rewritten *before* dbDelta(): dbDelta only adds missing indexes and
		 * would try to ADD the same name, which MySQL rejects with a duplicate
		 * key error (WordPress then logs a database error on every upgrade).
		 */
		$existing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $existing === $table ) {
			$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'object_key'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			$cols = is_array( $rows ) ? wp_list_pluck( $rows, 'Column_name' ) : array();

			if ( array( 'object_key_hash' ) === array_values( $cols ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name, one-time index rewrite.
				$wpdb->query( "ALTER TABLE {$table} DROP INDEX object_key" );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name, one-time index rewrite.
				$rewritten = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY object_key (profile_id,object_key_hash)" );

				if ( false === $rewritten ) {
					// Duplicate rows from the old index can block the rewrite; keep
					// the queue usable with a non-unique index instead of failing.
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name.
					$wpdb->query( "ALTER TABLE {$table} ADD KEY object_key (profile_id,object_key_hash)" );
					do_action( 'sakura_imgopt_s3_log', __( '删除队列索引升级：存在跨配置重复键，已退化为普通索引。', 'sakura-imgopt' ) );
				}
			}
		}

		dbDelta( $sql );

		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! in_array( 'lease_until', $columns, true ) || ! in_array( 'claim_token', $columns, true ) || ! in_array( 'target_hash', $columns, true ) ) {
			do_action( 'sakura_imgopt_s3_log', __( '删除队列表升级未完成：缺少租约、令牌或目标指纹字段，已保留旧版本标记。', 'sakura-imgopt' ) );
			return;
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Install when the stored schema version differs.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== (string) get_option( self::OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Persist keys before attempting a delete.
	 *
	 * @param int                $attachment_id Attachment ID, 0 when unknown.
	 * @param array<int,string>  $keys          Object keys.
	 * @param int                $delay         Seconds before the first attempt.
	 * @param string             $profile_id    Storage profile at enqueue time.
	 * @return int Accepted keys.
	 */
	public function enqueue( int $attachment_id, array $keys, int $delay = 0, string $profile_id = '', string $target_hash = '' ): int {
		global $wpdb;

		self::maybe_install();

		$profile_id = self::normalize_profile_id( $profile_id );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$next   = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) );
		$values = array();
		$seen   = array();

		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$values[] = $wpdb->prepare(
				'(%d, %s, %s, UNHEX(%s), %s, %s, %s, %s, %s)',
				max( 0, $attachment_id ),
				$profile_id,
				$key,
				bin2hex( hash( 'sha256', $key, true ) ),
				$target_hash,
				'queued',
				$next,
				$now,
				$now
			);
		}

		if ( ! $values ) {
			return 0;
		}

		foreach ( array_chunk( $values, 100 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$this->table}
					(attachment_id, profile_id, object_key, object_key_hash, target_hash, state, next_at, created_at, updated_at)
				VALUES " . implode( ', ', $chunk ) . "
				ON DUPLICATE KEY UPDATE
					attachment_id = VALUES(attachment_id),
					profile_id = VALUES(profile_id),
					target_hash = VALUES(target_hash),
					attempts = IF(state = 'dead', 0, attempts),
					claim_token = IF(state = 'dead', '', claim_token),
					lease_until = IF(state = 'dead', NULL, lease_until),
					next_at = IF(state = 'dead', VALUES(next_at), IF(state = 'queued', LEAST(next_at, VALUES(next_at)), next_at)),
					updated_at = VALUES(updated_at),
					state = IF(state = 'dead', 'queued', state)"
			);
		}

		return count( $values );
	}

	/**
	 * Due rows, including a worker that died mid-delete.
	 *
	 * @param int $limit         Max rows.
	 * @param int $stale_seconds Running rows older than this are retried.
	 * @return array<int,array<string,mixed>>
	 */
	public function due( int $limit = 20, int $stale_seconds = 600 ): array {
		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$stale = gmdate( 'Y-m-d H:i:s', time() - max( 60, $stale_seconds ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE (state = 'queued' AND next_at <= %s)
					OR (state = 'running' AND ((lease_until IS NULL AND updated_at <= %s) OR lease_until <= %s))
				ORDER BY next_at ASC, id ASC
				LIMIT %d",
				$now,
				$stale,
				$now,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of due rows.
	 */
	public function due_count(): int {
		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 600 );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				WHERE (state = 'queued' AND next_at <= %s)
					OR (state = 'running' AND ((lease_until IS NULL AND updated_at <= %s) OR lease_until <= %s))",
				$now,
				$stale,
				$now
			)
		);
	}

	/**
	 * Claim a due row.
	 *
	 * @param int $id             Row ID.
	 * @param int $stale_seconds  Running rows older than this can be reclaimed.
	 * @return string|false Claim token, or false when another worker owns it.
	 */
	public function start( int $id, int $stale_seconds = 600 ): string|false {
		global $wpdb;

		$now          = gmdate( 'Y-m-d H:i:s' );
		$stale        = gmdate( 'Y-m-d H:i:s', time() - max( 60, $stale_seconds ) );
		$lease_until  = gmdate( 'Y-m-d H:i:s', time() + max( 60, $stale_seconds ) );
		$claim_token  = bin2hex( random_bytes( 32 ) );
		$updated      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = 'running', claim_token = %s, lease_until = %s, updated_at = %s
				WHERE id = %d
					AND ((state = 'queued' AND next_at <= %s)
						OR (state = 'running' AND ((lease_until IS NULL AND updated_at <= %s) OR lease_until <= %s)))",
				$claim_token,
				$lease_until,
				$now,
				$id,
				$now,
				$stale,
				$now
			)
		);

		return 1 === (int) $updated ? $claim_token : false;
	}

	/** Extend an active claim only while its token and lease are still valid. */
	public function renew( int $id, string $claim_token, int $lease_seconds = 600 ): bool {
		global $wpdb;

		$now         = gmdate( 'Y-m-d H:i:s' );
		$lease_until = gmdate( 'Y-m-d H:i:s', time() + max( 60, $lease_seconds ) );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET lease_until = %s, updated_at = %s
				WHERE id = %d AND state = 'running' AND claim_token = %s AND lease_until >= %s",
				$lease_until,
				$now,
				$id,
				$claim_token,
				$now
			)
		);
		if ( false === $updated ) {
			return false;
		}

		// MySQL may report zero when the new values equal the current values.
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->table} WHERE id = %d AND state = 'running' AND claim_token = %s AND lease_until >= %s",
				$id,
				$claim_token,
				$lease_until
			)
		);
	}

	/**
	 * Remove a confirmed delete only for the current claim.
	 *
	 * @return bool Whether this worker still owned the row.
	 */
	public function complete( int $id, string $claim_token ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE id = %d AND state = 'running' AND claim_token = %s",
				$id,
				$claim_token
			)
		);
	}

	/**
	 * Record a failed attempt and schedule the next try.
	 *
	 * @param int    $id          Row ID.
	 * @param string $error       Error message.
	 * @param string $claim_token Current claim token.
	 * @return array{attempts:int,dead:bool,applied:bool}
	 */
	public function fail( int $id, string $error, string $claim_token ): array {
		global $wpdb;

		$now      = gmdate( 'Y-m-d H:i:s' );
		$attempts = $this->attempts( $id ) + 1;
		$next     = gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) );
		$max      = self::MAX_ATTEMPTS;
		$error    = mb_substr( wp_strip_all_tags( $error ), 0, 500 );
		$updated  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = IF(attempts + 1 >= %d, 'dead', 'queued'),
					next_at = IF(attempts + 1 >= %d, next_at, %s),
					last_error = %s,
					attempts = attempts + 1,
					lease_until = NULL,
					claim_token = '',
					updated_at = %s
				WHERE id = %d AND state = 'running' AND claim_token = %s",
				$max,
				$max,
				$next,
				$error,
				$now,
				$id,
				$claim_token
			)
		);

		if ( 1 !== (int) $updated ) {
			return array( 'attempts' => 0, 'dead' => false, 'applied' => false );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, state FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		return array(
			'attempts' => (int) ( $row['attempts'] ?? $attempts ),
			'dead'     => 'dead' === (string) ( $row['state'] ?? 'dead' ),
			'applied'  => true,
		);
	}

	/**
	 * Current attempt count.
	 *
	 * @param int $id Row ID.
	 */
	public function attempts( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$this->table} WHERE id = %d", $id ) );
	}

	/**
	 * Release a claimed row back to the queue without counting a failure.
	 *
	 * H-04: a stale object may only be deleted once the replacement upload has
	 * settled. When the attachment still has queued uploads, or its status is
	 * still pending/failed, the delete is pushed back instead of executed —
	 * retrying is cheap, deleting the only copy is not.
	 *
	 * @param int    $id          Row ID.
	 * @param string $claim_token Current claim token.
	 * @param int    $seconds     Delay before the next check.
	 * @return bool Whether this worker still owned the row.
	 */
	public function postpone( int $id, string $claim_token, int $seconds = 300 ): bool {
		global $wpdb;

		$next    = gmdate( 'Y-m-d H:i:s', time() + max( 30, $seconds ) );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = 'queued', next_at = %s, lease_until = NULL, claim_token = '', updated_at = %s
				WHERE id = %d AND state = 'running' AND claim_token = %s",
				$next,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				$claim_token
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Reset dead rows for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Rows requeued.
	 */
	public function retry_attachment( int $attachment_id ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = 'queued', attempts = 0, next_at = %s, last_error = '', updated_at = %s
				WHERE attachment_id = %d AND state = 'dead'",
				$now,
				$now,
				$attachment_id
			)
		);
	}

	/**
	 * Reset global dead rows.
	 *
	 * @param int $limit Max rows.
	 */
	public function requeue_dead( int $limit = 100 ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = 'queued', attempts = 0, next_at = %s, last_error = '', updated_at = %s
				WHERE state = 'dead'
				ORDER BY id ASC
				LIMIT %d",
				$now,
				$now,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Dead rows (orphans that still need manual attention).
	 */
	public function failure_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE state = 'dead'" );
	}

	/**
	 * Rows that have not progressed for a while (cron likely missing).
	 *
	 * @param int $seconds Age threshold.
	 */
	public function stale_count( int $seconds = 600 ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $seconds ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				WHERE state IN ('queued', 'running') AND updated_at <= %s",
				$cutoff
			)
		);
	}

	/**
	 * Queued + running rows.
	 */
	public function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE state IN ('queued', 'running')" );
	}

	/**
	 * Pending rows grouped by the storage target they were enqueued for.
	 *
	 * Needed after the bucket/endpoint of a profile changes: rows whose
	 * fingerprint no longer matches the current profile can never be removed
	 * from the new target, so the operator has to decide what happens to them.
	 *
	 * @return array<int,array{profile_id:string,target_hash:string,state:string,count:int}>
	 */
	public function target_groups(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT profile_id, target_hash, state, COUNT(*) AS row_count FROM {$this->table}
			GROUP BY profile_id, target_hash, state",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'profile_id'  => (string) $row['profile_id'],
					'target_hash' => (string) $row['target_hash'],
					'state'       => (string) $row['state'],
					'count'       => (int) $row['row_count'],
				);
			},
			$rows
		);
	}
}
