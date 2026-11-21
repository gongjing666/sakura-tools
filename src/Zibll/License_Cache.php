<?php
/**
 * Zibll license cache.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one successful license check and answers whether it still applies.
 *
 * The cache is the only thing this feature adds to a request: an autoloaded
 * toggle plus this option. It is never autoloaded and never trusted on its own
 * — {@see self::is_valid()} re-reads the theme version, the home URL, the
 * license fingerprint and the license expiry before the front-end guard may
 * remove the theme callback.
 */
final class License_Cache {

	/**
	 * Cache option; deliberately not autoloaded.
	 */
	public const OPTION = 'sakura_imgopt_zibll_license_cache';

	/**
	 * Stored verdict.
	 *
	 * @return array<string,mixed> Empty when nothing was cached yet.
	 */
	public static function get(): array {
		$cache = get_option( self::OPTION, array() );

		if ( ! is_array( $cache ) || empty( $cache['ok'] ) ) {
			return array();
		}

		return $cache;
	}

	/**
	 * Whether the stored verdict still describes this site and theme.
	 *
	 * @return bool
	 */
	public static function is_valid(): bool {
		$cache = self::get();

		if ( array() === $cache ) {
			return false;
		}

		if ( (string) ( $cache['theme_version'] ?? '' ) !== License::theme_version() ) {
			return false;
		}

		if ( untrailingslashit( (string) ( $cache['home_url'] ?? '' ) ) !== License::home_fingerprint() ) {
			return false;
		}

		$stored = (string) ( $cache['auth_fingerprint'] ?? '' );

		if ( '' === $stored || ! hash_equals( $stored, License::auth_fingerprint() ) ) {
			return false;
		}

		$expires = (int) ( $cache['expires_at'] ?? 0 );

		// An expired license must fall back to the theme's own check.
		return ! ( $expires > 0 && time() >= $expires );
	}

	/**
	 * Verify the license for real and persist the result.
	 *
	 * @param string $source Where the check came from (admin_enable, cron, ajax_manual).
	 * @return array<string,mixed>|\WP_Error Cache payload on success.
	 */
	public static function warm( string $source ) {
		if ( ! License::is_zibll_theme() ) {
			return new \WP_Error(
				'sakura_zibll_no_theme',
				__( '当前主题不是子比（zibll），没有可缓存的授权校验。', 'sakura-imgopt' )
			);
		}

		$version = License::theme_version();

		if ( '' === $version ) {
			return new \WP_Error(
				'sakura_zibll_no_version',
				__( '读不到子比主题版本，无法为授权缓存打上指纹。', 'sakura-imgopt' )
			);
		}

		$result = Probe::run();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$cache = array(
			'ok'               => 1,
			'theme_version'    => $version,
			'home_url'         => License::home_fingerprint(),
			'auth_fingerprint' => License::auth_fingerprint(),
			'expires_at'       => License::expires_at(),
			'created_at'       => time(),
			'created_by'       => (int) get_current_user_id(),
			'source'           => sanitize_key( $source ),
		);

		// Third argument false: a per-request cache verdict must not be autoloaded
		// into every request that never reaches the guard.
		update_option( self::OPTION, $cache, false );

		return $cache;
	}

	/**
	 * Drop the stored verdict.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
