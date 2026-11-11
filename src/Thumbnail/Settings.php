<?php
/**
 * Thumbnail v2 settings, migration and provider resolution.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

use Sakura\ImageOptimizer\Oss\Url_Rewriter;
use Sakura\ImageOptimizer\S3\Options as S3_Options;
use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Providers\Generic;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for `sakura_imgopt_oss_thumb`.
 */
final class Settings {

	public const OPTION       = 'sakura_imgopt_oss_thumb';
	public const PROBE_OPTION = 'sakura_imgopt_thumb_probe';
	public const STATS_OPTION = 'sakura_imgopt_thumb_stats';
	public const FLUSH_OPTION = 'sakura_imgopt_thumb_cache_flush';
	public const SCHEMA       = 2;

	/**
	 * Resolved options cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $options = null;

	/**
	 * S3 configuration cache.
	 *
	 * @var array{provider:string,upload_url_path:string}|null
	 */
	private static ?array $s3 = null;

	/**
	 * Last cache report (also persisted for the settings page).
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $last_flush = null;

	/**
	 * Whether caches were already flushed in this request.
	 *
	 * @var bool
	 */
	private static bool $caches_flushed = false;

	/**
	 * Default sizes (confirmed Phase 0 values).
	 *
	 * @return array<string,array<string,int|string>>
	 */
	public static function default_sizes(): array {
		return array(
			'thumbnail'    => array(
				'width'    => 250,
				'height'   => 250,
				'mode'     => Rendition::MODE_CROP,
				'position' => 'auto',
				'quality'  => 82,
				'format'   => 'auto',
			),
			'medium'       => array(
				'width'    => 250,
				'height'   => 250,
				'mode'     => Rendition::MODE_CROP,
				'position' => 'auto',
				'quality'  => 82,
				'format'   => 'auto',
			),
			'large'        => array(
				'width'    => 800,
				'height'   => 0,
				'mode'     => Rendition::MODE_FIT,
				'position' => 'auto',
				'quality'  => 82,
				'format'   => 'auto',
			),
			'medium_large' => array(
				'width'    => 640,
				'height'   => 0,
				'mode'     => Rendition::MODE_FIT,
				'position' => 'auto',
				'quality'  => 82,
				'format'   => 'auto',
			),
		);
	}

	/**
	 * Default options.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema'        => self::SCHEMA,
			'enabled'       => 0,
			'engine'        => 'url',
			'provider'      => 'auto',
			'provider_mode' => 'auto',
			'dpr'           => 1.5,
			'scopes'        => array(
				'archive' => 1,
				'sidebar' => 1,
				'singular' => 0,
				'other'   => 0,
			),
			'sizes'         => self::default_sizes(),
			'scenes'        => array(
				'thumb'   => '',
				'sidebar' => '',
				'content' => '',
			),
			'style_map'     => array(
				'thumbnail' => 'siothumb',
				'medium'    => 'siothumb',
				'large'     => 'siolarge',
			),
			'overrides'     => array(),
			'advanced'      => array(
				'content_images'          => 0,
				'native_fallback'         => 1,
				'theme_bridge'            => 0,
				'full_cap'                => 0,
				'custom_provider_template' => '',
				'rest'                    => 0,
				'animated'                => 0,
				'unknown_fallback'        => 1,
				'stats'                   => 1,
			),
		);
	}

	/**
	 * Resolved v2 options for this request.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$options ) {
			return self::$options;
		}

		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		if ( array() === $raw ) {
			self::$options = self::defaults();
			return self::$options;
		}

		$schema = isset( $raw['schema'] ) ? (int) $raw['schema'] : 0;
		self::$options = $schema >= self::SCHEMA ? self::normalize( $raw ) : self::migrate( $raw );

		return self::$options;
	}

	/**
	 * Persist the v1 -> v2 migration once.
	 */
	public static function maybe_migrate(): void {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || array() === $raw ) {
			return;
		}

		$schema = isset( $raw['schema'] ) ? (int) $raw['schema'] : 0;
		if ( $schema >= self::SCHEMA ) {
			return;
		}

