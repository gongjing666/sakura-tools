<?php
/**
 * Url_Rewriter tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Oss\Url_Rewriter;

/**
 * Pure URL rewriting tests.
 */
final class UrlRewriterTest extends TestCase {

	/**
	 * Add parameters to a plain OSS URL.
	 */
	public function test_adds_suffix_to_plain_url(): void {
		$this->assertSame(
			'https://cdn.example.com/a.jpg?w=100&h=100',
			Url_Rewriter::rewrite_image_url( 'https://cdn.example.com/a.jpg', 'cdn.example.com', '?w=100&h=100' )
		);
	}

	/**
	 * Replace an existing optimizer suffix.
	 */
	public function test_replaces_existing_optimizer_suffix(): void {
		$this->assertSame(
			'https://cdn.example.com/a.jpg?w=200',
			Url_Rewriter::rewrite_image_url( 'https://cdn.example.com/a.jpg?w=100&h=100', 'cdn.example.com', '?w=200' )
		);
	}

	/**
	 * Never modify signed URLs.
	 */
	public function test_keeps_signed_url(): void {
		$url = 'https://cdn.example.com/a.jpg?x-oss-signature=abc&Expires=1';
		$this->assertSame( $url, Url_Rewriter::rewrite_image_url( $url, 'cdn.example.com', '?w=100' ) );
	}

	/**
	 * Ignore other hosts.
	 */
	public function test_ignores_other_host(): void {
		$url = 'https://other.example.com/a.jpg';
		$this->assertSame( $url, Url_Rewriter::rewrite_image_url( $url, 'cdn.example.com', '?w=100' ) );
	}

	/**
	 * Build the default thumbnail suffix.
	 */
	public function test_builds_thumbnail_suffix(): void {
		$options = array(
			'thumb_w'    => 150,
			'thumb_h'    => 150,
			'thumb_crop' => 1,
			'pos'        => 'auto',
		);

		$this->assertSame( '?w=150&h=150&mode=crop&pos=auto', Url_Rewriter::build_size_query_by_key( $options, 'thumbnail' ) );
	}

	/**
	 * Normalize custom suffixes.
	 */
	public function test_normalizes_suffix(): void {
		$this->assertSame( '?x=1', Url_Rewriter::normalize_suffix( '&x=1' ) );
		$this->assertSame( '?x=1', Url_Rewriter::normalize_suffix( 'x=1' ) );
		$this->assertSame( '', Url_Rewriter::normalize_suffix( ' ' ) );
	}

	/**
	 * Rewrite every srcset candidate.
	 */
	public function test_rewrites_srcset(): void {
		$this->assertSame(
			'https://cdn.example.com/a.jpg?w=100 1x, https://cdn.example.com/a.jpg?w=100 2x',
			Url_Rewriter::rewrite_srcset(
				'https://cdn.example.com/a.jpg 1x, https://cdn.example.com/a.jpg 2x',
				'cdn.example.com',
				'?w=100'
			)
		);
	}
}
