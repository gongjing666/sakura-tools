<?php
/**
 * Unit tests for upload metrics.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Upload_Metrics;

final class UploadMetricsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_empty_snapshot(): void {
		$snapshot = ( new Upload_Metrics() )->snapshot();

		$this->assertSame( 0, $snapshot['today']['ok'] );
		$this->assertSame( 0, $snapshot['today']['attempts'] );
		$this->assertSame( '—', $snapshot['today']['rate_text'] );
		$this->assertSame( '—', $snapshot['today']['avg_text'] );
		$this->assertFalse( $snapshot['last_error']['has'] );
		$this->assertArrayNotHasKey( 'backlog', $snapshot );
	}

	public function test_records_success_and_failure(): void {
		$metrics = new Upload_Metrics();
		$metrics->record_batch(
			array(
				array(
					'ok'         => true,
					'ms'         => 100,
					'bytes'      => 2048,
					'attachment' => 1,
					'key'        => 'a.jpg',
				),
				array(
					'ok'         => true,
					'ms'         => 200,
					'bytes'      => 1024,
					'attachment' => 2,
					'key'        => 'b.jpg',
				),
				array(
					'ok'         => false,
					'ms'         => 300,
					'bytes'      => 0,
					'error'      => 'Access Denied',
					'attachment' => 3,
					'key'        => 'c.jpg',
					'dead'       => true,
				),
			)
		);

		$snapshot = $metrics->snapshot();

		$this->assertSame( 2, $snapshot['today']['ok'] );
		$this->assertSame( 1, $snapshot['today']['fail'] );
		$this->assertSame( 1, $snapshot['today']['dead'] );
		$this->assertSame( 3, $snapshot['today']['attempts'] );
		$this->assertSame( '33.3%', $snapshot['today']['rate_text'] );
		$this->assertSame( '200 ms', $snapshot['today']['avg_text'] );
		$this->assertSame( '300 ms', $snapshot['today']['max_text'] );
		$this->assertSame( '3.0 KB', $snapshot['today']['bytes_text'] );
		$this->assertSame( 3, $snapshot['totals']['attempts'] );
		$this->assertSame( 1, $snapshot['totals']['dead'] );
		$this->assertTrue( $snapshot['last_error']['has'] );
		$this->assertSame( 'Access Denied', $snapshot['last_error']['message'] );
		$this->assertSame( 3, $snapshot['last_error']['attachment'] );
	}

	public function test_prunes_old_buckets(): void {
		$old = array(
			'ok'       => 9,
			'fail'     => 0,
			'dead'     => 0,
			'bytes'    => 0,
			'ms_sum'   => 0,
			'max_ms'   => 0,
			'attempts' => 9,
		);
		update_option(
			Upload_Metrics::OPTION,
			array(
				'version'    => Upload_Metrics::VERSION,
				'totals'     => array( 'ok' => 9, 'fail' => 0, 'dead' => 0, 'bytes' => 0, 'ms_sum' => 0, 'max_ms' => 0, 'attempts' => 9 ),
				'days'       => array( '2020-01-01' => $old ),
				'last_error' => array( 'at' => 0, 'message' => '', 'attachment' => 0, 'key' => '' ),
				'updated_at' => 0,
			)
		);

		$metrics = new Upload_Metrics();
		$metrics->record_batch(
			array(
				array(
					'ok'    => true,
					'ms'    => 50,
					'bytes' => 10,
				),
			)
		);

		$stored = get_option( Upload_Metrics::OPTION );
		$this->assertArrayNotHasKey( '2020-01-01', $stored['days'] );
		$this->assertCount( 1, $stored['days'] );
		$this->assertSame( 10, $stored['totals']['ok'] ); // Totals are not reset by pruning.
	}

	public function test_reset_removes_option(): void {
		$metrics = new Upload_Metrics();
		$metrics->record_batch( array( array( 'ok' => true, 'ms' => 1 ) ) );
		$this->assertNotSame( false, get_option( Upload_Metrics::OPTION ) );

		$metrics->reset();
		$this->assertFalse( get_option( Upload_Metrics::OPTION ) );
	}
}
