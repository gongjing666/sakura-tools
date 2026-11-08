<?php
/**
 * Optional zibll theme bridge.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites zibll's spare thumbnail URLs at option-read time.
 *
 * zibll's `zib_get_spare_thumb()` reads `_pz('spare_thumbnail')` and does not
 * expose a filter. Filtering `option_zibll_options` avoids editing the theme,
 * so theme updates do not remove the bridge.
 */
final class Theme_Bridge {

	/**
	 * Per-request URL cache.
	 *
	 * @var array<string,string>
	 */
	private static array $cache = array();

	/**
	 * Clear the per-request URL cache (tests, long-running processes).
	 */
	public static function reset_cache(): void {
		self::$cache = array();
	}

	/**
	 * Filter `zibll_options` on read.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public static function filter_options( $value ) {
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return $value;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $value;
		}

		if ( ! is_array( $value ) || empty( $value['spare_thumbnail'] ) || ! is_array( $value['spare_thumbnail'] ) ) {
			return $value;
		}

		$options = Settings::get();
		if ( empty( $options['advanced']['theme_bridge'] ) ) {
			return $value;
		}

		foreach ( $value['spare_thumbnail'] as $index => $row ) {
			if ( ! is_array( $row ) || empty( $row['img'] ) || ! is_string( $row['img'] ) ) {
				continue;
			}

			if ( ! array_key_exists( $row['img'], self::$cache ) ) {
				self::$cache[ $row['img'] ] = Decorator::filter_spare_thumb( $row['img'], false, false );
			}

			$url = self::$cache[ $row['img'] ];
			if ( is_string( $url ) && '' !== $url ) {
				$value['spare_thumbnail'][ $index ]['img'] = $url;
			}
		}

		return $value;
	}
}
