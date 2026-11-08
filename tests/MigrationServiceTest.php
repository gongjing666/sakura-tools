<?php
/**
 * Migration service tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Migration_Service;

/**
 * Exercises batched migration with injected callbacks.
 */
final class MigrationServiceTest extends TestCase {

	/**
	 * Temp dir.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create temp files.
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sakura-migrate-' . uniqid( '', true );
		mkdir( $this->dir, 0755, true );
		file_put_contents( $this->dir . '/a.jpg', 'a' );
		file_put_contents( $this->dir . '/b.jpg', 'b' );
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		@unlink( $this->dir . '/a.jpg' );
		@unlink( $this->dir . '/b.jpg' );
		@rmdir( $this->dir );
	}

	/**
	 * Migrates files, advances the cursor, and reports completion.
	 */
	public function test_migrate_batch(): void {
		$uploads = array();
		$service = new Migration_Service(
			function ( $id ) {
				return array( $this->dir . '/a.jpg', $this->dir . '/b.jpg' );
			},
			static function ( $file ) {
				return 'uploads/' . basename( $file );
			},
			function ( $key, $file, $delete ) use ( &$uploads ) {
				$uploads[] = $key;
				return true;
			},
			static function ( $cursor, $batch ) {
				return 0 === $cursor ? array( 10, 11 ) : array();
			}
		);

		$first = $service->migrate_batch( 0, 2 );

		$this->assertSame( 11, $first['cursor'] );
		$this->assertSame( 2, $first['attachments'] );
		$this->assertSame( 4, $first['uploaded'] );
		$this->assertSame( array(), $first['failed'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 4, $uploads );

		$second = $service->migrate_batch( 11, 2 );

		$this->assertSame( 11, $second['cursor'] );
		$this->assertSame( 0, $second['attachments'] );
		$this->assertTrue( $second['done'] );
	}

	/**
	 * Collects path and upload failures without aborting the batch.
	 */
	public function test_collects_failures(): void {
		$service = new Migration_Service(
			function ( $id ) {
				return array( $this->dir . '/a.jpg', $this->dir . '/b.jpg' );
			},
			static function ( $file ) {
				return 'b.jpg' === basename( $file ) ? false : 'uploads/a.jpg';
			},
			static function () {
				return new WP_Error( 'fail', 'bucket denied' );
			},
			static function () {
				return array( 7 );
			}
		);

		$result = $service->migrate_batch( 0, 3 );

		$this->assertSame( 0, $result['uploaded'] );
		$this->assertCount( 2, $result['failed'] );
		$this->assertStringContainsString( '#7', $result['failed'][0] );
		$this->assertStringContainsString( 'bucket denied', $result['failed'][0] );
		$this->assertStringContainsString( '无法映射', $result['failed'][1] );
	}
}
