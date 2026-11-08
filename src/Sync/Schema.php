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
 */
final class Schema {

	/**
	 * Schema version.
	 */
	public const VERSION = '1.8.2';

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
			UNIQUE KEY profile_object (profile_id,object_key_hash),
			KEY status_keyset (profile_id,status,object_key_hash),
			KEY profile_kind (profile_id,kind,object_key_hash),
			KEY profile_dir (profile_id,local_dir),
			KEY profile_key (profile_id,object_key),
			KEY profile_recent (profile_id,recent_at,object_key_hash)
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
			profile_id varchar(40) NOT NULL,
			status varchar(16) NOT NULL,
			item_count bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (profile_id,status)
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

		$failed = '';

		foreach ( $sql as $statement ) {
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

}
