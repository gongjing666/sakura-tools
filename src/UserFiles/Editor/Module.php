<?php
/**
 * Editor panel wiring: REST feed, toolbar button, assets, markup.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Editor;

use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Rest\Picker_Controller;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Only touches the two writing screens, and only when the switch is on.
 */
final class Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		// The picker route is registered everywhere: it is read-only and decides
		// per request who may use it.
		$hooks->add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// `wp` is late enough for is_page_template() and get_query_var() but
		// still before the footer, where TinyMCE reads the button filters.
		$hooks->add_action( 'wp', array( $this, 'start' ), 20 );
	}

	/**
	 * Register the read-only picker route.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		( new Picker_Controller( new Folders(), new \Nboke\UserFiles\Services\Quota() ) )->register();
	}

	/**
	 * Arm the editor integration for this request.
	 *
	 * @return void
	 */
	public function start(): void {
		if ( ! Context::allows() ) {
			return;
		}

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			Assets::enqueue();
		} else {
			add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		}

		add_action( 'wp_footer', array( Picker::class, 'render' ), 5 );
	}
}
