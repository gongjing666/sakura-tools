<?php
/**
 * Path-segment style suffix tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Style_Suffix;

/**
 * DogeCloud keeps the style as an extra path segment: `a.jpg/720wm`.
 */
final class StyleSuffixTest extends TestCase {

	public function test_plain_image_url_has_no_suffix(): void {
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg' ) );
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg?v=2' ) );
	}

	public function test_style_segment_is_recognised(): void {
		$this->assertTrue( Style_Suffix::has( 'https://doge.example.com/a.jpg/720wm' ) );
		$this->assertTrue( Style_Suffix::has( 'https://doge.example.com/a.webp/siomedium?v=2#frag' ) );
	}

	/**
	 * A directory may look like an extension but it is never the last segment,
	 * so the style detector must only look at the tail of the path.
	 */
	public function test_directory_names_are_not_mistaken_for_styles(): void {
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg/' ) );
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg/2026/11' ) );
	}

	public function test_matches_accepts_the_leading_slash_the_console_shows(): void {
		$url = 'https://doge.example.com/a.jpg/720wm';

		$this->assertTrue( Style_Suffix::matches( $url, '720wm' ) );
		$this->assertTrue( Style_Suffix::matches( $url, '/720wm' ) );
		$this->assertFalse( Style_Suffix::matches( $url, '720' ) );
		$this->assertFalse( Style_Suffix::matches( 'https://doge.example.com/a.jpg', '720wm' ) );
	}

	public function test_strip_removes_only_the_style(): void {
		$this->assertSame(
			'https://doge.example.com/a.jpg?v=2#frag',
			Style_Suffix::strip( 'https://doge.example.com/a.jpg/720wm?v=2#frag' )
		);
		$this->assertSame(
			'https://doge.example.com/a.jpg',
			Style_Suffix::strip( 'https://doge.example.com/a.jpg' )
		);
	}

	public function test_insert_places_the_suffix_before_the_query(): void {
		$this->assertSame(
			'https://doge.example.com/a.jpg/720wm?v=2#frag',
			Style_Suffix::insert( 'https://doge.example.com/a.jpg?v=2#frag', '/720wm' )
		);
	}

	/**
	 * The style grammar must not open a path-traversal hole: an attacker who
	 * types `..%2f` into the style field cannot make strip() walk upwards.
	 */
	public function test_traversal_like_segments_are_not_styles(): void {
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg/..' ) );
		$this->assertFalse( Style_Suffix::has( 'https://doge.example.com/a.jpg/../etc' ) );
	}
}
