<?php
/**
 * Registers the image REST routes on every request that can serve /wp-json.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Boots {@see Image_Controller}.
 *
 * The module is added unconditionally: REST requests never pass through
 * is_admin(), so anything built inside the admin branch would never register
 * its routes and the browser would see a 404.
 */
final class Image_Routes implements Module {

	/**
	 * Queue the hook.
	 *
	 * @param Hook_Loader $hooks Loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'rest_api_init', array( Image_Controller::class, 'register' ) );
	}
}
