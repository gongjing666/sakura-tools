<?php
/**
 * Backup relative-path validation tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Backup\Backup;

final class BackupPathTest extends TestCase {

	public function test_normal_path_passes(): void {
		$this->assertSame( '2026/10/a.jpg', Backup::normalize_relative_path( '2026/10/a.jpg' ) );
	}

	public function test_leading_slash_is_trimmed(): void {
		$this->assertSame( '2026/10/a.jpg', Backup::normalize_relative_path( '/2026/10/a.jpg' ) );
	}

	public function test_windows_separator_is_normalized(): void {
		$this->assertSame( '2026/10/a.jpg', Backup::normalize_relative_path( '2026\\10\\a.jpg' ) );
	}

	public function test_parent_segments_are_rejected(): void {
		$this->assertFalse( Backup::normalize_relative_path( '../wp-config.php' ) );
		$this->assertFalse( Backup::normalize_relative_path( '2026/../../wp-config.php' ) );
		$this->assertFalse( Backup::normalize_relative_path( '2026/./a.jpg' ) );
	}

	public function test_null_byte_is_rejected(): void {
		$this->assertFalse( Backup::normalize_relative_path( "2026/a\0.jpg" ) );
	}

	public function test_empty_or_non_string_is_rejected(): void {
		$this->assertFalse( Backup::normalize_relative_path( '' ) );
		$this->assertFalse( Backup::normalize_relative_path( null ) );
		$this->assertFalse( Backup::normalize_relative_path( array( 'a.jpg' ) ) );
	}
}
