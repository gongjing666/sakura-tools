<?php
/**
 * Tests for the Query Monitor timing bridge.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Support\Profiler;

/**
 * The bridge must be free when Query Monitor is absent, and must never emit a
 * lap or stop that Query Monitor did not see the matching start for.
 */
final class ProfilerTest extends TestCase {

	protected function setUp(): void {
		$this->reset_globals();
	}

	protected function tearDown(): void {
		Profiler::reset();
		$this->reset_globals();
	}

	private function reset_globals(): void {
		Profiler::reset();

		$GLOBALS['sakura_test_hooks']   = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['sakura_test_actions'] = array();
	}

	/**
	 * Attach a listener so enabled() answers true, the way Query Monitor's
	 * timing collector does on registration.
	 */
	private function attach_query_monitor(): void {
		$GLOBALS['sakura_test_hooks']['actions'][] = array( 'qm/start', 'strlen', 10, 1 );
	}

	/**
	 * Timing hooks fired so far, in order.
	 *
	 * @return array<int,array{0:string,1:array}>
	 */
	private function fired(): array {
		return array_values(
			array_filter(
				(array) $GLOBALS['sakura_test_actions'],
				static fn( array $entry ): bool => str_starts_with( (string) $entry[0], 'qm/' )
			)
		);
	}

	public function test_disabled_without_query_monitor(): void {
		$this->assertFalse( Profiler::enabled() );
	}

	public function test_enabled_when_query_monitor_listens(): void {
		$this->attach_query_monitor();

		$this->assertTrue( Profiler::enabled() );
	}

	public function test_disabled_after_query_monitor_ceases(): void {
		$this->attach_query_monitor();
		do_action( 'qm/cease' );

		$this->assertFalse( Profiler::enabled() );
	}

	public function test_start_fires_prefixed_name(): void {
		$this->attach_query_monitor();

		Profiler::start( 'boot' );

		$this->assertSame(
			array( array( 'qm/start', array( 'Sakura boot' ) ) ),
			$this->fired()
		);
	}

	public function test_lap_fires_only_for_a_started_timer(): void {
		$this->attach_query_monitor();

		Profiler::lap( 'boot', 'orphan' );
		$this->assertSame( array(), $this->fired(), 'A lap before start must stay silent.' );

		Profiler::start( 'boot' );
		$GLOBALS['sakura_test_actions'] = array();

		Profiler::lap( 'boot', 'schema' );

		$this->assertSame(
			array( array( 'qm/lap', array( 'Sakura boot', 'schema' ) ) ),
			$this->fired()
		);
	}

	public function test_stop_fires_once_and_closes_the_timer(): void {
		$this->attach_query_monitor();

		Profiler::start( 'boot' );
		$GLOBALS['sakura_test_actions'] = array();

		Profiler::stop( 'boot' );
		Profiler::stop( 'boot' );

		$this->assertSame(
			array( array( 'qm/stop', array( 'Sakura boot' ) ) ),
			$this->fired()
		);
	}

	public function test_no_hooks_fire_without_query_monitor(): void {
		Profiler::start( 'boot' );
		Profiler::lap( 'boot', 'schema' );
		Profiler::stop( 'boot' );

		$this->assertSame( array(), $this->fired() );
	}

	public function test_stop_after_cease_does_not_fire(): void {
		$this->attach_query_monitor();

		Profiler::start( 'boot' );
		do_action( 'qm/cease' );

		/*
		 * did_action() is a permanent per-request counter, so the cease event
		 * must stay in the log: clearing it here would fake a fresh request.
		 */
		$fired_before_stop = count( $this->fired() );

		Profiler::stop( 'boot' );

		$this->assertSame(
			$fired_before_stop,
			count( $this->fired() ),
			'A timer nobody collects is not worth reporting.'
		);
	}

	public function test_measure_returns_the_callback_value_and_pairs_the_timer(): void {
		$this->attach_query_monitor();

		$result = Profiler::measure(
			'modules',
			static function (): array {
				return array( 'ok' );
			}
		);

		$this->assertSame( array( 'ok' ), $result );
		$this->assertSame(
			array(
				array( 'qm/start', array( 'Sakura modules' ) ),
				array( 'qm/stop', array( 'Sakura modules' ) ),
			),
			$this->fired()
		);
	}

	public function test_measure_still_stops_the_timer_when_the_callback_throws(): void {
		$this->attach_query_monitor();

		try {
			Profiler::measure(
				'modules',
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The callback exception must propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array( 'qm/start', array( 'Sakura modules' ) ),
				array( 'qm/stop', array( 'Sakura modules' ) ),
			),
			$this->fired(),
			'A throwing span must not leave a "Timer not stopped" row behind.'
		);
	}

	public function test_measure_without_query_monitor_just_runs_the_callback(): void {
		$ran = false;

		$result = Profiler::measure(
			'modules',
			static function () use ( &$ran ): string {
				$ran = true;

				return 'value';
			}
		);

		$this->assertTrue( $ran );
		$this->assertSame( 'value', $result );
		$this->assertSame( array(), $this->fired() );
	}
}
