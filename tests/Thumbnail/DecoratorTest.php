<?php
/**
 * Decorator tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Context;
use Sakura\ImageOptimizer\Thumbnail\Decorator;
use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Providers\Doge;
use Sakura\ImageOptimizer\Thumbnail\Settings;

/**
 * End-to-end URL decoration with fakes.
 */
final class DecoratorTest extends TestCase {

	protected function setUp(): void {
		Context::reset();
	}

	protected function tearDown(): void {
		Context::reset();
		Decorator::reset();
	}

	private function options( string $mode = 'style' ): array {
		$options = Settings::defaults();
		$options['enabled']       = 1;
		$options['engine']        = 'url';
		$options['provider_mode'] = $mode;
		$options['style_map']     = array( 'medium' => 'siomedium', 'large' => 'siolarge' );
		$options['sizes']['medium'] = array(
			'width'    => 500,
			'height'   => 500,
			'mode'     => 'crop',
			'position' => 'auto',
			'quality'  => 82,
			'format'   => 'auto',
		);
		$options['scopes']        = array( 'archive' => 1, 'sidebar' => 1, 'singular' => 0, 'other' => 0 );
		$options['advanced']['stats'] = 0;

		return $options;
	}

	private function decorator( string $mode = 'style' ): Decorator {
		$provider = new Bitiful( 'params' === $mode ? Bitiful::MODE_PARAMS : Bitiful::MODE_STYLE );

		return new Decorator( $this->options( $mode ), $provider, null, null, 'bf.example.com' );
	}

	private function image( string $url = 'https://bf.example.com/wp-content/uploads/a.webp' ): array {
		return array( $url, 800, 600, false );
	}

	public function test_rewrites_archive_medium(): void {
		Context::push( Context::ARCHIVE );
		$out = $this->decorator()->apply( $this->image(), 12, 'medium' );

		$this->assertSame( 'https://bf.example.com/wp-content/uploads/a.webp!style=siomedium', $out[0] );
		$this->assertSame( 800, $out[1] );
		$this->assertSame( 600, $out[2] );
	}

	public function test_params_mode_builds_query(): void {
		Context::push( Context::ARCHIVE );
		$out = $this->decorator( 'params' )->apply( $this->image(), 12, 'medium' );

		$this->assertStringContainsString( '?w=500&h=500&mode=crop', $out[0] );
	}

	public function test_disabled_context_is_untouched(): void {
		$image = $this->image();
		$out   = $this->decorator()->apply( $image, 12, 'medium' );

		$this->assertSame( $image, $out );
	}

	public function test_full_is_untouched(): void {
		Context::push( Context::ARCHIVE );
		$image = $this->image();
		$out   = $this->decorator()->apply( $image, 12, 'full' );

		$this->assertSame( $image, $out );
	}

	public function test_external_host_is_untouched(): void {
		Context::push( Context::ARCHIVE );
		$image = $this->image( 'https://cdn.other.com/a.webp' );
		$out   = $this->decorator()->apply( $image, 12, 'medium' );

		$this->assertSame( $image, $out );
	}

	public function test_svg_and_gif_are_untouched(): void {
		Context::push( Context::ARCHIVE );
		$decorator = $this->decorator();

		$this->assertSame( $this->image( 'https://bf.example.com/a.svg' ), $decorator->apply( $this->image( 'https://bf.example.com/a.svg' ), 0, 'medium' ) );
		$this->assertSame( $this->image( 'https://bf.example.com/a.gif' ), $decorator->apply( $this->image( 'https://bf.example.com/a.gif' ), 0, 'medium' ) );
	}

	public function test_already_optimized_is_untouched(): void {
		Context::push( Context::ARCHIVE );
		$image = $this->image( 'https://bf.example.com/a.webp!style=siothumb' );
		$out   = $this->decorator()->apply( $image, 12, 'medium' );

		$this->assertSame( $image, $out );
	}

	/**
	 * The DogeCloud carrier keeps the style as a path segment, so a URL that
	 * already ends in `a.webp/1280wm` is optimised even though its last
	 * extension-looking segment is gone.
	 */
	public function test_dogecloud_style_url_is_not_decorated_twice(): void {
		$options = $this->options( 'style' );
		$options['style_map'] = array( 'medium' => '720wm', 'large' => '1280wm' );
		$decorator = new Decorator( $options, new Doge( Doge::MODE_STYLE ), null, null, 'doge.example.com' );
		Context::push( Context::ARCHIVE );

		$image = $this->image( 'https://doge.example.com/wp-content/uploads/a.webp/1280wm' );

		$this->assertSame( $image, $decorator->apply( $image, 12, 'medium' ) );
	}

