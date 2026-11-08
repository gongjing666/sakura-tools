<?php
/**
 * S3 upload queue tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Upload_Queue;

/**
 * Exercises deduplication and request guards with injected callbacks.
 */
final class UploadQueueTest extends TestCase {

	/**
	 * Temp upload dir.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create a temp upload dir and file.
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sakura-s3-' . uniqid( '', true );
		mkdir( $this->dir, 0755, true );
		file_put_contents( $this->dir . '/a.jpg', 'image-bytes' );
		$GLOBALS['sakura_test_upload_dir'] = $this->dir;
	}

	/**
	 * Cleanup.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_upload_dir'] );
		@unlink( $this->dir . '/a.jpg' );
		@rmdir( $this->dir );
	}

	/**
	 * The same main file is uploaded once per request.
	 */
	public function test_metadata_upload_is_deduplicated(): void {
		$calls = array();
		$queue = new Upload_Queue(
			static function ( $key, $file, $delete ) use ( &$calls ) {
				$calls[] = $key;
				return true;
			},
			static function ( $file ) {
				return 'uploads/' . basename( $file );
			},
			static function () {
				return array(
					'no_thumb'        => 'true',
					'no_local_saving' => 'false',
				);
			},
			static function () {
				return false;
			}
		);

		$metadata = array(
			'file'  => 'a.jpg',
			'sizes' => array(),
		);

		$queue->upload_metadata( $metadata );
		$queue->upload_metadata( $metadata );

		$this->assertSame( array( 'uploads/a.jpg' ), $calls );
	}

	/**
	 * A skipped request queues nothing.
	 */
	public function test_skip_request_queues_nothing(): void {
		$calls = array();
		$queue = new Upload_Queue(
			static function ( $key, $file, $delete ) use ( &$calls ) {
				$calls[] = $key;
				return true;
			},
			static function ( $file ) {
				return 'uploads/' . basename( $file );
			},
			static function () {
				return array();
			},
			static function () {
				return true;
			}
		);

		$queue->queue( 123 );
		$queue->flush();

		$this->assertSame( array(), $calls );
	}
}
