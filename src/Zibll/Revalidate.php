<?php
/**
 * Daily revalidation of the Zibll license cache.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the revalidation schedule and its callback.
 *
 * A cached verdict is bound to the license expiry the theme recorded, so it
 * lapses by itself; the daily job exists to notice a renewed license (and to
 * re-check a site whose expiry date moved) without waiting for an operator to
 * press the button. A failed re-check clears the cache, which puts the theme's
 * own check back in charge on the next front-end request.
 */
final class Revalidate {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'sakura_imgopt_zibll_revalidate';

	/**
	 * Schedule the daily check when it is missing.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
	}

	/**
	 * Remove every pending instance of the hook.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( (int) $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Keep the schedule in step with the toggle, once per request.
	 *
	 * Cheap on purpose: one autoloaded option read plus the cron array that
	 * WordPress already loaded.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( Settings::enabled() ) {
			self::schedule();
			return;
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			self::unschedule();
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! Settings::enabled() ) {
			License_Cache::clear();
			self::unschedule();
			return;
		}

		if ( ! License::is_supported( License::theme_version() ) ) {
			// Keep the schedule: a theme rollback should heal on the next run
			// instead of requiring the operator to toggle the switch again.
			License_Cache::clear();
			return;
		}

		$cache = License_Cache::warm( 'cron' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();
		}
	}
}
