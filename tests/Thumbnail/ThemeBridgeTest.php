<?php
/**
 * Theme bridge tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail\Stats;
use Sakura\ImageOptimizer\Thumbnail\Theme_Bridge;

/**
 * Option-read bridge for zibll spare thumbnails.
 */
final class ThemeBridgeTest extends TestCase {

	protected function setUp(): void {
		Settings::reset();
		Theme_Bridge::reset_cache();
		$GLOBALS['sakura_test_options'] = array();
	}

	protected function tearDown(): void {
		Settings::reset();
		Theme_Bridge::reset_cache();
		$GLOBALS['sakura_test_options'] = array();
	}

	private function enable_bridge(): void {
		$options = Settings::defaults();
		$options['enabled']                      = 1;
		$options['provider_mode']                = 'style';
		$options['advanced']['theme_bridge']     = 1;
		$options['style_map']                    = array( 'medium' => 'siomedium' );
		$options['sizes']['medium']['width']     = 500;
		$options['sizes']['medium']['height']    = 500;
		$options['sizes']['medium']['mode']      = 'crop';

		update_option( Settings::OPTION, Settings::normalize( $options ) );
		update_option(
			's3_options',
			array(
				'provider'        => 'bitiful',
				'upload_url_path' => 'https://bf.example.com/wp-content/uploads',
			)
		);
		Settings::reset();
	}

	public function test_disabled_bridge_keeps_theme_options(): void {
		$value = array(
			'spare_thumbnail' => array(
				array( 'img' => 'https://bf.example.com/wp-content/uploads/a.webp' ),
			),
		);

		$this->assertSame( $value, Theme_Bridge::filter_options( $value ) );
	}

	public function test_enabled_bridge_rewrites_spare_thumbnail_on_read(): void {
		$this->enable_bridge();
		$value = array(
			'spare_thumbnail' => array(
				array( 'img' => 'https://bf.example.com/wp-content/uploads/a.webp' ),
			),
			'other'           => 'untouched',
		);

		$out = Theme_Bridge::filter_options( $value );

		$this->assertSame( 'https://bf.example.com/wp-content/uploads/a.webp!style=siomedium', $out['spare_thumbnail'][0]['img'] );
		$this->assertSame( 'untouched', $out['other'] );
	}

	public function test_bridge_does_not_record_stats_on_option_read(): void {
		$this->enable_bridge();
		$before = Stats::summary();
		Theme_Bridge::filter_options(
			array(
				'spare_thumbnail' => array(
					array( 'img' => 'https://bf.example.com/wp-content/uploads/a.webp' ),
					array( 'img' => 'https://bf.example.com/wp-content/uploads/b.webp' ),
				),
			)
		);
		$after = Stats::summary();

		$this->assertSame( $before['rewrite'], $after['rewrite'] );
	}
}
