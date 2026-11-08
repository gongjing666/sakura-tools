<?php
/**
 * Policy tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Policy;
use Sakura\ImageOptimizer\Thumbnail\Rendition;
use Sakura\ImageOptimizer\Thumbnail\Settings;

/**
 * Context and size resolution.
 */
final class PolicyTest extends TestCase {

	private function options(): array {
		$options = Settings::defaults();
		$options['enabled'] = 1;
		$options['scopes']  = array( 'archive' => 1, 'sidebar' => 1, 'singular' => 0, 'other' => 0 );
		$options['sizes']['medium'] = array(
			'width'    => 500,
			'height'   => 500,
			'mode'     => Rendition::MODE_CROP,
			'position' => 'auto',
			'quality'  => 82,
			'format'   => 'auto',
		);
		$options['style_map']['medium'] = 'siomedium';

		return $options;
	}

	public function test_archive_medium_uses_crop_and_style(): void {
		$policy    = new Policy( $this->options() );
		$rendition = $policy->resolve( 'archive', 'medium', 1200, 800, 800, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 500, $rendition->width );
		$this->assertSame( 500, $rendition->height );
		$this->assertSame( Rendition::MODE_CROP, $rendition->mode );
		$this->assertSame( 'siomedium', $rendition->style );
	}

	public function test_disabled_context_returns_null(): void {
		$policy = new Policy( $this->options() );

		$this->assertNull( $policy->resolve( 'singular', 'medium', 1200, 800, 800, 'style' ) );
	}

	public function test_full_is_skipped_unless_capped(): void {
		$policy = new Policy( $this->options() );
		$this->assertNull( $policy->resolve( 'archive', 'full', 2000, 1500, 2000, 'style' ) );

		$options = $this->options();
		$options['advanced']['full_cap'] = 1;
		$rendition = ( new Policy( $options ) )->resolve( 'archive', 'full', 2000, 1500, 2000, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 800, $rendition->width );
		$this->assertSame( 'siolarge', $rendition->style );
	}

	public function test_crop_never_upscales(): void {
		$policy = new Policy( $this->options() );

		$this->assertNull( $policy->resolve( 'archive', 'medium', 400, 400, 400, 'style' ) );
	}

	public function test_unknown_size_falls_back_to_dpr_width(): void {
		$policy    = new Policy( $this->options() );
		$rendition = $policy->resolve( 'archive', 'custom-size', 2000, 1000, 300, 'params' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 450, $rendition->width );
		$this->assertSame( Rendition::MODE_FIT, $rendition->mode );
	}

	public function test_style_mode_requires_style_mapping(): void {
		$options = $this->options();
		$options['style_map'] = array();
		$policy = new Policy( $options );

		$this->assertNull( $policy->resolve( 'archive', 'medium', 1200, 800, 800, 'style' ) );
	}

	public function test_native_mode_returns_null(): void {
		$policy = new Policy( $this->options() );

		$this->assertNull( $policy->resolve( 'archive', 'medium', 1200, 800, 800, 'native' ) );
	}

	public function test_thumbnail_scene_replaces_archive_size(): void {
		$options = $this->options();
		$options['scenes']['thumb'] = 'large';

		$rendition = ( new Policy( $options ) )->resolve( 'archive', 'medium', 2000, 1500, 300, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 800, $rendition->width );
		$this->assertSame( 'siolarge', $rendition->style );
	}

	public function test_sidebar_scene_replaces_requested_size(): void {
		$options = $this->options();
		$options['scenes']['sidebar'] = 'medium';

		$rendition = ( new Policy( $options ) )->resolve( 'sidebar', 'large', 1200, 800, 800, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 500, $rendition->width );
		$this->assertSame( 'siomedium', $rendition->style );
	}

	public function test_thumbnail_scene_does_not_touch_singular(): void {
		$options = $this->options();
		$options['scopes']['singular'] = 1;
		$options['scenes']['thumb']    = 'medium';

		$rendition = ( new Policy( $options ) )->resolve( 'singular', 'large', 1200, 800, 800, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 'siolarge', $rendition->style );
	}

	public function test_content_scene_replaces_large(): void {
		$options = $this->options();
		$options['scenes']['content'] = 'medium';

		$rendition = ( new Policy( $options ) )->resolve( 'singular', 'large', 2000, 1500, 1200, 'style', true, Policy::SCENE_CONTENT );

		$this->assertNotNull( $rendition );
		$this->assertSame( 500, $rendition->width );
		$this->assertSame( 'siomedium', $rendition->style );
	}

	public function test_scene_ignores_missing_target(): void {
		$options = $this->options();
		$options['scenes']['sidebar'] = 'deleted';

		$rendition = ( new Policy( $options ) )->resolve( 'sidebar', 'large', 1200, 800, 800, 'style' );

		$this->assertNotNull( $rendition );
		$this->assertSame( 'siolarge', $rendition->style );
	}
}
