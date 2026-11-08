<?php
/**
 * Basic settings report tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Environment_Report;
use Sakura\ImageOptimizer\Admin\Basic_Settings_Module;

/**
 * The overview is read-only, so its pure aggregation is tested directly.
 */
final class BasicSettingsTest extends TestCase {

	/**
	 * Check rows use ok/warn/fail, the hero meter uses pass/warn/fail.
	 */
	public function test_summary_maps_ok_to_pass(): void {
		$checks = array(
			array( 'state' => 'ok' ),
			array( 'state' => 'ok' ),
			array( 'state' => 'warn' ),
			array( 'state' => 'fail' ),
			array( 'state' => 'unexpected' ),
		);

		$this->assertSame(
			array(
				'pass' => 2,
				'warn' => 1,
				'fail' => 1,
			),
			Environment_Report::summarize( $checks )
		);
	}

	/**
	 * The first module owns the stable top-level address.
	 */
	public function test_menu_slug_is_top_level(): void {
		$this->assertSame( 'sakura-tools', Basic_Settings_Module::MENU_SLUG );
	}
}
