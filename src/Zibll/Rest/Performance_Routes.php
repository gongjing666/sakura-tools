<?php
/**
 * REST route registration for the Zibll performance page.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll\Rest;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded outside the is_admin() branch, like the other admin REST routes.
 */
final class Performance_Routes implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'rest_api_init', array( Performance_Controller::class, 'register' ) );
	}
}
