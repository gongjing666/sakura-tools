<?php
/**
 * 前台文件空间的台账显示名。
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Services\Uploader;
use PHPUnit\Framework\TestCase;

/**
 * 上传重命名发生在 wp_handle_upload() 内部，$_FILES 里的名字是重命名之前
 * 的原名。台账 display_name 曾经取的是那个名字，于是前台文件列表显示原始
 * 哈希名，而媒体库因为 post_title 被回写过显示的是新名。这两个私有助手就是
 * 取名的唯一出口，所以在这里钉住它。
 */
final class UserFilesUploadNameTest extends TestCase {

	/**
	 * Call the private display-name helper.
	 *
	 * @param string $value File name or absolute path.
	 */
	private function display( string $value ): string {
		$method = new ReflectionMethod( Uploader::class, 'display_name' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $value );
	}

	/**
	 * Call the private title helper.
	 *
	 * @param string $value Display name.
	 */
	private function title( string $value ): string {
		$method = new ReflectionMethod( Uploader::class, 'title_for' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $value );
	}

	/**
	 * The renamed file on disk is what the ledger must remember.
	 */
	public function test_the_uploaded_path_yields_the_renamed_basename(): void {
		$this->assertSame(
			'20261118-234452-TX8uy.webp',
			$this->display( '/www/wwwroot/www.nboke.com/wp-content/uploads/2026/11/20261118-234452-TX8uy.webp' )
		);
	}

	/**
	 * Before wp_handle_upload() runs, the original $_FILES name is all we have;
	 * it stays the fallback for the failure path.
	 */
	public function test_the_original_name_is_still_accepted(): void {
		$this->assertSame( '681e5edfb0f736caad2227aa6b69b079.jpg', $this->display( '681e5edfb0f736caad2227aa6b69b079.jpg' ) );
	}

	/**
	 * Titles drop the extension, and an empty name still gets a caption.
	 */
	public function test_titles_drop_the_extension(): void {
		$this->assertSame( '20261118-234452-TX8uy', $this->title( '20261118-234452-TX8uy.webp' ) );
		$this->assertSame( '文件', $this->title( '' ) );
	}
}
