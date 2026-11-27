<?php
/**
 * M-08 no-gain guard tests: keep original when processing does not shrink the file.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Image\Handlers\GD_Handler;
use Sakura\ImageOptimizer\Image\Engine_Manager;
use Sakura\ImageOptimizer\Image\No_Gain_Guard;
use Sakura\ImageOptimizer\Image\Handlers\Imagick_Handler;

final class NoGainGuardTest extends TestCase {

	private $files = array();
	private $dirs  = array();

	private function workspace(): string {
		$dir = sys_get_temp_dir() . '/sakura-guard-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		return $dir;
	}

	private function remember( string $path ): string {
		$this->files[] = $path;
		return $path;
	}

	private function png( string $path, int $width, int $height, bool $noise = false ): string {
		$img = imagecreatetruecolor( $width, $height );
		if ( $noise ) {
			for ( $x = 0; $x < $width; $x++ ) {
				for ( $y = 0; $y < $height; $y++ ) {
					$color = imagecolorallocate( $img, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) );
					imagesetpixel( $img, $x, $y, $color );
				}
			}
		} else {
			$color = imagecolorallocate( $img, 200, 30, 90 );
			imagefilledrectangle( $img, 0, 0, $width - 1, $height - 1, $color );
		}
		imagepng( $img, $path );
		imagedestroy( $img );
		return $this->remember( $path );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			foreach ( array( '', '.jpg', '.jpeg', '.webp', '.avif' ) as $suffix ) {
				$candidate = $file . $suffix;
				if ( is_file( $candidate ) ) {
					unlink( $candidate );
				}
				foreach ( (array) glob( $candidate . '.sakuratmp_*' ) as $tmp ) {
					if ( is_file( $tmp ) ) {
						unlink( $tmp );
					}
				}
			}
		}
		foreach ( $this->dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->files = array();
		$this->dirs  = array();
	}

	public function test_guard_keeps_original_when_output_is_equal_or_larger(): void {
		$this->assertTrue( No_Gain_Guard::should_keep_original( 1000, 1000 ) );
		$this->assertTrue( No_Gain_Guard::should_keep_original( 1000, 1001 ) );
		$this->assertTrue( No_Gain_Guard::should_keep_original( 90, 692 ) );
	}

	public function test_guard_allows_smaller_output_and_unknown_sizes(): void {
		$this->assertFalse( No_Gain_Guard::should_keep_original( 1000, 999 ) );
		$this->assertFalse( No_Gain_Guard::should_keep_original( 0, 500 ) );
		$this->assertFalse( No_Gain_Guard::should_keep_original( 1000, 0 ) );
	}

	public function test_skipped_result_shape(): void {
		$result = No_Gain_Guard::skipped_result( '/tmp/x.png', 90, 692, 'gd' );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( No_Gain_Guard::REASON, $result['skip_reason'] );
		$this->assertSame( '/tmp/x.png', $result['path'] );
		$this->assertSame( 90, $result['size_before'] );
		$this->assertSame( 0, $result['size_after'] );
		$this->assertSame( 'gd', $result['engine'] );
		$this->assertStringContainsString( '90', $result['error'] );
		$this->assertStringContainsString( '692', $result['error'] );
	}

	public function test_gd_keeps_original_when_jpeg_is_larger_than_png(): void {
		$dir  = $this->workspace();
		$path = $this->png( $dir . '/pixel.png', 1, 1 );
		$hash = md5_file( $path );

		$result = GD_Handler::process( $path, '', array( 'format' => 'jpg', 'quality' => 82 ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( No_Gain_Guard::REASON, $result['skip_reason'] );
		$this->assertSame( $path, $result['path'] );
		$this->assertSame( 'gd', $result['engine'] );
		$this->assertSame( $hash, md5_file( $path ), '原图内容不应改动' );
		$this->assertFileDoesNotExist( $dir . '/pixel.jpg' );
		$this->assertSame( array(), (array) glob( $dir . '/pixel.jpg.sakuratmp_*' ) );
	}

	public function test_imagick_keeps_original_when_jpeg_is_larger_than_png(): void {
		if ( ! class_exists( 'Imagick' ) ) {
			$this->markTestSkipped( 'Imagick 不可用' );
		}

		$dir  = $this->workspace();
		$path = $this->png( $dir . '/pixel.png', 1, 1 );
		$hash = md5_file( $path );

		$result = Imagick_Handler::process( $path, '', array( 'format' => 'jpg', 'quality' => 82 ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'imagick', $result['engine'] );
		$this->assertSame( $hash, md5_file( $path ), '原图内容不应改动' );
		$this->assertFileDoesNotExist( $dir . '/pixel.jpg' );
	}

	public function test_engine_manager_falls_through_and_reports_skip(): void {
		$dir  = $this->workspace();
		$path = $this->png( $dir . '/pixel.png', 1, 1 );
		$hash = md5_file( $path );

		$manager = new Engine_Manager();
		$result  = $manager->process( $path, array( 'format' => 'jpg', 'quality' => 82 ) );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['skipped'] );
		$this->assertSame( No_Gain_Guard::REASON, $result['skip_reason'] );
		$this->assertSame( 'gd', $result['engine'], 'Imagick 跳过后仍应尝试 GD，并保留最后一次的引擎标记' );
		$this->assertSame( $hash, md5_file( $path ) );
	}

	public function test_gd_writes_smaller_output_when_compression_wins(): void {
		$dir  = $this->workspace();
		$path = $this->png( $dir . '/noise.png', 160, 160, true );
		$size = filesize( $path );

		$result = GD_Handler::process( $path, '', array( 'format' => 'jpg', 'quality' => 82 ) );

		$this->assertTrue( $result['success'], (string) ( $result['error'] ?? '' ) );
		$this->assertFalse( ! empty( $result['skipped'] ) );
		$this->assertSame( $dir . '/noise.jpg', $result['path'] );
		$this->assertFileExists( $result['path'] );
		$this->assertLessThan( $size, (int) $result['size_after'] );
		$this->assertSame( (int) filesize( $result['path'] ), (int) $result['size_after'] );
		$this->assertSame( array(), (array) glob( $dir . '/noise.jpg.sakuratmp_*' ) );
	}
}
