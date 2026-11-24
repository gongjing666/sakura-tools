<?php
/**
 * Wires the storage island routes on every request.
 *
 * A module of its own for the same reason as System_Routes: the storage screen
 * module is only assembled inside is_admin(), and a /wp-json/ request is not an
 * admin screen, so the routes have to be registered from somewhere that always
 * loads.
 *
 * The metrics and monitoring cards ship their own controllers. They are looked
 * up by name so a checkout with only part of the screen still boots, and so
 * this file never has to know their route shapes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every storage route; each carries its own permission callback.
 */
final class Storage_Routes implements Module {

	/**
	 * Controllers the delegated cards own, if they are present.
	 */
	private const CARD_CONTROLLERS = array(
		'Storage_Metrics_Controller',
		'Storage_Monitoring_Controller',
	);

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Register the storage routes and any card controller that ships with it.
	 */
	public static function register(): void {
		Storage_Controller::register();

		foreach ( self::CARD_CONTROLLERS as $name ) {
			$class = __NAMESPACE__ . '\\' . $name;

			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				call_user_func( array( $class, 'register' ) );
			}
		}
	}
}
