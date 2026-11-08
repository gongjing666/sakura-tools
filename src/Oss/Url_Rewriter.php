<?php
/**
 * OSS thumbnail URL rewriting.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Oss;

defined( 'ABSPATH' ) || exit;

/**
 * Pure URL/query helpers extracted from Thumbnail_Optimizer_Module.
 */
final class Url_Rewriter {

	/**
	 * Rewrite every candidate in a srcset string.
	 *
	 * @param string $srcset Original srcset.
	 * @param string $host   OSS host.
	 * @param string $suffix Query suffix.
	 * @return string
	 */
	public static function rewrite_srcset( string $srcset, string $host, string $suffix ): string {
		$candidates = preg_split( '/\s*,\s*/', trim( $srcset ) );
		if ( ! is_array( $candidates ) ) {
			return $srcset;
		}

		$changed = false;
		foreach ( $candidates as &$candidate ) {
			if ( ! preg_match( '/^(\S+)(\s+.*)?$/', $candidate, $matches ) ) {
				continue;
			}

			$url        = $matches[1];
			$descriptor = isset( $matches[2] ) ? $matches[2] : '';
			$new_url    = self::rewrite_image_url( $url, $host, $suffix );
			if ( $new_url !== $url ) {
				$candidate = $new_url . $descriptor;
				$changed   = true;
			}
		}
		unset( $candidate );

		return $changed ? implode( ', ', $candidates ) : $srcset;
	}

	/**
	 * Add or replace thumbnail parameters on one OSS URL.
	 *
	 * @param string $url    Image URL.
	 * @param string $host   OSS host.
	 * @param string $suffix Query suffix.
	 * @return string
	 */
	public static function rewrite_image_url( string $url, string $host, string $suffix ): string {
		$url = trim( $url );
		if ( '' === $url || '' === $suffix ) {
			return $url;
		}

		$parse_url = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		$url_host  = wp_parse_url( $parse_url, PHP_URL_HOST );
		if ( ! is_string( $url_host ) || strtolower( rtrim( $url_host, '.' ) ) !== $host ) {
			return $url;
		}

		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$query_pos = strpos( $url, '?' );
		$style_pos = false;
		$last_bang = strrpos( $url, '!' );
		if ( false !== $last_bang && false !== strpos( substr( $url, $last_bang ), '=' ) ) {
			$style_pos = $last_bang;
		}

		$cut_pos = false;
		if ( false !== $query_pos && false !== $style_pos ) {
			$cut_pos = min( $query_pos, $style_pos );
		} elseif ( false !== $query_pos ) {
			$cut_pos = $query_pos;
		} elseif ( false !== $style_pos ) {
			$cut_pos = $style_pos;
		}

		$root     = false === $cut_pos ? $url : substr( $url, 0, $cut_pos );
		$existing = false === $cut_pos ? '' : substr( $url, $cut_pos );

		if ( $existing === $suffix ) {
			return $url . $fragment;
		}

		if ( '' === $existing || self::is_optimizer_suffix( $existing ) ) {
			return $root . $suffix . $fragment;
		}

		if ( self::is_signed_query( $existing ) || '?' !== substr( $suffix, 0, 1 ) || '?' !== substr( $existing, 0, 1 ) ) {
			return $url . $fragment;
		}

		$new_args = ltrim( $suffix, '?' );
		if ( '' === $new_args ) {
			return $url . $fragment;
		}

		return $url . '&' . $new_args . $fragment;
	}

	/**
	 * Whether a suffix already contains image-processing parameters.
	 *
	 * @param string $suffix URL suffix.
	 * @return bool
	 */
	public static function is_optimizer_suffix( string $suffix ): bool {
		if ( '!' === substr( $suffix, 0, 1 ) ) {
			return true;
		}

		return (bool) preg_match(
			'/(?:^|[?&])(?:w|h|width|height|mode|pos|fmt|format|q|quality|resize|crop|x-oss-process|imageMogr2|imageView2|image-process)=/i',
			$suffix
		);
	}

