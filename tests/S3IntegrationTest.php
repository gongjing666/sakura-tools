<?php
/**
 * Optional MinIO/moto integration test.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Sakura\Vendor\Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Object_Store;

/**
 * Round-trips one object against a local S3-compatible endpoint.
 *
 * Skipped unless SAKURA_S3_ENDPOINT is set; CI never talks to a real bucket.
 */
final class S3IntegrationTest extends TestCase {

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private S3Client $client;

	/**
	 * Bucket.
	 *
	 * @var string
	 */
	private string $bucket = 'sakura-phpunit';

	/**
	 * Temp file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Configure or skip.
	 */
	protected function setUp(): void {
		$endpoint = getenv( 'SAKURA_S3_ENDPOINT' );
		if ( ! $endpoint ) {
			self::markTestSkipped( 'Set SAKURA_S3_ENDPOINT to run S3 integration tests.' );
		}

		require_once dirname( __DIR__ ) . '/vendor/s3/vendor-prefixed/autoload.php';

		$this->client = new S3Client(
			array(
				'version'                 => 'latest',
				'region'                  => 'us-east-1',
				'endpoint'                => (string) $endpoint,
				'use_path_style_endpoint'      => true,
				'request_checksum_calculation' => 'when_required',
				'response_checksum_validation' => 'when_required',
				'credentials'                  => array(
					'key'    => 'minioadmin',
					'secret' => 'minioadmin',
				),
			)
		);
		try {
			$this->client->createBucket( array( 'Bucket' => $this->bucket ) );
		} catch ( \Throwable $e ) {
			// Existing bucket is fine.
		}

		$this->file = sys_get_temp_dir() . '/sakura-integration-' . uniqid( '', true ) . '.jpg';
		file_put_contents( $this->file, str_repeat( 'S', 2048 ) );
	}

	/**
	 * Cleanup.
	 */
	protected function tearDown(): void {
		if ( isset( $this->file ) ) {
			@unlink( $this->file );
		}
	}

	/**
	 * Upload, HEAD, delete.
	 */
	public function test_object_store_round_trip(): void {
		$store = new Object_Store(
			function () {
				return $this->client;
			},
			function () {
				return array( 'bucket' => $this->bucket );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		$key = 'phpunit/' . uniqid( '', true ) . '.jpg';

		$this->assertTrue( $store->upload_file( $key, $this->file, false ) );
		$head = $this->client->headObject( array( 'Bucket' => $this->bucket, 'Key' => $key ) );
		$this->assertSame( 2048, (int) $head['ContentLength'] );

		$this->assertTrue( $store->delete_keys( array( $key ) ) );
		try {
			$this->client->headObject( array( 'Bucket' => $this->bucket, 'Key' => $key ) );
			$this->fail( 'Object still exists after delete.' );
		} catch ( \Throwable $e ) {
			$this->assertTrue( true );
		}
	}

	/**
	 * A 17 MB object must take the multipart path and round-trip.
	 */
	public function test_multipart_round_trip(): void {
		$file = sys_get_temp_dir() . '/sakura-integration-multipart-' . uniqid( '', true ) . '.bin';
		$handle = fopen( $file, 'wb' );
		ftruncate( $handle, 17 * 1024 * 1024 );
		fclose( $handle );

		$store = new Object_Store(
			function () {
				return $this->client;
			},
			function () {
				return array( 'bucket' => $this->bucket );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		$key = 'phpunit/multipart-' . uniqid( '', true ) . '.bin';

		try {
			$this->assertTrue( $store->upload_file( $key, $file, false ) );
			$head = $this->client->headObject( array( 'Bucket' => $this->bucket, 'Key' => $key ) );
			$this->assertSame( 17 * 1024 * 1024, (int) $head['ContentLength'] );
			$this->assertTrue( $store->delete_keys( array( $key ) ) );
			$this->assertFileExists( $file );
		} finally {
			@unlink( $file );
		}
	}
}
