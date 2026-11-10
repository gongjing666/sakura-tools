<?php
/**
 * In-place upgrade guard.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the activation routine when the stored version is older than the code.
 *
 * WordPress only fires the activation hook on a fresh activation. Updating a
 * plugin in place (upload, WP-CLI, or an auto-update) leaves scheduled events
 * and schema markers untouched, so boot() calls this guard after the option
 * migrations. The routine is idempotent: options are added only when absent,
 * tables use dbDelta(), and every schedule checks wp_next_scheduled() first.
 */
final class Upgrade_Guard {

	/**
	 * Option that stores the installed plugin version.
	 */
	public const VERSION_OPTION = 'sakura_imgopt_version';

	/**
	 * Short-lived option lock that keeps concurrent requests from racing.
	 */
	public const LOCK_OPTION = 'sakura_imgopt_upgrading';

	/**
	 * Transient that records the last failed attempt for the admin UI.
	 */
	public const ERROR_TRANSIENT = 'sakura_imgopt_upgrade_error';

	/**
	 * How long a lock is honoured before it is treated as stale, in seconds.
	 */
	private const LOCK_TTL = 300;

	/**
	 * Decide whether the stored version needs the activation routine.
	 *
	 * An empty version means the version marker was never written (a site that
	 * upgraded from a release older than this guard), so it needs the routine
	 * once. A newer stored version is a downgrade and must not be rewritten.
	 *
	 * @param string $installed Version stored in the database.
	 * @param string $current   Version of the running code.
	 */
	public static function needs_upgrade( string $installed, string $current ): bool {
		if ( '' === $installed ) {
			return true;
		}

		return version_compare( $installed, $current, '<' );
	}

	/**
	 * Run the activation routine once when the code is newer than the database.
	 *
	 * @param string   $current Version of the running code.
	 * @param callable $upgrade Activation routine.
	 * @return bool True when the routine ran, false when it was skipped.
	 */
	public static function maybe_upgrade( string $current, callable $upgrade ): bool {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( ! self::needs_upgrade( $installed, $current ) ) {
			return false;
		}

		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			$upgrade();
		} catch ( \Throwable $error ) {
			delete_option( self::LOCK_OPTION );
			// Do not take the site down when one step fails: keep the old
			// version marker so the next request retries, and leave a trail
			// that an admin notice or a site plugin can pick up.
			set_transient(
				self::ERROR_TRANSIENT,
				array(
					'message' => $error->getMessage(),
					'time'    => time(),
				),
				DAY_IN_SECONDS
			);
			do_action( 'sakura_imgopt_upgrade_failed', $error );
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return true;
	}

	/**
	 * Take the upgrade lock, stealing it when the previous holder crashed.
	 *
	 * add_option() is atomic in the options table (the option name is unique),
	 * which is stronger than a transient for a lock that must not double-run.
	 */
	private static function acquire_lock(): bool {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && $held > $now - self::LOCK_TTL ) {
			return false;
		}

		update_option( self::LOCK_OPTION, $now, false );

		return true;
	}
}
