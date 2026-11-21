<?php
/**
 * Always-on Zibll runtime hooks.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks that must exist on the front end, not only in wp-admin.
 *
 * Loaded outside the `is_admin()` branch of the composition root: the whole
 * point of the feature is a front-end request that skips the theme check, and
 * the cron job that refreshes it also runs without an admin context.
 */
final class Runtime_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		// Must run before the theme's own init:10 registration check in
		// Frontend_Guard::maybe_skip_auth() looks the callback up by name.
		$hooks->add_action( 'init', array( Frontend_Guard::class, 'maybe_skip_auth' ), -PHP_INT_MAX );
		$hooks->add_action( 'init', array( Revalidate::class, 'ensure' ), 20 );
		$hooks->add_action( Revalidate::HOOK, array( Revalidate::class, 'run' ) );

		/*
		 * A cached verdict is bound to the site URL and the license payload, so
		 * any of these writes invalidates it. post_zat_next is deliberately not
		 * watched: the theme refreshes the expiry on its own schedule, and
		 * clearing on every refresh would keep the cache permanently cold.
		 */
		foreach ( array( 'home', 'siteurl', 'zibll_code', 'post_zat_end_time' ) as $option ) {
			$hooks->add_action( 'update_option_' . $option, array( self::class, 'on_identity_change' ) );
		}

		$hooks->add_action( 'after_switch_theme', array( self::class, 'on_identity_change' ) );
	}

	/**
	 * Drop the cache after a change that makes it describe something else.
	 *
	 * @return void
	 */
	public static function on_identity_change(): void {
		License_Cache::clear();
	}
}
