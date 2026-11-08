<?php
/**
 * Uninstall routine.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\S3\Upload_URL;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin data only when the site owner opted in.
 *
 * Ephemeral data (queues and token caches) is always removed. Persistent data
 * (options, post meta and backup files) is kept by default so an uninstall can
 * be followed by a reinstall without losing settings.
 */
final class Uninstaller {

	/**
	 * Run the uninstall routine for the current site or every site.
	 */
	public static function run(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::cleanup_current_site();
				restore_current_blog();
			}

			return;
		}

		self::cleanup_current_site();
	}

	/**
	 * Clean the current site, respecting the opt-in switch.
	 */
	private static function cleanup_current_site(): void {
		wp_clear_scheduled_hook( 'sakura_imgopt_daily_backup_cleanup' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_retry' );

		// Index scans can leave a single event per scan; clear every event for the hooks.
		wp_unschedule_hook( 'sakura_imgopt_sync_scan_tick' );
		wp_unschedule_hook( 'sakura_imgopt_sync_cleanup' );
		wp_unschedule_hook( 'sakura_imgopt_sync_kind_backfill' );
		wp_unschedule_hook( 'sakura_imgopt_sync_recent_backfill' );
		wp_unschedule_hook( 'sakura_imgopt_sync_daily_reconcile' );
		wp_unschedule_hook( 'sakura_imgopt_sync_job_tick' );
		wp_unschedule_hook( 'sakura_imgopt_sync_verify_tick' );
		wp_unschedule_hook( 'sakura_imgopt_s3_delete_tick' );
		wp_unschedule_hook( 'sakura_imgopt_s3_upload_tick' );
		wp_unschedule_hook( 'sakura_imgopt_s3_upload_watchdog' );
		wp_unschedule_hook( 'sakura_imgopt_s3_alert_check' );
		wp_unschedule_hook( 'sufm_tick' );
		// Capabilities are not user data: they are removed even when the owner
		// asked to keep files and ledger rows.
		\Nboke\UserFiles\Capabilities::revoke();

		/*
		 * The sync index is a rebuildable cache derived from the uploads
		 * directory and the bucket. It is dropped unconditionally: keeping a
		 * multi-hundred-megabyte orphan table after uninstall would surprise
		 * site owners, and a reinstall rebuilds it with one scan.
		 */
		self::drop_sync_tables();

		/*
		 * The user file space keeps its tables by default: the ledger explains
		 * every attachment it owns, so a reinstall can resume instead of
		 * orphaning rows. Dropping them is part of the explicit opt-in below.
		 */
		if ( self::should_delete_data() ) {
			self::cleanup_persistent_data();
		} else {
			self::delete_ephemeral_data();
		}
	}

	/**
	 * Drop the sync index tables and their schema marker.
	 */
	private static function drop_sync_tables(): void {
		global $wpdb;

		foreach ( array( 'index', 'scans', 'counts', 'jobs', 'events', 'operations' ) as $table ) {
			$name = $wpdb->prefix . 'sio_sync_' . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names, no user input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}

		// Legacy queue tables from <= 1.11 are no longer created, but upgraded
		// sites may still carry them; uninstall removes them if present.
		foreach ( array( 'sio_s3_uploads', 'sio_s3_deletes', 'sio_s3_upload_events' ) as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}

		delete_option( 'sakura_imgopt_sync_schema' );
		delete_option( 'sakura_imgopt_sync_migration' );
		delete_option( 'sakura_imgopt_s3_queue_schema' );
		delete_option( 'sakura_imgopt_s3_delete_schema' );
		delete_option( 'sakura_imgopt_s3_upload_schema' );
		delete_option( 'sakura_imgopt_sync_index_enabled' );
		delete_option( 'sakura_imgopt_sync_recent_ready' );
		delete_option( 'sakura_imgopt_sync_verify_cursor' );

		foreach ( self::monitoring_options() as $option ) {
			delete_option( $option );
		}

		/*
		 * Derived state: rebuildable caches, cursors and schema markers. These
		 * are meaningless without the plugin and are removed even when the
		 * owner asked to keep settings and files.
		 */
		foreach ( self::derived_options() as $option ) {
			delete_option( $option );
		}
		delete_transient( 'sakura_imgopt_s3_alert_last' );
		delete_transient( 'sakura_imgopt_s3_inventory' );
		delete_transient( 'sakura_imgopt_s3_snapshot_compat' );
	}

	/**
	 * Drop the user file space tables (only after an explicit opt-in).
	 */
	private static function drop_sufm_tables(): void {
		global $wpdb;

		foreach ( array( 'folders', 'files', 'usage', 'reservations', 'artifacts', 'delete_requests', 'delete_request_items', 'delete_batches', 'jobs', 'events', 'notifications', 'rate_limits', 'media_operations', 'space_grants' ) as $table ) {
			$name = $wpdb->prefix . 'sufm_' . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names, no user input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}
	}

	/**
	 * Monitoring options that are meaningless after the plugin is removed.
	 *
	 * @return array<int,string>
	 */
	public static function monitoring_options(): array {
		return array(
			'sakura_imgopt_s3_upload_metrics',
			'sakura_imgopt_s3_alerts',
			'sakura_imgopt_s3_prometheus_token',
		);
	}

	/**
	 * Rebuildable state that should not survive an uninstall.
	 *
	 * @return array<int,string>
	 */
	public static function derived_options(): array {
		return array(
			'sakura_imgopt_crop_scan',
			'sakura_imgopt_crop_scan_names',
			'sakura_imgopt_s3_migrate_state',
			'sakura_imgopt_s3_options',
			'sakura_imgopt_sync_dir_indexed',
			'sakura_imgopt_sync_full_at',
			'sakura_imgopt_sync_metrics',
			'sakura_imgopt_thumb_cache_flush',
			'sakura_imgopt_thumb_probe',
			'sakura_imgopt_thumb_stats',
			'sufm_sync_state',
		);
	}

	/**
	 * Whether the site owner opted in to deleting persistent data.
	 */
	private static function should_delete_data(): bool {
		return 1 === (int) get_option( 'sakura_imgopt_delete_data_on_uninstall', 0 );
	}

	/**
	 * Remove persistent settings, metadata and plugin-owned files.
	 */
	private static function cleanup_persistent_data(): void {
		global $wpdb;

		$s3_options = get_option( 's3_options' );

		$managed_url  = is_array( $s3_options ) && isset( $s3_options['upload_url_path'] )
			? untrailingslashit( (string) $s3_options['upload_url_path'] )
			: '';
		$current_url  = untrailingslashit( (string) get_option( 'upload_url_path', '' ) );
		$previous_url = get_option( Upload_URL::PREVIOUS_URL_OPTION, null );

		// Only restore when the value is still ours; never overwrite a manual CDN change.
		if ( '' !== $managed_url && $managed_url === $current_url ) {
			update_option( 'upload_url_path', null === $previous_url ? '' : (string) $previous_url, false );
		}
		delete_option( Upload_URL::PREVIOUS_URL_OPTION );

		$previous_path = get_option( Upload_URL::PREVIOUS_PATH_OPTION, null );
		$managed_path  = is_array( $s3_options ) && isset( $s3_options['upload_path'] )
			? trim( (string) $s3_options['upload_path'], '/' )
			: '';
		$current_path  = trim( (string) get_option( 'upload_path', '' ), '/' );

		if ( null !== $previous_path && ( '' === $managed_path || $current_path === $managed_path ) ) {
			update_option( 'upload_path', (string) $previous_path, false );
		}
		delete_option( Upload_URL::PREVIOUS_PATH_OPTION );

		/*
		 * Credentials are removed only when persistent-data deletion is enabled.
		 * Sites that keep data must be told that s3_options still contains the
		 * access key and secret until they remove it manually.
		 */
		delete_option( 's3_options' );

		$options = array(
			'sakura_imgopt_disabled_sizes',
			'sakura_imgopt_enable_compression',
			'sakura_imgopt_compression_mode',
			'sakura_imgopt_convert_format',
			'sakura_imgopt_source_formats',
			'sakura_imgopt_quality',
			'sakura_imgopt_max_file_size_mb',
			'sakura_imgopt_max_image_width',
			'sakura_imgopt_max_image_height',
			'sakura_imgopt_rename_enabled',
			'sakura_imgopt_rename_rule',
			'sakura_imgopt_batch_size',
			'sakura_imgopt_ajax_delay',
			'sakura_imgopt_upload_concurrency',
			'sakura_imgopt_backup_enabled',
			'sakura_imgopt_backup_retention_days',
			'sakura_imgopt_backup_max_mb',
			'sakura_imgopt_backup_probe',
			'sakura_imgopt_delete_data_on_uninstall',
			'sakura_imgopt_version',
			'sakura_imgopt_stats',
			'sakura_imgopt_oss_thumb',
			'sakura_imgopt_layout_gaps',
			'sakura_imgopt_sync_ignored',
			'sakura_imgopt_disable_all_thumbs',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		delete_option( 'sufm_settings' );
		delete_option( 'sufm_space_settings' );
		delete_option( 'sufm_schema_version' );
		delete_option( 'sufm_backfill_pending' );
		delete_option( 'sufm_tokens_pending' );
		delete_option( 'sufm_tokens_after' );
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'sufm_quota_bytes' ) );
		self::drop_sufm_tables();

		self::delete_ephemeral_data();

		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'sakura_imgopt_log' ) );
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_sakura_imgopt_backup' ) );
		// The per-user card layout is presentation data owned by this plugin.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_sakura_imgopt_layout' ) );
		foreach ( array( '_sakura_imgopt_s3_status', '_sakura_imgopt_s3_error', '_sakura_imgopt_s3_objects', '_sakura_imgopt_s3_object_versions', '_sakura_imgopt_s3_failures', '_sakura_imgopt_s3_synced_at', '_sakura_imgopt_s3_jobs', '_sakura_imgopt_s3_target' ) as $meta_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			self::rmdir_recursive( trailingslashit( $upload_dir['basedir'] ) . 'sakura-imgopt-backup' );

			$token_cache_dir = trailingslashit( dirname( untrailingslashit( $upload_dir['basedir'] ) ) ) . '.sakura-s3-cache';
			self::rmdir_recursive( $token_cache_dir );
		}
	}

	/**
	 * Remove queues, usage caches and temporary credential caches.
	 */
	private static function delete_ephemeral_data(): void {
		global $wpdb;

		delete_transient( 'sakura_imgopt_backup_usage' );

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$token_cache_dir = trailingslashit( dirname( untrailingslashit( $upload_dir['basedir'] ) ) ) . '.sakura-s3-cache';
			self::rmdir_recursive( $token_cache_dir );
		}

		$prefixes = array(
			'_transient_sakura_queue_',
			'_transient_timeout_sakura_queue_',
			'_transient_sakura_imgopt_doge_token_',
			'_transient_timeout_sakura_imgopt_doge_token_',
		);

		foreach ( $prefixes as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
		}
	}

	/**
	 * Recursively delete a plugin-owned directory without following symlinks.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		if ( is_link( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Enumerated plugin-owned directory only.
		rmdir( $dir );
	}
}
