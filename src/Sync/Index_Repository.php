<?php
/**
 * Sync index repository.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Batched persistence and keyset queries for the sync index.
 *
 * Query budget (measured on 1,000,000 rows, MariaDB 12.3):
 * - keyset page: 0.5 ms
 * - kind filter: 0.3 ms
 * - prefix search hit: ~110 ms, miss: ~2.7 s (full profile scan; callers must
 *   treat search as an explicit, slower user action)
 * - first paint: counts table + latest scan row only, never an aggregate.
 */
final class Index_Repository {

	/**
	 * Index table.
	 */
	private string $index;

	/**
	 * Scans table.
	 */
	private string $scans;

	/**
	 * Counts table.
	 */
	private string $counts;

	/**
	 * Jobs table.
	 */
	private string $jobs;

	/**
	 * Events table.
	 */
	private string $events;

	/**
	 * Cached "recent_at backfill complete" flag.
	 */
	private ?bool $recent_ready = null;

	/**
	 * Attempts before a queued action is marked dead.
	 */
	public const JOB_MAX_ATTEMPTS = 8;

	/**
	 * Seconds a claimed job may run before another worker may reclaim it.
	 */
	public const JOB_LEASE_SECONDS = 300;

	/**
	 * Numeric scan columns.
	 *
	 * @var string[]
	 */
	private const SCAN_INTEGERS = array( 'total_local', 'total_remote', 'local_bytes', 'remote_bytes', 'cloud_image', 'cloud_video', 'cloud_archive', 'processed', 'attempts' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->index  = Schema::table( 'index' );
		$this->scans  = Schema::table( 'scans' );
		$this->counts = Schema::table( 'counts' );
		$this->jobs   = Schema::table( 'jobs' );
		$this->events = Schema::table( 'events' );
	}

	/**
	 * Physical index table name.
	 */
	public function table_name(): string {
		return $this->index;
	}

	/**
	 * Acquire a per-profile MySQL advisory lock.
	 *
	 * @param string $profile_id Profile ID.
	 */
	public function acquire_lock( string $profile_id ): bool {
		global $wpdb;
		$name = 'sio_scan_' . substr( md5( $profile_id ), 0, 20 );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		return '1' === (string) $got;
	}

