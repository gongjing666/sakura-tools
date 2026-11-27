<?php
/**
 * System settings page: environment readout plus the shared layout style.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level menu and renders the environment sheet.
 *
 * The page owns the `sakura-tools` slug so the plugin's first screen stays at
 * a stable address; the image optimizer moved to `sakura-tools-optimize`.
 *
 * Saving the two layout options moved to `POST /sakura-tools/v1/system/layout`
 * (see System_Layout_Settings) when the sheet became a React island: the
 * Settings API registration and the `Card_Form::guard_options()` call that used
 * to live here only existed to make an `options.php` form safe, and there is no
 * such form any more.
 */
class System_Settings_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	// The menu is registered by Admin\Menu from Admin\Registry; this module only
	// owns the environment report screen.

	/**
	 * Render the page.
	 *
	 * The view keeps the page shell (title, status badge, actions) and prints the
	 * island mount point plus its bootstrap JSON; React takes over from there.
	 */
	public function render_system_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$payload = App_Manifest::payload( 'system-settings' );

		if ( null === $payload ) {
			return;
		}

		require SAKURA_TOOLS_INC . 'views/system-settings.php';
	}

	/**
	 * Load the shared admin stack on this page only.
	 *
	 * The shell bundle itself is enqueued by Page_Assets::enqueue(); this screen
	 * keeps no bundle of its own.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( ! Registry::on( 'system' ) ) {
			return;
		}

		unset( $hook );
		Assets::enqueue_page();
	}
}
