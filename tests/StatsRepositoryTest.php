<?php
/**
 * Stats repository tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Stats;
use Sakura\ImageOptimizer\Stats\Stats_Repository;

/**
 * Verifies the stats adapter reads the aggregate option.
 */
final class StatsRepositoryTest extends TestCase {

	/**
	 * Reset option state.
	 */
	protected function setUp(): void {
		Stats::flush_pending();
		unset( $GLOBALS['sakura_test_options']['sakura_imgopt_stats'] );
	}

	/**
	 * Defaults are returned for a fresh site.
	 */
	public function test_defaults(): void {
		$repo = new Stats_Repository();
		$all  = $repo->get_stats();

		$this->assertSame( 0, $all['total_processed'] );
		$this->assertSame( 0, $all['total_success'] );
		$this->assertSame( 0, $all['total_failed'] );
		$this->assertSame( 0, $all['total_skipped'] );
	}

	/**
	 * Aggregate values are passed through.
	 */
	public function test_reads_stored_aggregate(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_stats'] = array(
			'total_processed'    => 10,
			'total_success'      => 9,
			'total_failed'       => 1,
			'total_source_size'  => 1000,
			'total_target_size'  => 400,
		);

		$all = ( new Stats_Repository() )->get_stats();
		$this->assertSame( 10, $all['total_processed'] );
		$this->assertSame( 9, $all['total_success'] );
		$this->assertSame( 1, $all['total_failed'] );
	}

	/**
	 * M-08: a skip is neither a success nor a failure.
	 */
	public function test_skipped_record_increments_total_skipped(): void {
		Stats::record(
			0,
			array(
				'success'     => false,
				'skipped'     => true,
				'skip_reason' => 'no_gain',
				'size_before' => 90,
				'size_after'  => 0,
				'engine'      => 'gd',
				'error'       => '处理后体积未减小（90 → 692 字节），已保留原图。',
			),
			'/tmp/a.png',
			'upload'
		);

		$all = ( new Stats_Repository() )->get_stats();
		$this->assertSame( 1, $all['total_processed'] );
		$this->assertSame( 0, $all['total_success'] );
		$this->assertSame( 0, $all['total_failed'] );
		$this->assertSame( 1, $all['total_skipped'] );
		$this->assertSame( 0, $all['total_source_size'] );
		$this->assertSame( 0, $all['total_target_size'] );
	}

	/**
	 * Success keeps accumulating sizes for the savings KPIs.
	 */
	public function test_success_record_accumulates_savings(): void {
		Stats::record(
			0,
			array(
				'success'     => true,
				'path'        => '/tmp/a.jpg',
				'size_before' => 1000,
				'size_after'  => 400,
				'engine'      => 'gd',
			),
			'/tmp/a.png',
			'batch'
		);

		$all = ( new Stats_Repository() )->get_stats();
		$this->assertSame( 1, $all['total_success'] );
		$this->assertSame( 0, $all['total_skipped'] );
		$this->assertSame( 1000, $all['total_source_size'] );
		$this->assertSame( 400, $all['total_target_size'] );
	}

	/**
	 * Records are buffered in memory and written once on flush.
	 */
	public function test_records_are_buffered_until_flush(): void {
		foreach ( array( 1, 2, 3 ) as $i ) {
			Stats::record(
				0,
				array(
					'success'     => true,
					'path'        => '/tmp/' . $i . '.jpg',
					'size_before' => 100,
					'size_after'  => 50,
				),
				'/tmp/' . $i . '.png'
			);
		}

		$this->assertArrayNotHasKey( 'sakura_imgopt_stats', $GLOBALS['sakura_test_options'] );

		$live = Stats::get_stats();
		$this->assertSame( 3, $live['total_processed'] );
		$this->assertSame( 300, $live['total_source_size'] );

		Stats::flush_pending();

		$stored = $GLOBALS['sakura_test_options']['sakura_imgopt_stats'];
		$this->assertSame( 3, $stored['total_processed'] );
		$this->assertSame( 3, $stored['total_success'] );
		$this->assertSame( 150, $stored['total_target_size'] );

		// 落盘后 pending 清空，再读不会重复累加。
		$this->assertSame( 3, Stats::get_stats()['total_processed'] );
	}

	/**
	 * A buffered request merges its increments with values written by others.
	 */
	public function test_flush_merges_with_concurrent_writes(): void {
		Stats::record( 0, array( 'success' => true, 'path' => '/tmp/a.jpg', 'size_before' => 10, 'size_after' => 5 ), '/tmp/a.png' );

		// 模拟另一个请求在本次请求尚未落盘时写入。
		$GLOBALS['sakura_test_options']['sakura_imgopt_stats'] = array( 'total_processed' => 7, 'total_success' => 7 );

		Stats::flush_pending();

		$stored = $GLOBALS['sakura_test_options']['sakura_imgopt_stats'];
		$this->assertSame( 8, $stored['total_processed'] );
		$this->assertSame( 8, $stored['total_success'] );
	}
}
