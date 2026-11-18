<?php
/**
 * Sync index schema.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the sync index tables.
 *
 * 1.1.0 adds the derived `kind` column (image/video/archive/other) with a
 * covering keyset index, and pre-aggregated cloud type counts on the scan row.
 * Both exist so list filtering and first paint never aggregate a million rows.
 *
 * 1.9.0 re-partitions the index by bucket instead of by profile row (R19.7 in
 * docs/开发规则/07-S3存储生命周期.md). Every row now carries `target_hash`, the
 * fingerprint of the bucket it was observed in, and the unique key is
 * (target_hash, object_key_hash). `profile_id` stays as a label: the row that
 * last confirmed the object. The old key made a bucket unreadable to any other
 * row pointing at it, so a standby row saw a pre-seeded bucket as empty and
 * every URL fell back to the site domain.
 */
final class Schema {

	/**
	 * Schema version.
	 */
	public const VERSION = '1.9.0';

	/**
	 * Schema option.
	 */
	public const OPTION = 'sakura_imgopt_sync_schema';

	/**
	 * Cron hook used for the bounded kind backfill.
	 */
	public const BACKFILL_HOOK = 'sakura_imgopt_sync_kind_backfill';

	/**
	 * Cron hook used for the bounded recent_at backfill.
	 */
	public const BACKFILL_RECENT_HOOK = 'sakura_imgopt_sync_recent_backfill';

	/**
	 * Option that flips when every row has a recent_at value.
	 */
	public const RECENT_READY = 'sakura_imgopt_sync_recent_ready';

	/**
	 * Set when the 1.9.0 migration dropped and recreated the counts table.
	 *
	 * @var bool
	 */
	private static bool $bucket_migrated = false;

