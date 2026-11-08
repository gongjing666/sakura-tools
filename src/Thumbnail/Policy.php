<?php
/**
 * Context + size -> rendition policy.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a rendition without touching WordPress globals, which keeps the
 * priority rules unit-testable.
 */
final class Policy {

	/**
	 * Scene keys accepted by resolve().
	 */
	public const SCENE_NONE    = '';
	public const SCENE_THUMB   = 'thumb';
	public const SCENE_SIDEBAR = 'sidebar';
	public const SCENE_CONTENT = 'content';

	/**
	 * Normalized option array.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $options Normalized options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Whether a context is enabled.
	 *
	 * @param string $context Context.
	 */
	public function context_enabled( string $context ): bool {
		$scopes = isset( $this->options['scopes'] ) && is_array( $this->options['scopes'] ) ? $this->options['scopes'] : array();

		return ! empty( $scopes[ $context ] );
	}

	/**
	 * Resolve the target rendition.
	 *
	 * @param string $context     Current context.
	 * @param string $size        WordPress size name.
	 * @param int    $orig_width  Original attachment width.
	 * @param int    $orig_height Original attachment height.
	 * @param int    $requested   Width WordPress resolved for the requested size.
	 * @param string $provider_mode Effective provider mode.
	 * @param bool   $force       Bypass the context switch (content image filter).
	 * @param string $scene       Scene whose configured size may replace $size.
	 */
	public function resolve( string $context, string $size, int $orig_width, int $orig_height, int $requested = 0, string $provider_mode = 'params', bool $force = false, string $scene = self::SCENE_THUMB ): ?Rendition {
		$is_full = false;
		if ( 'full' === $size ) {
			$full = $this->options['advanced']['full_cap'] ?? 0;
			if ( empty( $full ) ) {
				return null;
			}
			$size    = 'large';
			$is_full = true;
		}

		if ( ! $force && ! $this->context_enabled( $context ) ) {
			return null;
		}

		if ( ! $is_full ) {
			$size = $this->scene_size( $context, $size, $scene );
		}

		$sizes     = isset( $this->options['sizes'] ) && is_array( $this->options['sizes'] ) ? $this->options['sizes'] : array();
		$overrides = isset( $this->options['overrides'] ) && is_array( $this->options['overrides'] ) ? $this->options['overrides'] : array();
		$styles    = isset( $this->options['style_map'] ) && is_array( $this->options['style_map'] ) ? $this->options['style_map'] : array();

		$row = null;
		$key = $context . ':' . $size;
		if ( isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) {
			$row = $overrides[ $key ];
		} elseif ( isset( $sizes[ $size ] ) && is_array( $sizes[ $size ] ) ) {
			$row = $sizes[ $size ];
		}

		if ( null === $row ) {
			$advanced = isset( $this->options['advanced'] ) && is_array( $this->options['advanced'] ) ? $this->options['advanced'] : array();
			if ( empty( $advanced['unknown_fallback'] ) || $requested <= 0 ) {
				return null;
			}
			$dpr   = isset( $this->options['dpr'] ) ? (float) $this->options['dpr'] : 1.5;
			$width = (int) ceil( $requested * max( 1.0, $dpr ) );
			if ( $orig_width > 0 ) {
				$width = min( $width, $orig_width );
			}
			if ( $width <= 0 || ( $orig_width > 0 && $width >= $orig_width ) ) {
				return null;
			}
			$row = array(
				'width'    => $width,
				'height'   => 0,
				'mode'     => Rendition::MODE_FIT,
				'position' => 'auto',
				'quality'  => 82,
				'format'   => 'auto',
			);
			$size = '';
		}

		$style = '';
		if ( '' !== $size && isset( $styles[ $size ] ) && is_string( $styles[ $size ] ) ) {
			$style = Rendition::sanitize_style( $styles[ $size ] );
		}
		if ( isset( $row['style'] ) && is_string( $row['style'] ) ) {
			$style = Rendition::sanitize_style( $row['style'] );
		}

		$rendition = Rendition::from_array( $row );
		if ( '' !== $style ) {
			$rendition = $rendition->with_style( $style );
		}

		if ( ! $rendition->is_usable() ) {
			return null;
		}

		if ( 'style' === $provider_mode && '' === $rendition->style ) {
			return null;
		}

		if ( 'native' === $provider_mode || 'none' === $provider_mode ) {
			return null;
		}

		return $this->guard_upscale( $rendition, $orig_width, $orig_height );
	}

	/**
	 * Replace the requested size with the size configured for a scene.
	 *
	 * Empty scene targets mean "follow the theme request". The mapping only
	 * applies when the target row exists, so a stale option cannot point at a
	 * missing size. The scene is matched against the current context: the
	 * sidebar scene never changes singular content, and the thumbnail scene
	 * only affects list/card contexts.
	 *
	 * @param string $context Current context.
	 * @param string $size    Requested size.
	 * @param string $scene   Scene key: thumb, content, or none.
	 */
	public function scene_size( string $context, string $size, string $scene ): string {
		if ( '' === $size || self::SCENE_NONE === $scene ) {
			return $size;
		}

		$scenes = isset( $this->options['scenes'] ) && is_array( $this->options['scenes'] ) ? $this->options['scenes'] : array();
		$sizes  = isset( $this->options['sizes'] ) && is_array( $this->options['sizes'] ) ? $this->options['sizes'] : array();

		if ( self::SCENE_CONTENT === $scene ) {
			$key = 'content';
		} elseif ( Context::SIDEBAR === $context ) {
			$key = 'sidebar';
		} elseif ( self::SCENE_THUMB === $scene && ( Context::ARCHIVE === $context || Context::OTHER === $context ) ) {
			$key = 'thumb';
		} else {
			return $size;
		}

		if ( empty( $scenes[ $key ] ) || ! is_string( $scenes[ $key ] ) ) {
			return $size;
		}

		$target = (string) $scenes[ $key ];

		return isset( $sizes[ $target ] ) ? $target : $size;
	}

	/**
	 * Reject renditions that cannot shrink the original.
	 *
	 * @param Rendition $rendition Target.
	 * @param int       $orig_w    Original width.
	 * @param int       $orig_h    Original height.
	 */
	private function guard_upscale( Rendition $rendition, int $orig_w, int $orig_h ): ?Rendition {
		if ( $orig_w <= 0 || $orig_h <= 0 ) {
			return $rendition;
		}

		if ( Rendition::MODE_CROP === $rendition->mode ) {
			if ( $rendition->width > 0 && $rendition->width > $orig_w ) {
				return null;
			}
			if ( $rendition->height > 0 && $rendition->height > $orig_h ) {
				return null;
			}

			return $rendition;
		}

		$width  = $rendition->width;
		$height = $rendition->height;

		if ( $width > 0 && $width >= $orig_w && ( 0 === $height || $height >= $orig_h ) ) {
			return null;
		}
		if ( 0 === $width && $height > 0 && $height >= $orig_h ) {
			return null;
		}

		return $rendition;
	}
}
