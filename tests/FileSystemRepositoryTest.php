<?php
/**
 * File system repository tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Filesystem\File_System_Repository;

/**
 * Verifies the adapter delegates explicit-failure operations.
 */
final class FileSystemRepositoryTest extends TestCase {

	/**
	 * Temporary directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create temp dir.
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sakura-fs-' . uniqid( '', true );
		mkdir( $this->dir, 0755, true );
	}

	/**
	 * Remove temp dir.
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->dir ) ) {
			foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
				@unlink( $file );
			}
			@rmdir( $this->dir );
		}
	}

	/**
	 * Write, read, copy, move, delete round-trip.
	 */
	public function test_round_trip(): void {
		$repo = new File_System_Repository();
		$one  = $this->dir . '/one.txt';
		$two  = $this->dir . '/two.txt';
		$move = $this->dir . '/moved.txt';

		$this->assertTrue( $repo->write( $one, 'hello' ) );
		$this->assertSame( 'hello', $repo->read( $one ) );
		$this->assertTrue( $repo->copy( $one, $two ) );
		$this->assertFileExists( $two );
		$this->assertTrue( $repo->move( $two, $move ) );
		$this->assertFileDoesNotExist( $two );
		$this->assertFileExists( $move );
		$this->assertTrue( $repo->delete( $move ) );
		$this->assertFileDoesNotExist( $move );
		$this->assertTrue( $repo->delete( $one ) );
	}

	/**
	 * Deleting a missing file is idempotent.
	 */
	public function test_delete_missing_is_idempotent(): void {
		$repo = new File_System_Repository();
		$this->assertTrue( $repo->delete( $this->dir . '/missing.txt' ) );
	}
}
