<?php
/**
 * Object store tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Object_Store;
use Sakura\ImageOptimizer\S3\Upload_Verify;

/**
 * Exercises upload/delete behavior with an in-memory S3 client.
 */
final class ObjectStoreTest extends TestCase {

	/**
	 * Temp file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Logs.
	 *
	 * @var string[]
	 */
	private array $logs = array();

	/**
	 * Create a temp file.
	 */
	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/sakura-object-' . uniqid( '', true ) . '.jpg';
		file_put_contents( $this->file, 'jpeg-bytes' );
		$this->logs = array();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		@unlink( $this->file );
	}

	/**
	 * Upload passes bucket/key/ACL and removes local after success.
	 */
	public function test_upload_file(): void {
		$client = new class() {
			/** @var array<int,array<string,mixed>> */
			public array $puts = array();

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function putObject( $args ) {
				$this->puts[] = $args;
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 10,
					'ETag'          => '"' . md5( 'jpeg-bytes' ) . '"',
				);
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'public-read';
			},
			function ( $message ) {
				$this->logs[] = $message;
			}
		);

		$this->assertTrue( $store->upload_file( '2026/10/photo.jpg', $this->file, true ) );
		$this->assertCount( 1, $client->puts );
		$this->assertSame( 0, $client->puts[0]['@retries'] );
		$this->assertSame( 'test-bucket', $client->puts[0]['Bucket'] );
		$this->assertSame( '2026/10/photo.jpg', $client->puts[0]['Key'] );
		$this->assertSame( 'public-read', $client->puts[0]['ACL'] );
		$this->assertSame( 'image/jpeg', $client->puts[0]['ContentType'] );
		$this->assertFileDoesNotExist( $this->file );
	}

	/**
	 * Client failures become WP_Error and leave the local file in place.
	 */
	public function test_upload_failure_keeps_local_file(): void {
		$store = new Object_Store(
			static function () {
				throw new RuntimeException( 'network down' );
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			function ( $message ) {
				$this->logs[] = $message;
			}
		);

		$result = $store->upload_file( '2026/10/photo.jpg', $this->file, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFileExists( $this->file );
		$this->assertNotEmpty( $this->logs );
	}

	/**
	 * Deletes are chunked at 1000 keys.
	 */
	public function test_delete_keys_chunks(): void {
		$client = new class() {
			/** @var array<int,array<string,mixed>> */
			public array $deletes = array();

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function deleteObjects( $args ) {
				$this->deletes[] = $args;
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'public-read';
			},
			static function () {
				return;
			}
		);

		$keys = array_map(
			static function ( $index ) {
				return 'key-' . $index;
			},
			range( 1, 1001 )
		);

		$this->assertTrue( $store->delete_keys( $keys ) );
		$this->assertCount( 2, $client->deletes );
		$this->assertCount( 1000, $client->deletes[0]['Delete']['Objects'] );
		$this->assertCount( 1, $client->deletes[1]['Delete']['Objects'] );
	}

	public function test_partial_delete_errors_are_not_reported_as_success(): void {
		$client = new class() {
			public array $requests = array();
			public function deleteObjects( $args ) {
				$this->requests[] = $args;
				return array( 'Errors' => array( array( 'Key' => 'b', 'Code' => 'AccessDenied' ) ) );
			}
		};
		$store = new Object_Store( static fn() => $client, static fn() => array( 'bucket' => 'test' ), static fn() => 'private', static function () {} );
		$result = $store->delete_keys( array( 'a', 'b' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sio_delete_partial', $result->get_error_code() );
		$this->assertCount( 1, $client->requests );
		$this->assertSame( 0, $client->requests[0]['@retries'] );
	}

	/**
	 * Does not retry individual deletes after an ambiguous multi-delete error.
	 */
	public function test_delete_keys_stops_after_batch_exception(): void {
		$client = new class() {
			/** @var string[] */
			public array $deleted = array();

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function deleteObjects( $args ) {
				throw new RuntimeException( 'not implemented' );
			}

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function deleteObject( $args ) {
				$this->deleted[] = $args['Key'];
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'public-read';
			},
			static function () {
				return;
			}
		);

		$this->assertInstanceOf( WP_Error::class, $store->delete_keys( array( 'a.jpg', 'b.jpg' ) ) );
		$this->assertSame( array(), $client->deleted );
	}

	/**
	 * COS rejects DeleteObjects without Content-MD5; retry each key singly.
	 */
	public function test_delete_keys_falls_back_to_single_deletes_when_content_md5_is_required(): void {
		$client = new class() {
			/** @var string[] */
			public array $deleted = array();

			/** @var int */
			public int $batches = 0;

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function deleteObjects( $args ) {
				++$this->batches;
				throw new RuntimeException( 'Missing required header for this request: Content-MD5' );
			}

			/**
			 * @param array<string,mixed> $args Request.
			 * @return void
			 */
			public function deleteObject( $args ) {
				$this->deleted[] = $args['Key'];
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'public-read';
			},
			static function () {
				return;
			}
		);

		$this->assertTrue( $store->delete_keys( array( 'a.jpg', 'b.jpg' ) ) );
		$this->assertSame( 1, $client->batches );
		$this->assertSame( array( 'a.jpg', 'b.jpg' ), $client->deleted );
	}

	/**
	 * Files over the multipart threshold use create/upload/complete.
	 */
	public function test_upload_multipart_large_file(): void {
		$file = sys_get_temp_dir() . '/sakura-multipart-' . uniqid( '', true ) . '.bin';
		$handle = fopen( $file, 'wb' );
		ftruncate( $handle, 17 * 1024 * 1024 );
		fclose( $handle );

		$client = new class() {
			/** @var array<int,array<string,mixed>> */
			public array $parts = array();
			/** @var array<string,mixed>|null */
			public ?array $completed = null;
			/** @var bool */
			public bool $aborted = false;

			public function createMultipartUpload( $args ) {
				return array( 'UploadId' => 'upload-1' );
			}

			/** @var array<int,string> */
			public array $md5s = array();

			public function uploadPart( $args ) {
				$this->parts[] = $args;
				$this->md5s[] = md5( $args['Body'] );
				return array( 'ETag' => '"etag-' . $args['PartNumber'] . '"' );
			}

			public function completeMultipartUpload( $args ) {
				$this->completed = $args;
				return array();
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 17 * 1024 * 1024,
					'ETag'          => '"' . Upload_Verify::multipart_etag( $this->md5s ) . '"',
				);
			}

			public function abortMultipartUpload( $args ) {
				$this->aborted = true;
				return array();
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		try {
			$this->assertTrue( $store->upload_file( '2026/10/large.bin', $file, false ) );
			$this->assertCount( 3, $client->parts );
			$this->assertNotNull( $client->completed );
			$this->assertSame( 0, $client->completed['@retries'] );
			$this->assertSame( 0, $client->parts[0]['@retries'] );
			$this->assertFalse( $client->aborted );
			$this->assertSame( 3, count( $client->completed['MultipartUpload']['Parts'] ) );
		} finally {
			@unlink( $file );
		}
	}

	/**
	 * Multipart failures abort the upload and keep the local file.
	 */
	public function test_multipart_failure_aborts(): void {
		$file = sys_get_temp_dir() . '/sakura-multipart-fail-' . uniqid( '', true ) . '.bin';
		$handle = fopen( $file, 'wb' );
		ftruncate( $handle, 17 * 1024 * 1024 );
		fclose( $handle );

		$client = new class() {
			/** @var bool */
			public bool $aborted = false;

			public function createMultipartUpload( $args ) {
				return array( 'UploadId' => 'upload-2' );
			}

			public function uploadPart( $args ) {
				throw new RuntimeException( 'part failed' );
			}

			public function abortMultipartUpload( $args ) {
				$this->aborted = true;
				return array();
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		try {
			$result = $store->upload_file( '2026/10/large.bin', $file, true );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertTrue( $client->aborted );
			$this->assertFileExists( $file );
		} finally {
			@unlink( $file );
		}
	}

	/**
	 * A file exactly at the 16 MB threshold must use multipart, not putObject.
	 */
	public function test_exact_threshold_uses_multipart(): void {
		$file = sys_get_temp_dir() . '/sakura-threshold-' . uniqid( '', true ) . '.bin';
		$handle = fopen( $file, 'wb' );
		ftruncate( $handle, 16 * 1024 * 1024 );
		fclose( $handle );

		$client = new class() {
			/** @var bool */
			public bool $multipart = false;
			/** @var bool */
			public bool $single = false;
			/** @var int */
			public int $parts = 0;

			public function createMultipartUpload( $args ) {
				$this->multipart = true;
				return array( 'UploadId' => 'threshold-upload' );
			}

			/** @var array<int,string> */
			public array $md5s = array();

			public function uploadPart( $args ) {
				++$this->parts;
				$this->md5s[] = md5( $args['Body'] );
				return array( 'ETag' => '"part-' . $args['PartNumber'] . '"' );
			}

			public function completeMultipartUpload( $args ) {
				return array();
			}

			public function abortMultipartUpload( $args ) {
				return array();
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 16 * 1024 * 1024,
					'ETag'          => '"' . Upload_Verify::multipart_etag( $this->md5s ) . '"',
				);
			}

			public function putObject( $args ) {
				$this->single = true;
				return array();
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		try {
			$this->assertTrue( $store->upload_file( '2026/10/threshold.bin', $file, false ) );
			$this->assertTrue( $client->multipart );
			$this->assertFalse( $client->single );
			$this->assertSame( 2, $client->parts );
		} finally {
			@unlink( $file );
		}
	}


	/**
	 * A failed verification must keep the uploaded object.
	 *
	 * H-02: deleting on a HEAD/verify error turns a transient permission or
	 * network problem into data loss — the object may be complete and the check
	 * may simply be unable to read it. The upload reports the error, the local
	 * file stays, and the object is left for the retry/verify path to settle.
	 */
	public function test_upload_verification_failure_keeps_object(): void {
		$client = new class() {
			/** @var array<int,string> */
			public array $deleted = array();

			public function putObject( $args ) {
				return array();
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 3,
					'ETag'          => '"' . md5( 'jpeg-bytes' ) . '"',
				);
			}

			public function deleteObject( $args ) {
				$this->deleted[] = $args['Key'];
				return array();
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			function ( $message ) {
				$this->logs[] = $message;
			}
		);

		$result = $store->upload_file( '2026/10/photo.jpg', $this->file, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sio_upload_verify_failed', $result->get_error_code() );
		$this->assertSame( array(), $client->deleted, 'Verification failures must not delete a possibly-good object.' );
		$this->assertFileExists( $this->file );
	}

	/**
	 * The put request carries Content-MD5 for single-part uploads.
	 */
	public function test_upload_sends_content_md5(): void {
		$client = new class() {
			/** @var array<int,array<string,mixed>> */
			public array $puts = array();

			public function putObject( $args ) {
				$this->puts[] = $args;
				return array();
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 10,
					'ETag'          => '"' . md5( 'jpeg-bytes' ) . '"',
				);
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () {
				return array( 'bucket' => 'test-bucket' );
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		$this->assertTrue( $store->upload_file( '2026/10/md5.jpg', $this->file, false ) );
		$this->assertSame( base64_encode( md5( 'jpeg-bytes', true ) ), $client->puts[0]['ContentMD5'] );
	}

	/**
	 * COS-style providers are allowed to return a non-AWS multipart ETag.
	 *
	 * @dataProvider cos_provider_provider
	 * @param string $provider Provider id.
	 */
	public function test_multipart_etag_skipped_for_cos_providers( string $provider ): void {
		list( $store, $file ) = $this->multipart_store( $provider, '"d0cf65d15fc10ffe1f57c137cd776fb5-3"' );

		try {
			$this->assertTrue( $store->upload_file( '2026/10/large.bin', $file, false ) );
		} finally {
			@unlink( $file );
		}
	}

	/**
	 * Providers with the AWS multipart ETag still reject a wrong final ETag.
	 */
	public function test_multipart_etag_mismatch_fails_for_standard_provider(): void {
		list( $store, $file ) = $this->multipart_store( 'minio', '"d0cf65d15fc10ffe1f57c137cd776fb5-3"' );

		try {
			$result = $store->upload_file( '2026/10/large.bin', $file, false );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'sio_upload_verify_failed', $result->get_error_code() );
		} finally {
			@unlink( $file );
		}
	}

	/**
	 * COS providers for the data provider.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function cos_provider_provider(): array {
		return array(
			'dogecloud' => array( 'dogecloud' ),
			'tencent'   => array( 'tencent' ),
		);
	}

	/**
	 * Build a store whose 17 MiB upload returns a chosen final ETag.
	 *
	 * @param string $provider   Provider id.
	 * @param string $remote_etag Final ETag returned by HEAD.
	 * @return array{0:Object_Store,1:string}
	 */
	private function multipart_store( string $provider, string $remote_etag ): array {
		$file   = sys_get_temp_dir() . '/sakura-multipart-etag-' . uniqid( '', true ) . '.bin';
		$handle = fopen( $file, 'wb' );
		ftruncate( $handle, 17 * 1024 * 1024 );
		fclose( $handle );

		$client = new class( $remote_etag ) {
			/** @var string */
			private string $etag;

			public function __construct( string $etag ) {
				$this->etag = $etag;
			}

			public function createMultipartUpload( $args ) {
				return array( 'UploadId' => 'upload-etag' );
			}

			public function uploadPart( $args ) {
				return array( 'ETag' => '"etag-' . $args['PartNumber'] . '"' );
			}

			public function completeMultipartUpload( $args ) {
				return array();
			}

			public function headObject( $args ) {
				return array(
					'ContentLength' => 17 * 1024 * 1024,
					'ETag'          => $this->etag,
				);
			}

			public function abortMultipartUpload( $args ) {
				return array();
			}
		};

		$store = new Object_Store(
			static function () use ( $client ) {
				return $client;
			},
			static function () use ( $provider ) {
				return array(
					'bucket'   => 'test-bucket',
					'provider' => $provider,
				);
			},
			static function () {
				return 'private';
			},
			static function () {
				return;
			}
		);

		return array( $store, $file );
	}
}
