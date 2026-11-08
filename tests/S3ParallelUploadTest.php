<?php
/**
 * Regression: EachPromise must receive upload promises, not closures.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Object_Store;
use Sakura\ImageOptimizer\S3\Upload_Capabilities;

final class S3ParallelUploadTest extends TestCase {

	private array $paths = array();

	protected function setUp(): void {
		if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
			define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
		}
		require_once dirname( __DIR__ ) . '/vendor/s3/vendor-prefixed/autoload.php';
		Upload_Capabilities::supports_parallel();

		$this->paths = array(
			'wp-content/uploads/parallel-a.jpg' => (string) tempnam( sys_get_temp_dir(), 'sio-a-' ),
			'wp-content/uploads/parallel-b.jpg' => (string) tempnam( sys_get_temp_dir(), 'sio-b-' ),
		);
		foreach ( $this->paths as $path ) {
			file_put_contents( $path, 'parallel-upload-probe' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function test_each_parallel_file_is_dispatched_and_resolved(): void {
		$this->assertTrue( Upload_Capabilities::supports_parallel(), 'The test host must expose cURL multi support.' );

		$client = new class( $this->paths ) {
			/** @var array<string,string> */
			private array $paths;
			/** @var string[] */
			public array $puts = array();

			public function __construct( array $paths ) {
				$this->paths = $paths;
			}

			public function getCommand( string $name, array $args ): array {
				return array( $name, $args );
			}

			public function executeAsync( array $command ) {
				$args = $command[1];
				$this->puts[] = (string) $args['Key'];
				return \Sakura\Vendor\GuzzleHttp\Promise\Create::promiseFor( array( 'ETag' => '"' . md5_file( $this->paths[ $args['Key'] ] ) . '"' ) );
			}

			public function headObject( array $args ): array {
				$path = $this->paths[ $args['Key'] ];
				return array(
					'ContentLength' => (int) filesize( $path ),
					'ETag'          => '"' . md5_file( $path ) . '"',
				);
			}
		};

		$store = new Object_Store(
			static fn() => $client,
			static fn() => array( 'bucket' => 'probe-bucket' ),
			static fn() => 'private',
			static fn() => null
		);

		$files = array();
		foreach ( $this->paths as $key => $path ) {
			$files[] = array( 'key' => $key, 'path' => $path );
		}

		$results = $store->upload_many( $files, 4 );

		$this->assertCount( 2, $client->puts );
		$this->assertSame( true, $results[ 'wp-content/uploads/parallel-a.jpg' ] ?? null );
		$this->assertSame( true, $results[ 'wp-content/uploads/parallel-b.jpg' ] ?? null );
	}
}
