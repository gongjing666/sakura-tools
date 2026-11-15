<?php
/**
 * Media library thumbnail tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Admin_Thumbnails;
use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Providers\Doge;
use Sakura\ImageOptimizer\Thumbnail\Providers\Generic;
use Sakura\ImageOptimizer\Thumbnail\Settings;

/**
 * The media library asks image_downsize() for both views; every case here is
 * one shape that filter has to survive.
 */
final class AdminThumbnailsTest extends TestCase {

	private const HOST = 'doge.nboke.com';

	private const URL = 'https://doge.nboke.com/wp-content/uploads/2026/11/a.webp';

	protected function setUp(): void {
		Admin_Thumbnails::reset();
		$GLOBALS['sakura_test_is_admin']          = true;
		$GLOBALS['sakura_test_doing_ajax']        = false;
		$GLOBALS['sakura_test_attachment_urls']   = array( 12 => self::URL );
		$GLOBALS['sakura_test_attachment_is_image'] = array();
	}

	protected function tearDown(): void {
		Admin_Thumbnails::reset();
		Settings::reset();
		$GLOBALS['sakura_test_options'] = array();
		unset(
			$GLOBALS['sakura_test_is_admin'],
			$GLOBALS['sakura_test_doing_ajax'],
			$GLOBALS['sakura_test_attachment_urls'],
			$GLOBALS['sakura_test_attachment_is_image']
		);
	}

	private function options( string $mode = 'style' ): array {
		$options = Settings::defaults();
		$options['enabled']       = 1;
		$options['engine']        = 'url';
		$options['provider_mode'] = $mode;
		$options['style_map']     = array(
			'thumbnail'     => 'siothumb',
			'medium'        => 'siothumb',
			'large'         => 'siolarge',
			'medium_large'  => 'siomedium',
		);
		$options['advanced']['stats'] = 0;

		return $options;
	}

	private function thumbnails( string $mode = 'style', ?array $options = null ): Admin_Thumbnails {
		$provider = new Doge( 'params' === $mode ? Doge::MODE_PARAMS : Doge::MODE_STYLE );

		return new Admin_Thumbnails( $options ?? $this->options( $mode ), $provider, null, self::HOST );
	}

	public function test_named_style_applies_to_a_size_name(): void {
		$out = $this->thumbnails()->apply( 12, 'thumbnail', false );

		$this->assertSame( self::URL . '/siothumb', $out[0] );
		$this->assertTrue( $out[3] );
	}

	/**
	 * The grid keeps a size only when the fourth element is truthy, so a
	 * falsy flag silently drops the candidate and the grid shows the original.
	 */
	public function test_fourth_element_is_truthy_for_the_grid(): void {
		$out = $this->thumbnails()->apply( 12, 'large', false );

		$this->assertTrue( $out[3] );
		$this->assertSame( self::URL . '/siolarge', $out[0] );
	}

	/**
	 * The list table asks for a bare pixel box with no size name, which cannot
	 * reach a named style on its own.
	 */
	public function test_pixel_box_maps_onto_the_configured_thumbnail(): void {
		$out = $this->thumbnails()->apply( 12, array( 60, 60 ), false );

		$this->assertSame( self::URL . '/siothumb', $out[0] );
		$this->assertSame( 60, $out[1] );
		$this->assertSame( 60, $out[2] );
		$this->assertTrue( $out[3] );
	}

	public function test_params_mode_builds_image_view2(): void {
		$out = $this->thumbnails( 'params' )->apply( 12, 'thumbnail', false );

		$this->assertStringContainsString( '?imageView2/', $out[0] );
		$this->assertStringContainsString( '/w/250', $out[0] );
		$this->assertTrue( $out[3] );
	}

	public function test_disabled_engine_returns_the_original_value(): void {
		$options             = $this->options();
		$options['enabled']  = 0;

		$this->assertFalse( $this->thumbnails( 'style', $options )->apply( 12, 'thumbnail', false ) );
	}

	public function test_html_engine_returns_the_original_value(): void {
		$options           = $this->options();
		$options['engine'] = 'html';

		$this->assertFalse( $this->thumbnails( 'style', $options )->apply( 12, 'thumbnail', false ) );
	}

	/**
	 * Local URLs mean the object is not in the bucket yet; decorating them
	 * would point the style at a missing object.
	 */
	public function test_off_host_url_is_left_alone(): void {
		$GLOBALS['sakura_test_attachment_urls'] = array( 12 => 'https://nboke.com/wp-content/uploads/a.webp' );

		$this->assertFalse( $this->thumbnails()->apply( 12, 'thumbnail', false ) );
	}

