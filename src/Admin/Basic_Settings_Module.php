<?php
/**
 * Basic settings page: the first module of the Sakura Tools menu.
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
 * Registers the top-level menu, renders the environment sheet and owns the
 * global card-gap setting.
 *
 * The page owns the `sakura-tools` slug so the plugin's first screen is a
 * stable overview; the image optimizer moved to `sakura-tools-optimize`.
 */
class Basic_Settings_Module implements Module {

	/**
	 * Top-level menu slug.
	 */
	public const MENU_SLUG = 'sakura-tools';

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the one writable setting on this page: card gaps.
	 */
	public function register_settings(): void {
		register_setting(
			'sakura_imgopt_basic',
			Layout::GAPS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Layout::class, 'sanitize_gaps' ),
				'default'           => Layout::DEFAULT_GAPS,
				'show_in_rest'      => false,
			)
		);
	}

	// The menu is registered by Admin\Menu from Admin\Registry; this module only
	// owns the environment report screen.


	/**
	 * Render the page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = Environment_Report::collect();
		$gaps   = Layout::gaps();

		$gaps_option = Layout::GAPS_OPTION;
		$gaps_max    = Layout::MAX_GAP;

		require SAKURA_TOOLS_INC . 'views/basic-settings.php';
	}

	/**
	 * Load the new admin UI stack on this page only.
	 *
	 * The page is deliberately server-rendered: no scan script, no AJAX.
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