		update_option( self::OPTION, self::migrate( $raw ) );
		self::reset();
	}

	/**
	 * Migrate a v1 option array to v2.
	 *
	 * The engine is set to `html` so an upgrade keeps the old behaviour until
	 * the URL engine is explicitly enabled after probing.
	 *
	 * @param array<string,mixed> $raw Legacy option.
	 * @return array<string,mixed>
	 */
	public static function migrate( array $raw ): array {
		$opt            = self::defaults();
		$opt['legacy']  = $raw;
		$opt['enabled'] = empty( $raw['enabled'] ) ? 0 : 1;
		$opt['engine']  = 'html';

		$opt['scopes']['archive'] = $opt['enabled'];
		$opt['scopes']['sidebar'] = empty( $raw['sidebar_enabled'] ) ? 0 : 1;
		$opt['scopes']['singular'] = 0;
		$opt['scopes']['other']    = 0;

		$opt['sizes']['thumbnail'] = self::legacy_size_row( $raw, 'thumb', 250, 250, true );
		$opt['sizes']['medium']    = self::legacy_size_row( $raw, 'medium', 500, 500, true );
		$large_width               = isset( $raw['large_w'] ) ? (int) $raw['large_w'] : 0;
		$large_height              = isset( $raw['large_h'] ) ? (int) $raw['large_h'] : 0;
		$large_crop                = ! empty( $raw['large_crop'] );
		if ( $large_width <= 0 && $large_height <= 0 ) {
			$large_width = 800;
		}
		$opt['sizes']['large'] = array(
			'width'    => max( 0, $large_width ),
			'height'   => max( 0, $large_height ),
			'mode'     => $large_crop ? Rendition::MODE_CROP : Rendition::MODE_FIT,
			'position' => self::sanitize_position( isset( $raw['pos'] ) ? (string) $raw['pos'] : 'auto' ),
			'quality'  => 82,
			'format'   => 'auto',
		);

		foreach ( array( 'fallback_list_custom', 'fallback_card_custom', 'fallback_sidebar_custom', 'thumb_custom', 'medium_custom', 'large_custom' ) as $key ) {
			$value = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? trim( $raw[ $key ] ) : '';
			if ( '' !== $value ) {
				$opt['advanced']['custom_provider_template'] = $value;
				break;
			}
		}

		return self::normalize( $opt );
	}

	/**
	 * Build one legacy size row.
	 *
	 * @param array<string,mixed> $raw       Legacy option.
	 * @param string              $prefix    Field prefix.
	 * @param int                 $fallback_w Default width.
	 * @param int                 $fallback_h Default height.
	 * @param bool                $fallback_crop Default crop.
	 * @return array<string,int|string>
	 */
	private static function legacy_size_row( array $raw, string $prefix, int $fallback_w, int $fallback_h, bool $fallback_crop ): array {
		$width  = isset( $raw[ $prefix . '_w' ] ) ? (int) $raw[ $prefix . '_w' ] : $fallback_w;
		$height = isset( $raw[ $prefix . '_h' ] ) ? (int) $raw[ $prefix . '_h' ] : $fallback_h;
		$crop   = array_key_exists( $prefix . '_crop', $raw ) ? ! empty( $raw[ $prefix . '_crop' ] ) : $fallback_crop;

		return array(
			'width'    => max( 0, $width ),
			'height'   => max( 0, $height ),
			'mode'     => $crop ? Rendition::MODE_CROP : Rendition::MODE_FIT,
			'position' => self::sanitize_position( isset( $raw['pos'] ) ? (string) $raw['pos'] : 'auto' ),
			'quality'  => 82,
			'format'   => 'auto',
		);
	}

	/**
	 * Normalize any option array into the full v2 shape.
	 *
	 * @param array<string,mixed> $source Source.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $source ): array {
		$defaults = self::defaults();
		$opt      = $defaults;

		$opt['schema']  = self::SCHEMA;
		$opt['enabled'] = empty( $source['enabled'] ) ? 0 : 1;
		$engine         = isset( $source['engine'] ) && is_string( $source['engine'] ) ? strtolower( $source['engine'] ) : 'url';
		$opt['engine']  = in_array( $engine, array( 'url', 'html', 'off' ), true ) ? $engine : 'url';

		$mode = isset( $source['provider_mode'] ) && is_string( $source['provider_mode'] ) ? strtolower( $source['provider_mode'] ) : 'auto';
		$opt['provider_mode'] = in_array( $mode, array( 'auto', 'params', 'style', 'native', 'none' ), true ) ? $mode : 'auto';
		$opt['provider']      = isset( $source['provider'] ) && is_string( $source['provider'] ) ? sanitize_key( $source['provider'] ) : 'auto';

		$dpr         = isset( $source['dpr'] ) ? (float) $source['dpr'] : 1.5;
		$opt['dpr']  = max( 1.0, min( 3.0, round( $dpr, 2 ) ) );

		$scopes = isset( $source['scopes'] ) && is_array( $source['scopes'] ) ? $source['scopes'] : array();
		foreach ( array_keys( $defaults['scopes'] ) as $scope ) {
			$opt['scopes'][ $scope ] = empty( $scopes[ $scope ] ) ? 0 : 1;
		}

		$style_map = array();
		if ( isset( $source['style_map'] ) && is_array( $source['style_map'] ) ) {
			foreach ( $source['style_map'] as $size => $style ) {
				$size  = self::sanitize_size_name( (string) $size );
				$style = is_string( $style ) ? Rendition::sanitize_style( $style ) : '';
				if ( '' !== $size && '' !== $style ) {
					$style_map[ $size ] = $style;
				}
			}
		} elseif ( isset( $source['schema'] ) && (int) $source['schema'] >= self::SCHEMA ) {
			$style_map = array();
		} else {
			$style_map = $defaults['style_map'];
		}

		$sizes = array();
		$raw_sizes = isset( $source['sizes'] ) && is_array( $source['sizes'] ) ? $source['sizes'] : array();
		foreach ( $defaults['sizes'] as $name => $row ) {
			$input_row = isset( $raw_sizes[ $name ] ) && is_array( $raw_sizes[ $name ] ) ? $raw_sizes[ $name ] : array();
			$sizes[ $name ] = self::normalize_size_row( $name, $row, $input_row, $style_map );
			self::absorb_row_style( $name, $input_row, $style_map );
		}
		foreach ( $raw_sizes as $name => $row ) {
			$name = self::sanitize_size_name( (string) $name );
			if ( '' === $name || isset( $sizes[ $name ] ) || ! is_array( $row ) ) {
				continue;
			}
			$sizes[ $name ] = self::normalize_size_row( $name, array( 'width' => 0, 'height' => 0, 'mode' => Rendition::MODE_FIT, 'position' => 'auto', 'quality' => 82, 'format' => 'auto' ), $row, $style_map );
			self::absorb_row_style( $name, $row, $style_map );
		}
		$opt['sizes']     = $sizes;
		$opt['style_map'] = $style_map;

		$scenes     = array();
		$raw_scenes = isset( $source['scenes'] ) && is_array( $source['scenes'] ) ? $source['scenes'] : array();
		foreach ( array_keys( $defaults['scenes'] ) as $scene ) {
			$target = isset( $raw_scenes[ $scene ] ) && is_string( $raw_scenes[ $scene ] ) ? self::sanitize_size_name( $raw_scenes[ $scene ] ) : '';
			// An empty string means "follow the size requested by the theme".
			$scenes[ $scene ] = ( '' !== $target && isset( $sizes[ $target ] ) ) ? $target : '';
		}
		$opt['scenes'] = $scenes;

		$overrides = array();
		if ( isset( $source['overrides'] ) && is_array( $source['overrides'] ) ) {
			foreach ( $source['overrides'] as $key => $row ) {
				$key = (string) $key;
				if ( ! is_array( $row ) || ! preg_match( '/^(archive|sidebar|singular|other):[a-z0-9_-]+$/', $key ) ) {
					continue;
				}
				$overrides[ $key ] = self::normalize_size_row( $key, self::default_sizes()['medium'], $row, $style_map );
			}
		}
		$opt['overrides'] = $overrides;

		$advanced = isset( $source['advanced'] ) && is_array( $source['advanced'] ) ? $source['advanced'] : array();
		$opt['advanced']['content_images']   = empty( $advanced['content_images'] ) ? 0 : 1;
		$opt['advanced']['native_fallback']  = empty( $advanced['native_fallback'] ) ? 0 : 1;
		$opt['advanced']['theme_bridge']     = empty( $advanced['theme_bridge'] ) ? 0 : 1;
		$opt['advanced']['full_cap']         = empty( $advanced['full_cap'] ) ? 0 : 1;
		$opt['advanced']['rest']             = empty( $advanced['rest'] ) ? 0 : 1;
		$opt['advanced']['animated']         = empty( $advanced['animated'] ) ? 0 : 1;
		$opt['advanced']['unknown_fallback'] = empty( $advanced['unknown_fallback'] ) ? 0 : 1;
		$opt['advanced']['stats']            = array_key_exists( 'stats', $advanced ) ? ( empty( $advanced['stats'] ) ? 0 : 1 ) : 1;
		$template                            = isset( $advanced['custom_provider_template'] ) && is_string( $advanced['custom_provider_template'] ) ? trim( $advanced['custom_provider_template'] ) : '';
		$opt['advanced']['custom_provider_template'] = $template;

		if ( isset( $source['legacy'] ) && is_array( $source['legacy'] ) ) {
			$opt['legacy'] = $source['legacy'];
		}

		return $opt;
	}

	/**
	 * Move a row-level style into the style map.
	 *
	 * @param string              $name      Size name.
	 * @param array<string,mixed> $row       Input row.
	 * @param array<string,string> $style_map Style map (modified by reference).
	 */
	private static function absorb_row_style( string $name, array $row, array &$style_map ): void {
		if ( ! isset( $row['style'] ) || ! is_string( $row['style'] ) ) {
			return;
		}
		$style = Rendition::sanitize_style( $row['style'] );
		if ( '' !== $style ) {
			$style_map[ $name ] = $style;
		} else {
			unset( $style_map[ $name ] );
		}
	}

	/**
	 * Normalize one size row.
	 *
	 * @param string              $name      Size name.
	 * @param array<string,mixed> $defaults  Default row.
	 * @param array<string,mixed> $row       Input row.
	 * @param array<string,string> $style_map Style map by reference semantics (copy).
	 * @return array<string,int|string>
	 */
	private static function normalize_size_row( string $name, array $defaults, array $row, array $style_map ): array {
		$width  = array_key_exists( 'width', $row ) ? max( 0, (int) $row['width'] ) : (int) $defaults['width'];
		$height = array_key_exists( 'height', $row ) ? max( 0, (int) $row['height'] ) : (int) $defaults['height'];
		$mode   = isset( $row['mode'] ) && is_string( $row['mode'] ) ? strtolower( $row['mode'] ) : (string) $defaults['mode'];
		if ( ! in_array( $mode, Rendition::MODES, true ) ) {
			$mode = Rendition::MODE_FIT;
		}
		$quality = array_key_exists( 'quality', $row ) ? (int) $row['quality'] : (int) $defaults['quality'];
		$format  = isset( $row['format'] ) && is_string( $row['format'] ) ? strtolower( $row['format'] ) : (string) $defaults['format'];
		if ( ! in_array( $format, Rendition::FORMATS, true ) ) {
			$format = 'auto';
		}

		return array(
			'width'    => $width,
			'height'   => $height,
			'mode'     => $mode,
			'position' => self::sanitize_position( isset( $row['position'] ) ? (string) $row['position'] : (string) $defaults['position'] ),
			'quality'  => max( 0, min( 100, $quality ) ),
			'format'   => $format,
		);
	}

	/**
	 * Sanitize a crop position.
	 *
	 * @param string $position Candidate.
	 */
	public static function sanitize_position( string $position ): string {
		$position = strtolower( trim( $position ) );

		return in_array( $position, Rendition::POSITIONS, true ) ? $position : 'auto';
	}

	/**
	 * Sanitize a WordPress image size name.
	 *
	 * @param string $name Candidate.
	 */
	public static function sanitize_size_name( string $name ): string {
		$name = strtolower( trim( $name ) );

		return 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $name ) ? $name : '';
	}

	/**
	 * Sanitize form input for register_setting().
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$old    = self::get();
		$source = $old;

		$card    = isset( $input['_sakura_card'] ) && is_string( $input['_sakura_card'] )
			? sanitize_key( $input['_sakura_card'] )
			: '';
		$partial = ! empty( $input['_sakura_partial'] );
		$cards   = array( 'switches', 'scopes', 'scenes', 'sizes', 'advanced' );

		// A partial submission without a known card must not blank the option.
		if ( $partial && ! in_array( $card, $cards, true ) ) {
			return $old;
		}

		$owns = static function ( string $name ) use ( $partial, $card ): bool {
			return ! $partial || $card === $name;
		};

		if ( $owns( 'switches' ) ) {
			$source['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
			if ( isset( $input['engine'] ) ) {
				$source['engine'] = (string) $input['engine'];
			}
			if ( isset( $input['provider_mode'] ) ) {
				$source['provider_mode'] = (string) $input['provider_mode'];
			}
			if ( isset( $input['dpr'] ) ) {
				$source['dpr'] = (float) $input['dpr'];
			}
		}
		if ( $owns( 'scopes' ) && isset( $input['scopes'] ) && is_array( $input['scopes'] ) ) {
			$source['scopes'] = $input['scopes'];
		}

		if ( $owns( 'sizes' ) && isset( $input['sizes'] ) && is_array( $input['sizes'] ) ) {
			$sizes     = isset( $old['sizes'] ) && is_array( $old['sizes'] ) ? $old['sizes'] : array();
			$style_map = isset( $old['style_map'] ) && is_array( $old['style_map'] ) ? $old['style_map'] : array();
			foreach ( $input['sizes'] as $name => $row ) {
				$name = self::sanitize_size_name( (string) $name );
				if ( '' === $name || ! is_array( $row ) ) {
					continue;
				}
				$sizes[ $name ] = $row;
				$style          = isset( $row['style'] ) && is_string( $row['style'] ) ? Rendition::sanitize_style( $row['style'] ) : '';
				if ( '' !== $style ) {
					$style_map[ $name ] = $style;
				} else {
					unset( $style_map[ $name ] );
				}
			}
			$source['sizes']     = $sizes;
			$source['style_map'] = $style_map;
		}

		if ( $owns( 'scenes' ) && isset( $input['scenes'] ) && is_array( $input['scenes'] ) ) {
			// normalize() validates each entry against the configured sizes.
			$source['scenes'] = $input['scenes'];
		}

		if ( $owns( 'sizes' ) && isset( $input['style_map'] ) && is_array( $input['style_map'] ) ) {
			$source['style_map'] = $input['style_map'];
		}

		if ( $owns( 'advanced' ) && isset( $input['advanced'] ) && is_array( $input['advanced'] ) ) {
			$advanced = isset( $old['advanced'] ) && is_array( $old['advanced'] ) ? $old['advanced'] : array();
			$keys     = array( 'content_images', 'native_fallback', 'theme_bridge', 'full_cap', 'rest', 'animated', 'unknown_fallback', 'stats' );
			foreach ( $keys as $key ) {
				// A card form posts every switch (hidden 0 + checkbox 1). Only a
				// full submission treats missing switches as unchecked.
				if ( $partial && ! array_key_exists( $key, $input['advanced'] ) ) {
					continue;
				}
				$advanced[ $key ] = empty( $input['advanced'][ $key ] ) ? 0 : 1;
			}
			$template = isset( $input['advanced']['custom_provider_template'] ) && is_string( $input['advanced']['custom_provider_template'] )
				? sanitize_text_field( wp_unslash( $input['advanced']['custom_provider_template'] ) )
				: (string) ( $advanced['custom_provider_template'] ?? '' );
			$advanced['custom_provider_template'] = trim( $template );
			$source['advanced'] = $advanced;
		}

		if ( isset( $old['legacy'] ) && is_array( $old['legacy'] ) ) {
			$source['legacy'] = $old['legacy'];
		}

		$normalized    = self::normalize( $source );
		self::$options = $normalized;

		// Also covers an unchanged form: WordPress skips update_option_* when
		// the stored value is identical, but the user still asked for a flush.
		self::flush_caches();

		return $normalized;
	}

	/**
	 * Persist sanitized input and flush caches.
	 *
	 * The `update_option_*` hook also flushes after a real write; the call here
	 * covers the no-change case (WordPress skips the hook when the value is
	 * unchanged) and direct callers such as the CLI.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	public static function save( array $input ): array {
		$value = self::sanitize( $input );
		update_option( self::OPTION, $value );
		self::reset();
		self::flush_caches();

		return $value;
	}

	/**
	 * Flush caches after the option is updated through options.php.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 */
	public static function after_option_update( $old_value = null, $new_value = null ): void {
		self::reset();
		self::flush_caches();
	}

	/**
	 * Detect the persistent object cache backend.
	 *
	 * WordPress does not expose a backend name, so this inspects the drop-in
	 * class and the well-known Redis constants. It is for reporting only; the
	 * rewrite path never branches on it.
	 *
	 * @param string|null $class Optional class name override (tests).
	 * @return array{type:string,label:string,group_flush:bool,class:string}
	 */
	public static function object_cache_backend( ?string $class = null ): array {
		if ( null === $class ) {
			$class = '';
			if ( isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
				$class = (string) get_class( $GLOBALS['wp_object_cache'] );
			}
		}
		$class = strtolower( $class );
		$is_memcached_class = false !== strpos( $class, 'memcache' );

		$group_flush = function_exists( 'wp_cache_flush_group' ) && ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_group' ) );
		$is_external = ! function_exists( 'wp_using_ext_object_cache' ) || wp_using_ext_object_cache();

		if ( ! $is_external ) {
			return array(
				'type'        => 'none',
				'label'       => __( 'WordPress 内置缓存（非持久）', 'sakura-imgopt' ),
				'group_flush' => false,
				'class'       => $class,
			);
		}

		if ( false !== strpos( $class, 'redis' ) || ( ! $is_memcached_class && ( defined( 'WP_REDIS_VERSION' ) || defined( 'WP_REDIS_CONFIG' ) ) ) ) {
			return array(
				'type'        => 'redis',
				'label'       => __( 'Redis', 'sakura-imgopt' ),
				'group_flush' => $group_flush,
				'class'       => $class,
			);
		}

		if ( $is_memcached_class || class_exists( 'Memcached' ) ) {
			return array(
				'type'        => 'memcached',
				'label'       => __( 'Memcached', 'sakura-imgopt' ),
				'group_flush' => $group_flush,
				'class'       => $class,
			);
		}

		return array(
			'type'        => 'other',
			'label'       => __( '其他持久对象缓存', 'sakura-imgopt' ),
			'group_flush' => $group_flush,
			'class'       => $class,
		);
	}

	/**
	 * Cache groups that embed optimized thumbnail URLs.
	 *
	 * Zibll's BBS feed caches the rendered image HTML in `post_multi_thumbnail`,
	 * so a size change must invalidate that group as well; flushing an absent
	 * group is a no-op on the supported backends.
	 *
	 * @return string[]
	 */
	public static function cache_groups(): array {
		$sizes = array_keys( self::default_sizes() );
		$current = self::get();
		if ( isset( $current['sizes'] ) && is_array( $current['sizes'] ) ) {
			$sizes = array_merge( $sizes, array_keys( $current['sizes'] ) );
		}

		$groups = array();
		foreach ( array_unique( $sizes ) as $size ) {
			$groups[] = 'post_thumbnail_url_' . $size;
		}
		$groups[] = 'post_multi_thumbnail';

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Flush only this plugin's cache groups and OPcache.
	 *
	 * Runs once per request. The report is persisted so the settings page can
	 * show which backend was detected and whether group flushing is supported.
	 * The full object cache is intentionally never flushed.
	 *
	 * @return array<string,mixed>
	 */
	public static function flush_caches(): array {
		if ( self::$caches_flushed && is_array( self::$last_flush ) ) {
			return self::$last_flush;
		}
		self::$caches_flushed = true;

		$backend = self::object_cache_backend();
		$report  = array(
			'time'             => time(),
			'object_cache'     => false,
			'backend'          => $backend['type'],
			'backend_label'    => $backend['label'],
			'group_flush'      => (bool) $backend['group_flush'],
			'requested_groups' => 0,
			'theme_groups'     => 0,
			'cache_state'      => 'unsupported',
			'opcache'          => 'unavailable',
		);

		$groups = self::cache_groups();
		$report['requested_groups'] = count( $groups );

		if ( $backend['group_flush'] ) {
			$flushed = 0;
			foreach ( $groups as $group ) {
				if ( wp_cache_flush_group( $group ) ) {
					++$flushed;
				}
			}
			$report['theme_groups'] = $flushed;
			$report['object_cache'] = $flushed > 0;
			if ( $flushed === count( $groups ) ) {
				$report['cache_state'] = 'ok';
			} elseif ( $flushed > 0 ) {
				$report['cache_state'] = 'partial';
			} else {
				$report['cache_state'] = 'failed';
			}
		} elseif ( 'none' === $backend['type'] ) {
			$report['cache_state'] = 'none';
		}

		$report['opcache'] = self::should_reset_opcache() ? self::reset_opcache() : 'skipped';

		update_option( self::FLUSH_OPTION, $report, false );
		self::$last_flush = $report;

		return $report;
	}

	/**
	 * Whether saving settings should reset the whole OPcache.
	 *
	 * Saving settings does not modify any PHP file, so a full reset only forces
	 * every site in the same PHP-FPM pool to recompile. Off by default; sites
	 * that relied on the old behaviour can opt in with this filter.
	 *
	 * @return bool
	 */
	private static function should_reset_opcache(): bool {
		return (bool) apply_filters( 'sakura_imgopt_opcache_reset', false );
	}

	/**
	 * Reset OPcache when the SAPI allows it.
	 *
	 * Web requests normally can reset OPcache; CLI has a separate cache which
	 * is often disabled (opcache.enable_cli=0).
	 *
	 * @return string ok|failed|unavailable|skipped|skipped_cli
	 */
	private static function reset_opcache(): string {
		if ( ! function_exists( 'opcache_reset' ) || ! function_exists( 'opcache_get_status' ) ) {
			return 'unavailable';
		}

		if ( 'cli' === PHP_SAPI && ! ini_get( 'opcache.enable_cli' ) ) {
			return 'skipped_cli';
		}

		$status = opcache_get_status( false );
		if ( ! is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
			return 'unavailable';
		}

		return opcache_reset() ? 'ok' : 'failed';
	}

	/**
	 * Last persisted cache flush report.
	 *
	 * @return array<string,mixed>
	 */
	public static function cache_flush_report(): array {
		$stored = get_option( self::FLUSH_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$state = isset( $stored['cache_state'] ) ? (string) $stored['cache_state'] : ( ! empty( $stored['object_cache'] ) ? 'ok' : 'failed' );

		return array(
			'time'             => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
			'object_cache'     => ! empty( $stored['object_cache'] ),
			'backend'          => isset( $stored['backend'] ) ? (string) $stored['backend'] : 'unknown',
			'backend_label'    => isset( $stored['backend_label'] ) ? (string) $stored['backend_label'] : __( '对象缓存', 'sakura-imgopt' ),
			'group_flush'      => ! empty( $stored['group_flush'] ),
			'requested_groups' => isset( $stored['requested_groups'] ) ? (int) $stored['requested_groups'] : 0,
			'theme_groups'     => isset( $stored['theme_groups'] ) ? (int) $stored['theme_groups'] : 0,
			'cache_state'      => $state,
			'opcache'          => isset( $stored['opcache'] ) ? (string) $stored['opcache'] : 'unavailable',
		);
	}

	/**
	 * Clear request caches (tests, after external option updates).
	 */
	public static function reset(): void {
		self::$options = null;
		self::$s3      = null;
	}

	/**
	 * Resolve the active S3 provider and media URL base.
	 *
	 * @return array{provider:string,upload_url_path:string}
	 */
	public static function s3_config(): array {
		if ( null !== self::$s3 ) {
			return self::$s3;
		}

		$provider = '';
		$url      = '';

		if ( class_exists( S3_Options::class ) ) {
			$options  = new S3_Options();
			$provider = (string) $options->get( 'provider', '' );
			$url      = (string) $options->get( 'upload_url_path', '' );
			$active   = $options->active_profile_id();
			if ( '' !== $active ) {
				$profile = $options->profile( $active );
				if ( isset( $profile['provider'] ) && is_string( $profile['provider'] ) && '' !== $profile['provider'] ) {
					$provider = $profile['provider'];
				}
				if ( isset( $profile['upload_url_path'] ) && is_string( $profile['upload_url_path'] ) && '' !== $profile['upload_url_path'] ) {
					$url = $profile['upload_url_path'];
				}
			}
		} else {
			$raw      = get_option( 's3_options', array() );
			$raw      = is_array( $raw ) ? $raw : array();
			$provider = isset( $raw['provider'] ) ? (string) $raw['provider'] : '';
			$url      = isset( $raw['upload_url_path'] ) ? (string) $raw['upload_url_path'] : '';
		}

		self::$s3 = array(
			'provider'        => sanitize_key( $provider ),
			'upload_url_path' => trim( $url ),
		);

		return self::$s3;
	}

	/**
	 * Exact media host, or an empty string when unavailable.
	 */
	public static function media_host(): string {
		$config = self::s3_config();
		$url    = $config['upload_url_path'];
		if ( '' === $url ) {
			return '';
		}

		$target = false === strpos( $url, '://' ) ? 'https://' . ltrim( $url, '/' ) : $url;
		$host   = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return strtolower( rtrim( $host, '.' ) );
	}

	/**
	 * Active provider identifier from the S3 profile.
	 */
	public static function provider_id(): string {
		$config = self::s3_config();

		return $config['provider'];
	}

	/**
	 * Stored probe result.
	 */
	public static function probe_status(): Provider_Status {
		$raw = get_option( self::PROBE_OPTION, array() );

		return is_array( $raw ) ? Provider_Status::from_array( $raw ) : new Provider_Status();
	}

	/**
	 * Persist a probe result.
	 *
	 * @param Provider_Status $status Status.
	 */
	public static function store_probe( Provider_Status $status ): void {
		update_option( self::PROBE_OPTION, $status->to_array() );
	}

	/**
	 * Effective provider mode after degradation.
	 */
	public static function effective_mode(): string {
		$status = self::probe_status();

		return self::resolve_mode(
			self::get(),
			self::provider_id(),
			self::media_host(),
			$status->is_ok(),
			(string) $status->mode
		);
	}

	/**
	 * Pure mode resolution, so callers can preview a proposed option set.
	 *
	 * @param array<string,mixed> $opt        Normalized options.
	 * @param string              $provider   Saved S3 provider id.
	 * @param string              $host       Media host (empty when off-site).
	 * @param bool                $probe_ok   Whether the last probe succeeded.
	 * @param string              $probe_mode Mode reported by the probe.
	 */
	public static function resolve_mode( array $opt, string $provider, string $host, bool $probe_ok, string $probe_mode ): string {
		$config   = (string) ( $opt['provider_mode'] ?? 'auto' );
		$styles   = isset( $opt['style_map'] ) && is_array( $opt['style_map'] ) ? array_filter( $opt['style_map'] ) : array();
		$template = isset( $opt['advanced']['custom_provider_template'] ) ? (string) $opt['advanced']['custom_provider_template'] : '';
		$generic  = '' !== $template;
		$bitiful  = 'bitiful' === $provider;

		if ( 'none' === $config || '' === $host ) {
			return 'none';
		}

		if ( 'style' === $config ) {
			return ( $bitiful && array() !== $styles ) ? 'style' : 'none';
		}

		if ( 'params' === $config ) {
			return ( $bitiful || $generic ) ? 'params' : 'none';
		}

		if ( 'native' === $config ) {
			return 'native';
		}

		if ( $probe_ok ) {
			if ( 'style' === $probe_mode && $bitiful && array() !== $styles ) {
				return 'style';
			}
			if ( 'params' === $probe_mode && ( $bitiful || $generic ) ) {
				return 'params';
			}
			if ( 'native' === $probe_mode ) {
				return 'native';
			}
		}

		// No successful probe: do not pretend a mode works.
		return 'none';
	}

	/**
	 * Interactions that silently break images, reported before or after save.
	 *
	 * @param array<string,mixed>|null          $opt   Proposed options (defaults to stored).
	 * @param array<string,mixed>|null          $s3    Raw s3_options array.
	 * @param array{ok?:bool,mode?:string}|null $probe Probe override.
	 * @return array<int,array{code:string,severity:string,message:string}>
	 */
	public static function conflicts( ?array $opt = null, ?array $s3 = null, ?array $probe = null ): array {
		$opt = is_array( $opt ) ? $opt : self::get();

		if ( ! is_array( $s3 ) ) {
			$raw = get_option( 's3_options', array() );
			$s3  = is_array( $raw ) ? $raw : array();
		}

		if ( ! is_array( $probe ) ) {
			$status = self::probe_status();
			$probe  = array(
				'ok'   => $status->is_ok(),
				'mode' => (string) $status->mode,
			);
		}

		$provider = isset( $s3['provider'] ) ? sanitize_key( (string) $s3['provider'] ) : '';
		$url      = isset( $s3['upload_url_path'] ) ? trim( (string) $s3['upload_url_path'] ) : '';
		$host     = '';

		if ( '' !== $url ) {
			$target = false === strpos( $url, '://' ) ? 'https://' . ltrim( $url, '/' ) : $url;
			$parsed = wp_parse_url( $target, PHP_URL_HOST );
			if ( is_string( $parsed ) ) {
				$host = strtolower( rtrim( $parsed, '.' ) );
			}
		}

		$mode    = self::resolve_mode( $opt, $provider, $host, (bool) ( $probe['ok'] ?? false ), (string) ( $probe['mode'] ?? '' ) );
		$rewrite = ! empty( $opt['enabled'] ) && in_array( $mode, array( 'style', 'params' ), true );
		$out     = array();

		if ( $rewrite && 'private' === (string) ( $s3['object_acl'] ?? 'public-read' ) ) {
			$out[] = array(
				'code'     => 'private_acl',
				'severity' => 'warning',
				'message'  => __( '缩略图地址改写已开启，但 S3 对象权限是“私有”。改写后的地址不带签名，访客会收到 403；如已配置签名 CDN，可忽略。', 'sakura-imgopt' ),
			);
		}

		if ( $rewrite && 'true' === (string) ( $s3['no_thumb'] ?? 'false' ) ) {
			$out[] = array(
				'code'     => 'no_thumb',
				'severity' => 'warning',
				'message'  => __( '缩略图地址改写已开启，但 S3 设置为“不上传缩略图”。页面会指向未上传的子尺寸，可能出现 404；请关闭改写或允许上传缩略图。', 'sakura-imgopt' ),
			);
		}

		return $out;
	}

	/**
	 * Build the provider for the effective mode.
	 */
	public static function make_provider(): Provider {
		$opt  = self::get();
		$mode = self::effective_mode();

		if ( 'bitiful' === self::provider_id() ) {
			return new Bitiful( $mode );
		}

		$template = isset( $opt['advanced']['custom_provider_template'] ) ? (string) $opt['advanced']['custom_provider_template'] : '';

		return new Generic( $template );
	}

	/**
	 * Legacy v1 options for the HTML rollback engine.
	 *
	 * @return array<string,mixed>
	 */
	public static function legacy_options(): array {
		$opt    = self::get();
		$legacy = isset( $opt['legacy'] ) && is_array( $opt['legacy'] ) ? $opt['legacy'] : array();
		$defaults = array(
			'enabled'                 => 0,
			'oss_host'                => '',
			'pos'                     => 'auto',
			'sidebar_enabled'         => 0,
			'fallback'                => 0,
			'fallback_size'           => 'thumbnail',
			'fallback_list_size'      => 'thumbnail',
			'fallback_card_size'      => 'medium',
			'fallback_other_size'     => 'thumbnail',
			'fallback_sidebar_size'   => 'thumbnail',
			'fallback_list_custom'    => '',
			'fallback_card_custom'    => '',
			'fallback_sidebar_custom' => '',
			'thumb_custom'            => '',
			'medium_custom'           => '',
			'large_custom'            => '',
			'thumb_w'                 => 250,
			'thumb_h'                 => 250,
			'thumb_crop'              => 1,
			'medium_w'                => 500,
			'medium_h'                => 500,
			'medium_crop'             => 1,
			'large_w'                 => 800,
			'large_h'                 => 0,
			'large_crop'              => 0,
			'sidebar_classes'         => 'sidebar',
			'row_classes'             => 'posts-row',
			'post_classes'            => 'posts-item,ajax-item',
			'thumb_classes'           => 'item-thumbnail,thumb-items',
		);
		$legacy = array_merge( $defaults, $legacy );

		$legacy['enabled']   = (int) $opt['enabled'];
		$legacy['oss_host']  = self::media_host();
		$legacy['pos']       = (string) ( $opt['sizes']['medium']['position'] ?? 'auto' );
		$legacy['sidebar_enabled'] = (int) $opt['scopes']['sidebar'];

		foreach ( array( 'thumbnail' => 'thumb', 'medium' => 'medium', 'large' => 'large' ) as $size => $prefix ) {
			$row = isset( $opt['sizes'][ $size ] ) && is_array( $opt['sizes'][ $size ] ) ? $opt['sizes'][ $size ] : array();
			$legacy[ $prefix . '_w' ]    = (int) ( $row['width'] ?? 0 );
			$legacy[ $prefix . '_h' ]    = (int) ( $row['height'] ?? 0 );
			$legacy[ $prefix . '_crop' ] = Rendition::MODE_CROP === ( $row['mode'] ?? '' ) ? 1 : 0;
		}

		return $legacy;
	}

}