	public function test_non_image_attachment_is_left_alone(): void {
		$GLOBALS['sakura_test_attachment_is_image'] = array( 12 => false );

		$this->assertFalse( $this->thumbnails()->apply( 12, 'thumbnail', false ) );
	}

	/**
	 * A size with no row and no upsafe fallback stays alone; the size 250x250
	 * row is larger than this 1200x800 original only for `large`.
	 */
	public function test_size_that_would_upscale_is_left_alone(): void {
		$options                       = $this->options();
		$options['sizes']['large']     = array(
			'width'    => 4000,
			'height'   => 0,
			'mode'     => 'fit',
			'position' => 'auto',
			'quality'  => 82,
			'format'   => 'auto',
		);

		$this->assertFalse( $this->thumbnails( 'style', $options )->apply( 12, 'large', false ) );
	}

	/**
	 * normalize() always restores the four default rows, so an unconfigured
	 * size name is the realistic miss (a theme asking for post-thumbnail).
	 */
	public function test_size_name_without_a_row_is_left_alone(): void {
		$this->assertFalse( $this->thumbnails()->apply( 12, 'post-thumbnail', false ) );
	}

	/**
	 * Another plugin answering first owns those bytes; relabelling them would
	 * mismatch the URL against the reported dimensions.
	 */
	public function test_earlier_filter_answer_is_preserved(): void {
		$existing = array( 'https://cdn.example.com/a.webp', 10, 10, true );

		$this->assertSame( $existing, Admin_Thumbnails::filter( $existing, 12, 'thumbnail' ) );
	}

	public function test_front_end_requests_are_not_touched(): void {
		$GLOBALS['sakura_test_is_admin'] = false;

		$this->assertFalse( Admin_Thumbnails::filter( false, 12, 'thumbnail' ) );
	}

	public function test_filter_delegates_for_admin_requests(): void {
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = $this->options();
		$GLOBALS['sakura_test_options']['s3_options']         = array(
			'provider'        => 'dogecloud',
			'upload_url_path' => 'https://' . self::HOST,
		);
		Settings::reset();
		Admin_Thumbnails::reset();

		$out = Admin_Thumbnails::filter( false, 12, 'thumbnail' );

		$this->assertIsArray( $out );
		$this->assertSame( self::URL . '/siothumb', $out[0] );
		$this->assertTrue( $out[3] );
	}

	/**
	 * A provider that cannot express anything must fall back rather than emit
	 * a URL the bucket will 404. Generic with an empty template is that case.
	 */
	public function test_inert_provider_leaves_value_alone(): void {
		$thumbs = new Admin_Thumbnails( $this->options(), new Generic(), null, self::HOST );

		$this->assertFalse( $thumbs->apply( 12, 'thumbnail', false ) );
	}

	/**
	 * Named styles are a style-mode concept; in params mode the provider builds
	 * a parameter URL instead, exactly like the front-end decorator does.
	 */
	public function test_params_mode_ignores_the_style_map(): void {
		$thumbs = new Admin_Thumbnails( $this->options(), new Bitiful( Bitiful::MODE_PARAMS ), null, self::HOST );
		$out    = $thumbs->apply( 12, 'thumbnail', false );

		$this->assertStringContainsString( '?w=250', $out[0] );
		$this->assertStringNotContainsString( 'siothumb', $out[0] );
	}

	public function test_zero_attachment_id_is_ignored(): void {
		$this->assertFalse( $this->thumbnails()->apply( 0, 'thumbnail', false ) );
	}

	/**
	 * A styled SVG path answers 400 from the bucket, so the media library has
	 * to leave SVGs alone exactly like the front-end decorator does.
	 */
	public function test_svg_attachment_is_left_alone(): void {
		$GLOBALS['sakura_test_attachment_urls'] = array( 12 => 'https://' . self::HOST . '/wp-content/uploads/2026/08/a.svg' );

		$this->assertFalse( $this->thumbnails()->apply( 12, 'thumbnail', false ) );
	}

	/**
	 * The rendition converts to WebP, which freezes a GIF. The `animated`
	 * switch is the user opt-in for that trade-off.
	 */
	public function test_gif_attachment_is_left_alone_unless_animated_is_allowed(): void {
		$GLOBALS['sakura_test_attachment_urls'] = array( 12 => 'https://' . self::HOST . '/wp-content/uploads/2026/07/a.gif' );

		$this->assertFalse( $this->thumbnails()->apply( 12, 'thumbnail', false ) );

		$options                         = $this->options();
		$options['advanced']['animated'] = 1;

		$out = $this->thumbnails( 'style', $options )->apply( 12, 'thumbnail', false );

		$this->assertSame( 'https://' . self::HOST . '/wp-content/uploads/2026/07/a.gif/siothumb', $out[0] );
	}
}
