<?php
/**
 * Object-key prefix resolution (P1-6).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;

final class OptionsUploadPathTest extends TestCase {

	/** @var string */
	private $uploads = '';

	protected function setUp(): void {
		$GLOBALS['sakura_test_options']    = array();
		$this->uploads                     = ABSPATH . '/wp-content/uploads';
		$GLOBALS['sakura_test_upload_dir'] = $this->uploads;
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
		unset( $GLOBALS['sakura_test_upload_dir'] );
	}

	public function test_derives_prefix_from_uploads_directory(): void {
		$this->assertSame( 'wp-content/uploads', Options::resolve_upload_path( array() ) );
		$this->assertSame( 'derived', Options::upload_path_source( array() ) );
	}

	public function test_wordpress_option_wins_and_is_never_rewritten(): void {
		$GLOBALS['sakura_test_options']['upload_path'] = 'files';

		$this->assertSame( 'files', Options::resolve_upload_path( array( 'upload_path' => 'other/prefix' ) ) );
		$this->assertSame( 'wordpress_option', Options::upload_path_source( array() ) );
		$this->assertSame( 'files', $GLOBALS['sakura_test_options']['upload_path'] );
	}

	public function test_plugin_prefix_is_used_when_site_option_is_empty(): void {
		$this->assertSame( 'cdn/media', Options::resolve_upload_path( array( 'upload_path' => 'cdn/media' ) ) );
		$this->assertSame( 'plugin', Options::upload_path_source( array( 'upload_path' => 'cdn/media' ) ) );
	}

	public function test_root_mode_is_preserved(): void {
		$this->assertSame( '.', Options::resolve_upload_path( array( 'upload_path' => '.' ) ) );
	}

	public function test_falls_back_when_uploads_live_outside_the_root(): void {
		$GLOBALS['sakura_test_upload_dir'] = sys_get_temp_dir() . '/outside-uploads';

		$this->assertSame( Options::DEFAULT_UPLOAD_PATH, Options::resolve_upload_path( array() ) );
		$this->assertSame( 'fallback', Options::upload_path_source( array() ) );
	}

	public function test_instance_uses_its_own_snapshot(): void {
		$options = new Options( array( 'upload_path' => 'snapshot/uploads' ) );

		$this->assertSame( 'snapshot/uploads', $options->upload_path() );
	}
}
