<?php
/**
 * DogeCloud provider tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Providers\Doge;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

/**
 * URL syntax: named style is a path segment, parameters use imageView2.
 */
final class DogeTest extends TestCase {

	public function test_style_is_appended_as_a_path_segment(): void {
		$provider = new Doge( Doge::MODE_STYLE );

		$this->assertSame(
			'https://doge.example.com/a.jpg/720wm',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 0, 0, Rendition::MODE_FIT, 'auto', 0, 'auto', '720wm' ) )
		);
	}

	/**
	 * The console hands out rule names that already start with the separator;
	 * saving that verbatim must not produce a double slash.
	 */
	public function test_style_with_leading_slash_is_not_doubled(): void {
		$provider = new Doge( Doge::MODE_STYLE );

		$this->assertSame(
			'https://doge.example.com/a.jpg/720wm',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 0, 0, Rendition::MODE_FIT, 'auto', 0, 'auto', '/720wm' ) )
		);
	}

	public function test_style_goes_before_query_and_fragment(): void {
		$provider = new Doge( Doge::MODE_STYLE );

		$this->assertSame(
			'https://doge.example.com/a.jpg/720wm?v=2#frag',
			$provider->decorate( 'https://doge.example.com/a.jpg?v=2#frag', new Rendition( 0, 0, Rendition::MODE_FIT, 'auto', 0, 'auto', '720wm' ) )
		);
	}

	public function test_style_mode_without_style_keeps_url(): void {
		$provider = new Doge( Doge::MODE_STYLE );
		$url      = 'https://doge.example.com/a.jpg';

		$this->assertSame( $url, $provider->decorate( $url, new Rendition( 400, 400, Rendition::MODE_CROP ) ) );
	}

	/**
	 * DogeCloud ignores bare `?w=`/`?h=` (it answers 200 with the original
	 * bytes), so the parameter mode has to speak imageView2.
	 */
	public function test_crop_uses_image_view2_mode_1(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/1/w/400/h/300',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 400, 300, Rendition::MODE_CROP ) )
		);
	}

	public function test_fit_uses_image_view2_mode_2(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/2/w/400/h/300',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 400, 300, Rendition::MODE_FIT ) )
		);
	}

	public function test_single_width_and_height_keep_the_ratio(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/2/w/400',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 400, 0 ) )
		);
		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/2/h/300',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 0, 300 ) )
		);
	}

	public function test_quality_and_webp_are_appended_in_order(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/1/w/400/h/300/q/82/format/webp',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 400, 300, Rendition::MODE_CROP, 'auto', 82, 'webp' ) )
		);
	}

	/**
	 * imageView2 has no avif target, so promising one would ship a URL that
	 * silently returns JPEG.
	 */
	public function test_avif_is_not_requested(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?imageView2/2/w/400',
			$provider->decorate( 'https://doge.example.com/a.jpg', new Rendition( 400, 0, Rendition::MODE_FIT, 'auto', 0, 'avif' ) )
		);
	}

	public function test_pad_mode_is_not_supported(): void {
		$provider = new Doge( Doge::MODE_PARAMS );
		$url      = 'https://doge.example.com/a.jpg';

		$this->assertSame( $url, $provider->decorate( $url, new Rendition( 500, 500, Rendition::MODE_PAD ) ) );
	}

	public function test_parameters_merge_with_an_existing_query(): void {
		$provider = new Doge( Doge::MODE_PARAMS );

		$this->assertSame(
			'https://doge.example.com/a.jpg?v=2&imageView2/2/w/400',
			$provider->decorate( 'https://doge.example.com/a.jpg?v=2', new Rendition( 400, 0 ) )
		);
	}

	public function test_capabilities_follow_the_mode(): void {
		$style  = new Doge( Doge::MODE_STYLE );
		$params = new Doge( Doge::MODE_PARAMS );

		$this->assertTrue( $style->supports( 'style' ) );
		$this->assertFalse( $style->supports( 'quality' ) );
		$this->assertTrue( $params->supports( 'quality' ) );
		$this->assertFalse( $params->supports( 'style' ) );
		$this->assertFalse( $params->supports( 'format' ) );
		$this->assertSame( 'dogecloud', $params->id() );
	}
}
