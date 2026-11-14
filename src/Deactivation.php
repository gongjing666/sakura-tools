<?php
/**
 * Deactivation routine.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Clears scheduled events on deactivation.
 */
final class Deactivation {

	/**
	 * Run deactivation.
	 */
	public static function run(): void {
		// 停用只清定时任务；媒体 URL、选项与备份都保留，卸载时按开关处理。
		// 清除定时任务（选项保留，方便再次激活）。
		$timestamp = wp_next_scheduled( 'sakura_imgopt_daily_backup_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sakura_imgopt_daily_backup_cleanup' );
		}
		wp_clear_scheduled_hook( 'sakura_imgopt_daily_backup_cleanup' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_retry' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_delete_tick' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_upload_tick' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_upload_watchdog' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_alert_check' );
		wp_clear_scheduled_hook( 'sakura_imgopt_sync_verify_tick' );
		wp_clear_scheduled_hook( 'sufm_tick' );
		wp_unschedule_hook( 'sufm_tick' );
		wp_unschedule_hook( 'sufm_delete_tick' );

		// Sync/action events are scheduled by their workers, not by activation,
		// so they kept firing after the plugin was disabled (audit B12). Clear
		// every hook the plugin can own, including the argument-carrying ones.
		$sufm_events = array(
			\Sakura\ImageOptimizer\Sync\Action_Worker::HOOK,
			\Sakura\ImageOptimizer\Sync\Scan_Worker::CLEANUP_HOOK,
			\Sakura\ImageOptimizer\Sync\Scan_Worker::DAILY_HOOK,
			\Sakura\ImageOptimizer\Sync\Schema::BACKFILL_HOOK,
			\Sakura\ImageOptimizer\Sync\Schema::BACKFILL_RECENT_HOOK,
			\Sakura\ImageOptimizer\Sync\Sync_Calibrator::CALIBRATE_HOOK,
			\Sakura\ImageOptimizer\Sync\Sync_Calibrator::KICK_HOOK,
			'sakura_imgopt_sync_scan_tick',
		);

		foreach ( $sufm_events as $sufm_event ) {
			wp_clear_scheduled_hook( $sufm_event );
			wp_unschedule_hook( $sufm_event );
		}

		// Remove the six capabilities the plugin granted. Activation re-grants
		// them, so a deactivate/reactivate cycle restores the previous state.
		\Nboke\UserFiles\Capabilities::revoke();
	}
}