	/**
	 * Physical table name.
	 *
	 * @param string $name Logical name.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sio_sync_' . $name;
	}

	/**
	 * Install or upgrade tables when needed.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === (string) get_option( self::OPTION, '' ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the tables via dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$previous = (string) get_option( self::OPTION, '' );

		$charset = $wpdb->get_charset_collate();
		$index   = self::table( 'index' );
		$scans   = self::table( 'scans' );
		$counts  = self::table( 'counts' );
		$jobs    = self::table( 'jobs' );
		$events  = self::table( 'events' );

		$sql = array();

		$sql[] = "CREATE TABLE {$index} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			target_hash char(64) NOT NULL DEFAULT '',
			profile_id varchar(40) NOT NULL,
			object_key_hash binary(32) NOT NULL,
			object_key varbinary(1024) NOT NULL,
			kind varchar(16) NOT NULL DEFAULT '',
			local_path varbinary(768) NULL,
			local_dir varchar(255) NOT NULL DEFAULT '',
			local_size bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_size bigint(20) unsigned NOT NULL DEFAULT 0,
			local_mtime datetime NULL,
			local_md5 char(32) NULL,
			remote_mtime datetime NULL,
			recent_at datetime NULL,
			etag varchar(64) NULL,
			verify_state tinyint(3) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'pending',
			error_code varchar(64) NULL,
			error_message varchar(255) NULL,
			scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			seen_local tinyint(1) NOT NULL DEFAULT 0,
			seen_remote tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY target_object (target_hash,object_key_hash),
			KEY status_keyset (target_hash,status,object_key_hash),
			KEY target_kind (target_hash,kind,object_key_hash),
			KEY target_dir (target_hash,local_dir),
			KEY target_key (target_hash,object_key),
			KEY target_recent (target_hash,recent_at,object_key_hash)
		) {$charset};";

		$sql[] = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id varchar(40) NOT NULL,
			mode varchar(16) NOT NULL DEFAULT 'full',
			state varchar(16) NOT NULL DEFAULT 'queued',
			phase varchar(16) NOT NULL DEFAULT '',
			scan_cursor varbinary(1024) NULL,
			total_local bigint(20) unsigned NOT NULL DEFAULT 0,
			total_remote bigint(20) unsigned NOT NULL DEFAULT 0,
			local_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			cloud_image bigint(20) unsigned NOT NULL DEFAULT 0,
			cloud_video bigint(20) unsigned NOT NULL DEFAULT 0,
			cloud_archive bigint(20) unsigned NOT NULL DEFAULT 0,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			error_code varchar(64) NULL,
			error_message varchar(255) NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY profile_state (profile_id,state,id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$counts} (
			target_hash char(64) NOT NULL DEFAULT '',
			profile_id varchar(40) NOT NULL,
			status varchar(16) NOT NULL,
			item_count bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (target_hash,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id varchar(40) NOT NULL,
			object_key_hash binary(32) NOT NULL,
			object_key varbinary(1024) NOT NULL,
			action varchar(16) NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_hash char(64) NOT NULL DEFAULT '',
			dedupe_key char(64) NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_at datetime NOT NULL,
			lease_until datetime NULL,
			claim_token char(64) NOT NULL DEFAULT '',
			last_error varchar(255) NULL,
			state varchar(16) NOT NULL DEFAULT 'queued',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (dedupe_key),
			KEY due (state,next_at),
			KEY profile_object (profile_id,object_key_hash)
		) {$charset};";

		$sql[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_id varchar(40) NOT NULL,
			level varchar(16) NOT NULL DEFAULT 'info',
			event varchar(64) NOT NULL,
			message text NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY profile_time (profile_id,created_at)
		) {$charset};";

		$operations = self::table( 'operations' );
		$sql[] = "CREATE TABLE {$operations} (
			resource_hash char(64) NOT NULL,
			token char(64) NOT NULL,
			object_key varbinary(1024) NOT NULL,
			action varchar(16) NOT NULL,
			state varchar(16) NOT NULL DEFAULT 'inflight',
			last_error varchar(255) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			context_json longtext NULL,
			PRIMARY KEY  (resource_hash),
			KEY state_created (state,created_at)
		) {$charset};";

		$failed = self::prepare_bucket_partition( $previous );

		foreach ( $sql as $statement ) {
			if ( '' !== $failed ) {
				break;
			}

			dbDelta( $statement );

			if ( '' !== (string) $wpdb->last_error ) {
				$failed = (string) $wpdb->last_error;
				break;
			}
		}

		if ( '' !== $failed ) {
			// Never advertise a version that did not fully land: the next request
			// would skip the migration and then read missing tables or columns.
			do_action( 'sakura_imgopt_s3_schema_failed', $failed );

			return;
		}

		update_option( self::OPTION, self::VERSION, false );

		if ( self::$bucket_migrated ) {
			// The old table was keyed by profile and has just been recreated
			// empty; without this the sync centre would report zero objects
			// until someone ran a full scan.
			self::rebuild_bucket_counts();
		}

		if ( ! get_option( 'sakura_imgopt_sync_dir_indexed' ) ) {
			// Existing rows predate local_dir; without a backfill they would look
			// like orphans to the incremental scan and get purged.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"UPDATE {$index}
				SET local_dir = LEFT( local_path, CHAR_LENGTH( local_path ) - CHAR_LENGTH( SUBSTRING_INDEX( local_path, '/', -1 ) ) - 1 )
				WHERE local_dir = '' AND local_path LIKE '%/%'"
			);
			update_option( 'sakura_imgopt_sync_dir_indexed', 1, false );
		}

		if ( version_compare( $previous, '1.1.0', '<' ) ) {
			self::migrate_kind();
			delete_option( 'sakura_imgopt_s3_inventory' );
		}

		if ( version_compare( $previous, '1.4.0', '<' ) ) {
			self::migrate_recent();
		}
	}

	/**
	 * Backfill `kind` for rows written before 1.1.0.
	 *
	 * Runs a bounded batch inline so a small site is fixed immediately, then
	 * hands the rest to a cron event to avoid a front-end timeout while
	 * upgrading a million-row index.
	 */
	private static function migrate_kind(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Index_Repository' ) ) {
			return;
		}
		$repository = new Index_Repository();
		$repository->backfill_kind( 20000 );

