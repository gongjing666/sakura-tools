<?php
/**
 * Uninstall opt-in behavior tests.
 *
 * @package Sakura_Image_Optimizer
 */

use Nboke\UserFiles\Schema;
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Uninstaller;

/**
 * Tests the persistent-data switch without touching WordPress.
 */
final class UninstallerTest extends TestCase {

	/**
	 * The switch defaults to "keep data".
	 */
	public function test_data_is_kept_by_default(): void {
		$GLOBALS['sakura_test_options'] = array();
		$this->assertFalse( $this->should_delete() );
	}

	/**
	 * A non-1 value keeps data.
	 */
	public function test_non_one_value_keeps_data(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_delete_data_on_uninstall'] = 'yes';
		$this->assertFalse( $this->should_delete() );
	}

	/**
	 * An explicit 1 opts in to deletion.
	 */
	public function test_one_deletes_data(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_delete_data_on_uninstall'] = 1;
		$this->assertTrue( $this->should_delete() );
	}

	/**
	 * Monitoring options are deleted on uninstall.
	 */
	public function test_monitoring_options_are_cleaned(): void {
		$options = Uninstaller::monitoring_options();

		$this->assertContains( 'sakura_imgopt_s3_upload_metrics', $options );
		$this->assertContains( 'sakura_imgopt_s3_alerts', $options );
		$this->assertContains( 'sakura_imgopt_s3_prometheus_token', $options );
	}

