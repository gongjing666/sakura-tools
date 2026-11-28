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

	/**
	 * A hand-edited option full of arrays must not warn or leak "Array" text.
	 *
	 * @return void
	 */
	public function test_non_scalar_package_fields_do_not_warn_or_leak_into_settings(): void {
		update_option(
			Space_Settings::OPTION,
			array(
				'order_icon'      => array( 'https://example.test/x.png' ),
				'level_rule'      => array(
					'enabled'         => array( 1 ),
					'points_per_step' => array( 1000 ),
					'bytes_per_step'  => array( 104857600 ),
				),
				'vip_bonus'       => array( 1 => array( 1073741824 ) ),
				'level_bonus'     => array( 1 => array( 1073741824 ) ),
				'packages'        => array(
					array(
						'id'     => array( 'a1' ),
						'label'  => array( 'x' ),
						'bytes'  => 10 * 1073741824,
						'price'  => 30,
						'days'   => array( 365 ),
						'active' => array( 1 ),
					),
				),
				'points'          => array(
					'enabled'          => array( 1 ),
					'points_per_gb'    => array( 90 ),
					'max_gb_per_order' => array( 10 ),
					'daily_cap_gb'     => array( 20 ),
				),
				'points_packages' => array(
					array(
						'id'     => array( 'p1' ),
						'gb'     => array( 1 ),
						'points' => array( 90 ),
						'days'   => array( 365 ),
						'active' => array( 1 ),
					),
				),
			)
		);

		Space_Settings::flush();

		$warnings = array();

		set_error_handler(
			static function ( int $errno, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			},
			E_WARNING | E_USER_WARNING
		);

		try {
			$clean = Space_Settings::get();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings, 'Non-scalar option fields must not raise conversion warnings.' );
		$this->assertSame( '', $clean['order_icon'] );
		$this->assertSame( 1000, $clean['level_rule']['points_per_step'] );
		$this->assertSame( 104857600, $clean['level_rule']['bytes_per_step'] );
		$this->assertSame( 90, $clean['points']['points_per_gb'] );
		$this->assertSame( 10, $clean['points']['max_gb_per_order'] );
		$this->assertSame( 20, $clean['points']['daily_cap_gb'] );

		$this->assertCount( 1, $clean['packages'] );
		$this->assertSame( 'a1', $clean['packages'][0]['label'] );
		$this->assertSame( 0, $clean['packages'][0]['days'] );
		$this->assertFalse( $clean['packages'][0]['active'] );
		$this->assertSame( array(), $clean['points_packages'] );
		$this->assertStringNotContainsString( 'Array', (string) json_encode( $clean ) );
	}
}