		if ( $repository->pending_kind_count() > 0 && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::BACKFILL_HOOK );
		}
	}

	/**
	 * Backfill the derived `recent_at` column used by the "modified in N days"
	 * filter.
	 *
	 * The column stores the newest of local_mtime/remote_mtime as a UTC
	 * datetime (1970-01-01 when both are missing), so a bounded index range
	 * can replace the OR-over-two-columns scan. Existing rows are backfilled in batches;
	 * while the backfill is running the repository intentionally falls back to
	 * the legacy predicate, so results stay correct but slower.
	 */
	private static function migrate_recent(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Index_Repository' ) ) {
			return;
		}
		$repository = new Index_Repository();
		$repository->backfill_recent( 20000 );

		if ( $repository->pending_recent_count() > 0 ) {
			if ( ! wp_next_scheduled( self::BACKFILL_RECENT_HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::BACKFILL_RECENT_HOOK );
			}
			return;
		}
		update_option( self::RECENT_READY, '1', false );
	}

	/**
	 * Move the index from profile partitions to bucket partitions (1.9.0).
	 *
	 * dbDelta only ever adds indexes, so it would add the new unique key while
	 * leaving the old one in place: two rows describing the same object in the
	 * same bucket would then never merge. The legacy keys are dropped here, the
	 * bucket fingerprint is copied from each row's own profile row, and rows
	 * that collapse onto one (bucket, key) are reduced to a single row before
	 * the unique key exists.
	 *
	 * The counts table changes its primary key, which dbDelta cannot do in
	 * place, so it is dropped and rebuilt from the index afterwards; it only
	 * holds derived totals.
	 *
	 * Returns '' for a fresh table or an already migrated one, so the caller can
	 * treat the rest as a normal install.
	 *
	 * @param string $previous Version recorded before this run.
	 * @return string Error message, '' when the caller may continue.
	 */
	private static function prepare_bucket_partition( string $previous ): string {
		global $wpdb;

		if ( version_compare( $previous, self::VERSION, '>=' ) ) {
			return '';
		}

		$index  = self::table( 'index' );
		$counts = self::table( 'counts' );
		$keys   = self::index_names( $index );

		if ( array() === $keys || isset( $keys['target_object'] ) ) {
			$wpdb->last_error = '';
			return '';
		}

		// Every legacy key starts with profile_id, so all of them must go: the
		// new keys lead with target_hash and the old ones would keep serving
		// profile-scoped lookups that no longer mean anything.
		foreach ( array( 'profile_object', 'status_keyset', 'profile_kind', 'profile_dir', 'profile_key', 'profile_recent' ) as $name ) {
			if ( ! isset( $keys[ $name ] ) ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE {$index} DROP INDEX {$name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( '' !== (string) $wpdb->last_error ) {
				return (string) $wpdb->last_error;
			}
		}

		if ( ! self::has_column( $index, 'target_hash' ) ) {
			$wpdb->query( "ALTER TABLE {$index} ADD COLUMN target_hash char(64) NOT NULL DEFAULT '' AFTER id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( '' !== (string) $wpdb->last_error ) {
				return (string) $wpdb->last_error;
			}
		}

		$error = self::backfill_target_hash( $index );
		if ( '' !== $error ) {
			return $error;
		}

		// Two config rows can name the same bucket, so the same object can have
		// two records. Keep the earliest: it is the one the first scan wrote.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE dup FROM {$index} AS dup
			INNER JOIN {$index} AS keep
				ON keep.object_key_hash = dup.object_key_hash
				AND keep.target_hash = dup.target_hash
				AND keep.id < dup.id"
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return (string) $wpdb->last_error;
		}

		// The counters table is rebuilt from the index right after dbDelta ran, so
		// the old primary key does not have to be migrated: dropping the table is
		// the only way to move the primary key from profile_id to target_hash
		// (dbDelta can add a primary key, never replace one).
		$wpdb->query( "DROP TABLE IF EXISTS {$counts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::$bucket_migrated = true;

		return '';
	}

	/**
	 * Copy each row's bucket fingerprint from the profile row that wrote it.
	 *
	 * Rows whose profile row is gone stay empty: deriving them from the active
	 * settings would file objects under a bucket that never held them.
	 *
	 * @param string $index Index table name.
	 * @return string Error message, '' on success.
	 */
	private static function backfill_target_hash( string $index ): string {
		global $wpdb;

		$options_class  = '\Sakura\ImageOptimizer\S3\Options';
		$identity_class = '\Sakura\ImageOptimizer\S3\Bucket_Identity';

		if ( ! class_exists( $options_class ) || ! class_exists( $identity_class ) ) {
			// Without the identity classes nothing can be attributed, and adding
			// the unique key below would then fail on the first duplicate.
			return 's3 bucket identity classes are unavailable';
		}

		$options  = new $options_class();
		$profiles = $wpdb->get_col( "SELECT DISTINCT profile_id FROM {$index} WHERE profile_id <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $profiles as $profile_id ) {
			$profile_id = (string) $profile_id;
			$profile    = $options->profile( $profile_id );

			if ( array() === $profile ) {
				continue;
			}

			$hash = $identity_class::from_profile( $profile, $options )->hash();

			$wpdb->query( $wpdb->prepare( "UPDATE {$index} SET target_hash = %s WHERE profile_id = %s", $hash, $profile_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( '' !== (string) $wpdb->last_error ) {
				return (string) $wpdb->last_error;
			}
		}

		return '';
	}

	/**
	 * Rebuild the counts table from the freshly partitioned index.
	 */
	private static function rebuild_bucket_counts(): void {
		global $wpdb;

		$index  = self::table( 'index' );
		$counts = self::table( 'counts' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT INTO {$counts} (target_hash, profile_id, status, item_count, bytes, updated_at)
				SELECT target_hash, MIN(profile_id), status, COUNT(*), COALESCE(SUM(GREATEST(local_size, remote_size)), 0), %s
				FROM {$index}
				GROUP BY target_hash, status",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Index names present on a table.
	 *
	 * @param string $table Table name.
	 * @return array<string,bool> Key name => true.
	 */
	private static function index_names( string $table ): array {
		global $wpdb;

		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			if ( isset( $row['Key_name'] ) ) {
				$names[ (string) $row['Key_name'] ] = true;
			}
		}

		return $names;
	}

	/**
	 * Whether a table already has a column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function has_column( string $table, string $column ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null !== $found && '' !== (string) $found;
	}
}