	/**
	 * Every sufm table from Schema is dropped, including file_tokens.
	 */
	public function test_drop_sufm_tables_uses_schema(): void {
		require_once dirname( __DIR__ ) . '/src/UserFiles/Schema.php';

		$had_previous = array_key_exists( 'wpdb', $GLOBALS );
		$previous     = $GLOBALS['wpdb'] ?? null;
		$db           = new class() {
			/** @var string */
			public $prefix = 't_';

			/** @var array<int,string> */
			public $queries = array();

			/**
			 * @param string $sql SQL statement.
			 * @return bool
			 */
			public function query( $sql ) {
				$this->queries[] = (string) $sql;
				return true;
			}
		};
		$GLOBALS['wpdb'] = $db;

		try {
			$method = new ReflectionMethod( Uninstaller::class, 'drop_sufm_tables' );
			$method->setAccessible( true );
			$method->invoke( null );
		} finally {
			if ( $had_previous ) {
				$GLOBALS['wpdb'] = $previous;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		$this->assertCount( count( Schema::tables() ), $db->queries );
		foreach ( Schema::tables() as $table ) {
			$this->assertContains( "DROP TABLE IF EXISTS `t_sufm_{$table}`", $db->queries );
		}
		$this->assertContains( 'DROP TABLE IF EXISTS `t_sufm_file_tokens`', $db->queries );
	}

	/**
	 * Every hook the plugin can own is unscheduled, including the ones that
	 * carry an argument (a plain wp_clear_scheduled_hook misses those).
	 */
	public function test_cleanup_unschedules_every_owned_hook(): void {
		$GLOBALS['sakura_test_unscheduled'] = array();
		$this->with_fake_wpdb()->invoke( 'cleanup_current_site' );

		foreach (
			array(
				'sakura_imgopt_daily_backup_cleanup',
				'sakura_imgopt_s3_retry',
				'sakura_imgopt_s3_delete_tick',
				'sakura_imgopt_s3_upload_tick',
				'sakura_imgopt_s3_upload_watchdog',
				'sakura_imgopt_s3_alert_check',
				'sakura_imgopt_sync_scan_tick',
				'sakura_imgopt_sync_cleanup',
				'sakura_imgopt_sync_kind_backfill',
				'sakura_imgopt_sync_recent_backfill',
				'sakura_imgopt_sync_daily_reconcile',
				'sakura_imgopt_sync_job_tick',
				'sakura_imgopt_sync_verify_tick',
				'sakura_imgopt_sync_calibrate',
				'sakura_imgopt_sync_calibrate_now',
				'sufm_tick',
				'sufm_delete_tick',
			)
			as $hook
		) {
			$this->assertContains( $hook, $GLOBALS['sakura_test_unscheduled'], $hook . ' must be unscheduled' );
		}
	}

	/**
	 * The appearance option is plugin settings, so it must not outlive an
	 * uninstall that was told to delete data. It used to be the one option
	 * nothing ever removed.
	 */
	public function test_cleanup_persistent_data_removes_the_appearance_option(): void {
		$GLOBALS['sakura_test_options'] = array(
			'sakura_imgopt_ui_style'                 => array( 'radius' => 18 ),
			'sakura_imgopt_quality'                  => 82,
			'sakura_imgopt_delete_data_on_uninstall' => 1,
		);

		$this->with_fake_wpdb()->invoke( 'cleanup_persistent_data' );

		$this->assertArrayNotHasKey( 'sakura_imgopt_ui_style', $GLOBALS['sakura_test_options'] );
		$this->assertArrayNotHasKey( 'sakura_imgopt_quality', $GLOBALS['sakura_test_options'] );
	}

	/**
	 * Locks are leftovers of a request that died mid-write: they are worthless
	 * without the plugin, so they go even when settings and files are kept.
	 */
	public function test_stale_locks_are_removed_even_when_data_is_kept(): void {
		$GLOBALS['sakura_test_options'] = array(
			'sakura_imgopt_delete_data_on_uninstall' => 0,
			'sakura_imgopt_stats_lock'               => 1700000000,
			'sakura_imgopt_upgrading'                => 1700000000,
		);

		// The keep-data branch, i.e. the one an ordinary uninstall takes.
		$this->with_fake_wpdb()->invoke( 'cleanup_current_site' );

		$this->assertArrayNotHasKey( 'sakura_imgopt_stats_lock', $GLOBALS['sakura_test_options'] );
		$this->assertArrayNotHasKey( 'sakura_imgopt_upgrading', $GLOBALS['sakura_test_options'] );
	}

	/**
	 * The transient sweep must cover the whole plugin namespace, not just the
	 * two caches that existed when it was written.
	 */
	public function test_transient_sweep_covers_the_plugin_namespace(): void {
		$db = $this->with_fake_wpdb();
		$db->invoke( 'delete_ephemeral_data' );

		$likes = $db->likes();
		foreach ( array( '_transient_sakura_imgopt_%', '_transient_timeout_sakura_imgopt_%', '_transient_sufm_%', '_transient_timeout_sufm_%', 'sakura_imgopt_lock_%' ) as $like ) {
			$this->assertContains( $like, $likes, $like . ' must be swept' );
		}
	}

	/**
	 * The S3 setup prompt stores its dismissal per administrator; that is
	 * plugin data and must not survive an opt-in uninstall.
	 */
	public function test_cleanup_persistent_data_removes_the_setup_notice_meta(): void {
		$GLOBALS['sakura_test_options'] = array( 'sakura_imgopt_delete_data_on_uninstall' => 1 );

		$db = $this->with_fake_wpdb();
		$db->invoke( 'cleanup_persistent_data' );

		$this->assertContains( 'sakura_imgopt_s3_config_notice_dismissed', $db->usermeta_keys() );
		$this->assertContains( 'sakura_imgopt_s3_config_notice_snoozed_until', $db->usermeta_keys() );
	}

	/**
	 * Multisite runs the same cleanup once per site.
	 */
	public function test_run_visits_every_site_once(): void {
		$GLOBALS['sakura_test_unscheduled']  = array();
		$GLOBALS['sakura_test_switched_to']  = array();
		$GLOBALS['sakura_test_is_multisite'] = true;
		$GLOBALS['sakura_test_site_ids']     = array( 2, 3 );
		$GLOBALS['sakura_test_options']      = array( 'sakura_imgopt_delete_data_on_uninstall' => 1 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sakura-tools/sakura-tools.php' );
		}

		$db = $this->with_fake_wpdb();
		try {
			Uninstaller::run();
		} finally {
			$db->restore();
			$GLOBALS['sakura_test_is_multisite'] = false;
		}

		$this->assertSame( array( 2, 3 ), $GLOBALS['sakura_test_switched_to'] );
	}

	/**
	 * Install a recording wpdb double for the duration of one test.
	 *
	 * @return Sakura_Test_Wpdb
	 */
	private function with_fake_wpdb(): Sakura_Test_Wpdb {
		$double = new Sakura_Test_Wpdb();
		$double->install();
		return $double;
	}

	/**
	 * Invoke the private opt-in check.
	 */
	private function should_delete(): bool {
		$method = new ReflectionMethod( Uninstaller::class, 'should_delete_data' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null );
	}
}
