<?php
/**
 * Refund planning and state label tests for the space expansion store.
 *
 * These are pure tests: no theme, database or HTTP request is needed.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use Nboke\UserFiles\Services\Space_Store;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/UserFiles/Services/Space_Store.php';

/**
 * Covers the private refund plan and user-facing grant state labels.
 */
final class SpaceStoreRefundTest extends TestCase {

	/**
	 * Invoke a private method on a fresh store.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke( string $method, ...$args ) {
		$ref = new ReflectionMethod( Space_Store::class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( new Space_Store(), ...$args );
	}

	/**
	 * A balance order credits the balance wallet with the order price.
	 *
	 * @return void
	 */
	public function test_balance_refund_uses_order_price(): void {
		$plan = $this->invoke( 'wallet_refund_plan', array( 'channel' => 'balance', 'price' => 30.5, 'points' => 0 ) );

		$this->assertSame( 'balance', $plan['kind'] );
		$this->assertSame( 'balance', $plan['channel'] );
		$this->assertSame( 30.5, $plan['amount'] );
	}

	/**
	 * A points order credits the points recorded on the order.
	 *
	 * @return void
	 */
	public function test_points_refund_uses_order_points(): void {
		$plan = $this->invoke( 'wallet_refund_plan', array( 'channel' => 'points', 'price' => 0, 'points' => 90 ) );

		$this->assertSame( 'points', $plan['kind'] );
		$this->assertSame( 90, $plan['amount'] );
	}

	/**
	 * Older points orders only have a price; round it to whole points.
	 *
	 * @return void
	 */
	public function test_points_refund_falls_back_to_rounded_price(): void {
		$plan = $this->invoke( 'wallet_refund_plan', array( 'channel' => 'points', 'price' => 89.6, 'points' => 0 ) );

		$this->assertSame( 'points', $plan['kind'] );
		$this->assertSame( 90, $plan['amount'] );
	}

	/**
	 * QR channels are refunded by the gateway; the plugin only frees quota.
	 *
	 * @return void
	 */
	public function test_external_channel_only_releases_quota(): void {
		foreach ( array( 'alipay', 'wechat', 'paypal', 'unknown' ) as $channel ) {
			$plan = $this->invoke( 'wallet_refund_plan', array( 'channel' => $channel, 'price' => 30, 'points' => 0 ) );

			$this->assertSame( 'external', $plan['kind'], $channel );
			$this->assertSame( 0, $plan['amount'], $channel );
		}
	}

	/**
	 * Negative or missing values never become a negative wallet credit.
	 *
	 * @return void
	 */
	public function test_refund_amounts_are_clamped_at_zero(): void {
		$balance = $this->invoke( 'wallet_refund_plan', array( 'channel' => 'balance', 'price' => -10 ) );
		$points  = $this->invoke( 'wallet_refund_plan', array( 'channel' => 'points', 'price' => 0, 'points' => -5 ) );

		$this->assertSame( 0.0, $balance['amount'] );
		$this->assertSame( 0, $points['amount'] );
	}

	/**
	 * Refund states stay readable in the user-facing grant table.
	 *
	 * @return void
	 */
	public function test_refund_state_labels(): void {
		$this->assertSame( '退款处理中', $this->invoke( 'state_label', 'refund_pending' ) );
		$this->assertSame( '退款失败', $this->invoke( 'state_label', 'refund_failed' ) );
		$this->assertSame( '已退款', $this->invoke( 'state_label', 'refunded' ) );
	}
}
