<?php
/**
 * WordPress image size catalog.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the image sizes WordPress can generate.
 *
 * Core sizes are read from their options, additional sizes from the global
 * registry. The list deliberately ignores the `intermediate_image_sizes`
 * filter: this plugin removes sizes through that filter, and the admin screen
 * must keep showing every size so a disabled size can be re-enabled.
 */
final class Size_Catalog {

	/**
	 * Core sizes and the options backing them.
	 *
	 * `medium_large`, `1536x1536` and `2048x2048` are registered by core
	 * without editable height options for the 2x variants.
	 *
	 * @return array<string,array{label:string,width_option:string|null,height_option:string|null,crop_option:string|null,width:int,height:int,crop:bool}>
	 */
	private static function core_data(): array {
		return array(
		'thumbnail'    => array(
			'label'        => __( '缩略图', 'sakura-imgopt' ),
			'width_option' => 'thumbnail_size_w',
			'height_option' => 'thumbnail_size_h',
			'crop_option'  => 'thumbnail_crop',
			'width'        => 150,
			'height'       => 150,
			'crop'         => false,
		),
		'medium'       => array(
			'label'        => __( '中等尺寸', 'sakura-imgopt' ),
			'width_option' => 'medium_size_w',
			'height_option' => 'medium_size_h',
			'crop_option'  => null,
			'width'        => 300,
			'height'       => 300,
			'crop'         => false,
		),
		'medium_large' => array(
			'label'        => __( '中等大尺寸', 'sakura-imgopt' ),
			'width_option' => 'medium_large_size_w',
			'height_option' => 'medium_large_size_h',
			'crop_option'  => null,
			'width'        => 768,
			'height'       => 0,
			'crop'         => false,
		),
		'large'        => array(
			'label'        => __( '大尺寸', 'sakura-imgopt' ),
			'width_option' => 'large_size_w',
			'height_option' => 'large_size_h',
			'crop_option'  => null,
			'width'        => 1024,
			'height'       => 1024,
			'crop'         => false,
		),
		'1536x1536'    => array(
			'label'        => __( '2 倍中等大（WP 5.3+）', 'sakura-imgopt' ),
			'width_option' => null,
			'height_option' => null,
			'crop_option'  => null,
			'width'        => 1536,
			'height'       => 1536,
			'crop'         => false,
		),
		'2048x2048'    => array(
			'label'        => __( '2 倍大（WP 5.3+）', 'sakura-imgopt' ),
			'width_option' => null,
			'height_option' => null,
			'crop_option'  => null,
			'width'        => 2048,
			'height'       => 2048,
			'crop'         => false,
		),
		);
	}

	/**
	 * Core sizes with their current option values.
	 *
	 * @return array<string,array{width:int,height:int,crop:bool,label:string,detail:string,source:string}>
	 */
	public static function core(): array {
		$sizes = array();
		foreach ( self::core_data() as $name => $data ) {
			$width  = null === $data['width_option'] ? $data['width'] : (int) get_option( $data['width_option'], $data['width'] );
			$height = null === $data['height_option'] ? $data['height'] : (int) get_option( $data['height_option'], $data['height'] );

			$crop = $data['crop'];
			if ( null !== $data['crop_option'] ) {
				$crop = (bool) get_option( $data['crop_option'], $data['crop'] );
			}

			if ( $width <= 0 && $height <= 0 ) {
				// Mirrors core: a size with no width and no height is not generated.
				continue;
			}

			$sizes[ $name ] = array(
				'width'  => $width,
				'height' => $height,
				'crop'   => $crop,
				'label'  => $data['label'],
				'detail' => null === $data['width_option'] ? __( 'WordPress 核心内置', 'sakura-imgopt' ) : sprintf( /* translators: %s: option name. */ __( '设置 → 媒体（%s）', 'sakura-imgopt' ), $data['width_option'] ),
				'source' => 'core',
			);
		}
		return $sizes;
	}

	/**
	 * Sizes registered through add_image_size() during this request.
	 *
	 * @return array<string,array{width:int,height:int,crop:bool,label:string,detail:string,source:string}>
	 */
	public static function additional(): array {
		global $_wp_additional_image_sizes;

		$sizes = array();
		if ( ! is_array( $_wp_additional_image_sizes ) ) {
			return $sizes;
		}

		foreach ( $_wp_additional_image_sizes as $name => $data ) {
			$width  = isset( $data['width'] ) ? (int) $data['width'] : 0;
			$height = isset( $data['height'] ) ? (int) $data['height'] : 0;
			if ( $width <= 0 && $height <= 0 ) {
				continue;
			}
			$sizes[ (string) $name ] = array(
				'width'  => $width,
				'height' => $height,
				'crop'   => ! empty( $data['crop'] ),
				'label'  => (string) $name,
				'detail' => __( 'add_image_size() 注册', 'sakura-imgopt' ),
				'source' => 'additional',
			);
		}
		return $sizes;
	}

	/**
	 * All sizes available in the admin list, core first, then additional.
	 *
	 * @return array<string,array{width:int,height:int,crop:bool}>
	 */
	public static function available(): array {
		$sizes = array();
		foreach ( self::core() as $name => $data ) {
			$sizes[ $name ] = array(
				'width'  => $data['width'],
				'height' => $data['height'],
				'crop'   => $data['crop'],
			);
		}
		foreach ( self::additional() as $name => $data ) {
			$sizes[ $name ] = array(
				'width'  => $data['width'],
				'height' => $data['height'],
				'crop'   => $data['crop'],
			);
		}
		return $sizes;
	}

	/**
	 * Sanitize a size name coming from scanned source code.
	 *
	 * Size names become array keys in attachment metadata, so only the
	 * characters WordPress itself tolerates are accepted.
	 *
	 * @param string $name Raw name.
	 * @return string Empty string when invalid.
	 */
	public static function sanitize_name( string $name ): string {
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > 64 ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ? $name : '';
	}
}
