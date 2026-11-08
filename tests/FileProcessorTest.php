<?php
/**
 * File_Processor policy tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Image\File_Processor;

final class FileProcessorTest extends TestCase {

	private $files = array();

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
		$GLOBALS['sakura_test_options'] = array();
	}

	private function fixture( $extension, $bytes ) {
		$file = tempnam( sys_get_temp_dir(), 'sakura-file-' );
		$path = $file . '.' . $extension;
		rename( $file, $path );
		file_put_contents( $path, $bytes );
		$this->files[] = $path;
		return $path;
	}

	public function test_source_format_whitelist_is_case_insensitive(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'JPG' );
		$processor = new File_Processor();

		$this->assertTrue( $processor->should_process_file( '/tmp/example.JPG' ) );
		$this->assertFalse( $processor->should_process_file( '/tmp/example.png' ) );
		$this->assertSame( 'unsupported', $processor->get_processing_skip_reason( '/tmp/example.txt' ) );
	}

	public function test_animated_gif_is_skipped_when_selected(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'gif' );
		$header = "GIF89a\x01\x00\x01\x00\x00\x00\x00";
		$frame  = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00";
		$path   = $this->fixture( 'gif', $header . $frame . $frame . ';' );

		$processor = new File_Processor();
		$this->assertSame( 'animated', $processor->get_processing_skip_reason( $path ) );

		$result = $processor->process_file( $path );
		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'animated', $result['skip_reason'] );
		$this->assertSame( 0, $result['size_after'] );
	}

	public function test_mime_mapping_falls_back_to_wordpress_filetype(): void {
		$processor = new File_Processor();
		$this->assertSame( 'image/webp', $processor->mime_from_path( '/tmp/a.webp' ) );
		$this->assertSame( 'image/png', $processor->mime_from_path( '/tmp/a.png' ) );
	}
	private function png_header( $width, $height ) {
		$ihdr = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );
		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
	}

	public function test_oversized_file_is_passed_through(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_file_size_mb'] = 1;
		$path = $this->fixture( 'jpg', str_repeat( 'A', 1048576 + 1024 ) );

		$processor = new File_Processor();
		$this->assertSame( 'too_large_file', $processor->get_processing_skip_reason( $path ) );

		$result = $processor->process_file( $path );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 0, $result['size_after'] );
		$this->assertStringContainsString( '超过上限 1 MB', $result['error'] );
	}

	public function test_resolution_above_4k_is_passed_through(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'png' );
		$path = $this->fixture( 'png', $this->png_header( 3841, 1200 ) );

		$processor = new File_Processor();
		$this->assertSame( 'too_large_resolution', $processor->get_processing_skip_reason( $path ) );

		$result = $processor->process_file( $path );
		$this->assertTrue( $result['skipped'] );
		$this->assertStringContainsString( '3841×1200', (string) $result['error'] );
		$this->assertStringContainsString( '3840×2160', (string) $result['error'] );
	}

	public function test_portrait_4k_fits_and_zero_disables_limits(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats'] = array( 'png' );
		$portrait = $this->fixture( 'png', $this->png_header( 2160, 3840 ) );

		$processor = new File_Processor();
		$this->assertSame( '', $processor->get_processing_skip_reason( $portrait ) );

		$GLOBALS['sakura_test_options']['sakura_imgopt_max_image_width']  = 0;
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_image_height'] = 0;
		$GLOBALS['sakura_test_options']['sakura_imgopt_max_file_size_mb'] = 0;
		$GLOBALS['sakura_test_options']['sakura_imgopt_source_formats']  = array( 'jpg' );
		$big = $this->fixture( 'jpg', str_repeat( 'B', 1048576 + 1 ) );
		$this->assertSame( '', $processor->get_processing_skip_reason( $big ) );
	}

}
