<?php
/**
 * REST routes for the admin islands.
 *
 * A module of its own because `System_Settings_Module` is only assembled inside
 * `is_admin()`: a `/wp-json/` request is not an admin screen, so a route hooked
 * from there answers 404 for every save the island makes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the island routes on every request; each route carries its own
 * permission callback.
 */
final class System_Routes implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'rest_api_init', array( System_Controller::class, 'register' ) );
	}
}
