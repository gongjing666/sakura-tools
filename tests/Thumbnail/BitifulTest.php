<?php
/**
 * Bitiful provider tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

/**
 * URL syntax.
 */
final class BitifulTest extends TestCase {

	public function test_style_suffix_goes_before_query(): void {
		$provider = new Bitiful( Bitiful::MODE_STYLE );
		$url      = 'https://cdn.example.com/a.webp?v=2#frag';

		$this->assertSame(
			'https://cdn.example.com/a.webp!style=siomedium?v=2#frag',
			$provider->decorate( $url, new Rendition( 500, 500, Rendition::MODE_CROP, 'auto', 82, 'auto', 'siomedium' ) )
		);
	}

	public function test_style_mode_without_style_keeps_url(): void {
		$provider = new Bitiful( Bitiful::MODE_STYLE );
		$url      = 'https://cdn.example.com/a.webp';

		$this->assertSame( $url, $provider->decorate( $url, new Rendition( 500, 500, Rendition::MODE_CROP ) ) );
	}

	public function test_params_mode_builds_crop_query(): void {
		$provider = new Bitiful( Bitiful::MODE_PARAMS );

		$this->assertSame(
			'https://cdn.example.com/a.webp?w=500&h=500&mode=crop&q=82',
			$provider->decorate( 'https://cdn.example.com/a.webp', new Rendition( 500, 500, Rendition::MODE_CROP, 'auto', 82 ) )
		);
	}

	public function test_pad_mode_is_not_supported(): void {
		$provider = new Bitiful( Bitiful::MODE_PARAMS );
		$url      = 'https://cdn.example.com/a.webp';

		$this->assertSame( $url, $provider->decorate( $url, new Rendition( 500, 500, Rendition::MODE_PAD ) ) );
	}
}
