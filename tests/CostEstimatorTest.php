<?php
/**
 * Cost estimator tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Cost_Estimator;

/**
 * Checks the tier ladder, the free allowances and the unit conversions.
 */
final class CostEstimatorTest extends TestCase {

	/**
	 * One vendor row from a full estimate.
	 *
	 * @param string              $key   Vendor id.
	 * @param array<string,mixed> $input Demand.
	 * @return array<string,mixed>
	 */
	private function row( string $key, array $input ): array {
		$result = Cost_Estimator::estimate( $input );

		foreach ( $result['rows'] as $row ) {
			if ( $key === $row['key'] ) {
				return $row;
			}
		}

		$this->fail( 'Missing vendor row: ' . $key );
	}

	/**
	 * Defaults stay inside the supported ranges.
	 */
	public function test_defaults_are_normalized(): void {
		$demand = Cost_Estimator::normalize( Cost_Estimator::defaults() );

		$this->assertSame( 'cdn', $demand['mode'] );
		$this->assertSame( 100.0, $demand['storage_gb'] );
		$this->assertSame( 0.0, $demand['image_gb'] );
		$this->assertSame( 80.0, $demand['hit_rate'] );
		$this->assertSame( 100.0, $demand['https_rate'] );
		$this->assertSame( 7.2, $demand['fx'] );
	}

	/**
	 * Out of range input is clamped, not rejected.
	 */
	public function test_normalize_clamps_values(): void {
		$demand = Cost_Estimator::normalize(
			array(
				'storage_gb' => -5,
				'hit_rate'   => 150,
				'mode'       => 'nonsense',
				'fx'         => 0,
			)
		);

		$this->assertSame( 0.0, $demand['storage_gb'] );
		$this->assertSame( 100.0, $demand['hit_rate'] );
		$this->assertSame( 'cdn', $demand['mode'] );
		$this->assertSame( 0.5, $demand['fx'] );
	}

	/**
	 * Cumulative tiers sum each slice instead of charging the last rate.
	 */
	public function test_tiered_charges_each_slice(): void {
		$tiers = array( array( 100.0, 0.2 ), array( 200.0, 0.15 ), array( null, 0.1 ) );

		$this->assertSame( 0.0, Cost_Estimator::tiered( 0.0, $tiers ) );
		$this->assertEqualsWithDelta( 20.0, Cost_Estimator::tiered( 100.0, $tiers ), 0.0001 );
		$this->assertEqualsWithDelta( 35.0, Cost_Estimator::tiered( 200.0, $tiers ), 0.0001 );
		$this->assertEqualsWithDelta( 45.0, Cost_Estimator::tiered( 300.0, $tiers ), 0.0001 );
	}

	/**
	 * Aliyun storage, CDN tier and origin traffic.
	 */
	public function test_aliyun_storage_cdn_and_origin(): void {
		$row = $this->row(
			'aliyun',
			array(
				'storage_gb' => 1000,
				'traffic_gb' => 20000,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'hit_rate'   => 0,
				'mode'       => 'cdn',
			)
		);

		$this->assertEqualsWithDelta( 120.0, (float) $row['storage'], 0.01 );
		$this->assertEqualsWithDelta( 4700.0, (float) $row['egress'], 0.01 );
		$this->assertEqualsWithDelta( 3000.0, (float) $row['origin'], 0.01 );
		$this->assertEqualsWithDelta( 0.0, (float) $row['requests'], 0.01 );
		$this->assertEqualsWithDelta( 7820.0, (float) $row['total'], 0.01 );
	}

	/**
	 * Tencent gives 3 million CDN requests away before charging.
	 *
	 * A full cache hit keeps origin reads at zero so the assertion only sees
	 * the CDN request fee: 400 - 300 free = 100 ten-thousand calls * 0.05.
	 */
	public function test_tencent_cdn_request_free_quota(): void {
		$row = $this->row(
			'tencent',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 0,
				'read_wan'   => 400,
				'write_wan'  => 0,
				'hit_rate'   => 100,
				'mode'       => 'cdn',
			)
		);

