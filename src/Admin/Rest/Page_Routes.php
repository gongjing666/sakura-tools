<?php
/**
 * Registers the shell's page route on every request.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Boots {@see Page_Controller}.
 *
 * Added unconditionally, next to the other REST modules: a /wp-json/ request is
 * not an admin screen, so a route registered from inside an is_admin() module
 * would answer 404 for every navigation fetch.
 */
final class Page_Routes implements Module {

	/**
	 * Queue the hook.
	 *
	 * @param Hook_Loader $hooks Loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'rest_api_init', array( Page_Controller::class, 'register' ) );
	}
}
