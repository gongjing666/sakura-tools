<?php
/**
 * Calibrator scheduling rules.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\Sync\Sync_Calibrator;
use Sakura\ImageOptimizer\Support\Hook_Loader;

require_once dirname( __DIR__ ) . '/src/Sync/Sync_Calibrator.php';

/**
 * The monthly calibration window is the contract between the cron job and the
 * "stale numbers" notice on the sync screen; both read STALE_SECONDS.
 */
final class SyncCalibratorTest extends TestCase {

	/** @var object|null Previous $wpdb. */
	private ?object $previous_wpdb = null;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			/** @var string */
			public $prefix = 't_';
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	/**
	 * The monthly interval is exactly the staleness window, so the cron and the
	 * notice can never disagree about what "stale" means.
	 */
	public function test_schedule_interval_matches_the_staleness_window(): void {
		$schedules = Sync_Calibrator::add_schedule( array() );

		$this->assertArrayHasKey( Sync_Calibrator::CALIBRATE_SCHEDULE, $schedules );
		$this->assertSame( Sync_Calibrator::STALE_SECONDS, $schedules[ Sync_Calibrator::CALIBRATE_SCHEDULE ]['interval'] );
		$this->assertSame( 30 * DAY_IN_SECONDS, Sync_Calibrator::STALE_SECONDS );
	}

	/**
	 * An existing schedule of the same name is never overwritten: another plugin
	 * bundling the same interval keeps whatever display string it registered.
	 */
	public function test_existing_schedule_is_preserved(): void {
		$existing = array(
			Sync_Calibrator::CALIBRATE_SCHEDULE => array(
				'interval' => 999,
				'display'  => 'custom',
			),
		);

		$schedules = Sync_Calibrator::add_schedule( $existing );

		$this->assertSame( 999, $schedules[ Sync_Calibrator::CALIBRATE_SCHEDULE ]['interval'] );
		$this->assertSame( 'custom', $schedules[ Sync_Calibrator::CALIBRATE_SCHEDULE ]['display'] );
	}

	/**
	 * Both the recurring event and the on-demand kick run the same handler; if
	 * either registration is missing the dashboard silently stops calibrating.
	 */
	public function test_register_hooks_queues_cron_and_calibration(): void {
		$before = $GLOBALS['sakura_test_hooks'] ?? array();

		$loader = new Hook_Loader();
		( new Sync_Calibrator( new Options( array() ) ) )->register_hooks( $loader );

		$hooks = array_merge(
			array_column( $loader->actions(), 'hook' ),
			array_column( $loader->filters(), 'hook' )
		);

		$this->assertContains( 'cron_schedules', $hooks );
		$this->assertContains( Sync_Calibrator::CALIBRATE_HOOK, $hooks );
		$this->assertContains( Sync_Calibrator::KICK_HOOK, $hooks );
		$this->assertSame( $before, $GLOBALS['sakura_test_hooks'] ?? array(), 'Registering on the loader must not touch WP hooks directly.' );
	}

	/**
	 * The Module contract forbids constructor-time hook registration.
	 */
	public function test_constructor_registers_nothing(): void {
		$before = $GLOBALS['sakura_test_hooks'] ?? array();

		new Sync_Calibrator( new Options( array() ) );

		$this->assertSame( $before, $GLOBALS['sakura_test_hooks'] ?? array() );
	}
}
