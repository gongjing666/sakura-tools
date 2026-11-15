<?php
/**
 * Search term normalisation shared by both sync list endpoints.
 *
 * The reconciliation table is served by two different code paths: the AJAX
 * handler in the admin (Index_Repository::page) and the SHORTINIT script
 * fast-search.php. They used to normalise the search box separately and both
 * completed a bare filename into `{upload_path}/filename`, which is a key
 * *prefix*: typing `x8BnM.webp` therefore matched nothing. Keeping the rule in
 * one class stops the two endpoints from drifting apart again.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw search box value into a LIKE pattern.
 */
final class Search_Term {

	/**
	 * Normalise the raw search box value.
	 *
	 * Object keys always use forward slashes and never start with one, so the
	 * same is enforced here; otherwise a pasted Windows path or a leading slash
	 * would never match.
	 *
	 * @param string $search Raw search.
	 * @return string
	 */
	public static function normalize( string $search ): string {
		$search = str_replace( '\\', '/', trim( $search ) );

		return ltrim( $search, '/' );
	}

	/**
	 * Build the LIKE pattern for a search box value.
	 *
	 * Matching is a plain "contains" test on the object key, so a filename
	 * pasted without its directory still finds the row. An empty term returns
	 * an empty pattern: callers must skip the clause rather than bind `%%`,
	 * which would match every row through the search ordering.
	 *
	 * @param string   $search   Raw search.
	 * @param callable $esc_like Escaper, usually array( $wpdb, 'esc_like' ).
	 * @return string Pattern, or '' when the term is empty.
	 */
	public static function pattern( string $search, callable $esc_like ): string {
		$term = self::normalize( $search );
		if ( '' === $term ) {
			return '';
		}

		return '%' . (string) call_user_func( $esc_like, $term ) . '%';
	}
}
