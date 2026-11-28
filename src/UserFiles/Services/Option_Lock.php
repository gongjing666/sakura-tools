<?php
/**
 * Cross-process advisory lock for option read-modify-write pairs.
 *
 * WordPress has no compare-and-swap for options. The space cards merge a few
 * fields into one shared option, so two concurrent saves (two browser tabs, or
 * a retried REST request) could both read the same snapshot and the later
 * write would silently drop the other card's fields. GET_LOCK() is a MariaDB /
 * MySQL session lock: it serialises the read-merge-write across PHP workers,
 * which a front-end pending state or a per-process mutex cannot do.
 *
 * @package Sakura_Image_Optimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around MySQL GET_LOCK()/RELEASE_LOCK().
 */
final class Option_Lock {

	/**
	 * Seconds to wait for a competing writer before failing the save.
	 */
	private const TIMEOUT = 5;

	/**
	 * Lock name prefix; the md5 keeps it below MySQL's 64-character limit.
	 */
	private const PREFIX = 'sakura_tools_opt_';

	/**
	 * Lock held by this request ('' when none).
	 */
	private static string $held = '';

	/**
	 * Deterministic lock name for one option, scoped by the table prefix.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public static function name( string $option ): string {
		global $wpdb;
		$scope = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		return self::PREFIX . md5( $scope . '|' . $option );
	}

	/**
	 * Try to take the lock. Returns false when another worker held it for the
	 * whole timeout, which must abort the save instead of writing unlocked.
	 *
	 * @param string $option Option being protected.
	 * @return bool
	 */
	public static function acquire( string $option ): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$name = self::name( $option );

		// Re-entrant call in the same request already owns this option.
		if ( self::$held === $name ) {
			return true;
		}

		if ( '' !== self::$held ) {
			return false;
		}

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT ) );

		if ( '1' !== (string) $got ) {
			return false;
		}

		self::$held = $name;

		return true;
	}

	/**
	 * Release the lock held by this request, if any.
	 *
	 * @return void
	 */
	public static function release(): void {
		global $wpdb;

		if ( '' === self::$held ) {
			return;
		}

		$name       = self::$held;
		self::$held = '';

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Lock currently held by this request (tests / diagnostics).
	 *
	 * @return string
	 */
	public static function held(): string {
		return self::$held;
	}
}
