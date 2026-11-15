<?php

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests\Thumbnail;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Context;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail\Theme_Cache;

final class ThemeCacheTest extends TestCase {

	private const RAW = 'https://bf.nboke.com/wp-content/uploads/2026/07/20260705-195251-6ejiA.webp';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sakura_test_cache'] = array();
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = array_merge(
			Settings::defaults(),
			array(
				'engine'        => 'url',
				'provider_mode' => 'style',
				'style_map'     => array(
					'medium' => 'siothumb',
					'large'  => 'siolarge',
				),
			)
		);
		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'provider'        => 'bitiful',
			'upload_url_path' => 'https://bf.nboke.com/wp-content/uploads',
		);
		Settings::reset();
		Context::reset();
		Context::push( Context::SIDEBAR );
	}

	protected function tearDown(): void {
		Context::reset();
		Settings::reset();
		parent::tearDown();
	}

	public function test_guard_repairs_raw_theme_cache_in_place(): void {
		wp_cache_set( 1, self::RAW, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$url = wp_cache_get( 1, 'post_thumbnail_url_large' );
		$this->assertIsString( $url );
		$this->assertStringContainsString( '!style=siolarge', $url );
	}

	public function test_guard_leaves_already_optimized_url(): void {
		$url = self::RAW . '!style=siolarge';
		wp_cache_set( 1, $url, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$this->assertSame( $url, wp_cache_get( 1, 'post_thumbnail_url_large' ) );
	}

	public function test_guard_does_not_touch_external_url(): void {
		$url = 'https://bf.77il.cn/wp-content/uploads/2024/08/20240804171208635.webp';
		wp_cache_set( 44, $url, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 44 );

		$this->assertSame( $url, wp_cache_get( 44, 'post_thumbnail_url_large' ) );
	}

	public function test_custom_loop_inherits_sidebar_context(): void {
		$query = new class() {
			public function is_main_query(): bool {
				return false;
			}
		};

		Context::handle_loop_start( $query );

		$this->assertSame( Context::SIDEBAR, Context::current() );
		Context::pop();
	}

	public function test_guard_replaces_stale_style_when_scene_changes(): void {
		$options = $GLOBALS['sakura_test_options'][ Settings::OPTION ];
		$options['scenes']['sidebar'] = 'medium';
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = $options;
		Settings::reset();

		wp_cache_set( 1, self::RAW . '!style=siolarge', 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$url = wp_cache_get( 1, 'post_thumbnail_url_large' );
		$this->assertIsString( $url );
		$this->assertStringContainsString( '!style=siothumb', $url );
		$this->assertStringNotContainsString( 'siolarge', $url );
	}

	/**
	 * DogeCloud keeps its style as a path segment (`a.jpg/720wm`), which no
	 * longer ends in an image extension. A theme cache holding that URL is
	 * already optimised and must not be rewritten with a second style.
	 */
	public function test_guard_leaves_dogecloud_style_url_alone(): void {
		$options = $GLOBALS['sakura_test_options'][ Settings::OPTION ];
		$options['style_map'] = array(
			'medium' => '720wm',
			'large'  => '1280wm',
		);
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = $options;
		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'provider'        => 'dogecloud',
			'upload_url_path' => 'https://doge.nboke.com/wp-content/uploads',
		);
		Settings::reset();

		$url = 'https://doge.nboke.com/wp-content/uploads/2026/07/a.webp/1280wm';
		wp_cache_set( 1, $url, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$this->assertSame( $url, wp_cache_get( 1, 'post_thumbnail_url_large' ) );
	}

	/**
	 * The theme caches URLs for days. A cache entry written while the plugin
	 * still used imageView2 must be rewritten to the style the site uses now —
	 * and `attachment_url_to_postid()` only finds the attachment once the
	 * slash-separated payload is stripped, not parse_str()'d into a key.
	 */
	public function test_guard_replaces_a_stale_imageview2_cache_entry(): void {
		$options = $GLOBALS['sakura_test_options'][ Settings::OPTION ];
		$options['style_map'] = array(
			'medium' => '720wm',
			'large'  => '1280wm',
		);
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = $options;
		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'provider'        => 'dogecloud',
			'upload_url_path' => 'https://doge.nboke.com/wp-content/uploads',
		);
		Settings::reset();

		$stale = 'https://doge.nboke.com/wp-content/uploads/2026/07/20260705-195251-6ejiA.webp?imageView2/1/w/250/h/250/q/82';
		wp_cache_set( 1, $stale, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$url = wp_cache_get( 1, 'post_thumbnail_url_large' );
		$this->assertIsString( $url );
		$this->assertStringContainsString( '/1280wm', $url );
		$this->assertStringNotContainsString( 'imageView2', $url );
	}

	public function test_guard_repairs_dogecloud_raw_url_with_the_path_style(): void {
		$options = $GLOBALS['sakura_test_options'][ Settings::OPTION ];
		$options['style_map'] = array(
			'medium' => '720wm',
			'large'  => '1280wm',
		);
		$GLOBALS['sakura_test_options'][ Settings::OPTION ] = $options;
		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'provider'        => 'dogecloud',
			'upload_url_path' => 'https://doge.nboke.com/wp-content/uploads',
		);
		Settings::reset();

		$raw = 'https://doge.nboke.com/wp-content/uploads/2026/07/a.webp';
		wp_cache_set( 1, $raw, 'post_thumbnail_url_large' );

		Theme_Cache::guard( 1 );

		$this->assertSame( $raw . '/1280wm', wp_cache_get( 1, 'post_thumbnail_url_large' ) );
	}
}
