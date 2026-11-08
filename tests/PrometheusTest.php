<?php
/**
 * Prometheus exposition tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Prometheus;

/**
 * Covers the text format and token comparison.
 */
final class PrometheusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options']    = array();
		$GLOBALS['sakura_test_transients'] = array();
	}

	/** The exposition document carries HELP/TYPE and upload totals. */
	public function test_format_exposes_totals(): void {
		$text = Prometheus::format(
			array(
				'totals'     => array( 'ok' => 10, 'fail' => 2, 'dead' => 1, 'bytes' => 2048, 'avg_ms' => 12, 'max_ms' => 40, 'rate' => 0.166667 ),
				'last_error' => array( 'at' => 1700 ),
				'updated_at' => 1800,
			)
		);

		$this->assertStringNotContainsString( 'queue_depth', $text );
		$this->assertStringContainsString( 'sakura_imgopt_upload_attempts_total{result="ok"} 10', $text );
		$this->assertStringContainsString( 'sakura_imgopt_upload_failure_ratio 0.166667', $text );
		$this->assertStringContainsString( 'sakura_imgopt_info{version="1.6.5"} 1', $text );
		$this->assertStringEndsWith( "\n", $text );
	}

	/** Tokens are generated and compared in constant time. */
	public function test_token_round_trip_and_rotation(): void {
		$token = Prometheus::token();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $token );
		$this->assertTrue( Prometheus::valid( $token ) );
		$this->assertFalse( Prometheus::valid( 'nope' ) );

		$rotated = Prometheus::rotate();

		$this->assertNotSame( $token, $rotated );
		$this->assertFalse( Prometheus::valid( $token ) );
		$this->assertTrue( Prometheus::valid( $rotated ) );
	}
}
