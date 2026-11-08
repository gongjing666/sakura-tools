<?php
/**
 * Bitiful (缤纷云) URL provider.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail\Providers;

use Sakura\ImageOptimizer\Thumbnail\Provider;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Bitiful style (`!style=name`) or processing-parameter URLs.
 *
 * The named-style form is required on buckets that keep the origin-file
 * protection enabled: anonymous `?w=` parameters are silently overridden by
 * the bucket's default style.
 */
final class Bitiful implements Provider {

	public const MODE_PARAMS = 'params';
	public const MODE_STYLE  = 'style';
	public const MODE_NONE   = 'none';

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
		return 'bitiful';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array(
			'resize'  => self::MODE_NONE !== $this->mode,
			'crop'    => self::MODE_NONE !== $this->mode,
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
			if ( '' === $rendition->style ) {
				return $url;
			}

			return self::append_path_suffix( $url, '!style=' . rawurlencode( $rendition->style ) );
		}

		if ( Rendition::MODE_PAD === $rendition->mode ) {
			return $url;
		}

		$args = array();
		if ( Rendition::MODE_CROP === $rendition->mode && $rendition->width > 0 && $rendition->height > 0 ) {
			$args['w']    = $rendition->width;
			$args['h']    = $rendition->height;
			$args['mode'] = 'crop';
			if ( 'auto' !== $rendition->position ) {
				$args['pos'] = $rendition->position;
			}
		} elseif ( $rendition->width > 0 ) {
			$args['w'] = $rendition->width;
		} elseif ( $rendition->height > 0 ) {
			$args['h'] = $rendition->height;
		} else {
			return $url;
		}

		if ( $rendition->quality > 0 ) {
			$args['q'] = $rendition->quality;
		}

		return self::append_query( $url, $args );
	}

	/**
	 * Insert a suffix into the path, before any query string.
	 *
	 * @param string $url    URL.
	 * @param string $suffix Suffix.
	 */
	private static function append_path_suffix( string $url, string $suffix ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$query = '';
		$mark  = strpos( $url, '?' );
		if ( false !== $mark ) {
			$query = substr( $url, $mark );
			$url   = substr( $url, 0, $mark );
		}

		return $url . $suffix . $query . $fragment;
	}

	/**
	 * Append query arguments without clobbering an existing query string.
	 *
	 * @param string                $url  URL.
	 * @param array<string,int|string> $args Arguments.
	 */
	private static function append_query( string $url, array $args ): string {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$query = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		if ( '' === $query ) {
			return $url . $fragment;
		}

		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		return $url . $separator . $query . $fragment;
	}
}
