<?php
/**
 * Basic settings report tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Environment_Report;
use Sakura\ImageOptimizer\Admin\System_Settings_Module;

/**
 * The environment sheet is read-only, so its pure aggregation is tested directly.
 */
final class SystemSettingsTest extends TestCase {

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
	 * The first module owns the stable top-level address, and the registry is the
	 * only place that says so — the module used to carry a duplicate constant.
	 *
	 * @return void
	 */
	public function test_menu_slug_comes_from_the_registry(): void {
		require_once dirname( __DIR__ ) . '/src/Admin/Registry.php';

		$this->assertSame( 'sakura-tools', \Sakura\ImageOptimizer\Admin\Registry::PARENT );
	}

	/**
	 * The page saves through REST only.
	 *
	 * It has no options.php form, so the Settings API registration and the
	 * per-card guard that lived on this module were dead weight (removed in
	 * 1.29.0-beta-3). This locks the removal so the dead path cannot creep back.
	 *
	 * @return void
	 */
	public function test_the_page_registers_nothing_for_options_php(): void {
		require_once dirname( __DIR__ ) . '/src/Support/Hook_Loader.php';

		$module = new System_Settings_Module();
		$hooks  = new \Sakura\ImageOptimizer\Support\Hook_Loader();

		$module->register_hooks( $hooks );

		$this->assertNotContains( 'admin_init', array_column( $hooks->actions(), 'hook' ) );
		$this->assertFalse( method_exists( $module, 'register_settings' ) );
	}
}
