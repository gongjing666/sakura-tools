<?php
/**
 * License check bypass for read-only requests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the theme's license callback on requests where the cache applies.
 *
 * The theme registers `zib_init_add_action` on `init` at priority 10; this runs
 * at the earliest priority so the removal happens before the theme's callback
 * would fire. It never fabricates a verdict: the callback is only removed when
 * a cache written by the theme's own successful check is still valid for this
 * site, theme version and license, and only on plain read-only requests —
 * front-end page views and admin page views alike. Anything that writes, any
 * AJAX/REST/XML-RPC/cron context and the login/cron endpoints keep the original
 * callback, so every state-changing request still runs the real check.
 *
 * Admin page views are included on purpose: the theme's check costs the same
 * 33–47 ms there, and the admin license screen calls the check itself instead of
 * relying on a side effect of the `init` callback (verified on 9.2).
 */
final class Request_Guard {

	/**
	 * Theme callback that runs the license check on every request.
	 */
	public const THEME_HOOK = 'zib_init_add_action';

	/**
	 * Undo the theme callback when the cache allows it.
	 *
	 * @return void
	 */
	public static function maybe_skip_auth(): void {
		if ( ! Settings::enabled() || ! License_Cache::is_valid() || ! self::is_bypassable_request() ) {
			return;
		}

		$priority = has_action( 'init', self::THEME_HOOK );

		if ( false === $priority ) {
			return;
		}

		remove_action( 'init', self::THEME_HOOK, (int) $priority );
	}

	/**
	 * Whether this request may skip the theme check.
	 *
	 * A false negative costs one license check (33–47 ms); a false positive
	 * would skip the check in a context the cache was never verified for, so
	 * every ambiguous case returns false. In particular a request carrying a
	 * body or an upload is never skipped, whatever page it targets: that is
	 * where the theme's own option saves and licence actions live.
	 *
	 * @return bool
	 */
	public static function is_bypassable_request(): bool {
		if ( self::is_cli_request() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only inspection.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared, never echoed.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Parsed as a path, never echoed.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return self::is_read_path( $method, $path );
	}

	/**
	 * Whether the request method and path describe a plain page read.
	 *
	 * Admin page views count: the license screen fetches its own state, so a
	 * skipped `init` callback does not turn the theme into "unlicensed" there.
	 * Login, REST, XML-RPC and cron endpoints never count.
	 *
	 * @param string $method Request method, upper case.
	 * @param string $path   Request path.
	 * @return bool
	 */
	public static function is_read_path( string $method, string $path ): bool {
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}

		return ! preg_match( '#^/(wp-json|wp-login\.php|xmlrpc\.php|wp-cron\.php)(/|$)#i', $path );
	}

	/**
	 * Whether this process is a command-line run (WP-CLI, cron runner, tests).
	 *
	 * @return bool
	 */
	public static function is_cli_request(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI;
	}
}
