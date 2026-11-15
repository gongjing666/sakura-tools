<?php
/**
 * DogeCloud (多吉云) URL provider.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail\Providers;

use Sakura\ImageOptimizer\Thumbnail\Provider;
use Sakura\ImageOptimizer\Thumbnail\Rendition;
use Sakura\ImageOptimizer\Thumbnail\Style_Suffix;

defined( 'ABSPATH' ) || exit;

/**
 * Builds DogeCloud named-style (`a.jpg/720wm`) or imageView2 URLs.
 *
 * DogeCloud differs from Bitiful in two ways that matter here:
 *
 * - the named-style separator is `/`, and it is part of the rule name the
 *   operator types into the console (`/720wm`), so the suffix is a path
 *   segment rather than a `!style=` suffix;
 * - the bare `?w=`/`?h=` parameters Bitiful understands are ignored by
 *   DogeCloud (it answers 200 with the original bytes), so the parameter mode
 *   has to use the Tencent-style `imageView2` syntax it implements.
 */
final class Doge implements Provider {

	public const MODE_PARAMS = 'params';
	public const MODE_STYLE  = 'style';
	public const MODE_NONE   = 'none';

	/**
	 * imageView2 mode 1: scale to fill and crop the overflow.
	 */
	private const IV2_CROP = '1';

	/**
	 * imageView2 mode 2: scale to fit inside the box, keeping the ratio.
	 */
	private const IV2_FIT = '2';

	/**
	 * Active mode.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Constructor.
	 *
	 * @param string $mode params|style|none.
	 */
	public function __construct( string $mode = self::MODE_PARAMS ) {
		$this->mode = in_array( $mode, array( self::MODE_PARAMS, self::MODE_STYLE, self::MODE_NONE ), true ) ? $mode : self::MODE_PARAMS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'dogecloud';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array(
			'resize'  => self::MODE_NONE !== $this->mode,
			'crop'    => self::MODE_NONE !== $this->mode,
			// imageView2 knows `format/webp`, but accepts no `avif`, so the
			// decorator must never promise an arbitrary target format.
			'format'  => false,
			'quality' => self::MODE_PARAMS === $this->mode,
			'style'   => self::MODE_STYLE === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $capability Capability name.
	 */
	public function supports( string $capability ): bool {
		$all = $this->capabilities();

		return ! empty( $all[ $capability ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string    $url       Original URL.
	 * @param Rendition $rendition Target rendition.
	 */
	public function decorate( string $url, Rendition $rendition ): string {
		if ( self::MODE_NONE === $this->mode || ! $rendition->is_usable() ) {
			return $url;
		}

		if ( self::MODE_STYLE === $this->mode ) {
			$style = self::normalize_style( $rendition->style );
			if ( '' === $style ) {
				return $url;
			}

			// The rule name already starts with the separator, so only add the
			// slash when the operator left it out.
			return Style_Suffix::insert( $url, '/' . $style );
		}

		if ( Rendition::MODE_PAD === $rendition->mode ) {
			return $url;
		}

		$args = array();
		if ( $rendition->width > 0 && $rendition->height > 0 ) {
			$args[] = Rendition::MODE_CROP === $rendition->mode ? self::IV2_CROP : self::IV2_FIT;
			$args[] = 'w';
			$args[] = (string) $rendition->width;
			$args[] = 'h';
			$args[] = (string) $rendition->height;
		} elseif ( $rendition->width > 0 ) {
			// A single dimension keeps the ratio either way, and mode 2 is the
			// one that never crops away content.
			$args[] = self::IV2_FIT;
			$args[] = 'w';
			$args[] = (string) $rendition->width;
		} elseif ( $rendition->height > 0 ) {
			$args[] = self::IV2_FIT;
			$args[] = 'h';
			$args[] = (string) $rendition->height;
		} else {
			return $url;
		}

		if ( $rendition->quality > 0 ) {
			$args[] = 'q';
			$args[] = (string) max( 1, min( 100, $rendition->quality ) );
		}

		if ( 'webp' === $rendition->format ) {
			$args[] = 'format';
			$args[] = 'webp';
		}

		return self::append_query( $url, 'imageView2/' . implode( '/', $args ) );
	}

	/**
	 * Strip the separator the operator may have typed, so it is added once.
	 *
	 * @param string $style Rule name.
	 */
	private static function normalize_style( string $style ): string {
		return ltrim( trim( $style ), '/' );
	}

	/**
	 * Append one processing parameter without clobbering an existing query.
	 *
	 * @param string $url   URL.
	 * @param string $value Parameter value, e.g. `imageView2/2/w/400`.
	 */
	private static function append_query( string $url, string $value ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		return $url . $separator . $value . $fragment;
	}
}