		$this->assertEqualsWithDelta( 5.0, (float) $row['requests'], 0.01 );
	}

	/**
	 * A free allowance covers the first slice only.
	 */
	public function test_qiniu_storage_free_allowance(): void {
		$free = $this->row(
			'qiniu',
			array(
				'storage_gb' => 10,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
			)
		);
		$paid = $this->row(
			'qiniu',
			array(
				'storage_gb' => 100,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
			)
		);

		$this->assertSame( 0.0, (float) $free['storage'] );
		$this->assertEqualsWithDelta( 10.35, (float) $paid['storage'], 0.01 );
	}

	/**
	 * Bitiful storage uses the published ladder.
	 */
	public function test_bitiful_storage_ladder(): void {
		$row = $this->row(
			'bitiful',
			array(
				'storage_gb' => 60,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
			)
		);

		$this->assertEqualsWithDelta( 0.65, (float) $row['storage'], 0.01 );
	}

	/**
	 * Dollar priced vendors are converted with the submitted rate.
	 */
	public function test_r2_usd_conversion(): void {
		$row = $this->row(
			'cloudflare',
			array(
				'storage_gb' => 1000,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'mode'       => 'cdn',
				'fx'         => 7.2,
			)
		);

		$this->assertTrue( (bool) $row['usd'] );
		$this->assertEqualsWithDelta( 106.92, (float) $row['storage'], 0.01 );
		$this->assertEqualsWithDelta( 106.92, (float) $row['total'], 0.01 );
	}

	/**
	 * Upyun offsets CDN traffic against GB-days of storage.
	 */
	public function test_upyun_offsets_cdn_traffic(): void {
		$row = $this->row(
			'upyun',
			array(
				'storage_gb' => 100,
				'traffic_gb' => 300,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'mode'       => 'cdn',
			)
		);

		$this->assertEqualsWithDelta( 11.61, (float) $row['storage'], 0.01 );
	}

	/**
	 * Backblaze direct egress is free up to three times the stored volume.
	 */
	public function test_b2_direct_free_egress(): void {
		$row = $this->row(
			'backblaze',
			array(
				'storage_gb' => 100,
				'traffic_gb' => 400,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'mode'       => 'direct',
			)
		);

		$this->assertEqualsWithDelta( 7.2, (float) $row['egress'], 0.01 );
	}

	/**
	 * Aliyun direct egress blends the busy and idle rate.
	 */
	public function test_aliyun_direct_busy_blend(): void {
		$busy = $this->row(
			'aliyun',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 1000,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'busy_rate'  => 100,
				'mode'       => 'direct',
			)
		);
		$idle = $this->row(
			'aliyun',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 1000,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'busy_rate'  => 0,
				'mode'       => 'direct',
			)
		);

		$this->assertEqualsWithDelta( 500.0, (float) $busy['egress'], 0.01 );
		$this->assertEqualsWithDelta( 250.0, (float) $idle['egress'], 0.01 );
	}

	/**
	 * Qiniu bills HTTPS and HTTP downloads at different rates.
	 */
	public function test_qiniu_https_traffic_blend(): void {
		$row = $this->row(
			'qiniu',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 1000,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'hit_rate'   => 0,
				'https_rate' => 50,
				'mode'       => 'cdn',
			)
		);

		$this->assertEqualsWithDelta( 257.4, (float) $row['egress'], 0.01 );
	}

	/**
	 * Only the HTTPS share of CDN requests counts against paid tiers.
	 */
	public function test_tencent_https_share_limits_cdn_requests(): void {
		$row = $this->row(
			'tencent',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 0,
				'read_wan'   => 800,
				'write_wan'  => 0,
				'hit_rate'   => 100,
				'https_rate' => 50,
				'mode'       => 'cdn',
			)
		);

		$this->assertEqualsWithDelta( 5.0, (float) $row['requests'], 0.01 );
	}

	/**
	 * Image processing is free for the first 10 TB, then 0.025 yuan per GB.
	 */
	public function test_image_processing_ladder(): void {
		$free = $this->row(
			'aliyun',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'image_gb'   => 10000,
			)
		);
		$paid = $this->row(
			'aliyun',
			array(
				'storage_gb' => 0,
				'traffic_gb' => 0,
				'read_wan'   => 0,
				'write_wan'  => 0,
				'image_gb'   => 15000,
			)
		);

		$this->assertSame( 0.0, (float) $free['image'] );
		$this->assertEqualsWithDelta( 119.0, (float) $paid['image'], 0.01 );
		$this->assertEqualsWithDelta( 119.0, (float) $paid['total'], 0.01 );
	}

	/**
	 * Vendors without a GB-based processing price are marked and billed zero.
	 */
	public function test_vendors_without_image_processing_are_excluded(): void {
		foreach ( array( 'cloudflare', 'backblaze' ) as $key ) {
			$row = $this->row(
				$key,
				array(
					'storage_gb' => 0,
					'traffic_gb' => 0,
					'read_wan'   => 0,
					'write_wan'  => 0,
					'image_gb'   => 20000,
				)
			);

			$this->assertTrue( (bool) $row['image_excluded'], $key );
			$this->assertSame( 0.0, (float) $row['image'], $key );
		}
	}

	/**
	 * Paid vendors come back sorted, with exactly one cheapest badge; the
	 * self-hosted row stays last and never takes the badge.
	 */
	public function test_rows_are_sorted_and_badged(): void {
		$result = Cost_Estimator::estimate( Cost_Estimator::defaults() );
		$ranked = array_values(
			array_filter(
				$result['rows'],
				static function ( array $row ): bool {
					return empty( $row['self_hosted'] );
				}
			)
		);
		$totals = array_map(
			static function ( array $row ): float {
				return (float) $row['total'];
			},
			$ranked
		);

		$sorted = $totals;
		sort( $sorted );
		$this->assertSame( $sorted, $totals );
		$this->assertCount( 1, array_filter( $result['rows'], static function ( array $row ): bool {
			return ! empty( $row['cheapest'] );
		} ) );

		$last = end( $result['rows'] );
		$this->assertSame( 'minio', $last['key'] );
		$this->assertFalse( $last['cheapest'] );
	}
}
