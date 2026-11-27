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
	public const VERSION = '1.3.0';

	/**
	 * Schema option name.
	 */
	public const OPTION = 'sakura_imgopt_s3_delete_schema';

	/**
	 * Give up after this many failed attempts.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * How long completed rows stay visible before the worker prunes them.
	 */
	public const DONE_RETENTION_DAYS = 7;

	/**
	 * Rows removed per prune statement, so one cleanup never locks the table.
	 */
	public const PRUNE_LIMIT = 500;

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
	 * This is deliberately not the same fingerprint as
	 * Synchronous_Uploader::target_hash_for(): uploads also bind the active
	 * profile id and upload_path, while deletes bind the bucket identity that
	 * includes the region. The two are never compared with each other; changing
	 * either algorithm invalidates the hashes already stored in
	 * sio_s3_deletes.target_hash, sio_sync_jobs.target_hash and post meta.
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
			object_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			target_hash char(64) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_error varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			completed_at datetime NULL DEFAULT NULL,
			resolution varchar(20) NOT NULL DEFAULT '',
			lease_until datetime NULL,
			claim_token char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY object_key (profile_id,object_key_hash),
			KEY state_next (state,next_at),
			KEY state_completed (state,completed_at),
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
		if ( ! in_array( 'lease_until', $columns, true ) || ! in_array( 'claim_token', $columns, true ) || ! in_array( 'target_hash', $columns, true ) || ! in_array( 'completed_at', $columns, true ) || ! in_array( 'resolution', $columns, true ) || ! in_array( 'object_bytes', $columns, true ) ) {
			do_action( 'sakura_imgopt_s3_log', __( '删除队列表升级未完成：缺少租约、令牌、目标指纹或完成字段，已保留旧版本标记。', 'sakura-imgopt' ) );
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
	public function enqueue( int $attachment_id, array $keys, int $delay = 0, string $profile_id = '', string $target_hash = '', int $bytes = 0 ): int {
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
				'(%d, %s, %s, UNHEX(%s), %d, %s, %s, %s, %s, %s)',
				max( 0, $attachment_id ),
				$profile_id,
				$key,
				bin2hex( hash( 'sha256', $key, true ) ),
				max( 0, $bytes ),
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
					(attachment_id, profile_id, object_key, object_key_hash, object_bytes, target_hash, state, next_at, created_at, updated_at)
				VALUES " . implode( ', ', $chunk ) . "
				ON DUPLICATE KEY UPDATE
					attachment_id = VALUES(attachment_id),
					profile_id = VALUES(profile_id),
					object_bytes = VALUES(object_bytes),
					target_hash = VALUES(target_hash),
					attempts = IF(state IN ('dead', 'done'), 0, attempts),
					claim_token = IF(state IN ('dead', 'done'), '', claim_token),
					lease_until = IF(state IN ('dead', 'done'), NULL, lease_until),
					completed_at = IF(state IN ('dead', 'done'), NULL, completed_at),
					resolution = IF(state IN ('dead', 'done'), '', resolution),
					created_at = IF(state IN ('dead', 'done'), VALUES(created_at), created_at),
					next_at = IF(state IN ('dead', 'done'), VALUES(next_at), IF(state = 'queued', LEAST(next_at, VALUES(next_at)), next_at)),
					updated_at = VALUES(updated_at),
					state = IF(state IN ('dead', 'done'), 'queued', state)"
			);
		}

		return count( $values );
	}

	/**
	 * Queue state per attachment, for read-only display.
	 *
	 * One grouped query answers the whole media-list page. The strongest state
	 * wins: a dead row means the object may still be in the bucket even after
	 * its attachment row is gone, so it must not be hidden behind a pending
	 * retry of another key.
	 *
	 * @param array<int, int> $attachment_ids Attachment ids.
	 * @return array<int, array{state: string, count: int, last_error: string}>
	 */
	public function states_for( array $attachment_ids ): array {
		global $wpdb;

		$ids = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id > 0 ) {
				$ids[ $attachment_id ] = $attachment_id;
			}
		}

		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT attachment_id, state, COUNT(*) AS row_count, MAX(last_error) AS last_error FROM {$this->table} WHERE attachment_id IN ({$placeholders}) GROUP BY attachment_id, state";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_values( $ids ) ) );

		$rank   = array(
			'queued'  => 1,
			'running' => 2,
			'dead'    => 3,
		);
		$states = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$attachment_id = (int) ( $row->attachment_id ?? 0 );
			$state         = sanitize_key( (string) ( $row->state ?? '' ) );

			if ( $attachment_id <= 0 || ! isset( $rank[ $state ] ) ) {
				continue;
			}

			$count = max( 0, (int) ( $row->row_count ?? 0 ) );

			if ( ! isset( $states[ $attachment_id ] ) ) {
				$states[ $attachment_id ] = array(
					'state'      => $state,
					'count'      => $count,
					'last_error' => (string) ( $row->last_error ?? '' ),
				);
				continue;
			}

			$states[ $attachment_id ]['count'] += $count;

			if ( $rank[ $state ] > $rank[ $states[ $attachment_id ]['state'] ] ) {
				$states[ $attachment_id ]['state']      = $state;
				$states[ $attachment_id ]['last_error'] = (string) ( $row->last_error ?? '' );
			}
		}

		return $states;
	}

	/**
	 * Drop every queued delete for one profile (R8 step 3).
	 *
	 * Deleting a configuration has to take its queue with it: the rows name keys
	 * that only that profile's adapter can reach, so after the profile is gone
	 * each one would retry forever and end up as a dead row nobody can clear.
	 *
	 * @param string $profile_id Profile whose queue is being abandoned.
	 * @return int Rows removed.
	 */
	public function forget_profile( string $profile_id ): int {
		global $wpdb;
		if ( '' === $profile_id ) {
			return 0;
		}

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE profile_id = %s", $profile_id ) );

		return is_int( $deleted ) ? $deleted : 0;
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
	 * Aggregate of rows that may run right now (queued and past next_at).
	 *
	 * Retry rows waiting for their backoff are excluded on purpose: they must
	 * not keep a batch from dispatching.
	 *
	 * @return array{count:int,bytes:int,oldest:int}
	 */
	public function queued_stats(): array {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, COALESCE(SUM(object_bytes), 0) AS b, MIN(created_at) AS o
				FROM {$this->table} WHERE state = 'queued' AND next_at <= %s",
				$now
			),
			ARRAY_A
		);

		/*
		 * The table stores UTC timestamps. UNIX_TIMESTAMP() would reinterpret them
		 * in the session timezone, which on a non-UTC site shifts "how long has
		 * this waited" by the UTC offset — enough to make every batch look overdue.
		 */
		$oldest = is_array( $row ) && ! empty( $row['o'] ) ? (int) strtotime( (string) $row['o'] . ' UTC' ) : 0;

		return array(
			'count'  => is_array( $row ) ? (int) $row['c'] : 0,
			'bytes'  => is_array( $row ) ? (int) $row['b'] : 0,
			'oldest' => $oldest,
		);
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
	 * Mark a confirmed delete as done for the current claim.
	 *
	 * The row is kept instead of deleted so the screen can show what was
	 * removed, when, and whether the object was deleted or intentionally kept
	 * (still referenced by an attachment / filtered by a hook). A worker tick
	 * prunes these rows after the retention window.
	 *
	 * @param int    $id          Row ID.
	 * @param string $claim_token Current claim token.
	 * @param string $resolution  deleted | kept | skipped.
	 * @return bool Whether this worker still owned the row.
	 */
	/**
	 * Settle a queued row as deleted because the caller removed the object itself.
	 *
	 * The sync centre deletes attachment-backed rows through the kernel, which
	 * removes the object synchronously. The queue row still exists so the tab can
	 * list the request; this flips it to "done" instead of leaving a ghost that
	 * the worker would later answer "kept" for.
	 *
	 * @param int      $attachment_id Attachment id (0 for a bare cloud copy).
	 * @param string   $profile_id    Profile id.
	 * @param string[] $keys          Object keys that were deleted.
	 * @return int Rows updated.
	 */
	public function settle_deleted( int $attachment_id, string $profile_id, array $keys ): int {
		global $wpdb;

		self::maybe_install();

		$updated = 0;
		$now     = gmdate( 'Y-m-d H:i:s' );

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			$updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					SET state = 'done', resolution = 'deleted', completed_at = %s, updated_at = %s, last_error = ''
					WHERE profile_id = %s AND object_key_hash = UNHEX(%s) AND state IN ('queued','running')",
					$now,
					$now,
					$profile_id,
					bin2hex( hash( 'sha256', $key, true ) )
				)
			);
		}

		return $updated;
	}

	public function complete( int $id, string $claim_token, string $resolution = 'deleted' ): bool {
		global $wpdb;

		$resolution = in_array( $resolution, array( 'deleted', 'kept', 'skipped' ), true ) ? $resolution : 'deleted';
		$now        = gmdate( 'Y-m-d H:i:s' );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET state = 'done', resolution = %s, completed_at = %s, lease_until = NULL, claim_token = '', last_error = '', updated_at = %s
				WHERE id = %d AND state = 'running' AND claim_token = %s",
				$resolution,
				$now,
				$now,
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
	 * Row counts per state.
	 *
	 * retrying is not a state: it counts queued rows that already failed at
	 * least once, so the screen can say how much of the backlog is a retry.
	 *
	 * @return array{all:int,queued:int,running:int,done:int,dead:int,failed:int,retrying:int}
	 */
	public function counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS total, SUM(attempts > 0) AS retried FROM {$this->table} GROUP BY state", ARRAY_A );
		$out  = array(
			'all'      => 0,
			'queued'   => 0,
			'running'  => 0,
			'done'     => 0,
			'dead'     => 0,
			'failed'   => 0,
			'retrying' => 0,
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$state = sanitize_key( (string) ( $row['state'] ?? '' ) );
			$total = max( 0, (int) ( $row['total'] ?? 0 ) );

			switch ( $state ) {
				case 'queued':
					$out['queued']   = $total;
					$out['retrying'] = max( 0, (int) ( $row['retried'] ?? 0 ) );
					break;
				case 'running':
					$out['running'] = $total;
					break;
				case 'done':
					$out['done'] = $total;
					break;
				case 'dead':
					$out['dead'] = $total;
					break;
				case 'failed':
					$out['failed'] = $total;
					break;
				default:
					continue 2;
			}

			$out['all'] += $total;
		}

		return $out;
	}

	/**
	 * Total rows for one state, or every row when the state is unknown.
	 *
	 * @param string $state State filter.
	 */
	public function total_rows( string $state = 'all' ): int {
		global $wpdb;

		$where = $this->state_where( $state );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from a whitelisted state.
	}

	/**
	 * One page of queue rows, active work first.
	 *
	 * @param string $state    State filter.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array<int,array<string,mixed>>
	 */
	public function page_rows( string $state = 'all', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$where    = $this->state_where( $state );
		$sql      = $wpdb->prepare(
			"SELECT id, attachment_id, profile_id, state, attempts, object_key, last_error, next_at, created_at, updated_at, completed_at, resolution
			FROM {$this->table} {$where}
			ORDER BY CASE state WHEN 'running' THEN 0 WHEN 'queued' THEN 1 WHEN 'failed' THEN 2 WHEN 'dead' THEN 3 WHEN 'done' THEN 4 ELSE 5 END, id DESC
			LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from a whitelisted state.
			$per_page,
			$offset
		);

		$rows = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : array();

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Completion and activity facts used by the live panel.
	 *
	 * @param int $minutes Recent window in minutes.
	 * @return array{done_recent:int,done_hour:int,last_done:string,last_activity:string}
	 */
	public function activity( int $minutes = 10 ): array {
		global $wpdb;

		$now    = time();
		$recent = gmdate( 'Y-m-d H:i:s', $now - max( 1, $minutes ) * MINUTE_IN_SECONDS );
		$hour   = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(completed_at IS NOT NULL AND completed_at >= %s) AS done_recent,
					SUM(completed_at IS NOT NULL AND completed_at >= %s) AS done_hour,
					MAX(completed_at) AS last_done,
					MAX(CASE WHEN state IN ('queued', 'running') THEN updated_at END) AS last_activity
				FROM {$this->table}",
				$recent,
				$hour
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'done_recent'   => max( 0, (int) ( $row['done_recent'] ?? 0 ) ),
			'done_hour'     => max( 0, (int) ( $row['done_hour'] ?? 0 ) ),
			'last_done'     => (string) ( $row['last_done'] ?? '' ),
			'last_activity' => (string) ( $row['last_activity'] ?? '' ),
		);
	}

	/**
	 * Earliest queued next_at, as a GMT string.
	 */
	public function next_due(): string {
		global $wpdb;

		$value = $wpdb->get_var( "SELECT MIN(next_at) FROM {$this->table} WHERE state = 'queued'" );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Rows a worker currently holds, newest activity first.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function running_rows( int $limit = 5 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, profile_id, object_key, attempts, updated_at, lease_until
				FROM {$this->table} WHERE state = 'running' ORDER BY updated_at DESC LIMIT %d",
				max( 1, min( 20, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return selected rows to the queue.
	 *
	 * Completed rows are deliberately excluded: the object is gone, and a new
	 * upload may already occupy the same key, so re-running it could delete the
	 * replacement. Only pending work can be retried.
	 *
	 * @param array<int,int> $ids Selected row ids.
	 */
	public function retry_ids( array $ids ): int {
		global $wpdb;

		$ids = self::clean_ids( $ids );

		if ( array() === $ids ) {
			return 0;
		}

		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Two %s placeholders plus the dynamic IN list, passed as one array.
			"UPDATE {$this->table}
			SET state = 'queued', attempts = 0, next_at = %s, lease_until = NULL, claim_token = '', last_error = '', completed_at = NULL, resolution = '', updated_at = %s
			WHERE id IN ({$in}) AND state IN ('queued', 'dead', 'failed')", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.
			array_merge( array( $now, $now ), $ids )
		);

		$updated = is_string( $sql ) ? (int) $wpdb->query( $sql ) : 0;

		return max( 0, $updated );
	}

	/**
	 * Drop queue rows without touching the remote objects.
	 *
	 * @param array<int,int> $ids Selected row ids.
	 */
	public function drop_ids( array $ids ): int {
		global $wpdb;

		$ids = self::clean_ids( $ids );

		if ( array() === $ids ) {
			return 0;
		}

		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $wpdb->prepare( "DELETE FROM {$this->table} WHERE id IN ({$in})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.

		$deleted = is_string( $sql ) ? (int) $wpdb->query( $sql ) : 0;

		return max( 0, $deleted );
	}

	/**
	 * Rows for the CSV export, oldest first.
	 *
	 * @param array<int,int> $ids Selected ids; empty exports everything.
	 * @return array<int,array<string,mixed>>
	 */
	public function export_rows( array $ids = array() ): array {
		global $wpdb;

		$ids = self::clean_ids( $ids );
		$sql = "SELECT id, attachment_id, profile_id, state, attempts, object_key, last_error, created_at, updated_at, completed_at, resolution FROM {$this->table}";

		if ( array() !== $ids ) {
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = $wpdb->prepare( "{$sql} WHERE id IN ({$in})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.
		}

		if ( ! is_string( $sql ) ) {
			return array();
		}

		$rows = $wpdb->get_results( $sql . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when needed.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete completed rows past the retention window.
	 *
	 * @param int $days Retention in days.
	 */
	public function prune_done( int $days = self::DONE_RETENTION_DAYS ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$total  = 0;

		for ( $i = 0; $i < 20; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE state = 'done' AND completed_at IS NOT NULL AND completed_at < %s LIMIT %d",
					$cutoff,
					self::PRUNE_LIMIT
				)
			);

			if ( false === $deleted || 0 === (int) $deleted ) {
				break;
			}

			$total += (int) $deleted;

			if ( (int) $deleted < self::PRUNE_LIMIT ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * WHERE clause for a whitelisted state filter.
	 *
	 * @param string $state State filter.
	 */
	private function state_where( string $state ): string {
		global $wpdb;

		if ( ! in_array( $state, array( 'queued', 'running', 'done', 'dead', 'failed' ), true ) ) {
			return '';
		}

		return (string) $wpdb->prepare( 'WHERE state = %s', $state );
	}

	/**
	 * Positive ids, deduplicated.
	 *
	 * @param array<int,int> $ids Raw ids.
	 * @return array<int,int>
	 */
	private static function clean_ids( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}

		return array_values( $out );
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
			WHERE state IN ('queued', 'running', 'dead')
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
