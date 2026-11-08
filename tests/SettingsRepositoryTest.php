<?php
/**
 * Settings repository tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

/**
 * Verifies defaults, prefixing and typed normalisation.
 */
final class SettingsRepositoryTest extends TestCase {

	/**
	 * Reset option state.
	 */
	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	/**
	 * Full option names are prefixed exactly once.
	 */
	public function test_option_name_is_prefixed_once(): void {
		$repo = new Settings_Repository();

		$this->assertSame( 'sakura_imgopt_quality', $repo->option_name( 'quality' ) );
		$this->assertSame( 'sakura_imgopt_quality', $repo->option_name( 'sakura_imgopt_quality' ) );
	}

	/**
	 * Missing options fall back to the documented defaults.
	 */
	public function test_defaults(): void {
		$repo = new Settings_Repository();

		$this->assertFalse( $repo->compression_enabled() );
		$this->assertSame( 'compress_only', $repo->compression_mode() );
		$this->assertSame( 'webp', $repo->convert_format() );
		$this->assertSame( 82, $repo->quality() );
		$this->assertTrue( $repo->backup_enabled() );
		$this->assertSame( 1, $repo->batch_size() );
		$this->assertSame( 500, $repo->ajax_delay() );
		$this->assertSame( 1, $repo->upload_concurrency() );
		$this->assertSame( 24, $repo->max_file_size_mb() );
		$this->assertSame( 3840, $repo->max_image_width() );
		$this->assertSame( 2160, $repo->max_image_height() );
		$this->assertFalse( $repo->delete_data_on_uninstall() );
		$this->assertSame( array(), $repo->disabled_sizes() );
	}

	/**
	 * Transfer concurrency is clamped to the supported 1-4 range.
	 */
	public function test_upload_concurrency_is_clamped(): void {
		$repo = new Settings_Repository();

		update_option( 'sakura_imgopt_upload_concurrency', 4 );
		$this->assertSame( 4, $repo->upload_concurrency() );

		update_option( 'sakura_imgopt_upload_concurrency', 9 );
		$this->assertSame( 4, $repo->upload_concurrency() );

		update_option( 'sakura_imgopt_upload_concurrency', 0 );
		$this->assertSame( 1, $repo->upload_concurrency() );

		update_option( 'sakura_imgopt_upload_concurrency', 'many' );
		$this->assertSame( 1, $repo->upload_concurrency() );
	}

	/**
	 * update() writes the full option name.
	 */
	public function test_update_uses_prefixed_name(): void {
		$repo = new Settings_Repository();

		$this->assertTrue( $repo->update( 'quality', 90 ) );
		$this->assertSame( 90, $GLOBALS['sakura_test_options']['sakura_imgopt_quality'] );
		$this->assertSame( 90, $repo->quality() );
	}

	/**
	 * Numeric values are clamped on read.
	 */
	public function test_numeric_values_are_clamped(): void {
		$repo = new Settings_Repository();

		$GLOBALS['sakura_test_options']['sakura_imgopt_quality'] = 0;
		$GLOBALS['sakura_test_options']['sakura_imgopt_batch_size'] = 99;
		$GLOBALS['sakura_test_options']['sakura_imgopt_ajax_delay'] = 9000;
		$GLOBALS['sakura_test_options']['sakura_imgopt_backup_retention_days'] = -5;
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_file_size_mb'] = -1;
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_image_width']  = 99999;
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_image_height'] = 0;

		$this->assertSame( 1, $repo->quality() );
		$this->assertSame( 20, $repo->batch_size() );
		$this->assertSame( 2000, $repo->ajax_delay() );
		$this->assertSame( 0, $repo->backup_retention_days() );
		$this->assertSame( 0, $repo->max_file_size_mb() );
		$this->assertSame( 20000, $repo->max_image_width() );
		$this->assertSame( 0, $repo->max_image_height() );
	}

	/**
	 * invalid enum values are rejected.
	 */
	public function test_enum_values_are_normalised(): void {
		$repo = new Settings_Repository();

		$GLOBALS['sakura_test_options']['sakura_imgopt_compression_mode'] = 'drop_tables';
		$GLOBALS['sakura_test_options']['sakura_imgopt_convert_format'] = 'gif';

		$this->assertSame( 'compress_only', $repo->compression_mode() );
		$this->assertSame( 'webp', $repo->convert_format() );
	}

	/**
	 * Source format selection is filtered but an empty selection stays empty.
	 */
	public function test_source_formats_are_filtered_and_empty_is_preserved(): void {
		$repo = new Settings_Repository();

		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'JPG', 'exe', 'webp' );
		$this->assertSame( array( 'jpg', 'webp' ), $repo->source_formats() );

		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array();
		$this->assertSame( array(), $repo->source_formats() );
	}

	/**
	 * all() exposes every managed setting.
	 */
	public function test_all_contains_managed_defaults(): void {
		$repo = new Settings_Repository();
		$all  = $repo->all();

		$this->assertArrayHasKey( 'sakura_imgopt_quality', $all );
		$this->assertArrayHasKey( 'sakura_imgopt_backup_enabled', $all );
		$this->assertArrayHasKey( 'sakura_imgopt_delete_data_on_uninstall', $all );
		$this->assertSame( 82, $all['sakura_imgopt_quality'] );
	}

	/**
	 * New sites do not rasterize SVG by default, but explicit opt-in still works.
	 */
	public function test_default_source_formats_exclude_svg(): void {
		$repo = new Settings_Repository();

		$this->assertNotContains( 'svg', Settings_Repository::DEFAULT_SOURCE_FORMATS );
		$this->assertNotContains( 'svg', $repo->source_formats() );
		$this->assertContains( 'svg', Settings_Repository::SOURCE_FORMATS );

		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'svg', 'webp' );
		$this->assertSame( array( 'svg', 'webp' ), $repo->source_formats(), 'explicit selection survives' );
	}
}
