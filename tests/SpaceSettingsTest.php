<?php
/**
 * Settings tests for the space payment allow-list and points switch.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use Nboke\UserFiles\Space_Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/UserFiles/Space_Settings.php';

/**
 * Covers Space_Settings::allowed_methods(), points_enabled() and the
 * checkbox-group sanitisation rules.
 */
final class SpaceSettingsTest extends TestCase {

	/**
	 * Clears the stored option and the request cache before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if ( ! isset( $GLOBALS['sakura_test_options'] ) || ! is_array( $GLOBALS['sakura_test_options'] ) ) {
			$GLOBALS['sakura_test_options'] = array();
		}

		unset( $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] );
		Space_Settings::flush();
	}

	/**
	 * Clears the option after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] );
		Space_Settings::flush();
	}

	/**
	 * A fresh install keeps the safe balance channel checked.
	 *
	 * @return void
	 */
	public function test_defaults_keep_balance_checked(): void {
		$this->assertSame( array( 'balance' ), Space_Settings::allowed_methods() );
		$this->assertTrue( Space_Settings::points_enabled() );
	}

	/**
	 * Clearing every checkbox must close the cash checkout, not fall back.
	 *
	 * @return void
	 */
	public function test_all_boxes_cleared_closes_the_checkout(): void {
		$out = Space_Settings::sanitize(
			array(
				'_partial'               => 1,
				'allowed_methods_present' => 1,
			)
		);

		$this->assertSame( array(), $out['allowed_methods'] );
	}

	/**
	 * A card that does not edit the allow-list must preserve the stored value.
	 *
	 * @return void
	 */
	public function test_partial_save_preserves_allow_list(): void {
		update_option(
			Space_Settings::OPTION,
			array(
				'allowed_methods' => array( 'alipay', 'wechat' ),
				'points'          => array( 'enabled' => false ),
			)
		);
		Space_Settings::flush();

		$out = Space_Settings::sanitize(
			array(
				'_partial' => 1,
				'enabled'  => true,
			)
		);

		$this->assertSame( array( 'alipay', 'wechat' ), $out['allowed_methods'] );
		$this->assertFalse( $out['points']['enabled'] );
	}

	/**
	 * The payment card only posts points[enabled]; stored prices must survive.
	 *
	 * @return void
	 */
	public function test_points_switch_partial_save_preserves_stored_prices(): void {
		update_option(
			Space_Settings::OPTION,
			array(
				'points' => array(
					'enabled'          => true,
					'points_per_gb'    => 120,
					'max_gb_per_order' => 40,
					'daily_cap_gb'     => 50,
				),
			)
		);
		Space_Settings::flush();

		$out = Space_Settings::sanitize(
			array(
				'_partial' => 1,
				'points'   => array( 'enabled' => false ),
			)
		);

		$this->assertFalse( $out['points']['enabled'] );
		$this->assertSame( 120, $out['points']['points_per_gb'] );
		$this->assertSame( 40, $out['points']['max_gb_per_order'] );
		$this->assertSame( 50, $out['points']['daily_cap_gb'] );
	}

	/**
	 * Method ids are normalised, de-duplicated and bounded.
	 *
	 * @return void
	 */
	public function test_allow_list_is_normalised(): void {
		$out = Space_Settings::sanitize(
			array(
				'_partial'                => 1,
				'allowed_methods_present' => 1,
				'allowed_methods'         => array( ' Alipay! ', 'alipay', 'wechat', '', 123 ),
			)
		);

		$this->assertSame( array( 'alipay', 'wechat' ), $out['allowed_methods'] );
	}

	/**
	 * The points checkbox is independent from its price fields.
	 *
	 * @return void
	 */
	public function test_points_switch_can_be_closed_without_losing_prices(): void {
		$out = Space_Settings::sanitize(
			array(
				'_partial' => 1,
				'points'   => array(
					'enabled'       => false,
					'points_per_gb' => 120,
				),
			)
		);

		$this->assertFalse( $out['points']['enabled'] );
		$this->assertSame( 120, $out['points']['points_per_gb'] );
	}
}
