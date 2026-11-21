<?php
/**
 * Zibll license environment.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Theme version, license fingerprints and the legacy mu-plugin check.
 *
 * Everything here is read-only environment state. The cache this module writes
 * is a record of one successful check by the theme's own code, so these values
 * are the identity a cached verdict is bound to: change the theme version, the
 * site URL or the license payload and the stored cache stops being valid.
 */
final class License {

	/**
	 * Theme versions the cache was verified against.
	 *
	 * Zibll 8.8 introduced the Z5 license layer; 9.1 and 9.2 are the versions
	 * this cache was measured on (47 ms and 33 ms per front-end request, most
	 * of it eval() the opcode cache cannot store).
	 */
	public const SUPPORTED_VERSIONS = array( '9.1', '9.2' );

	/**
	 * Version the cache contract was designed for.
	 */
	public const BASELINE = '9.2';

	/**
	 * Legacy must-use plugin that skips the license check unconditionally.
	 */
	public const LEGACY_MUPLUGIN = 'zibll-performance.php';

	/**
	 * Active theme version, or '' when it cannot be read.
	 *
	 * @return string
	 */
	public static function theme_version(): string {
		if ( defined( 'THEME_VERSION' ) ) {
			return trim( (string) THEME_VERSION );
		}

		if ( ! function_exists( 'wp_get_theme' ) ) {
			return '';
		}

		try {
			$version = wp_get_theme()->get( 'Version' );
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_string( $version ) ? trim( $version ) : '';
	}

	/**
	 * Supported versions rendered for the operator: "9.1 / 9.2".
	 *
	 * @return string
	 */
	public static function supported_label(): string {
		return implode( ' / ', self::SUPPORTED_VERSIONS );
	}

	/**
	 * Whether one theme version was verified against this cache contract.
	 *
	 * @param string $version Theme version.
	 * @return bool
	 */
	public static function is_supported( string $version ): bool {
		return in_array( $version, self::SUPPORTED_VERSIONS, true );
	}

	/**
	 * Whether the active theme (parent or child) is Zibll.
	 *
	 * @return bool
	 */
	public static function is_zibll_theme(): bool {
		if ( class_exists( '\Nboke\UserFiles\Services\Zibll_Bridge' ) ) {
			return \Nboke\UserFiles\Services\Zibll_Bridge::instance()->is_zibll_theme();
		}

		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_template' ) ) {
			return false;
		}

		try {
			return 'zibll' === (string) get_stylesheet() || 'zibll' === (string) get_template();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Site identity a cached verdict belongs to.
	 *
	 * A domain move invalidates the cache because the theme's check is licensed
	 * per site; the fingerprint is the normalized home URL without a trailing slash.
	 *
	 * @return string
	 */
	public static function home_fingerprint(): string {
		return untrailingslashit( (string) home_url( '/' ) );
	}

	/**
	 * License payload fingerprint.
	 *
	 * Zibll stores the license as two options; both feed the theme's check, so
	 * a renewed or replaced license must invalidate a cached verdict.
	 *
	 * @return string
	 */
	public static function auth_fingerprint(): string {
		return hash( 'sha256', self::code() . '|' . self::end_time() );
	}

	/**
	 * Whether the site carries the license payload the theme checks.
	 *
	 * Checked before running the probe: a site without a license would make the
	 * theme take its own exit path instead of returning a clean failure.
	 *
	 * @return bool
	 */
	public static function has_credentials(): bool {
		return '' !== self::code() && '' !== self::end_time();
	}

	/**
	 * License expiry as the theme records it (0 when unknown).
	 *
	 * @return int
	 */
	public static function expires_at(): int {
		return (int) get_option( 'post_zat_next', 0 );
	}

	/**
	 * Absolute path of the legacy mu-plugin, or '' when WordPress has no content dir.
	 *
	 * @return string
	 */
	public static function legacy_muplugin(): string {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}

		return rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/mu-plugins/' . self::LEGACY_MUPLUGIN;
	}

	/**
	 * Whether the legacy mu-plugin is installed.
	 *
	 * It removes the theme callback before this plugin can decide anything, so
	 * "enabled" here would be a lie about what actually runs on the front end.
	 *
	 * @return bool
	 */
	public static function has_legacy_muplugin(): bool {
		$path = self::legacy_muplugin();

		return '' !== $path && file_exists( $path );
	}

	/**
	 * License code option.
	 *
	 * @return string
	 */
	private static function code(): string {
		return trim( (string) get_option( 'zibll_code', '' ) );
	}

	/**
	 * License end-time option.
	 *
	 * @return string
	 */
	private static function end_time(): string {
		return trim( (string) get_option( 'post_zat_end_time', '' ) );
	}
}