	/**
	 * Whether the query is a signed URL that must not be modified.
	 *
	 * @param string $query Query string.
	 * @return bool
	 */
	public static function is_signed_query( string $query ): bool {
		return (bool) preg_match(
			'/(?:^|[?&])(?:signature|ossaccesskeyid|expires|x-amz-signature|x-amz-credential|x-amz-date|x-amz-security-token|x-oss-signature|x-oss-credential|x-oss-date|x-oss-security-token)=/i',
			$query
		);
	}

	/**
	 * Build the suffix for a scope.
	 *
	 * @param array<string, int|string> $opt   Options.
	 * @param string                    $scope list|card|sidebar.
	 * @return string
	 */
	public static function build_scope_suffix( array $opt, string $scope ): string {
		$custom_key = 'fallback_' . $scope . '_custom';
		if ( ! empty( $opt[ $custom_key ] ) ) {
			return self::normalize_suffix( (string) $opt[ $custom_key ] );
		}

		$size_key = 'fallback_' . $scope . '_size';
		$size     = isset( $opt[ $size_key ] ) ? (string) $opt[ $size_key ] : 'thumbnail';
		return self::build_size_query_by_key( $opt, $size );
	}

	/**
	 * Build a suffix from a WordPress size preset.
	 *
	 * @param array<string, int|string> $opt      Options.
	 * @param string                    $size_key thumbnail|medium|large.
	 * @return string
	 */
	public static function build_size_query_by_key( array $opt, string $size_key ): string {
		if ( ! in_array( $size_key, array( 'thumbnail', 'medium', 'large' ), true ) ) {
			$size_key = 'thumbnail';
		}

		$prefix = 'thumbnail' === $size_key ? 'thumb' : $size_key;
		$custom = isset( $opt[ $prefix . '_custom' ] ) ? trim( (string) $opt[ $prefix . '_custom' ] ) : '';
		if ( '' !== $custom ) {
			return self::normalize_suffix( $custom );
		}

		$width  = isset( $opt[ $prefix . '_w' ] ) ? (int) $opt[ $prefix . '_w' ] : 0;
		$height = isset( $opt[ $prefix . '_h' ] ) ? (int) $opt[ $prefix . '_h' ] : 0;
		$crop   = isset( $opt[ $prefix . '_crop' ] ) ? (int) $opt[ $prefix . '_crop' ] : 0;

		if ( ! $width && ! $height ) {
			return '';
		}

		return self::build_query( $width, $height, $crop, isset( $opt['pos'] ) ? (string) $opt['pos'] : '' );
	}

	/**
	 * Build a width/height/crop query.
	 *
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param int    $crop   Crop flag.
	 * @param string $pos    Crop position.
	 * @return string
	 */
	private static function build_query( int $width, int $height, int $crop, string $pos ): string {
		$args = array();
		if ( $width ) {
			$args[] = 'w=' . $width;
		}
		if ( $height ) {
			$args[] = 'h=' . $height;
		}
		if ( $crop && $width && $height ) {
			$args[] = 'mode=crop';
			if ( '' !== $pos ) {
				$args[] = 'pos=' . rawurlencode( $pos );
			}
		}

		return empty( $args ) ? '' : '?' . implode( '&', $args );
	}

	/**
	 * Normalize a custom suffix.
	 *
	 * @param string $suffix User suffix.
	 * @return string
	 */
	public static function normalize_suffix( string $suffix ): string {
		$suffix = trim( $suffix );
		if ( '' === $suffix ) {
			return '';
		}

		$first = substr( $suffix, 0, 1 );
		if ( '?' === $first || '!' === $first ) {
			return $suffix;
		}
		if ( '&' === $first ) {
			return '?' . ltrim( $suffix, '&' );
		}

		return '?' . $suffix;
	}
}
