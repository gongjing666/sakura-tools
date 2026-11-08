<?php
/**
 * Upload rename service tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Image\Upload_Renamer;

final class UploadRenamerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array( 'sakura_imgopt_rename_enabled' => 0 );
	}

	public function test_disabled_rename_keeps_upload_name(): void {
		$files = new class() implements File_System_Interface {
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool { return true; }
			public function read( string $path ): string|false { return false; }
			public function copy( string $source, string $destination ): bool { return true; }
			public function move( string $source, string $destination ): bool { return true; }
			public function delete( string $path ): bool { return true; }
			public function remove_dir( string $path ): bool { return true; }
			public function chmod( string $path, int $mode ): bool { return true; }
		};

		$renamer = new Upload_Renamer( $files );
		$file    = array( 'name' => 'original.jpg', 'type' => 'image/jpeg' );

		$this->assertSame( $file, $renamer->pre_upload_rename( $file ) );
	}

	public function test_title_sync_is_a_noop_without_a_pending_rename(): void {
		$files = new class() implements File_System_Interface {
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool { return true; }
			public function read( string $path ): string|false { return false; }
			public function copy( string $source, string $destination ): bool { return true; }
			public function move( string $source, string $destination ): bool { return true; }
			public function delete( string $path ): bool { return true; }
			public function remove_dir( string $path ): bool { return true; }
			public function chmod( string $path, int $mode ): bool { return true; }
		};

		$renamer = new Upload_Renamer( $files );
		$data    = array( 'post_type' => 'attachment', 'post_title' => 'Old', 'post_name' => 'old' );

		$this->assertSame( $data, $renamer->sync_attachment_title( $data, array(), array(), false ) );
	}
}
