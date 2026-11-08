<?php
/**
 * Pure function tests for the space expansion settings / rules.
 *
 * No database access on purpose: everything asserted here is deterministic given
 * the shipped defaults.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Space_Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers Space_Settings::sanitize() and Space_Rules::vip_labels().
 */
final class SpaceRulesTest extends TestCase {

	/**
	 * One megabyte in bytes, mirroring Space_Settings.
	 */
	private const MB = 1048576;

	/**
	 * Skips when the WordPress runtime (or the plugin autoloader) is missing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$required = array( '__', 'apply_filters', 'get_option', 'sanitize_key', 'sanitize_text_field' );

		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				$this->markTestSkipped( 'WordPress runtime is not available.' );
			}
		}

		if ( ! class_exists( 'Nboke\\UserFiles\\Space_Settings' ) ) {
			$this->markTestSkipped( 'Space_Settings is not autoloadable.' );
		}
	}

	/**
	 * A single order can never be larger than the daily allowance.
	 *
	 * @return void
	 */
	public function test_sanitize_clamps_per_order_cap_to_daily_cap(): void {
		$out = Space_Settings::sanitize(
			array(
				'points' => array(
					'enabled'          => true,
					'points_per_gb'    => 90,
					'max_gb_per_order' => 50,
					'daily_cap_gb'     => 20,
				),
			)
		);

		$this->assertSame( 20, $out['points']['max_gb_per_order'] );
		$this->assertSame( 20, $out['points']['daily_cap_gb'] );
	}

	/**
	 * Negative bonus input is clamped to zero, positive values become bytes.
	 *
	 * @return void
	 */
	public function test_sanitize_negative_level_bonus_becomes_zero(): void {
		$out = Space_Settings::sanitize(
			array(
				'level_bonus_mb' => array(
					1 => -5,
					2 => 128,
				),
			)
		);

		$this->assertSame( 0, $out['level_bonus'][1] );
		$this->assertSame( 128 * self::MB, $out['level_bonus'][2] );
	}

	/**
	 * Out-of-range bonus keys are dropped, not silently created.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_out_of_range_keys(): void {
		$out = Space_Settings::sanitize(
			array(
				'level_bonus_mb' => array(
					0   => 512,
					99  => 512,
					3   => 256,
				),
				'vip_bonus_mb'   => array(
					5 => 1024,
					1 => 5120,
				),
			)
		);

		$this->assertArrayNotHasKey( 0, $out['level_bonus'] );
		$this->assertArrayNotHasKey( 99, $out['level_bonus'] );
		$this->assertSame( 256 * self::MB, $out['level_bonus'][3] );
		$this->assertArrayNotHasKey( 5, $out['vip_bonus'] );
		$this->assertSame( 5120 * self::MB, $out['vip_bonus'][1] );
	}

	/**
	 * An unknown override mode falls back to cap.
	 *
	 * @return void
	 */
	public function test_sanitize_unknown_mode_falls_back_to_cap(): void {
		$out = Space_Settings::sanitize( array( 'quota_override_mode' => 'whatever' ) );

		$this->assertSame( 'cap', $out['quota_override_mode'] );
	}

	/**
	 * The switch defaults must stay off (shipping with enabled=true would be a bug).
	 *
	 * @return void
	 */
	public function test_defaults_keep_sale_channel_disabled(): void {
		$defaults = Space_Settings::defaults();

		$this->assertFalse( $defaults['enabled'] );
		$this->assertTrue( $defaults['apply_bonuses'] );
		$this->assertSame( 'cap', $defaults['quota_override_mode'] );
	}

	/**
	 * VIP labels cover the three levels the theme can return (0 / 1 / 2).
	 *
	 * @return void
	 */
	public function test_vip_labels_expose_zero_one_two(): void {
		if ( ! class_exists( 'Nboke\\UserFiles\\Services\\Space_Rules' ) ) {
			$this->markTestSkipped( 'Space_Rules is not autoloadable.' );
		}

		$labels = Space_Rules::vip_labels();

		$this->assertSame( array( 0, 1, 2 ), array_keys( $labels ) );

		foreach ( $labels as $label ) {
			$this->assertTrue( is_string( $label ) );
			$this->assertNotSame( '', $label );
		}
	}

	/**
	 * Unknown level / VIP keys yield a zero bonus.
	 *
	 * @return void
	 */
	public function test_unknown_bonus_keys_yield_zero(): void {
		$this->assertSame( 0, Space_Rules::level_bonus( 19 ) );
		$this->assertSame( 0, Space_Rules::vip_bonus( 9 ) );
	}
}
