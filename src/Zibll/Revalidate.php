<?php
/**
 * Scheduled revalidation of the Zibll license cache.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the revalidation schedule and its callback.
 *
 * A cached verdict is bound to the license expiry the theme recorded, and to
 * the lifetime the operator picked, so it lapses by itself; this job exists to
 * refresh it just as it lapses (or once a day when the lifetime is unlimited),
 * which also notices a renewed license without waiting for the operator to
 * press the button. A failed re-check clears the cache, which puts the theme's
 * own check back in charge on the next request.
 */
final class Revalidate {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'sakura_imgopt_zibll_revalidate';

	/**
	 * Schedule the next check when it is missing.
	 *
	 * Idempotent on purpose: ensure() runs on every request, so re-scheduling
	 * here would push the event forward forever.
	 *
	 * @return void
	 */
	public static function schedule( ?int $lifetime = null ): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::delay( $lifetime ), self::HOOK );
	}

	/**
	 * Move the pending check to the newly configured interval.
	 *
	 * @return void
	 */
	public static function reschedule( ?int $lifetime = null ): void {
		self::unschedule();
		self::schedule( $lifetime );
	}

	/**
	 * Seconds until the next check.
	 *
	 * Follows the lifetime, so a 6-hour cache is refreshed around the moment it
	 * would lapse; an unlimited cache is re-checked daily to notice a renewed
	 * or revoked license. One hour is the floor: shorter intervals would spend
	 * more time probing than the cache saves.
	 *
	 * @return int
	 */
	private static function delay( ?int $lifetime = null ): int {
		if ( null === $lifetime ) {
			$lifetime = Settings::lifetime_seconds();
		}

		if ( $lifetime <= 0 ) {
			return DAY_IN_SECONDS;
		}

		return max( HOUR_IN_SECONDS, $lifetime );
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
			self::schedule();
			return;
		}

		$cache = License_Cache::warm( 'cron' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();
		}

		// A single event has just been consumed; queue the next cycle.
		self::schedule();
	}
}
