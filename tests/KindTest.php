<?php
/**
 * Object kind classifier tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Kind;

/**
 * Covers the coarse kind stored on each index row.
 */
final class KindTest extends TestCase {

	/**
	 * Known media extensions map to their bucket.
	 */
	public function test_known_extensions(): void {
		$this->assertSame( 'image', Kind::of( 'wp-content/uploads/2024/05/photo.jpg' ) );
		$this->assertSame( 'image', Kind::of( 'wp-content/uploads/2024/05/photo.WEBP' ) );
		$this->assertSame( 'video', Kind::of( 'wp-content/uploads/2024/05/clip.mp4' ) );
		$this->assertSame( 'archive', Kind::of( 'wp-content/uploads/backup/site.zip' ) );
	}

	/**
	 * Unknown or missing extensions are "other", never a filter surprise.
	 */
	public function test_unknown_extensions(): void {
		$this->assertSame( 'other', Kind::of( 'wp-content/uploads/2024/05/readme.txt' ) );
		$this->assertSame( 'other', Kind::of( 'wp-content/uploads/2024/05/README' ) );
		$this->assertSame( 'other', Kind::of( 'wp-content/uploads/2024.05/photo' ) );
	}

	/**
	 * A dot in a directory name must not be mistaken for the extension.
	 */
	public function test_dot_in_directory_is_not_extension(): void {
		$this->assertSame( 'other', Kind::of( 'wp-content/uploads/2024.05/photo' ) );
		$this->assertSame( 'image', Kind::of( 'wp-content/uploads/2024.05/photo.png' ) );
	}

	/**
	 * Compound archives classify by their last extension.
	 */
	public function test_compound_archive(): void {
		$this->assertSame( 'archive', Kind::of( 'backup/site.tar.gz' ) );
	}

	/**
	 * extensions() lists every extension for a kind.
	 */
	public function test_extensions_cover_the_kind(): void {
		$image = Kind::extensions( 'image' );
		$this->assertContains( 'jpg', $image );
		$this->assertContains( 'jpeg', $image );
		$this->assertNotContains( 'mp4', $image );
		$this->assertSame( array(), Kind::extensions( 'unknown' ) );
	}
}
