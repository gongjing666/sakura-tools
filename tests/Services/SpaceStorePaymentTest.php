<?php
/**
 * Payment channel and grant ledger tests for the space expansion store.
 *
 * These are pure tests: no theme, database or HTTP request is needed.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use Nboke\UserFiles\Services\Space_Store;
use Nboke\UserFiles\Space_Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/UserFiles/Space_Settings.php';
require_once dirname( __DIR__, 2 ) . '/src/UserFiles/Services/Space_Store.php';

/**
 * Covers Space_Store::filter_payment_methods(), grant_ledger_fields() and the
 * order normaliser that feeds them.
 */
final class SpaceStorePaymentTest extends TestCase {

	/**
	 * Only the synchronous balance channel is sold by default.
	 *
	 * @return void
	 */
	public function test_default_channel_list_is_balance_only(): void {
		$store = new Space_Store();

		$methods = $store->filter_payment_methods(
			array(
				'balance' => array( 'name' => '余额支付' ),
				'alipay'  => array( 'name' => '支付宝' ),
				'wechat'  => array( 'name' => '微信支付' ),
			)
		);

		$this->assertSame( array( 'balance' ), array_keys( $methods ) );
		$this->assertSame( '余额支付', $methods['balance']['name'] );
	}

	/**
	 * An empty allow-list closes the cash checkout instead of silently opening it.
	 *
	 * @return void
	 */
	public function test_empty_allow_list_closes_the_cash_checkout(): void {
		$store = new Space_Store();

		$this->assertSame( array(), $store->filter_payment_methods( array() ) );
		$this->assertSame(
			array(),
			$store->filter_payment_methods(
				array( 'alipay' => array( 'name' => '支付宝' ) )
			)
		);
	}

	/**
	 * A balance order is a purchase, not a points redemption.
	 *
	 * @return void
	 */
	public function test_balance_order_keeps_its_order_fields(): void {
		$store = new Space_Store();

		$ledger = $store->grant_ledger_fields(
			array(
				'channel'   => 'balance',
				'order_id'  => 41,
				'order_num' => 'BAL-41',
				'price'     => 30.0,
				'points'    => 0,
			)
		);

		$this->assertSame( 'purchase', $ledger['source'] );
		$this->assertSame( 'balance', $ledger['channel'] );
		$this->assertSame( 41, $ledger['order_id'] );
		$this->assertSame( 'BAL-41', $ledger['order_num'] );
		$this->assertSame( 30.0, $ledger['price'] );
		$this->assertSame( 0, $ledger['points'] );
	}

	/**
	 * A points order keeps the points count and never stores a cash price.
	 *
	 * @return void
	 */
	public function test_points_order_keeps_points_and_clears_price(): void {
		$store = new Space_Store();

		$ledger = $store->grant_ledger_fields(
			array(
				'channel'   => 'points',
				'order_id'  => 42,
				'order_num' => 'PTS-42',
				'price'     => 180.0,
				'points'    => 180,
			)
		);

		$this->assertSame( 'points', $ledger['source'] );
		$this->assertSame( 180, $ledger['points'] );
		$this->assertSame( 0.0, $ledger['price'] );
	}

	/**
	 * Older points payloads only carry the cost in order_price.
	 *
	 * @return void
	 */
	public function test_points_fall_back_to_the_rounded_order_price(): void {
		$store = new Space_Store();

		$ledger = $store->grant_ledger_fields(
			array(
				'channel' => 'points',
				'price'   => '179.6',
			)
		);

		$this->assertSame( 180, $ledger['points'] );
		$this->assertSame( 0.0, $ledger['price'] );
	}

	/**
	 * Unknown channels are purchases and the column width is respected.
	 *
	 * @return void
	 */
	public function test_unknown_and_overlong_channels_are_sanitized(): void {
		$store = new Space_Store();

		$ledger = $store->grant_ledger_fields(
			array(
				'channel' => ' Balance! ',
				'price'   => 12.5,
			)
		);

		$this->assertSame( 'purchase', $ledger['source'] );
		$this->assertSame( 'balance', $ledger['channel'] );
		$this->assertSame( 12.5, $ledger['price'] );

		$long = $store->grant_ledger_fields(
			array(
				'channel' => str_repeat( 'a', 40 ),
			)
		);

		$this->assertSame( 24, strlen( $long['channel'] ) );
	}

	/**
	 * normalise_order() reads points from pay_detail and other, and rejects other
	 * order types before any ledger work happens.
	 *
	 * @return void
	 */
	public function test_normalise_order_reads_points_and_rejects_other_types(): void {
		$store  = new Space_Store();
		$method = new ReflectionMethod( Space_Store::class, 'normalise_order' );
		$method->setAccessible( true );

		$points = $method->invoke(
			$store,
			array(
				'id'          => 51,
				'order_num'   => 'PTS-51',
				'user_id'     => 7,
				'product_id'  => 'sufm_space_points',
				'order_price' => '180',
				'pay_type'    => 'points',
				'order_type'  => '21',
				'other'       => array(),
				'pay_detail'  => array( 'points' => 180 ),
			)
		);

		$this->assertIsArray( $points );
		$this->assertSame( 180, $points['points'] );
		$this->assertSame( 'points', $points['channel'] );

		$balance = $method->invoke(
			$store,
			array(
				'id'          => 52,
				'order_num'   => 'BAL-52',
				'user_id'     => 7,
				'product_id'  => 'sufm_space_p10',
				'order_price' => '30.00',
				'pay_type'    => 'balance',
				'order_type'  => '21',
				'other'       => array(
					'plan_id' => 'p10',
					'bytes'   => 1073741824,
					'days'    => 30,
				),
			)
		);

		$this->assertIsArray( $balance );
		$this->assertSame( 0, $balance['points'] );
		$this->assertSame( 'balance', $balance['channel'] );
		$this->assertSame( 1073741824, $balance['bytes'] );

		$this->assertNull(
			$method->invoke(
				$store,
				array(
					'order_type' => '1',
					'product_id' => 'sufm_space_p10',
				)
			)
		);
	}

	/**
	 * The stored allow-list feeds the checkout filter; the filter cannot widen it.
	 *
	 * @return void
	 */
	public function test_saved_allow_list_is_the_only_source(): void {
		update_option( Space_Settings::OPTION, array( 'allowed_methods' => array( 'alipay' ) ) );
		Space_Settings::flush();

		try {
			$store   = new Space_Store();
			$methods = $store->filter_payment_methods(
				array(
					'balance' => array( 'name' => '余额支付' ),
					'alipay'  => array( 'name' => '支付宝' ),
				)
			);

			$this->assertSame( array( 'alipay' ), array_keys( $methods ) );
		} finally {
			update_option( Space_Settings::OPTION, array() );
			Space_Settings::flush();
		}
	}
}
