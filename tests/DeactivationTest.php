<?php
/**
 * Deactivation cron cleanup tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Deactivation;

/**
 * Deactivation must not leave a hook firing for a disabled plugin.
 */
final class DeactivationTest extends TestCase {

	/**
	 * Every hook the plugin can schedule is cleared on deactivation.
	 *
	 * The calibrator hooks are the ones this test was written for: they are
	 * scheduled by their worker rather than by activation, so forgetting them
	 * left a monthly event firing at a plugin that was no longer loaded.
	 */
	public function test_run_clears_every_owned_hook(): void {
		require_once dirname( __DIR__ ) . '/src/Deactivation.php';

		$GLOBALS['sakura_test_unscheduled'] = array();

		Deactivation::run();

		$cleared = $GLOBALS['sakura_test_unscheduled'];

		foreach (
			array(
				'sakura_imgopt_daily_backup_cleanup',
				'sakura_imgopt_s3_retry',
				'sakura_imgopt_s3_delete_tick',
				'sakura_imgopt_s3_upload_tick',
				'sakura_imgopt_s3_upload_watchdog',
				'sakura_imgopt_s3_alert_check',
				'sakura_imgopt_sync_verify_tick',
				'sakura_imgopt_sync_calibrate',
				'sakura_imgopt_sync_calibrate_now',
				'sakura_imgopt_sync_scan_tick',
				'sakura_imgopt_sync_cleanup',
				'sakura_imgopt_sync_kind_backfill',
				'sakura_imgopt_sync_recent_backfill',
				'sakura_imgopt_sync_daily_reconcile',
				'sakura_imgopt_sync_job_tick',
				'sufm_tick',
				'sufm_delete_tick',
			)
			as $hook
		) {
			$this->assertContains( $hook, $cleared, $hook . ' must be cleared on deactivation' );
		}
	}
}
