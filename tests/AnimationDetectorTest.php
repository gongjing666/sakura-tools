<?php
/**
 * Animation detector tests (no WordPress runtime required).
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Image\Animation_Detector;

final class AnimationDetectorTest extends TestCase {

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
	}

	private function fixture( $extension, $bytes ) {
		$file = tempnam( sys_get_temp_dir(), 'sakura-test-' );
		$path = $file . '.' . $extension;
		rename( $file, $path );
		file_put_contents( $path, $bytes );
		$this->files[] = $path;
		return $path;
	}

	private function gif_header() {
		return "GIF89a\x01\x00\x01\x00\x00\x00\x00";
	}

	private function gif_frame() {
		return "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00";
	}

	public function test_static_gif_is_not_animated(): void {
		$path = $this->fixture( 'gif', $this->gif_header() . ';' );
		$this->assertFalse( Animation_Detector::is_animated( $path ) );
	}

	public function test_two_frame_gif_is_animated(): void {
		$path = $this->fixture( 'gif', $this->gif_header() . $this->gif_frame() . $this->gif_frame() . ';' );
		$this->assertTrue( Animation_Detector::is_animated( $path ) );
	}

	public function test_apng_acTL_before_idat_is_animated(): void {
		$ihdr = pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 ) . pack( 'N', 0 );
		$actl = pack( 'N', 8 ) . 'acTL' . pack( 'NN', 1, 0 ) . pack( 'N', 0 );
		$path = $this->fixture( 'png', "\x89PNG\r\n\x1a\n" . $ihdr . $actl . pack( 'N', 0 ) . 'IEND' . pack( 'N', 0 ) );
		$this->assertTrue( Animation_Detector::is_animated( $path ) );
	}

	public function test_plain_png_is_not_animated(): void {
		$ihdr = pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 ) . pack( 'N', 0 );
		$path = $this->fixture( 'png', "\x89PNG\r\n\x1a\n" . $ihdr . pack( 'N', 0 ) . 'IDAT' . pack( 'N', 0 ) . pack( 'N', 0 ) . 'IEND' . pack( 'N', 0 ) );
		$this->assertFalse( Animation_Detector::is_animated( $path ) );
	}

	public function test_animated_webp_anim_chunk_is_animated(): void {
		$body = 'WEBP' . 'ANIM' . pack( 'V', 0 );
		$path = $this->fixture( 'webp', 'RIFF' . pack( 'V', strlen( $body ) ) . $body );
		$this->assertTrue( Animation_Detector::is_animated( $path ) );
	}

	public function test_static_webp_is_not_animated(): void {
		$body = 'WEBP' . 'VP8 ' . pack( 'V', 0 );
		$path = $this->fixture( 'webp', 'RIFF' . pack( 'V', strlen( $body ) ) . $body );
		$this->assertFalse( Animation_Detector::is_animated( $path ) );
	}
}