	public function test_dogecloud_decorates_a_raw_url_with_the_path_style(): void {
		$options = $this->options( 'style' );
		$options['style_map'] = array( 'medium' => '720wm', 'large' => '1280wm' );
		$decorator = new Decorator( $options, new Doge( Doge::MODE_STYLE ), null, null, 'doge.example.com' );
		Context::push( Context::ARCHIVE );

		$out = $decorator->apply( $this->image( 'https://doge.example.com/wp-content/uploads/a.webp' ), 12, 'medium' );

		$this->assertSame( 'https://doge.example.com/wp-content/uploads/a.webp/720wm', $out[0] );
	}

	public function test_dogecloud_params_url_is_not_decorated_twice(): void {
		$options = $this->options( 'params' );
		$decorator = new Decorator( $options, new Doge( Doge::MODE_PARAMS ), null, null, 'doge.example.com' );
		Context::push( Context::ARCHIVE );

		$image = $this->image( 'https://doge.example.com/wp-content/uploads/a.webp?imageView2/1/w/500/h/500' );

		$this->assertSame( $image, $decorator->apply( $image, 12, 'medium' ) );
	}

	public function test_dogecloud_params_mode_builds_image_view2(): void {
		$options = $this->options( 'params' );
		$decorator = new Decorator( $options, new Doge( Doge::MODE_PARAMS ), null, null, 'doge.example.com' );
		Context::push( Context::ARCHIVE );

		$out = $decorator->apply( $this->image( 'https://doge.example.com/wp-content/uploads/a.webp' ), 12, 'medium' );

		$this->assertStringContainsString( '?imageView2/1/w/500/h/500', $out[0] );
	}

	public function test_spare_thumb_bridge_rewrites_only_when_enabled(): void {
		$options = $this->options();
		$options['advanced']['theme_bridge'] = 1;
		$decorator = new Decorator( $options, new Bitiful( Bitiful::MODE_STYLE ), null, null, 'bf.example.com' );
		Context::push( Context::ARCHIVE );

		$this->assertSame(
			'https://bf.example.com/wp-content/uploads/spare.webp!style=siomedium',
			$decorator->apply_spare_thumb( 'https://bf.example.com/wp-content/uploads/spare.webp' )
		);
	}

	public function test_content_image_requires_html_processor(): void {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor is not available in the unit test bootstrap.' );
		}

		$options = $this->options();
		$options['advanced']['content_images'] = 1;
		$decorator = new Decorator( $options, new Bitiful( Bitiful::MODE_STYLE ), null, null, 'bf.example.com' );
		Context::push( Context::ARCHIVE );
		$tag = '<img src="https://bf.example.com/wp-content/uploads/a.webp" width="400" height="300">';

		$this->assertStringContainsString( '!style=siomedium', $decorator->apply_content_image( $tag, 0 ) );
	}

	public function test_engine_off_is_untouched(): void {
		Context::push( Context::ARCHIVE );
		$options = $this->options();
		$options['engine'] = 'off';
		$decorator = new Decorator( $options, new Bitiful( Bitiful::MODE_STYLE ), null, null, 'bf.example.com' );
		$image = $this->image();

		$this->assertSame( $image, $decorator->apply( $image, 12, 'medium' ) );
	}

	public function test_content_scene_uses_configured_size(): void {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor is not available in the unit test bootstrap.' );
		}

		$options = $this->options();
		$options['advanced']['content_images'] = 1;
		$options['scenes']['content']          = 'large';
		$decorator = new Decorator( $options, new Bitiful( Bitiful::MODE_STYLE ), null, null, 'bf.example.com' );
		Context::push( Context::ARCHIVE );
		$tag = '<img src="https://bf.example.com/wp-content/uploads/a.webp" width="400" height="300">';

		$this->assertStringContainsString( '!style=siolarge', $decorator->apply_content_image( $tag, 0 ) );
	}

	public function test_content_scene_without_style_falls_back(): void {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor is not available in the unit test bootstrap.' );
		}

		$options = $this->options();
		$options['advanced']['content_images'] = 1;
		$options['scenes']['content']          = 'medium_large';

		$decorator = new Decorator( $options, new Bitiful( Bitiful::MODE_STYLE ), null, null, 'bf.example.com' );
		Context::push( Context::ARCHIVE );
		$tag = '<img src="https://bf.example.com/wp-content/uploads/a.webp" width="400" height="300">';

		$this->assertStringContainsString( '!style=siomedium', $decorator->apply_content_image( $tag, 0 ) );
	}
}
