<?php
/**
 * Search term normalisation tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Search_Term;

/**
 * Pins the search box contract shared by the AJAX handler and fast-search.php.
 *
 * The two endpoints used to normalise the term separately and both completed a
 * bare filename into an upload-path prefix: pasting the name of a file that
 * exists showed "no matching file". These tests pin the contains pattern and
 * the single shared implementation.
 */
final class SearchTermTest extends TestCase {

	/**
	 * Mirror of wpdb::esc_like().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * A pasted filename must survive normalisation unchanged.
	 */
	public function test_normalize_keeps_a_bare_filename(): void {
		$this->assertSame( '20261115-071213-x8BnM.webp', Search_Term::normalize( '20261115-071213-x8BnM.webp' ) );
	}

	/**
	 * Surrounding whitespace, a leading slash and Windows separators are noise.
	 */
	public function test_normalize_strips_noise(): void {
		$this->assertSame( '2026/11/x8BnM.webp', Search_Term::normalize( "  /2026/11/x8BnM.webp  " ) );
		$this->assertSame( '2026/11/x8BnM.webp', Search_Term::normalize( '2026\\11\\x8BnM.webp' ) );
	}

	/**
	 * A blank search box is not a search.
	 */
	public function test_normalize_blank_is_empty(): void {
		$this->assertSame( '', Search_Term::normalize( '' ) );
		$this->assertSame( '', Search_Term::normalize( "  \n\t " ) );
	}

	/**
	 * The pattern must match anywhere in the key, not only at the front.
	 */
	public function test_pattern_matches_a_filename_within_the_key(): void {
		$pattern = Search_Term::pattern( 'x8BnM.webp', array( self::class, 'esc_like' ) );

		$this->assertSame( '%x8BnM.webp%', $pattern );
		$this->assertStringStartsWith( '%', $pattern );
		$this->assertStringEndsWith( '%', $pattern );
	}

	/**
	 * LIKE wildcards typed by the user are matched literally.
	 */
	public function test_pattern_escapes_wildcards(): void {
		$this->assertSame( '%100\\%\\_off%', Search_Term::pattern( '100%_off', array( self::class, 'esc_like' ) ) );
	}

	/**
	 * An empty term yields an empty pattern so callers skip the clause.
	 */
	public function test_pattern_of_blank_is_empty(): void {
		$this->assertSame( '', Search_Term::pattern( '   ', array( self::class, 'esc_like' ) ) );
	}

	/**
	 * Both list endpoints must share this implementation.
	 */
	public function test_both_endpoints_use_the_shared_term(): void {
		$root = dirname( __DIR__ );

		$fast = (string) file_get_contents( $root . '/fast-search.php' );
		$this->assertStringContainsString( 'Search_Term::normalize(', $fast );
		$this->assertStringContainsString( 'Search_Term::pattern(', $fast );
		$this->assertSame( 0, substr_count( $fast, "\$upload_path . '/' . \$search" ), 'A bare filename must not be completed into a key prefix.' );

		$repository = (string) file_get_contents( $root . '/src/Sync/Index_Repository.php' );
		$this->assertStringContainsString( 'Search_Term::pattern(', $repository );
	}
}
