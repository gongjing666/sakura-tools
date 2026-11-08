<?php
/**
 * Probe invalidation when the off-site target changes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail_Optimizer_Module;

require_once dirname( __DIR__ ) . '/includes/class-thumbnail-optimizer-module.php';

/**
 * A probe result and cached URLs describe one provider/host pair, not "S3" in
 * general: changing any of them must invalidate both.
 */
final class ThumbnailProbeInvalidationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options']    = array();
		$GLOBALS['sakura_test_transients'] = array();
		$this->reset_flush_state();
	}

	/** A new provider invalidates the probe and the cached URLs. */
	public function test_provider_change_resets_probe_and_cache(): void {
		$this->seed_probe();

		( new Thumbnail_Optimizer_Module() )->on_s3_options_changed(
			array( 'provider' => 'bitiful' ),
			array( 'provider' => 'aliyun' )
		);

		$this->assertFalse( get_option( Settings::PROBE_OPTION ) );
		$this->assertNotFalse( get_transient( 'sakura_imgopt_thumb_probe_reset' ) );
		$this->assertCacheFlushed();
	}

	/** A new image host invalidates cached `post_thumbnail_url_*` values. */
	public function test_host_change_resets_probe_and_cache(): void {
		$this->seed_probe();

		( new Thumbnail_Optimizer_Module() )->on_s3_options_changed(
			array( 'upload_url_path' => 'https://a.example.com/uploads' ),
			array( 'upload_url_path' => 'https://b.example.com/uploads' )
		);

		$this->assertFalse( get_option( Settings::PROBE_OPTION ) );
		$this->assertCacheFlushed();
	}

	/** A new endpoint points at different storage, so the probe is stale too. */
	public function test_endpoint_change_resets_probe(): void {
		$this->seed_probe();

		( new Thumbnail_Optimizer_Module() )->on_s3_options_changed(
			array( 'endpoint' => 'https://s3-a.example.com' ),
			array( 'endpoint' => 'https://s3-b.example.com' )
		);

		$this->assertFalse( get_option( Settings::PROBE_OPTION ) );
		$this->assertCacheFlushed();
	}

	/** Renaming a bucket alone does not invalidate the probe. */
	public function test_unrelated_change_keeps_probe(): void {
		$this->seed_probe();

		( new Thumbnail_Optimizer_Module() )->on_s3_options_changed(
			array( 'bucket' => 'old-bucket' ),
			array( 'bucket' => 'new-bucket' )
		);

		$this->assertIsArray( get_option( Settings::PROBE_OPTION ) );
		$this->assertFalse( get_transient( 'sakura_imgopt_thumb_probe_reset' ) );
	}

	/**
	 * Seed a passing probe so "deleted" means something.
	 */
	private function seed_probe(): void {
		update_option(
			Settings::PROBE_OPTION,
			array(
				'mode'       => 'style',
				'state'      => 'ok',
				'checked_at' => time(),
			)
		);
		delete_option( Settings::FLUSH_OPTION );
		$this->reset_flush_state();
	}

	/**
	 * `flush_caches()` runs once per request, so tests must reset its guard.
	 */
	private function reset_flush_state(): void {
		$flushed = new ReflectionProperty( Settings::class, 'caches_flushed' );
		$flushed->setAccessible( true );
		$flushed->setValue( null, false );

		$last = new ReflectionProperty( Settings::class, 'last_flush' );
		$last->setAccessible( true );
		$last->setValue( null, null );
	}

	/** The cache report is written after a flush. */
	private function assertCacheFlushed(): void {
		$report = get_option( Settings::FLUSH_OPTION );

		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'time', $report );
		$this->assertArrayHasKey( 'cache_state', $report );
	}
}
