<?php
/**
 * Path-segment named styles (DogeCloud stores the style after the file name).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises and strips the `/style` suffix DogeCloud appends to a URL.
 *
 * Bitiful puts its style in the query-ish suffix `a.webp!style=siomedium`,
 * which never changes the file extension. DogeCloud keeps the style as an
 * extra path segment — `a.jpg/720wm` — so the URL no longer ends in an image
 * extension. Everything that decides "is this already optimised?" therefore
 * has to know about this shape, otherwise the decorator appends a second
 * style and the CDN answers 404.
 */
final class Style_Suffix {

	/**
	 * An image file name followed by exactly one style segment.
	 *
	 * The style grammar matches what the settings form accepts, so a stray
	 * `../` can never be mistaken for a style.
	 */
	private const PATTERN = '#(\.(?:jpe?g|png|webp|avif|bmp))/([A-Za-z0-9][A-Za-z0-9_-]{0,35})$#i';

	/**
	 * Whether the URL already carries a path-segment style.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function has( string $url ): bool {
		return 1 === preg_match( self::PATTERN, self::head( $url ) );
	}

	/**
	 * Whether the URL carries exactly this style.
	 *
	 * @param string $url   Candidate URL.
	 * @param string $style Expected style, with or without the leading slash.
	 */
	public static function matches( string $url, string $style ): bool {
		$style = ltrim( trim( $style ), '/' );
		if ( '' === $style ) {
			return false;
		}

		$head = self::head( $url );
		$tail = '/' . $style;

		return strlen( $head ) > strlen( $tail )
			&& substr( $head, -strlen( $tail ) ) === $tail
			&& 1 === preg_match( self::PATTERN, $head );
	}

	/**
	 * Remove the style segment, leaving query and fragment untouched.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function strip( string $url ): string {
		$cut  = strcspn( $url, '?#' );
		$head = substr( $url, 0, $cut );
		$tail = substr( $url, $cut );

		return (string) preg_replace( self::PATTERN, '$1', $head ) . $tail;
	}

	/**
	 * Insert a suffix at the end of the path, before any query string.
	 *
	 * @param string $url    URL.
	 * @param string $suffix Suffix, e.g. `/720wm`.
	 */
	public static function insert( string $url, string $suffix ): string {
		$cut = strcspn( $url, '?#' );

		return substr( $url, 0, $cut ) . $suffix . substr( $url, $cut );
	}

	/**
	 * The URL up to its query string or fragment.
	 *
	 * @param string $url URL.
	 */
	private static function head( string $url ): string {
		return substr( $url, 0, strcspn( $url, '?#' ) );
	}
}
