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

	public function test_preview_tokens_cover_every_replacer(): void {
		$tokens = Upload_Renamer::preview_tokens();

		$this->assertSame(
			array( '{filename}', '{ext}', '{date}', '{time}', '{year}', '{month}', '{day}', '{yy}', '{week}', '{dow}', '{ts}', '{rand}', '{rand8}', '{hash}', '{wxh}', '{user}', '{fixed}' ),
			array_keys( $tokens )
		);
		$this->assertSame( 'sakura', $tokens['{filename}'] );
		$this->assertSame( 'webp', $tokens['{ext}'] );
		$this->assertSame( 'Kx7Qm', $tokens['{rand}'] );
		$this->assertSame( 'Kx7Qm9Ta', $tokens['{rand8}'] );
		$this->assertSame( 'a3f19c7d', $tokens['{hash}'] );
		$this->assertSame( '1920x1080', $tokens['{wxh}'] );
		$this->assertSame( '', $tokens['{fixed}'] );
		$this->assertMatchesRegularExpression( '/^\d{8}$/', $tokens['{date}'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $tokens['{time}'] );
		$this->assertMatchesRegularExpression( '/^\d{2}$/', $tokens['{yy}'] );
		$this->assertMatchesRegularExpression( '/^\d{1,2}$/', $tokens['{week}'] );
		$this->assertMatchesRegularExpression( '/^[1-7]$/', $tokens['{dow}'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $tokens['{ts}'] );
		// 上传者是登录名；测试环境没有登录用户，所以回落成示例名。
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $tokens['{user}'] );
	}

	public function test_preview_filename_expands_content_and_uploader_tokens(): void {
		$this->assertSame( 'a3f19c7d.webp', Upload_Renamer::preview_filename( '{hash}' ) );
		$this->assertSame( '1920x1080-a3f19c7d.webp', Upload_Renamer::preview_filename( '{wxh}-{hash}' ) );
		$this->assertStringEndsWith( '-sakura.webp', Upload_Renamer::preview_filename( '{user}-{filename}' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{1,2}\.webp$/', Upload_Renamer::preview_filename( '{year}-{week}' ) );
	}

	public function test_preview_tokens_accepts_the_user_defined_fixed_text(): void {
		$tokens = Upload_Renamer::preview_tokens( 'sakura' );

		$this->assertSame( 'sakura', $tokens['{fixed}'] );
	}

	public function test_preview_filename_expands_the_fixed_text(): void {
		$today = current_time( 'Ymd' );

		$this->assertSame( 'sakura-' . $today . '.webp', Upload_Renamer::preview_filename( '{fixed}-{date}', 'sakura' ) );
		$this->assertSame( 'sakura-sakura.webp', Upload_Renamer::preview_filename( '{fixed}-{filename}', 'sakura' ) );
	}

	public function test_empty_fixed_text_removes_its_dividers(): void {
		// 空 `{fixed}` 不能留下双分隔符，也不能把整个名字清空。
		$this->assertSame( 'sakura.webp', Upload_Renamer::preview_filename( '{fixed}-{filename}' ) );
		$this->assertSame( 'sakura.webp', Upload_Renamer::preview_filename( 'sakura-{fixed}' ) );
		$this->assertSame( 'sakura.webp', Upload_Renamer::preview_filename( '{fixed}' ) );
		// 夹在中间时只吃掉自己前面那个连接符，不能把两侧邻居粘起来。
		$this->assertSame( 'sakura-a3f19c7d.webp', Upload_Renamer::preview_filename( '{filename}-{fixed}-{hash}' ) );
		$this->assertSame( 'a3f19c7d-sakura.webp', Upload_Renamer::preview_filename( '{hash}-{fixed}-{filename}' ) );
	}

	public function test_preview_filename_expands_tokens_and_keeps_one_extension(): void {
		$this->assertSame( 'sakura.webp', Upload_Renamer::preview_filename( '{filename}.{ext}' ) );
		$this->assertSame( 'sakura.webp', Upload_Renamer::preview_filename( '{filename}' ) );
		$this->assertStringEndsWith( '.webp', Upload_Renamer::preview_filename( '{date}-{time}-{rand}' ) );
		$this->assertStringNotContainsString( 'webp.webp', Upload_Renamer::preview_filename( '{filename}.{ext}' ) );
	}

	public function test_preview_filename_falls_back_to_the_default_rule(): void {
		$preview = Upload_Renamer::preview_filename( '' );

		$this->assertNotSame( '', $preview );
		$this->assertStringEndsWith( '.webp', $preview );
	}
}