	/**
	 * Release the advisory lock.
	 *
	 * @param string $profile_id Profile ID.
	 */
	public function release_lock( string $profile_id ): void {
		global $wpdb;
		$name = 'sio_scan_' . substr( md5( $profile_id ), 0, 20 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Acquire the per-scan tick lock so AJAX and cron never advance the same
	 * cursor concurrently (design 8.1: GET_LOCK + scan state as two guards).
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function acquire_tick_lock( int $scan_id ): bool {
		global $wpdb;
		$name = 'sio_tick_' . substr( md5( (string) $scan_id ), 0, 20 );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		return '1' === (string) $got;
	}

	/**
	 * Release the per-scan tick lock.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function release_tick_lock( int $scan_id ): void {
		global $wpdb;
		$name = 'sio_tick_' . substr( md5( (string) $scan_id ), 0, 20 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Start a scan. Caller must release the lock when done.
	 *
	 * @param string $profile_id Profile ID.
	 * @param string $mode       full|resume.
	 * @return int|\WP_Error
	 */
	public function start_scan( string $profile_id, string $mode = 'full' ) {
		global $wpdb;

		if ( ! $this->acquire_lock( $profile_id ) ) {
			return new \WP_Error( 'sio_scan_locked', __( '已有对账任务在运行，请稍后再试。', 'sakura-imgopt' ) );
		}

		$error = $this->clear_running( $profile_id, true );
		if ( $error instanceof \WP_Error ) {
			$this->release_lock( $profile_id );
			return $error;
		}

		$now = $this->now();
		$ok  = $wpdb->insert(
			$this->scans,
			array(
				'profile_id'  => $profile_id,
				'mode'        => in_array( $mode, array( 'full', 'resume', 'incremental' ), true ) ? $mode : 'full',
				'state'       => 'running',
				'phase'       => '',
				'scan_cursor' => null,
				'started_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			$this->release_lock( $profile_id );
			return new \WP_Error( 'sio_scan_insert_failed', __( '无法创建对账任务。', 'sakura-imgopt' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Resume the latest failed/cancelled scan from its saved cursor.
	 *
	 * @param string $profile_id Profile ID.
	 * @return int|\WP_Error
	 */
	public function resume_scan( string $profile_id ) {
		global $wpdb;

		if ( ! $this->acquire_lock( $profile_id ) ) {
			return new \WP_Error( 'sio_scan_locked', __( '已有对账任务在运行，请稍后再试。', 'sakura-imgopt' ) );
		}

		$error = $this->clear_running( $profile_id, true );
		if ( $error instanceof \WP_Error ) {
			$this->release_lock( $profile_id );
			return $error;
		}

		$scan = $this->resumable_scan( $profile_id );
		if ( ! $scan ) {
			$this->release_lock( $profile_id );
			return new \WP_Error( 'sio_scan_not_resumable', __( '没有可继续的对账任务，请重新开始。', 'sakura-imgopt' ) );
		}

		$wpdb->update(
			$this->scans,
			array(
				'state'         => 'running',
				'mode'          => 'resume',
				'error_code'    => null,
				'error_message' => null,
				'finished_at'   => null,
				'attempts'      => 0,
				'updated_at'    => $this->now(),
			),
			array( 'id' => (int) $scan->id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		return (int) $scan->id;
	}

	/**
	 * Fail a stale running scan so a new one can start. Must be called while
	 * holding the profile lock; it does not release that lock.
	 *
	 * @param string $profile_id Profile ID.
	 * @param bool   $stale_ok   Whether a stale running row may be failed.
	 * @return \WP_Error|null
	 */
	private function clear_running( string $profile_id, bool $stale_ok ): ?\WP_Error {
		global $wpdb;
		$running = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->scans} WHERE profile_id=%s AND state IN ('queued','running') ORDER BY id DESC LIMIT 1",
				$profile_id
			)
		);
		if ( ! $running ) {
			return null;
		}

		$scan = $this->scan( (int) $running );
		$age  = $scan ? ( time() - (int) strtotime( (string) $scan->updated_at . ' UTC' ) ) : 0;
		if ( $stale_ok && $scan && $age > 600 ) {
			$wpdb->update(
				$this->scans,
				array(
					'state'         => 'failed',
					'error_code'    => 'sio_scan_stale',
					'error_message' => __( '上一次对账已超时，已自动终止。', 'sakura-imgopt' ),
					'finished_at'   => $this->now(),
					'updated_at'    => $this->now(),
				),
				array( 'id' => (int) $running ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return null;
		}

		return new \WP_Error( 'sio_scan_already_running', __( '已有一个对账任务在运行。', 'sakura-imgopt' ) );
	}

	/**
	 * Latest scan that can be resumed (failed or cancelled after starting).
	 *
	 * @param string $profile_id Profile ID.
	 */
	public function resumable_scan( string $profile_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans}
				WHERE profile_id=%s AND state IN ('failed','cancelled') AND phase<>''
				ORDER BY id DESC LIMIT 1",
				$profile_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Current state of a scan.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function state( int $scan_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$this->scans} WHERE id=%d", $scan_id ) );
	}

	/**
	 * Fetch one scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	public function scan( int $scan_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->scans} WHERE id=%d", $scan_id ) );
		return $row ?: null;
	}

	/**
	 * Latest scan for a profile.
	 *
	 * @param string $profile_id Profile.
	 */
	public function latest_scan( string $profile_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans} WHERE profile_id=%s ORDER BY id DESC LIMIT 1",
				$profile_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Recent scans for a profile, newest first.
	 *
	 * @param string $profile_id Profile.
	 * @param int    $limit      Rows.
	 * @return array<int,object>
	 */
	public function recent_scans( string $profile_id, int $limit = 5 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans} WHERE profile_id=%s ORDER BY id DESC LIMIT %d",
				$profile_id,
				$limit
			)
		);
	}

	/**
	 * Most recent completed scan before a given one (progress estimates).
	 *
	 * @param string $profile_id Profile.
	 * @param int    $before_id  Current scan ID.
	 */
	public function completed_scan_before( string $profile_id, int $before_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans} WHERE profile_id=%s AND state='done' AND id<%d ORDER BY id DESC LIMIT 1",
				$profile_id,
				$before_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Update scan fields.
	 *
	 * @param int                  $scan_id Scan ID.
	 * @param array<string,mixed>  $fields  Fields.
	 */
	public function update_scan( int $scan_id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = $this->now();
		$formats              = array();
		foreach ( array_keys( $fields ) as $key ) {
			$formats[] = in_array( $key, self::SCAN_INTEGERS, true ) ? '%d' : '%s';
		}
		$wpdb->update( $this->scans, $fields, array( 'id' => $scan_id ), $formats, array( '%d' ) );
	}

	/**
	 * Mark a scan done, but never overwrite a cancellation.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $total   Total differences.
	 */
	public function finish_scan( int $scan_id, int $total = 0 ): void {
		global $wpdb;
		$now = $this->now();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans}
				SET state='done', phase='done', processed=%d, finished_at=%s, updated_at=%s
				WHERE id=%d AND state='running'",
				$total,
				$now,
				$now,
				$scan_id
			)
		);
		$this->release_scan_lock( $scan_id );
	}

	/**
	 * Mark a scan failed without overwriting done/cancelled.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function fail_scan( int $scan_id, string $code, string $message ): void {
		global $wpdb;
		$now = $this->now();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans}
				SET state='failed', error_code=%s, error_message=%s, finished_at=%s, updated_at=%s
				WHERE id=%d AND state IN ('queued','running')",
				substr( $code, 0, 64 ),
				substr( $message, 0, 255 ),
				$now,
				$now,
				$scan_id
			)
		);
		$this->release_scan_lock( $scan_id );
	}

	/**
	 * Cancel a queued/running scan. The running tick notices the state change
	 * at its next batch and releases the profile lock itself.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function cancel_scan( int $scan_id ): bool {
		global $wpdb;
		$now = $this->now();
		$ok  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans}
				SET state='cancelled', finished_at=%s, updated_at=%s
				WHERE id=%d AND state IN ('queued','running')",
				$now,
				$now,
				$scan_id
			)
		);
		return (int) $ok > 0;
	}

	/**
	 * Release the profile lock for a scan (used when a tick exits).
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function release_scan_lock( int $scan_id ): void {
		$scan = $this->scan( $scan_id );
		if ( $scan ) {
			$this->release_lock( (string) $scan->profile_id );
		}
	}

	/**
	 * Upsert local files into the index.
	 *
	 * @param string                         $profile_id Profile.
	 * @param int                            $scan_id    Scan.
	 * @param array<int,array<string,mixed>> $rows       Local rows.
	 * @return int|\WP_Error Number of rows written.
	 */
	public function upsert_local( string $profile_id, int $scan_id, array $rows ) {
		return $this->upsert( $profile_id, $scan_id, $rows, true );
	}

	/**
	 * Upsert remote objects into the index.
	 *
	 * @param string                         $profile_id Profile.
	 * @param int                            $scan_id    Scan.
	 * @param array<int,array<string,mixed>> $rows       Remote rows.
	 * @return int|\WP_Error
	 */
	public function upsert_remote( string $profile_id, int $scan_id, array $rows ) {
		return $this->upsert( $profile_id, $scan_id, $rows, false );
	}

	/**
	 * Batch upsert.
	 *
	 * @param string                         $profile_id Profile.
	 * @param int                            $scan_id    Scan.
	 * @param array<int,array<string,mixed>> $rows       Rows.
	 * @param bool                           $local      Local vs remote.
	 * @return int|\WP_Error
	 */
	/**
	 * Queue every synced row of a profile for re-verification.
	 *
	 * @param string $profile_id Profile.
	 * @return int Rows reset.
	 */
	public function reset_verification( string $profile_id ): int {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index} SET verify_state = 0 WHERE profile_id = %s AND verify_state <> 0",
				$profile_id
			)
		);
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Store a known local MD5 (upload path) without forcing a status change.
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @param string $md5        32-hex MD5.
	 */
	public function record_md5( string $profile_id, string $key, string $md5 ): bool {
		global $wpdb;
		$md5 = strtolower( trim( $md5 ) );
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $md5 ) ) {
			return false;
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index}
				SET local_md5=%s,
					verify_state=CASE
						WHEN etag REGEXP '^[0-9a-f]{32}$' THEN IF(LOWER(etag)=%s,1,3)
						WHEN etag<>'' THEN 2
						ELSE 0 END,
					updated_at=%s
				WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				$md5,
				$md5,
				$this->now(),
				$profile_id,
				$this->hash_hex( $key )
			)
		);
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Record a just-uploaded object as synced without waiting for a scan.
	 *
	 * Keeps the reconciliation index truthful right after an upload so the sync
	 * screen does not keep showing a freshly uploaded file as "local only".
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @param string $path       Local path (absolute or uploads-relative).
	 * @param int    $size       Local size in bytes.
	 * @param int    $mtime      Local modification time.
	 * @param string $md5        Local MD5 (empty for multipart uploads).
	 * @param string $etag       Remote ETag.
	 * @return bool
	 */
	public function record_uploaded( string $profile_id, string $key, string $path, int $size, int $mtime, string $md5 = '', string $etag = '' ): bool {
		global $wpdb;

		$key = ltrim( trim( $key ), '/' );
		if ( '' === $profile_id || '' === $key ) {
			return false;
		}

		$hash = $this->hash_hex( $key );
		$old  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status,local_size,remote_size FROM {$this->index} WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				$profile_id,
				$hash
			),
			ARRAY_A
		);
		$old_status = $old ? (string) $old['status'] : '';
		$old_bytes  = $old ? max( (int) $old['local_size'], (int) $old['remote_size'] ) : 0;

		$scan_id = $this->running_scan_id( $profile_id );
		$local   = $this->upsert_local( $profile_id, $scan_id, array( array( 'key' => $key, 'path' => $path, 'size' => $size, 'mtime' => $mtime ) ) );
		$remote  = $this->upsert_remote( $profile_id, $scan_id, array( array( 'key' => $key, 'size' => $size, 'etag' => $etag, 'modified' => $mtime ) ) );
		if ( is_wp_error( $local ) || is_wp_error( $remote ) ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index}
				SET status='synced', error_code=NULL, error_message=NULL, local_md5=NULLIF(%s,''),
					verify_state=CASE WHEN etag<>'' AND etag=%s THEN 1 ELSE 0 END,
					updated_at=%s
				WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				$md5,
				$etag,
				$this->now(),
				$profile_id,
				$hash
			)
		);

		if ( '' !== $old_status && 'synced' !== $old_status ) {
			$this->adjust_counts( $profile_id, $old_status, -1, $old_bytes );
		}
		if ( 'synced' !== $old_status ) {
			$this->adjust_counts( $profile_id, 'synced', 1, $size );
		}

		return true;
	}

	/**
	 * Record a freshly downloaded object so the index stops showing it as cloud-only.
	 *
	 * Mirrors {@see record_uploaded()} for the opposite direction: the local file
	 * is authoritative here, so only the local columns are refreshed and the
	 * status flips to "synced" when the remote copy is already known and reports
	 * the same size.
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @param string $path       Local path (absolute or uploads-relative).
	 * @param int    $size       Local size in bytes.
	 * @param int    $mtime      Local modification time.
	 * @return bool
	 */
	public function record_downloaded( string $profile_id, string $key, string $path, int $size, int $mtime ): bool {
		global $wpdb;

		$key = ltrim( trim( $key ), '/' );
		if ( '' === $profile_id || '' === $key ) {
			return false;
		}

		$hash   = $this->hash_hex( $key );
		$before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status FROM {$this->index} WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				$profile_id,
				$hash
			),
			ARRAY_A
		);

		$scan_id = $this->running_scan_id( $profile_id );
		$local   = $this->upsert_local( $profile_id, $scan_id, array( array( 'key' => $key, 'path' => $path, 'size' => $size, 'mtime' => $mtime ) ) );
		if ( is_wp_error( $local ) ) {
			return false;
		}

		$row = $this->find( $profile_id, $key );
		if ( null === $row ) {
			return false;
		}

		$synced = 1 === (int) $row['seen_remote'] && (int) $row['remote_size'] === $size;
		$status = $synced ? 'synced' : 'pending';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index} SET status=%s,error_code=NULL,error_message=NULL,updated_at=%s
				WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				$status,
				$this->now(),
				$profile_id,
				$hash
			)
		);

		$old_status = $before ? (string) $before['status'] : '';
		$bytes      = max( $size, (int) $row['remote_size'] );
		if ( '' !== $old_status && $old_status !== $status ) {
			$this->adjust_counts( $profile_id, $old_status, -1, -$bytes );
		}
		if ( $old_status !== $status ) {
			$this->adjust_counts( $profile_id, $status, 1, $bytes );
		}

		return true;
	}

	/**
	 * Persist a verification result and keep status/counts consistent.
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @param string $md5        32-hex MD5.
	 * @param int    $state      1 match, 2 unverifiable, 3 mismatch.
	 */
	public function record_verification( string $profile_id, string $key, string $md5, int $state ): bool {
		global $wpdb;
		$old = $this->find( $profile_id, $key );
		if ( null === $old ) {
			return false;
		}
		$md5   = strtolower( trim( $md5 ) );
		$state = in_array( $state, array( 1, 2, 3 ), true ) ? $state : 0;
		$size  = max( (int) $old['local_size'], (int) $old['remote_size'] );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index} SET local_md5=NULLIF(%s,''),verify_state=%d,updated_at=%s
				WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
				preg_match( '/^[0-9a-f]{32}$/', $md5 ) ? $md5 : '',
				$state,
				$this->now(),
				$profile_id,
				$this->hash_hex( $key )
			)
		);

		if ( 3 === $state && 'synced' === (string) $old['status'] ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->index} SET status='mismatch',error_code='sio_etag_mismatch',error_message=%s,updated_at=%s
					WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
					__( '内容不一致：ETag 与本地 MD5 不同。', 'sakura-imgopt' ),
					$this->now(),
					$profile_id,
					$this->hash_hex( $key )
				)
			);
			$this->adjust_counts( $profile_id, 'synced', -1, -$size );
			$this->adjust_counts( $profile_id, 'mismatch', 1, $size );
		} elseif ( 1 === $state && 'mismatch' === (string) $old['status'] && 'sio_etag_mismatch' === (string) $old['error_code'] ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->index} SET status='synced',error_code=NULL,error_message=NULL,updated_at=%s
					WHERE profile_id=%s AND object_key_hash=UNHEX(%s)",
					$this->now(),
					$profile_id,
					$this->hash_hex( $key )
				)
			);
			$this->adjust_counts( $profile_id, 'mismatch', -1, -$size );
			$this->adjust_counts( $profile_id, 'synced', 1, $size );
		}
		return true;
	}

	private function upsert( string $profile_id, int $scan_id, array $rows, bool $local ) {
		global $wpdb;
		$rows = array_values( $rows );
		if ( ! $rows ) {
			return 0;
		}

		$now      = $this->now();
		$chunks   = array_chunk( $rows, 500 );
		$inserted = 0;

		foreach ( $chunks as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$key = isset( $row['key'] ) ? (string) $row['key'] : '';
				if ( '' === $key ) {
					continue;
				}
				$hash = hash( 'sha256', $key, true );
				$kind = Kind::of( $key );
				if ( $local ) {
					$mtime          = isset( $row['mtime'] ) && (int) $row['mtime'] > 0 ? (int) $row['mtime'] : 0;
					$recent         = $mtime > 0 ? gmdate( 'Y-m-d H:i:s', $mtime ) : '1970-01-01 00:00:00';
					$placeholders[] = '(%s,UNHEX(%s),%s,%s,%s,%s,%d,NULLIF(%s,\'\'),%s,%d,%s,%d)';
					array_push(
						$values,
						$profile_id,
						$this->hash_hex( $key ),
						$key,
						$kind,
						(string) ( $row['path'] ?? '' ),
						dirname( $key ) === '.' ? '' : dirname( $key ),
						max( 0, (int) ( $row['size'] ?? 0 ) ),
						$mtime > 0 ? gmdate( 'Y-m-d H:i:s', $mtime ) : null,
						$recent,
						$scan_id,
						$now,
						1
					);
				} else {
					$modified       = isset( $row['modified'] ) && (int) $row['modified'] > 0 ? (int) $row['modified'] : 0;
					$recent         = $modified > 0 ? gmdate( 'Y-m-d H:i:s', $modified ) : '1970-01-01 00:00:00';
					$placeholders[] = '(%s,UNHEX(%s),%s,%s,%d,%s,NULLIF(%s,\'\'),%s,%d,%s,%d)';
					array_push(
						$values,
						$profile_id,
						$this->hash_hex( $key ),
						$key,
						$kind,
						max( 0, (int) ( $row['size'] ?? 0 ) ),
						Checksums::normalize_etag( (string) ( $row['etag'] ?? '' ) ),
						$modified > 0 ? gmdate( 'Y-m-d H:i:s', $modified ) : null,
						$recent,
						$scan_id,
						$now,
						1
					);
				}
			}

			if ( ! $placeholders ) {
				continue;
			}

			if ( $local ) {
				$sql = "INSERT INTO {$this->index}
					(profile_id,object_key_hash,object_key,kind,local_path,local_dir,local_size,local_mtime,recent_at,scan_id,updated_at,seen_local)
					VALUES " . implode( ',', $placeholders ) . '
					ON DUPLICATE KEY UPDATE
						local_md5=IF(local_mtime <=> VALUES(local_mtime) AND local_size <=> VALUES(local_size),local_md5,NULL),
						verify_state=IF(local_mtime <=> VALUES(local_mtime) AND local_size <=> VALUES(local_size),verify_state,0),
						kind=VALUES(kind),local_path=VALUES(local_path),local_dir=VALUES(local_dir),local_size=VALUES(local_size),local_mtime=VALUES(local_mtime),
						recent_at=GREATEST(COALESCE(VALUES(local_mtime),\'1970-01-01 00:00:00\'),COALESCE(remote_mtime,\'1970-01-01 00:00:00\')),
						scan_id=VALUES(scan_id),seen_local=1,updated_at=VALUES(updated_at)';
			} else {
				$sql = "INSERT INTO {$this->index}
					(profile_id,object_key_hash,object_key,kind,remote_size,etag,remote_mtime,recent_at,scan_id,updated_at,seen_remote)
					VALUES " . implode( ',', $placeholders ) . '
					ON DUPLICATE KEY UPDATE
						verify_state=IF(etag <=> VALUES(etag) AND remote_size <=> VALUES(remote_size),verify_state,0),
						kind=VALUES(kind),remote_size=VALUES(remote_size),remote_mtime=VALUES(remote_mtime),etag=VALUES(etag),
						recent_at=GREATEST(COALESCE(local_mtime,\'1970-01-01 00:00:00\'),COALESCE(VALUES(remote_mtime),\'1970-01-01 00:00:00\')),
						seen_remote=1,scan_id=VALUES(scan_id),updated_at=VALUES(updated_at)';
			}

			$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder count is built above.
			if ( false === $prepared ) {
				return new \WP_Error( 'sio_index_prepare_failed', __( '对账索引写入失败。', 'sakura-imgopt' ) );
			}
			$result = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( false === $result ) {
				return new \WP_Error( 'sio_index_write_failed', __( '对账索引写入失败。', 'sakura-imgopt' ) );
			}
			$inserted += count( $chunk );
		}

		return $inserted;
	}

	/**
	 * Reset seen flags before a full scan.
	 *
	 * @param string $profile_id Profile.
	 */
	public function reset_seen( string $profile_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->index} SET seen_local=0,seen_remote=0 WHERE profile_id=%s", $profile_id ) );
	}

	/**
	 * Reconcile rows touched by a scan.
	 *
	 * Size equality stays the primary signal: the plugin never stores a local
	 * MD5, and remote multipart ETags (`...-N`) cannot be compared with a file
	 * hash, so using ETag/mtime here would flag healthy rows as mismatches.
	 *
	 * @param string $profile_id Profile.
	 * @param int    $scan_id    Scan.
	 */
	public function reconcile( string $profile_id, int $scan_id ): void {
		global $wpdb;
		$now = $this->now();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index}
				SET status = CASE
					WHEN status='ignored' THEN 'ignored'
					WHEN status='failed' AND error_code IS NOT NULL THEN 'failed'
					WHEN seen_local=1 AND seen_remote=1 AND local_size=remote_size AND local_md5 IS NOT NULL AND etag REGEXP '^[0-9a-f]{32}$'
						THEN IF(LOWER(etag)=local_md5,'synced','mismatch')
					WHEN seen_local=1 AND seen_remote=1 THEN IF(local_size=remote_size,'synced','mismatch')
					WHEN seen_local=1 THEN 'pending'
					WHEN seen_remote=1 THEN 'remote'
					ELSE status END,
				verify_state = CASE
					WHEN seen_local=1 AND seen_remote=1 AND local_size=remote_size AND local_md5 IS NOT NULL AND etag REGEXP '^[0-9a-f]{32}$'
						THEN IF(LOWER(etag)=local_md5,1,3)
					WHEN seen_local=1 AND seen_remote=1 AND local_size=remote_size AND etag<>'' AND etag NOT REGEXP '^[0-9a-f]{32}$' THEN 2
					WHEN seen_local=1 AND seen_remote=1 AND local_size=remote_size THEN 0
					ELSE verify_state END,
				error_code = CASE
					WHEN status='ignored' THEN error_code
					WHEN status='failed' AND error_code IS NOT NULL THEN error_code
					WHEN seen_local=1 AND seen_remote=1 AND local_size<>remote_size THEN 'sio_size_mismatch'
					WHEN seen_local=1 AND seen_remote=1 AND local_md5 IS NOT NULL AND etag REGEXP '^[0-9a-f]{32}$' AND LOWER(etag)<>local_md5 THEN 'sio_etag_mismatch'
					WHEN seen_local=1 AND seen_remote=1 THEN NULL
					ELSE error_code END,
				error_message = CASE
					WHEN status='ignored' THEN error_message
					WHEN status='failed' AND error_code IS NOT NULL THEN error_message
					WHEN seen_local=1 AND seen_remote=1 AND local_size<>remote_size THEN '本地与云端大小不一致。'
					WHEN seen_local=1 AND seen_remote=1 AND local_md5 IS NOT NULL AND etag REGEXP '^[0-9a-f]{32}$' AND LOWER(etag)<>local_md5 THEN '内容不一致：ETag 与本地 MD5 不同。'
					WHEN seen_local=1 AND seen_remote=1 THEN NULL
					ELSE error_message END,
				updated_at=%s
				WHERE profile_id=%s AND scan_id=%d",
				$now,
				$profile_id,
				$scan_id
			)
		);
	}
	/**
	 * Drop rows not seen by a completed scan.
	 *
	 * @param string $profile_id Profile.
	 * @param int    $scan_id    Scan.
	 */
	/**
	 * Re-stamp index rows of directories the incremental scan skipped.
	 *
	 * The walk skips file stats inside unchanged directories; without this the
	 * rows keep an old scan_id and the purge step would delete them.
	 *
	 * @param string   $profile_id Profile.
	 * @param int      $scan_id    Scan.
	 * @param string[] $dirs       Relative directory paths.
	 * @return int Rows touched.
	 */
	public function mark_dirs_scanned( string $profile_id, int $scan_id, array $dirs ): int {
		global $wpdb;
		$dirs = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $dirs ),
					static function ( $dir ) {
						return '' !== (string) $dir;
					}
				)
			)
		);
		if ( ! $dirs ) {
			return 0;
		}
		$now  = $this->now();
		$rows = 0;
		foreach ( array_chunk( $dirs, 200 ) as $chunk ) {
			$in       = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql      = "UPDATE {$this->index} SET scan_id=%d,seen_local=1,updated_at=%s WHERE profile_id=%s AND local_dir IN ({$in})";
			$values   = array_merge( array( $scan_id, $now, $profile_id ), $chunk );
			$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are built above.
			if ( false === $prepared ) {
				continue;
			}
			$rows += (int) $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}
		return $rows;
	}

	public function purge_stale( string $profile_id, int $scan_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->index} WHERE profile_id=%s AND scan_id<>%d",
				$profile_id,
				$scan_id
			)
		);
	}

	/**
	 * Refresh planner statistics after a bulk index change.
	 *
	 * A full scan can insert a million rows in seconds; without fresh
	 * statistics MySQL may pick the unique index for status pages and scan the
	 * whole table (~2.7 s). ANALYZE TABLE costs ~0.2 s at one million rows and
	 * runs only when a scan finishes or an operator asks for it.
	 */
	public function optimize(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
		$wpdb->query( "ANALYZE TABLE {$this->index}" );
	}

	/**
	 * Record one failed attempt for a scan and return the new attempt count.
	 *
	 * The scan keeps its lock and cursor; the worker decides when to fail it
	 * (three attempts) so a transient S3/DB error does not lose the scan.
	 *
	 * @param int    $scan_id Scan id.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return int
	 */
	public function record_scan_failure( int $scan_id, string $code, string $message ): int {
		global $wpdb;
		$scan = $this->scan( $scan_id );
		if ( ! $scan ) {
			return 0;
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans} SET attempts = attempts + 1, error_code = %s, error_message = %s, updated_at = %s WHERE id = %d AND state IN ('queued','running')",
				substr( $code, 0, 64 ),
				mb_substr( $message, 0, 250 ),
				$this->now(),
				$scan_id
			)
		);
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$this->scans} WHERE id = %d", $scan_id ) );
		$this->event(
			(string) $scan->profile_id,
			'warning',
			'scan_retry',
			sprintf(
				/* translators: 1: attempt number, 2: error message. */
				__( '对账第 %1$d 次失败：%2$s', 'sakura-imgopt' ),
				$attempts,
				$message
			),
			array( 'scan_id' => $scan_id )
		);
		return $attempts;
	}

	/**
	 * Recompute statuses from the local/remote flags already stored.
	 *
	 * This is the lightweight "refresh differences" path: it never lists S3,
	 * so it cannot discover new/removed objects, but it fixes status and
	 * counts after manual actions or a partial failure in under a second.
	 *
	 * @param string $profile_id Profile id.
	 * @return void
	 */
	public function recalculate( string $profile_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->index}
				SET status = CASE
						WHEN status = 'ignored' THEN 'ignored'
						WHEN status = 'failed' AND error_code IS NOT NULL AND error_code <> '' THEN 'failed'
						WHEN seen_local = 1 AND seen_remote = 1 THEN IF( local_size = remote_size, 'synced', 'mismatch' )
						WHEN seen_local = 1 THEN 'pending'
						WHEN seen_remote = 1 THEN 'remote'
						ELSE 'pending'
					END,
					updated_at = %s
				WHERE profile_id = %s AND status <> 'ignored'",
				$this->now(),
				$profile_id
			)
		);
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->index} SET error_code = NULL, error_message = NULL WHERE profile_id = %s AND status <> 'failed'", $profile_id ) );
		$this->rebuild_counts( $profile_id );
	}

	/**
	 * Queue an action for the background worker.
	 *
	 * The row carries the profile, target fingerprint, attachment and file
	 * version captured when the user asked for the action. A later settings
	 * switch must not redirect it into a different bucket, and the unique
	 * dedupe key makes a repeated click harmless.
	 *
	 * @param string $profile_id    Profile id.
	 * @param string $key           Object key.
	 * @param string $action        upload|download|delete.
	 * @param int    $delay         Seconds before the first attempt.
	 * @param int    $attachment_id Attachment id, 0 when unknown.
	 * @param string $target_hash   Target fingerprint at enqueue time.
	 * @param string $version       File version (size:mtime), '' when unknown.
	 * @return int|\WP_Error Job id.
	 */
	public function enqueue_job( string $profile_id, string $key, string $action, int $delay = 0, int $attachment_id = 0, string $target_hash = '', string $version = '' ) {
		global $wpdb;
		$action = strtolower( $action );
		if ( ! in_array( $action, array( 'upload', 'download', 'delete' ), true ) ) {
			return new \WP_Error( 'sio_job_action', __( '不支持的队列动作。', 'sakura-imgopt' ) );
		}
		$hash   = $this->hash_hex( $key );
		$dedupe = hash( 'sha256', $profile_id . '|' . $action . '|' . $key . '|' . $version . '|' . $target_hash );
		$now    = $this->now();
		$next   = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) );

		$sql = $wpdb->prepare(
			"INSERT INTO {$this->jobs} (profile_id, object_key_hash, object_key, action, attachment_id, target_hash, dedupe_key, attempts, next_at, claim_token, last_error, state, created_at, updated_at)
			VALUES (%s, UNHEX(%s), %s, %s, %d, %s, %s, 0, %s, '', NULL, 'queued', %s, %s)
			ON DUPLICATE KEY UPDATE
				id = LAST_INSERT_ID(id),
				next_at = IF(state = 'queued', LEAST(next_at, VALUES(next_at)), next_at),
				claim_token = IF(state = 'dead', '', claim_token),
				attempts = IF(state = 'dead', 0, attempts),
				state = IF(state = 'dead', 'queued', state),
				updated_at = VALUES(updated_at)",
			$profile_id,
			$hash,
			$key,
			$action,
			max( 0, $attachment_id ),
			$target_hash,
			$dedupe,
			$next,
			$now,
			$now
		);

		$ok = false === $sql ? false : $wpdb->query( $sql );
		if ( false === $ok ) {
			return new \WP_Error( 'sio_job_insert', __( '无法写入动作队列。', 'sakura-imgopt' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Jobs that are due now.
	 *
	 * @param int $limit Max jobs.
	 * @return array<int,object>
	 */
	public function due_jobs( int $limit = 10 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		// A worker that died mid-job must not hold the row forever. An expired
		// lease also spends an attempt: without this a job that keeps killing
		// workers would be retried forever instead of ending up dead.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->jobs} SET state = IF( attempts + 1 >= %d, 'dead', 'queued' ), last_error = IF( attempts + 1 >= %d, %s, last_error ), attempts = attempts + 1, lease_until = NULL, claim_token = '', updated_at = %s WHERE state = 'running' AND lease_until IS NOT NULL AND lease_until < %s",
				self::JOB_MAX_ATTEMPTS,
				self::JOB_MAX_ATTEMPTS,
				mb_substr( __( '工作进程超时，任务已放弃。', 'sakura-imgopt' ), 0, 250 ),
				$this->now(),
				$this->now()
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->jobs} WHERE state = 'queued' AND next_at <= %s ORDER BY next_at ASC, id ASC LIMIT %d",
				$this->now(),
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Claim a queued job and return its fencing token.
	 *
	 * @param int $job_id Job id.
	 * @return string|false Claim token, or false when another worker won.
	 */
	public function job_start( int $job_id ) {
		global $wpdb;
		$token  = bin2hex( random_bytes( 16 ) );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->jobs} SET state = 'running', lease_until = %s, claim_token = %s, updated_at = %s WHERE id = %d AND state = 'queued'",
				gmdate( 'Y-m-d H:i:s', time() + self::JOB_LEASE_SECONDS ),
				$token,
				$this->now(),
				$job_id
			)
		);
		return 1 === (int) $updated ? $token : false;
	}

	/** Renew only a still-live claim; never resurrect an expired or replaced one. */
	public function job_renew( int $job_id, string $claim_token ): bool {
		global $wpdb;
		$now = $this->now();
		$until = gmdate( 'Y-m-d H:i:s', time() + self::JOB_LEASE_SECONDS );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->jobs} SET lease_until = %s, updated_at = %s WHERE id = %d AND state = 'running' AND claim_token = %s AND lease_until >= %s",
			$until, $now, $job_id, $claim_token, $now
		) );
		if ( false === $updated ) {
			return false;
		}
		// A renewal in the same second may affect zero rows; verify ownership.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$this->jobs} WHERE id = %d AND state = 'running' AND claim_token = %s AND lease_until >= %s",
			$job_id, $claim_token, $until
		) );
	}

	/**
	 * Delete a completed job only when this worker still owns its lease.
	 *
	 * @param int    $job_id Job id.
	 * @param string $claim_token Fencing token.
	 * @return bool Whether this worker completed the row.
	 */
	public function job_success( int $job_id, string $claim_token ): bool {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->jobs} WHERE id = %d AND state = 'running' AND claim_token = %s",
				$job_id,
				$claim_token
			)
		);
		return 1 === (int) $deleted;
	}

	/**
	 * Reschedule a failed job with exponential backoff (60 s × 2^attempt, max 1 h).
	 *
	 * @param int    $job_id Job id.
	 * @param string $error  Error message.
	 * @param string $claim_token Fencing token.
	 * @return array{attempts:int,dead:bool,next_at:string,applied:bool}
	 */
	public function job_failure( int $job_id, string $error, string $claim_token ): array {
		global $wpdb;
		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$this->jobs} WHERE id = %d AND state = 'running' AND claim_token = %s",
				$job_id,
				$claim_token
			)
		);
		if ( null === $attempts ) {
			return array( 'attempts' => 0, 'dead' => false, 'next_at' => '', 'applied' => false );
		}
		$attempts = (int) $attempts + 1;
		$dead     = $attempts >= self::JOB_MAX_ATTEMPTS;
		$wait     = $dead ? 0 : min( HOUR_IN_SECONDS, 60 * ( 2 ** ( $attempts - 1 ) ) );
		$next     = gmdate( 'Y-m-d H:i:s', time() + $wait );
		$updated  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->jobs} SET attempts = %d, state = %s, next_at = %s, lease_until = NULL, claim_token = '', last_error = %s, updated_at = %s WHERE id = %d AND state = 'running' AND claim_token = %s",
				$attempts,
				$dead ? 'dead' : 'queued',
				$next,
				mb_substr( $error, 0, 250 ),
				$this->now(),
				$job_id,
				$claim_token
			)
		);
		if ( 1 !== (int) $updated ) {
			return array( 'attempts' => $attempts, 'dead' => $dead, 'next_at' => $next, 'applied' => false );
		}
		return array( 'attempts' => $attempts, 'dead' => $dead, 'next_at' => $next, 'applied' => true );
	}

	/**
	 * Job counts by state.
	 *
	 * @param string $profile_id Profile id.
	 * @return array<string,int>
	 */
	public function jobs_summary( string $profile_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS n FROM {$this->jobs} WHERE profile_id = %s GROUP BY state", $profile_id ) );
		$out  = array( 'queued' => 0, 'running' => 0, 'dead' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->state ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Whether any queued job remains (including ones waiting for backoff).
	 *
	 * @return bool
	 */
	public function has_queued_jobs(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$this->jobs} WHERE state = 'queued' LIMIT 1" );
	}

	/**
	 * Recent jobs with the object key.
	 *
	 * @param string $profile_id Profile id.
	 * @param int    $limit      Limit.
	 * @return array<int,object>
	 */
	public function recent_jobs( string $profile_id, int $limit = 10 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, profile_id, object_key, action, attempts, state, next_at, last_error, created_at, updated_at FROM {$this->jobs} WHERE profile_id = %s ORDER BY id DESC LIMIT %d",
				$profile_id,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of dead jobs across profiles.
	 *
	 * @return int
	 */
	public function dead_jobs_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->jobs} WHERE state = 'dead'" );
	}

	/**
	 * Recalculate counts and bytes from the index (scan completion / CLI).
	 *
	 * Never call this per action or per page request: a million rows cost
	 * ~1.3 s here, while adjust_counts() is a two-row primary-key update.
	 *
	 * @param string $profile_id Profile.
	 */
	public function rebuild_counts( string $profile_id ): void {
		global $wpdb;
		$now = $this->now();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->counts} WHERE profile_id=%s", $profile_id ) );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->counts} (profile_id,status,item_count,bytes,updated_at)
				SELECT profile_id,status,COUNT(*),COALESCE(SUM(GREATEST(local_size,remote_size)),0),%s
				FROM {$this->index} WHERE profile_id=%s GROUP BY profile_id,status",
				$now,
				$profile_id
			)
		);
	}

	/**
	 * Recompute cloud type counts for a scan from the index (CLI/backfill).
	 *
	 * @param string $profile_id Profile.
	 * @param int    $scan_id    Scan (0 = latest).
	 * @return array<string,int>
	 */
	public function rebuild_type_counts( string $profile_id, int $scan_id = 0 ): array {
		global $wpdb;
		if ( $scan_id <= 0 ) {
			$scan = $this->latest_scan( $profile_id );
			if ( ! $scan ) {
				return array( 'image' => 0, 'video' => 0, 'archive' => 0, 'other' => 0 );
			}
			$scan_id = (int) $scan->id;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kind,COUNT(*) AS total FROM {$this->index}
				WHERE profile_id=%s AND seen_remote=1 GROUP BY kind",
				$profile_id
			),
			ARRAY_A
		);

		$types = array( 'image' => 0, 'video' => 0, 'archive' => 0, 'other' => 0 );
		foreach ( (array) $rows as $row ) {
			$kind            = (string) $row['kind'];
			$types[ $kind ]  = isset( $types[ $kind ] ) ? (int) $row['total'] : $types['other'] + (int) $row['total'];
		}

		$this->update_scan(
			$scan_id,
			array(
				'cloud_image'   => (int) $types['image'],
				'cloud_video'   => (int) $types['video'],
				'cloud_archive' => (int) $types['archive'],
			)
		);

		return $types;
	}

	/**
	 * Counts and latest scan summary. Cheap: two primary-key reads.
	 *
	 * @param string $profile_id Profile.
	 * @return array<string,mixed>
	 */
	public function summary( string $profile_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status,item_count,bytes FROM {$this->counts} WHERE profile_id=%s", $profile_id ),
			ARRAY_A
		);

		$counts = array( 'synced' => 0, 'pending' => 0, 'failed' => 0, 'remote' => 0, 'mismatch' => 0 );
		$bytes  = array();
		foreach ( (array) $rows as $row ) {
			$status            = (string) $row['status'];
			$counts[ $status ] = (int) $row['item_count'];
			$bytes[ $status ]  = (int) $row['bytes'];
		}

		$scan          = $this->latest_scan( $profile_id );
		$total         = array_sum( $counts );
		$local_files   = $scan ? (int) $scan->total_local : 0;
		$cloud_objects = $scan ? (int) $scan->total_remote : 0;
		$coverage      = $total > 0 ? ( $counts['synced'] / $total ) : 0.0;
		$state         = $scan ? (string) $scan->state : '';
		$phase         = $scan ? (string) $scan->phase : '';
		$age           = $scan && $scan->updated_at ? time() - (int) strtotime( (string) $scan->updated_at . ' UTC' ) : 0;

		return array(
			'profile_id'    => $profile_id,
			'counts'        => $counts,
			'bytes'         => $bytes,
			'total'         => $total,
			'local_files'   => $local_files,
			'local_bytes'   => $scan ? (int) $scan->local_bytes : 0,
			'cloud_objects' => $cloud_objects,
			'cloud_bytes'   => $scan ? (int) $scan->remote_bytes : 0,
			'coverage'      => $coverage,
			'scan_id'       => $scan ? (int) $scan->id : 0,
			'scanned_at'    => $scan && $scan->finished_at ? (int) strtotime( $scan->finished_at . ' UTC' ) : 0,
			'scan'          => $scan,
			'scan_state'    => $state,
			'scan_phase'    => $phase,
			'busy'          => in_array( $state, array( 'queued', 'running' ), true ),
			'stale'         => in_array( $state, array( 'queued', 'running' ), true ) && $age > 600,
			'resumable'     => in_array( $state, array( 'failed', 'cancelled' ), true ) && '' !== $phase,
			'cloud_types'   => $this->types_from_scan( $scan ),
		);
	}

	/**
	 * Cloud object counts by type, pre-aggregated on the scan row.
	 *
	 * @param string $profile_id Profile.
	 * @return array<string,int>
	 */
	public function cloud_types( string $profile_id ): array {
		return $this->types_from_scan( $this->latest_scan( $profile_id ) );
	}

	/**
	 * Derive type counts from a scan row.
	 *
	 * @param object|null $scan Scan row.
	 * @return array<string,int>
	 */
	private function types_from_scan( ?object $scan ): array {
		$image   = $scan ? (int) $scan->cloud_image : 0;
		$video   = $scan ? (int) $scan->cloud_video : 0;
		$archive = $scan ? (int) $scan->cloud_archive : 0;
		$total   = $scan ? (int) $scan->total_remote : 0;
		return array(
			'image'   => $image,
			'video'   => $video,
			'archive' => $archive,
			'other'   => max( 0, $total - $image - $video - $archive ),
		);
	}

	/**
	 * Keyset page.
	 *
	 * @param string $profile_id Profile.
	 * @param string $status     all|synced|pending|failed|remote|mismatch.
	 * @param string $search     Key prefix (design 5.2: prefix only).
	 * @param string $type       all|image|video|archive|other.
	 * @param int    $days       Modified within N days.
	 * @param string $cursor     Opaque cursor: object key when searching, hash otherwise.
	 * @param int    $limit      Rows.
	 * @return array<string,mixed>
	 */
	public function page( string $profile_id, string $status = 'all', string $search = '', string $type = 'all', int $days = 0, string $cursor = '', int $limit = 50 ): array {
		global $wpdb;
		$limit  = max( 1, min( 200, $limit ) );
		$search    = ltrim( trim( $search ), '/' );
		$by_key    = '' !== $search;
		$by_recent = $days > 0 && $this->recent_ready();
		$where     = array( 'profile_id=%s' );
		$args   = array( $profile_id );

		if ( 'all' !== $status && in_array( $status, array( 'synced', 'pending', 'failed', 'remote', 'mismatch', 'ignored' ), true ) ) {
			$where[] = 'status=%s';
			$args[]  = $status;
		}
		if ( $by_key ) {
			$where[] = 'object_key LIKE %s';
			$args[]  = $wpdb->esc_like( $search ) . '%';
		}
		if ( '' !== $type && 'all' !== $type && in_array( $type, array( 'image', 'video', 'archive', 'other' ), true ) ) {
			$where[] = 'kind=%s';
			$args[]  = $type;
		}
		if ( $days > 0 ) {
			$cut_str = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			if ( $this->recent_ready() ) {
				$where[] = 'recent_at >= %s';
				$args[]  = $cut_str;
			} else {
				$where[] = '(local_mtime >= %s OR remote_mtime >= %s)';
				$args[]  = $cut_str;
				$args[]  = $cut_str;
			}
		}
		if ( '' !== $cursor ) {
			if ( $by_recent ) {
				$boundary = Keyset_Cursor::decode_recent( $cursor );
				if ( null !== $boundary ) {
					$where[] = '(recent_at < %s OR (recent_at = %s AND object_key_hash < UNHEX(%s)))';
					$args[]  = $boundary[0];
					$args[]  = $boundary[0];
					$args[]  = $boundary[1];
				}
			} elseif ( $by_key ) {
				$boundary = Keyset_Cursor::decode_value( $cursor );
				if ( null !== $boundary ) {
					$where[] = 'object_key > %s';
					$args[]  = $boundary;
				}
			} else {
				$binary = Keyset_Cursor::decode( $cursor );
				if ( null !== $binary ) {
					$where[] = 'object_key_hash > UNHEX(%s)';
					$args[]  = bin2hex( $binary );
				}
			}
		}

		if ( $by_recent ) {
			$order = 'recent_at DESC, object_key_hash DESC';
			$force = ' FORCE INDEX (profile_recent)';
		} else {
			$order = $by_key ? 'object_key ASC' : 'object_key_hash ASC';
			$force = '';
		}
		$args[] = $limit + 1;
		$sql    = "SELECT id,profile_id,object_key,kind,local_path,local_size,remote_size,local_mtime,remote_mtime,recent_at,status,error_code,error_message,scan_id,seen_local,seen_remote,updated_at
			FROM {$this->index}{$force} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order} LIMIT %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Where is built with placeholders.
		$rows   = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$key   = (string) $row['object_key'];
			$kind  = (string) ( $row['kind'] ?? '' );
			$out[] = array(
				'key'        => $key,
				'name'       => wp_basename( $key ),
				'path'       => (string) ( $row['local_path'] ?: $key ),
				'type'       => '' !== $kind ? $kind : Kind::of( $key ),
				'size'       => (int) $row['local_size'],
				'cloud_size' => (int) $row['remote_size'],
				'mtime'      => max(
					(int) ( $row['local_mtime'] ? strtotime( (string) $row['local_mtime'] . ' UTC' ) : 0 ),
					(int) ( $row['remote_mtime'] ? strtotime( (string) $row['remote_mtime'] . ' UTC' ) : 0 )
				),
				'status'     => (string) $row['status'],
				'error'      => (string) ( $row['error_message'] ?? '' ),
				'local'      => (bool) $row['seen_local'],
				'cloud'      => (bool) $row['seen_remote'],
				'id'         => 0,
			);
		}

		$next = '';
		if ( $has_more && $rows ) {
			$last = $rows[ count( $rows ) - 1 ];
			if ( $by_recent ) {
				$next = Keyset_Cursor::from_recent( (string) $last['recent_at'], hash( 'sha256', (string) $last['object_key'], true ) );
			} else {
				$next = $by_key
					? Keyset_Cursor::from_value( (string) $last['object_key'] )
					: Keyset_Cursor::from_key( (string) $last['object_key'] );
			}
		}

		return array(
			'rows'     => $out,
			'cursor'   => $next,
			'has_more' => $has_more,
		);
	}

	/**
	 * Find one row by key.
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @return array<string,mixed>|null
	 */
	public function find( string $profile_id, string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->index} WHERE profile_id=%s AND object_key_hash=UNHEX(%s) LIMIT 1",
				$profile_id,
				$this->hash_hex( $key )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Apply a successful action to the index and adjust counters in place.
	 *
	 * @param string $profile_id Profile.
	 * @param string $key        Object key.
	 * @param string $action     upload|download|delete.
	 */
	public function apply_action( string $profile_id, string $key, string $action ): void {
		global $wpdb;
		$hash = hash( 'sha256', $key, true );
		$row  = $this->find( $profile_id, $key );
		if ( ! $row ) {
			return;
		}

		$old_status = (string) $row['status'];
		$old_bytes  = max( (int) $row['local_size'], (int) $row['remote_size'] );

		if ( 'delete' === $action ) {
			$deleted = $wpdb->delete( $this->index, array( 'profile_id' => $profile_id, 'object_key_hash' => $hash ), array( '%s', '%s' ) );
			if ( $deleted ) {
				$this->adjust_counts( $profile_id, $old_status, -1, -$old_bytes );
			}
			return;
		}

		$fields = array(
			'status'        => 'synced',
			'error_code'    => null,
			'error_message' => null,
			'updated_at'    => $this->now(),
		);
		if ( 'download' === $action ) {
			$fields['local_path'] = $key;
			$fields['local_size'] = (int) $row['remote_size'];
			$fields['seen_local'] = 1;
			$new_bytes            = max( (int) $row['remote_size'], (int) $row['remote_size'] );
		} else {
			$fields['remote_size'] = (int) $row['local_size'];
			$fields['seen_remote'] = 1;
			$new_bytes             = max( (int) $row['local_size'], (int) $row['local_size'] );
		}

		$updated = $wpdb->update( $this->index, $fields, array( 'profile_id' => $profile_id, 'object_key_hash' => $hash ), null, array( '%s', '%s' ) );
		if ( false === $updated ) {
			return;
		}

		if ( $old_status !== 'synced' ) {
			$this->adjust_counts( $profile_id, $old_status, -1, -$old_bytes );
			$this->adjust_counts( $profile_id, 'synced', 1, $new_bytes );
		} elseif ( $new_bytes !== $old_bytes ) {
			$this->adjust_counts( $profile_id, 'synced', 0, $new_bytes - $old_bytes );
		}
	}

	/**
	 * Record local files discovered outside a scan (attachment hooks).
	 *
	 * Keeps status and the counts table in sync without a full GROUP BY so a
	 * single media upload costs a handful of primary-key updates.
	 *
	 * @param string                         $profile_id Profile.
	 * @param array<int,array<string,mixed>> $rows       key/path/size/mtime rows.
	 */
	public function record_local( string $profile_id, array $rows ): void {
		global $wpdb;
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return is_array( $row ) && isset( $row['key'] ) && '' !== (string) $row['key'];
				}
			)
		);
		if ( ! $rows ) {
			return;
		}

		$hashes = array();
		foreach ( $rows as $row ) {
			$hashes[] = $this->hash_hex( (string) $row['key'] );
		}
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), 'UNHEX(%s)' ) );
		$existing     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_key_hash,status,error_code,local_size,remote_size,seen_local,seen_remote
				FROM {$this->index} WHERE profile_id=%s AND object_key_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated from count.
				array_merge( array( $profile_id ), $hashes )
			),
			ARRAY_A
		);
		$old_rows = array();
		foreach ( (array) $existing as $existing_row ) {
			$old_rows[ bin2hex( (string) $existing_row['object_key_hash'] ) ] = $existing_row;
		}

		$scan_id = $this->running_scan_id( $profile_id );
		$this->upsert_local( $profile_id, $scan_id, $rows );

		foreach ( $rows as $row ) {
			$hash = hash( 'sha256', (string) $row['key'], true );
			$old  = $old_rows[ bin2hex( $hash ) ] ?? null;
			if ( $old && 'ignored' === (string) $old['status'] ) {
				continue;
			}

			$local_size  = max( 0, (int) ( $row['size'] ?? 0 ) );
			$remote_size = $old ? (int) $old['remote_size'] : 0;
			$seen_remote = $old ? (int) $old['seen_remote'] : 0;
			$status      = $this->local_status( $old, $local_size, $remote_size, $seen_remote );

			if ( $old && ( (string) $old['status'] !== $status || 'failed' !== $status ) ) {
				$wpdb->update(
					$this->index,
					array(
						'status'        => $status,
						'error_code'    => null,
						'error_message' => null,
						'updated_at'    => $this->now(),
					),
					array(
						'profile_id'      => $profile_id,
						'object_key_hash' => $hash,
					),
					array( '%s', '%s', '%s', '%s' ),
					array( '%s', '%s' )
				);
			}

			$old_bytes = $old ? max( (int) $old['local_size'], (int) $old['remote_size'] ) : 0;
			$new_bytes = max( $local_size, $seen_remote ? $remote_size : 0 );
			if ( $old ) {
				$this->adjust_counts( $profile_id, (string) $old['status'], -1, -$old_bytes );
			}
			$this->adjust_counts( $profile_id, $status, 1, $new_bytes );
		}
	}

	/**
	 * Forget local files for an attachment (delete hook).
	 *
	 * Rows whose object still exists remotely become `remote` instead of being
	 * deleted, so a later cloud scan does not have to recreate them.
	 *
	 * @param string   $profile_id Profile.
	 * @param string[] $keys       Object keys.
	 */
	public function forget_local( string $profile_id, array $keys ): void {
		global $wpdb;
		$keys = array_values(
			array_filter(
				array_map( 'strval', $keys ),
				static function ( $key ) {
					return '' !== $key;
				}
			)
		);
		if ( ! $keys ) {
			return;
		}

		$hashes       = array_map(
			fn( $key ) => $this->hash_hex( (string) $key ),
			$keys
		);
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), 'UNHEX(%s)' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_key_hash,status,local_size,remote_size,seen_remote
				FROM {$this->index} WHERE profile_id=%s AND object_key_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated from count.
				array_merge( array( $profile_id ), $hashes )
			),
			ARRAY_A
		);
		$scan_id = $this->running_scan_id( $profile_id );

		foreach ( (array) $rows as $row ) {
			$status    = (string) $row['status'];
			$hash      = (string) $row['object_key_hash'];
			$old_bytes = max( (int) $row['local_size'], (int) $row['remote_size'] );

			// "ignored" is a manual decision, so a deleted local file must not
			// silently revive it as pending. A local-only ignored row is dead
			// once the file is gone; a row with a remote twin still describes
			// the bucket object and downgrades to "remote" below.
			if ( 'ignored' === $status && (int) $row['seen_remote'] === 0 ) {
				$removed = $wpdb->delete(
					$this->index,
					array(
						'profile_id'      => $profile_id,
						'object_key_hash' => $hash,
					),
					array( '%s', '%s' )
				);
				if ( $removed ) {
					$this->adjust_counts( $profile_id, 'ignored', -1, -$old_bytes );
				}
				continue;
			}

			if ( (int) $row['seen_remote'] > 0 ) {
				$remote_bytes = (int) $row['remote_size'];
				$wpdb->update(
					$this->index,
					array(
						'local_path'    => '',
						'local_size'    => 0,
						'local_mtime'   => null,
						'seen_local'    => 0,
						'status'        => 'remote',
						'error_code'    => null,
						'error_message' => null,
						'scan_id'       => $scan_id,
						'updated_at'    => $this->now(),
					),
					array(
						'profile_id'      => $profile_id,
						'object_key_hash' => $hash,
					),
					array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ),
					array( '%s', '%s' )
				);
				if ( 'remote' !== $status ) {
					$this->adjust_counts( $profile_id, $status, -1, -$old_bytes );
					$this->adjust_counts( $profile_id, 'remote', 1, $remote_bytes );
				}
			} else {
				$deleted = $wpdb->delete(
					$this->index,
					array(
						'profile_id'      => $profile_id,
						'object_key_hash' => $hash,
					),
					array( '%s', '%s' )
				);
				if ( $deleted ) {
					$this->adjust_counts( $profile_id, $status, -1, -$old_bytes );
				}
			}
		}
	}

	/**
	 * Status after a local file appears, mirroring reconcile's rules.
	 *
	 * @param array<string,mixed>|null $old         Existing row.
	 * @param int                      $local_size  New local size.
	 * @param int                      $remote_size Remote size.
	 * @param int                      $seen_remote Whether the object exists remotely.
	 */
	private function local_status( ?array $old, int $local_size, int $remote_size, int $seen_remote ): string {
		if ( $old && 'ignored' === (string) $old['status'] ) {
			return 'ignored';
		}
		if ( $old && 'failed' === (string) $old['status'] && '' !== (string) $old['error_code'] ) {
			return 'failed';
		}
		if ( $seen_remote > 0 ) {
			return $local_size === $remote_size ? 'synced' : 'mismatch';
		}
		return 'pending';
	}

	/**
	 * Scan ID currently queued/running for a profile, or 0.
	 *
	 * @param string $profile_id Profile.
	 */
	private function running_scan_id( string $profile_id ): int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->scans} WHERE profile_id=%s AND state IN ('queued','running') ORDER BY id DESC LIMIT 1",
				$profile_id
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * Increment/decrement a counts row without a full GROUP BY.
	 *
	 * @param string $profile_id Profile.
	 * @param string $status     Status.
	 * @param int    $items      Signed item delta.
	 * @param int    $bytes      Signed byte delta.
	 */
	private function adjust_counts( string $profile_id, string $status, int $items, int $bytes ): void {
		global $wpdb;
		if ( ! in_array( $status, array( 'synced', 'pending', 'failed', 'remote', 'mismatch', 'ignored' ), true ) ) {
			return;
		}
		$now = $this->now();
		if ( $items < 0 || $bytes < 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->counts}
					SET item_count=GREATEST(0,CAST(item_count AS SIGNED) + %d),
						bytes=GREATEST(0,CAST(bytes AS SIGNED) + %d),
						updated_at=%s
					WHERE profile_id=%s AND status=%s",
					$items,
					$bytes,
					$now,
					$profile_id,
					$status
				)
			);
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->counts} (profile_id,status,item_count,bytes,updated_at)
				VALUES (%s,%s,%d,%d,%s)
				ON DUPLICATE KEY UPDATE
					item_count=item_count+VALUES(item_count),
					bytes=bytes+VALUES(bytes),
					updated_at=VALUES(updated_at)",
				$profile_id,
				$status,
				$items,
				$bytes,
				$now
			)
		);
	}

	/**
	 * Insert an event.
	 *
	 * @param string              $profile_id Profile.
	 * @param string              $level      info|warning|error.
	 * @param string              $event      Event key.
	 * @param string              $message    Message.
	 * @param array<string,mixed> $context    Context.
	 */
	public function event( string $profile_id, string $level, string $event, string $message, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$this->events,
			array(
				'profile_id' => $profile_id,
				'level'      => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'event'      => substr( $event, 0, 64 ),
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => $this->now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Latest events.
	 *
	 * @param string $profile_id Profile.
	 * @param int    $limit      Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function events( string $profile_id, int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT level,event,message,created_at FROM {$this->events} WHERE profile_id=%s ORDER BY id DESC LIMIT %d",
				$profile_id,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Retention: 30 days of events, 90 days of finished scans (latest per
	 * profile is always kept), 30 days of dead jobs.
	 *
	 * @return array<string,int>
	 */
	public function cleanup(): array {
		global $wpdb;
		$events_cut = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$scans_cut  = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		$events = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->events} WHERE created_at < %s", $events_cut ) );
		$jobs   = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->jobs} WHERE state='dead' AND updated_at < %s", $events_cut ) );

		$scans = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->scans}
				WHERE state IN ('done','failed','cancelled')
					AND finished_at IS NOT NULL
					AND finished_at < %s
					AND id NOT IN (
						SELECT latest FROM (
							SELECT MAX(id) AS latest FROM {$this->scans} GROUP BY profile_id
						) AS keep
					)",
				$scans_cut
			)
		);

		return array( 'events' => $events, 'scans' => $scans, 'jobs' => $jobs );
	}

	/**
	 * Remove every row this profile owns: the index itself, its counters, its
	 * queued actions, its scans and its event log.
	 *
	 * The scan row is what the sync center reads for "云端 N 个文件" and the
	 * event log is what the log card prints, so a purge that skipped them would
	 * leave a profile showing numbers for a bucket it no longer points at. Both
	 * callers (CLI `sync purge` and deleting a saved profile) mean "forget this
	 * target completely".
	 *
	 * @param string $profile_id Profile.
	 */
	public function purge_index( string $profile_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->index} WHERE profile_id=%s", $profile_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->counts} WHERE profile_id=%s", $profile_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->jobs} WHERE profile_id=%s", $profile_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->scans} WHERE profile_id=%s", $profile_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->events} WHERE profile_id=%s", $profile_id ) );
	}

	/**
	 * Backfill `recent_at` for rows written before 1.4.0.
	 *
	 * `recent_at` is the newest of local/remote mtime as a UTC datetime,
	 * floored to 1970-01-01 so NULL unambiguously means "not backfilled yet"
	 * even for rows that have neither timestamp.
	 *
	 * @param int $limit Rows per batch.
	 */
	public function backfill_recent( int $limit = 20000 ): int {
		global $wpdb;
		$limit = max( 1, min( 50000, $limit ) );
		$sql   = "UPDATE {$this->index}
			SET recent_at = GREATEST(COALESCE(local_mtime,'1970-01-01 00:00:00'),COALESCE(remote_mtime,'1970-01-01 00:00:00'))
			WHERE recent_at IS NULL LIMIT %d";

		return (int) $wpdb->query( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
	}

	/**
	 * Count rows whose `recent_at` has not been backfilled yet.
	 *
	 * @phpstan-impure
	 */
	public function pending_recent_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->index} WHERE recent_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
	}

	/**
	 * Whether the whole index has a usable `recent_at` value.
	 *
	 * While false, `page()` keeps the legacy OR predicate so an in-flight
	 * backfill can never hide rows from the days filter.
	 */
	public function recent_ready(): bool {
		if ( null === $this->recent_ready ) {
			$this->recent_ready = '1' === (string) get_option( Schema::RECENT_READY, '' );
		}
		return $this->recent_ready;
	}

	/**
	 * Fill `kind` for a bounded batch of pre-1.1.0 rows.
	 *
	 * @param int $limit Max rows.
	 * @return int Rows updated.
	 * @phpstan-impure
	 */
	public function backfill_kind( int $limit = 20000 ): int {
		global $wpdb;
		$limit = max( 1, min( 50000, $limit ) );

		$cases = array();
		foreach ( array( 'image', 'video', 'archive' ) as $kind ) {
			$extensions = array_map(
				static function ( string $ext ): string {
					return "'" . esc_sql( $ext ) . "'";
				},
				Kind::extensions( $kind )
			);
			$cases[] = "WHEN LOWER(SUBSTRING_INDEX(object_key,'.',-1)) IN (" . implode( ',', $extensions ) . ") THEN '{$kind}'";
		}

		$sql = "UPDATE {$this->index}
			SET kind = CASE " . implode( ' ', $cases ) . " ELSE 'other' END
			WHERE kind='' LIMIT %d";

		return (int) $wpdb->query( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CASE is built from Kind::extensions().
	}

	/**
	 * Rows still missing a kind.
	 */
	public function pending_kind_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->index} WHERE kind=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input.
	}

	/**
	 * SHA-256 of an object key as ASCII hex.
	 *
	 * Binary digests cannot travel through $wpdb->prepare() as %s values:
	 * random bytes are invalid UTF-8 under utf8mb4 and MariaDB rejects the
	 * statement, so every hash parameter goes through UNHEX(%s) instead.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function hash_hex( string $key ): string {
		return bin2hex( hash( 'sha256', $key, true ) );
	}

	/**
	 * UTC now in MySQL format.
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
