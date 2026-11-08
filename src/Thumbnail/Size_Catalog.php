<?php
/**
 * WordPress image size labels.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Lists core and registered image sizes for the settings table and CLI.
 */
final class Size_Catalog {

	/**
	 * Core labels.
	 *
	 * @return array<string,string>
	 */
	public static function core_labels(): array {
		return array(
			'thumbnail'    => __( '缩略图 (thumbnail)', 'sakura-imgopt' ),
			'medium'       => __( '中等 (medium)', 'sakura-imgopt' ),
			'medium_large' => __( '中等大 (medium_large)', 'sakura-imgopt' ),
			'large'        => __( '大图 (large)', 'sakura-imgopt' ),
		);
	}

	/**
	 * All known labels, including theme-registered custom sizes.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = self::core_labels();

		if ( function_exists( 'get_intermediate_image_sizes' ) ) {
			foreach ( (array) get_intermediate_image_sizes() as $name ) {
				$name = is_string( $name ) ? $name : '';
				if ( '' !== $name && ! isset( $labels[ $name ] ) ) {
					$labels[ $name ] = $name;
				}
			}
		}

		if ( function_exists( 'wp_get_additional_image_sizes' ) ) {
			foreach ( array_keys( (array) wp_get_additional_image_sizes() ) as $name ) {
				$name = is_string( $name ) ? $name : '';
				if ( '' !== $name && ! isset( $labels[ $name ] ) ) {
					$labels[ $name ] = $name;
				}
			}
		}

		return $labels;
	}

	/**
	 * Labels for every configured size, preserving configuration order.
	 *
	 * @param array<string,mixed> $sizes Configured sizes.
	 * @return array<string,string>
	 */
	public static function configured_labels( array $sizes ): array {
		$labels = self::labels();
		$out    = array();
		foreach ( array_keys( $sizes ) as $name ) {
			$name         = (string) $name;
			$out[ $name ] = $labels[ $name ] ?? $name;
		}

		return $out;
	}

}
