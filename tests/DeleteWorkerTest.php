<?php
/** Worker retirement and deletion-only scheduling regression tests. */
declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3 {
	// Scoped scheduling doubles: these never touch a real WordPress Cron option.
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['sio_delete_test_events'][ $hook ]['time'] ?? false;
	}
	function wp_unschedule_hook( $hook ) {
		unset( $GLOBALS['sio_delete_test_events'][ $hook ] );
		return true;
	}
	function wp_schedule_event( $time, $schedule, $hook ) {
		$GLOBALS['sio_delete_test_events'][ $hook ] = array( 'time' => $time, 'schedule' => $schedule );
		$GLOBALS['sio_delete_test_schedules'][] = $hook;
		return true;
	}
	function wp_schedule_single_event( $time, $hook ) {
		$GLOBALS['sio_delete_test_singles'][] = $hook;
		return true;
	}
	function wp_doing_cron() {
		return true;
	}
}

namespace Sakura\ImageOptimizer\Tests {
	use PHPUnit\Framework\TestCase;
	use Sakura\ImageOptimizer\S3\Delete_Jobs;
	use Sakura\ImageOptimizer\S3\Delete_Worker;
	use Sakura\ImageOptimizer\S3\Upload_Observer;
	use Sakura\ImageOptimizer\Support\Hook_Loader;

	final class DeleteWorkerTest extends TestCase {
		private array $options;
		protected function setUp(): void {
			$this->options = $GLOBALS['sakura_test_options'] ?? array();
			$GLOBALS['sakura_test_options'][ Delete_Jobs::OPTION ] = Delete_Jobs::VERSION;
			$GLOBALS['sio_delete_test_events'] = array();
			$GLOBALS['sio_delete_test_schedules'] = array();
			$GLOBALS['sio_delete_test_singles'] = array();
		}
		protected function tearDown(): void {
			$GLOBALS['sakura_test_options'] = $this->options;
			unset( $GLOBALS['sio_delete_test_events'], $GLOBALS['sio_delete_test_schedules'], $GLOBALS['sio_delete_test_singles'] );
		}

		public function test_upgrade_retires_old_hooks_even_after_prior_sync_migration(): void {
			$legacy = array( 'sakura_imgopt_s3_upload_tick', 'sakura_imgopt_s3_upload_watchdog', 'sakura_imgopt_s3_retry' );
			$GLOBALS['sakura_test_options']['sakura_imgopt_sync_migration'] = '1';
			foreach ( $legacy as $hook ) {
				$GLOBALS['sio_delete_test_events'][ $hook ] = array( 'time' => time(), 'schedule' => 'old' );
			}
			$GLOBALS['sio_delete_test_events']['sakura_imgopt_sync_job_tick'] = array( 'time' => time(), 'schedule' => 'single' );
			$GLOBALS['sio_delete_test_events']['unrelated_event'] = array( 'time' => time(), 'schedule' => 'daily' );
			Delete_Worker::schedule();
			foreach ( $legacy as $hook ) {
				$this->assertArrayNotHasKey( $hook, $GLOBALS['sio_delete_test_events'] );
			}
			$this->assertArrayHasKey( Delete_Worker::HOOK, $GLOBALS['sio_delete_test_events'] );
			$this->assertArrayHasKey( 'sakura_imgopt_sync_job_tick', $GLOBALS['sio_delete_test_events'] );
			$this->assertArrayHasKey( 'unrelated_event', $GLOBALS['sio_delete_test_events'] );
		}

		public function test_repeated_init_does_not_duplicate_deletion_tick(): void {
			$worker = new Delete_Worker();
			$worker->ensure_scheduled();
			$first = $GLOBALS['sio_delete_test_events'];
			$worker->ensure_scheduled();
			$this->assertSame( $first, $GLOBALS['sio_delete_test_events'] );
			$this->assertSame( array( Delete_Worker::HOOK ), $GLOBALS['sio_delete_test_schedules'] );
		}

		public function test_delete_kick_schedules_only_dedicated_hook(): void {
			Delete_Worker::kick();
			$this->assertSame( array( 'sakura_imgopt_s3_delete_tick' ), $GLOBALS['sio_delete_test_singles'] );
			$this->assertFalse( method_exists( Delete_Worker::class, 'run_after_response' ) );
			$this->assertFalse( method_exists( Delete_Worker::class, 'watchdog' ) );
			$this->assertFalse( method_exists( Delete_Worker::class, 'process' ) );
		}

		public function test_delete_module_never_registers_upload_or_shutdown_callback(): void {
			$hooks = new Hook_Loader();
			( new Delete_Worker() )->register_hooks( $hooks );
			$this->assertSame( array( 'init', 'admin_init', Delete_Worker::HOOK ), array_column( $hooks->actions(), 'hook' ) );
		}

		public function test_upload_observer_has_only_completed_upload_listener(): void {
			$hooks = new Hook_Loader();
			( new Upload_Observer() )->register_hooks( $hooks );
			$this->assertSame( array( 'sakura_imgopt_s3_uploaded' ), array_column( $hooks->actions(), 'hook' ) );
			$this->assertSame( 4, $hooks->actions()[0]['accepted_args'] );
			$this->assertSame( array(), $hooks->filters() );
		}

		public function test_retired_classes_cannot_be_autoloaded(): void {
			foreach ( array( 'Upload_Worker', 'Retry_Service', 'Upload_Jobs' ) as $class ) {
				$this->assertFalse( class_exists( 'Sakura\\ImageOptimizer\\S3\\' . $class ) );
			}
		}
	}
}
