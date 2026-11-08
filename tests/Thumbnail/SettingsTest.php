<?php
/**
 * Settings tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Provider_Status;
use Sakura\ImageOptimizer\Thumbnail\Settings;

/**
 * Migration, normalization and effective mode.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		Settings::reset();
		$GLOBALS['sakura_test_options'] = array();
	}

	protected function tearDown(): void {
		Settings::reset();
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_defaults_are_v2(): void {
		$options = Settings::defaults();

		$this->assertSame( 2, $options['schema'] );
		$this->assertSame( 0, $options['enabled'] );
		$this->assertSame( 'url', $options['engine'] );
		$this->assertSame( 1, $options['scopes']['archive'] );
		$this->assertSame( 0, $options['scopes']['singular'] );
		$this->assertSame( 'siothumb', $options['style_map']['medium'] );
		$this->assertSame( 250, $options['sizes']['medium']['width'] );
		$this->assertSame( 0, $options['advanced']['theme_bridge'] );
		$this->assertArrayNotHasKey( 'srcset', $options['advanced'] );
	}

	public function test_srcset_is_dropped_when_normalizing_legacy_input(): void {
		$options = Settings::normalize(
			array(
				'advanced' => array(
					'srcset' => 1,
				),
			)
		);

		$this->assertArrayNotHasKey( 'srcset', $options['advanced'] );
	}

	public function test_object_cache_backend_shape(): void {
		$backend = Settings::object_cache_backend();

		$this->assertArrayHasKey( 'type', $backend );
		$this->assertArrayHasKey( 'label', $backend );
		$this->assertArrayHasKey( 'group_flush', $backend );
		$this->assertIsBool( $backend['group_flush'] );
	}

	public function test_object_cache_backend_recognizes_memcached(): void {
		$backend = Settings::object_cache_backend( 'MemcachedObjectCache' );

		$this->assertSame( 'memcached', $backend['type'] );
		$this->assertSame( 'Memcached', $backend['label'] );
	}

	public function test_migrates_v1_fields(): void {
		$raw = array(
			'enabled'             => 1,
			'sidebar_enabled'     => 1,
			'thumb_w'             => 300,
			'thumb_h'             => 200,
			'thumb_crop'          => 1,
			'medium_w'            => 600,
			'medium_h'            => 0,
			'medium_crop'         => 0,
			'large_w'             => 0,
			'large_h'             => 0,
			'large_crop'          => 0,
			'pos'                 => 'center',
			'fallback_card_custom' => '?w={w}&q={q}',
			'oss_host'            => 'bf.example.com',
		);

		$options = Settings::migrate( $raw );

		$this->assertSame( 'html', $options['engine'] );
		$this->assertSame( 1, $options['enabled'] );
		$this->assertSame( 1, $options['scopes']['archive'] );
		$this->assertSame( 1, $options['scopes']['sidebar'] );
		$this->assertSame( 300, $options['sizes']['thumbnail']['width'] );
		$this->assertSame( 200, $options['sizes']['thumbnail']['height'] );
		$this->assertSame( 600, $options['sizes']['medium']['width'] );
		$this->assertSame( 800, $options['sizes']['large']['width'] );
		$this->assertSame( 'center', $options['sizes']['large']['position'] );
		$this->assertSame( '?w={w}&q={q}', $options['advanced']['custom_provider_template'] );
		$this->assertSame( $raw, $options['legacy'] );
	}

	public function test_custom_size_keeps_zero_height(): void {
		$options = Settings::normalize(
			array(
				'schema' => 2,
				'sizes'  => array(
					'customx' => array( 'width' => 123, 'height' => 0, 'mode' => 'fit' ),
				),
			)
		);

		$this->assertSame( 123, $options['sizes']['customx']['width'] );
		$this->assertSame( 0, $options['sizes']['customx']['height'] );
		$this->assertSame( 'fit', $options['sizes']['customx']['mode'] );
	}

	public function test_row_style_is_moved_to_style_map(): void {
		$options = Settings::normalize(
			array(
				'schema'    => 2,
				'style_map' => array(),
				'sizes'     => array(
					'medium' => array( 'width' => 500, 'height' => 500, 'style' => 'siomedium' ),
				),
			)
		);

		$this->assertSame( 'siomedium', $options['style_map']['medium'] );
	}

	public function test_effective_mode_uses_ok_probe(): void {
		update_option(
			's3_options',
			array(
				'provider'        => 'bitiful',
				'upload_url_path' => 'https://bf.example.com/wp-content/uploads',
			)
		);
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::store_probe(
			new Provider_Status( Provider_Status::MODE_STYLE, Provider_Status::STATE_OK, 'test' )
		);
		Settings::reset();

		$this->assertSame( 'style', Settings::effective_mode() );
	}

	public function test_effective_mode_never_pretends_without_probe(): void {
		update_option(
			's3_options',
			array(
				'provider'        => 'bitiful',
				'upload_url_path' => 'https://bf.example.com/wp-content/uploads',
			)
		);
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::reset();

		$this->assertSame( 'none', Settings::effective_mode() );
	}

	public function test_effective_mode_requires_host(): void {
		update_option( 's3_options', array( 'provider' => 'bitiful' ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::store_probe(
			new Provider_Status( Provider_Status::MODE_STYLE, Provider_Status::STATE_OK, 'test' )
		);
		Settings::reset();

		$this->assertSame( 'none', Settings::effective_mode() );
	}

	public function test_scenes_default_to_follow_theme(): void {
		$options = Settings::defaults();

		$this->assertSame( array( 'thumb' => '', 'sidebar' => '', 'content' => '' ), $options['scenes'] );
	}

	public function test_scenes_accept_configured_size(): void {
		$options = Settings::normalize(
			array(
				'schema' => 2,
				'scenes' => array( 'sidebar' => 'medium_large' ),
			)
		);

		$this->assertSame( 'medium_large', $options['scenes']['sidebar'] );
		$this->assertSame( '', $options['scenes']['thumb'] );
	}

	public function test_scenes_reject_unknown_size(): void {
		$options = Settings::normalize(
			array(
				'schema' => 2,
				'scenes' => array(
					'thumb'   => 'nope',
					'content' => 'large',
				),
			)
		);

		$this->assertSame( '', $options['scenes']['thumb'] );
		$this->assertSame( 'large', $options['scenes']['content'] );
	}

	public function test_sanitize_keeps_scenes(): void {
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::reset();

		$sanitized = Settings::sanitize(
			array(
				'enabled' => '1',
				'scenes'  => array( 'sidebar' => 'medium_large' ),
			)
		);

		$this->assertSame( 1, $sanitized['enabled'] );
		$this->assertSame( 'medium_large', $sanitized['scenes']['sidebar'] );
	}

	public function test_cache_groups_include_zibll_bbs_html(): void {
		$groups = Settings::cache_groups();

		$this->assertContains( 'post_thumbnail_url_large', $groups );
		$this->assertContains( 'post_multi_thumbnail', $groups );
	}

	public function test_flush_caches_includes_zibll_bbs_group(): void {
		$GLOBALS['sakura_test_flushed_groups'] = array();

		$flushed = new ReflectionProperty( Settings::class, 'caches_flushed' );
		$flushed->setAccessible( true );
		$flushed->setValue( null, false );

		$last = new ReflectionProperty( Settings::class, 'last_flush' );
		$last->setAccessible( true );
		$last->setValue( null, null );

		$report = Settings::flush_caches();

		$this->assertContains( 'post_multi_thumbnail', $GLOBALS['sakura_test_flushed_groups'] );
		$this->assertSame( count( Settings::cache_groups() ), $report['requested_groups'] );
		$this->assertSame( 'ok', $report['cache_state'] );

		$flushed->setValue( null, false );
		$last->setValue( null, null );
	}
}
