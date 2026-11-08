<?php
/**
 * Alerting configuration and threshold tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Alerting;

/**
 * Covers sanitizing, threshold evaluation and payload construction.
 */
final class AlertingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
		$GLOBALS['sakura_test_transients'] = array();
	}

	/** Defaults stay disabled. */
	public function test_defaults_are_disabled(): void {
		$config = Alerting::get();

		$this->assertSame( 0, $config['enabled'] );
		$this->assertSame( '', $config['webhook'] );
		$this->assertSame( 25, $config['fail_rate'] );
	}

	/** URLs other than http(s) with a host are rejected. */
	public function test_sanitize_rejects_non_http_urls(): void {
		$config = Alerting::sanitize(
			array(
				'webhook'   => 'javascript:alert(1)',
				'fail_rate' => 500,
				'cooldown'  => 1,
			)
		);

		$this->assertSame( '', $config['webhook'] );
		$this->assertSame( 100, $config['fail_rate'] );
		$this->assertSame( 5, $config['cooldown'] );
	}

	/** Valid webhook URLs survive sanitizing. */
	public function test_sanitize_keeps_valid_webhook(): void {
		$config = Alerting::sanitize( array( 'webhook' => 'https://hooks.example.test/abc?x=1' ) );

		$this->assertSame( 'https://hooks.example.test/abc?x=1', $config['webhook'] );
	}

	/** Disabled alerts never fire. */
	public function test_evaluate_returns_nothing_when_disabled(): void {
		$config = Alerting::defaults();

		$this->assertSame( array(), Alerting::evaluate( $this->snapshot(), $config ) );
	}

	/** Dead jobs above the threshold trigger the dead reason. */
	public function test_evaluate_flags_dead_jobs(): void {
		$config           = Alerting::defaults();
		$config['enabled']  = 1;
		$config['max_dead'] = 3;

		$this->assertContains( 'dead', Alerting::evaluate( $this->snapshot(), $config ) );
	}

	/** A zero dead threshold disables the dead rule. */
	public function test_evaluate_zero_dead_threshold_disables_rule(): void {
		$config              = Alerting::defaults();
		$config['enabled']   = 1;
		$config['max_dead']  = 0;

		$this->assertNotContains( 'dead', Alerting::evaluate( $this->snapshot(), $config ) );
	}

	/** Failure rate only fires after the minimum sample size. */
	public function test_evaluate_failure_rate_respects_min_attempts(): void {
		$config                 = Alerting::defaults();
		$config['enabled']      = 1;
		$config['max_dead']     = 0;
		$config['fail_rate']    = 25;
		$config['min_attempts'] = 40;

		$this->assertSame( array( 'failure_rate' ), Alerting::evaluate( $this->snapshot(), $config ) );

		$config['min_attempts'] = 60;
		$this->assertSame( array(), Alerting::evaluate( $this->snapshot(), $config ) );
	}

	/**
	 * `week.rate` is a percentage, so it must be compared against the
	 * percentage threshold instead of `fail_rate / 100`.
	 */
	public function test_evaluate_failure_rate_uses_percent_units(): void {
		$config                 = Alerting::defaults();
		$config['enabled']      = 1;
		$config['max_dead']     = 0;
		$config['fail_rate']    = 25;
		$config['min_attempts'] = 10;

		$snapshot                     = $this->snapshot();
		$snapshot['week']['attempts'] = 100;

		$snapshot['week']['rate'] = 10.0;
		$this->assertNotContains( 'failure_rate', Alerting::evaluate( $snapshot, $config ) );

		$snapshot['week']['rate'] = 24.9;
		$this->assertNotContains( 'failure_rate', Alerting::evaluate( $snapshot, $config ) );

		$snapshot['week']['rate'] = 25.0;
		$this->assertContains( 'failure_rate', Alerting::evaluate( $snapshot, $config ) );
	}

	/** Payload contains text and structured metrics. */
	public function test_payload_contains_metrics_and_text(): void {
		$payload = Alerting::payload( array( 'dead' ), $this->snapshot() );

		$this->assertSame( 'upload_queue_alert', $payload['event'] );
		$this->assertSame( array( 'dead' ), $payload['reasons'] );
		$this->assertSame( 3, $payload['metrics']['dead'] );
		$this->assertStringContainsString( '死信 3 条', (string) $payload['text'] );
	}

	/** A healthy queue describes itself without an alert keyword. */
	public function test_describe_returns_normal_message_for_no_reasons(): void {
		$this->assertStringContainsString( '正常', Alerting::describe( array(), $this->snapshot() ) );
	}

	/**
	 * Snapshot fixture: 40% failure over 50 attempts and 3 dead jobs.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(): array {
		return array(
			'totals'     => array( 'ok' => 30, 'fail' => 20, 'dead' => 3, 'bytes' => 100, 'rate' => 40.0, 'avg_ms' => 12, 'max_ms' => 30 ),
			'today'      => array( 'ok' => 1, 'fail' => 1, 'rate' => 50.0 ),
			'week'       => array( 'attempts' => 50, 'fail' => 20, 'rate' => 40.0, 'rate_text' => '40.0%' ),
			'backlog'    => array( 'queued' => 2, 'running' => 0, 'retrying' => 1, 'dead' => 3, 'backlog' => 2, 'oldest' => 120 ),
			'last_error' => array( 'at' => 1700, 'message' => 'boom', 'has' => true ),
			'updated_at' => 1700,
		);
	}

	/**
	 * Private and link-local webhook targets are refused on save.
	 */
	public function test_private_webhook_targets_are_rejected(): void {
		foreach ( array( 'http://127.0.0.1:9090/hook', 'http://localhost/hook', 'http://10.1.2.3/hook', 'http://[::1]/hook', 'http://printer.lan/hook' ) as $url ) {
			$config = Alerting::sanitize( array( 'webhook' => $url ) );
			$this->assertSame( '', $config['webhook'], $url );
		}

		$config = Alerting::sanitize( array( 'webhook' => 'https://hooks.example.com/services/x' ) );
		$this->assertSame( 'https://hooks.example.com/services/x', $config['webhook'] );
	}

	/**
	 * Host classification matrix.
	 *
	 * @dataProvider host_provider
	 *
	 * @param string $url      Webhook URL.
	 * @param bool   $expected Expected result.
	 */
	public function test_is_public_host( string $url, bool $expected ): void {
		$this->assertSame( $expected, Alerting::is_public_host( $url ), $url );
	}

	/**
	 * Host fixtures.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function host_provider(): array {
		return array(
			'public dns'     => array( 'https://hooks.slack.com/x', true ),
			'public ipv4'    => array( 'https://8.8.8.8/hook', true ),
			'public ipv6'    => array( 'https://[2606:4700:4700::1111]/x', true ),
			'loopback v4'    => array( 'http://127.0.0.1/x', false ),
			'loopback v6'    => array( 'http://[::1]/x', false ),
			'private a'      => array( 'http://10.0.0.8/x', false ),
			'private b'      => array( 'http://192.168.1.5/x', false ),
			'private c'      => array( 'http://172.20.1.1/x', false ),
			'link local'     => array( 'http://169.254.169.254/latest/meta-data', false ),
			'any address'    => array( 'http://0.0.0.0/x', false ),
			'localhost name' => array( 'http://localhost:8080/x', false ),
			'mdns'           => array( 'http://nas.local/x', false ),
			'internal'       => array( 'http://vault.internal/x', false ),
			'lan'            => array( 'http://printer.lan/x', false ),
		);
	}
}
