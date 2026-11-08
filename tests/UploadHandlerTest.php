<?php
/**
 * Upload_Handler backup-failure semantics.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Image\File_Processor;
use Sakura\ImageOptimizer\Image\Upload_Handler;

final class UploadHandlerTest extends TestCase {

	/** @var string */
	private $file = '';

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array(
			'sakura_imgopt_enable_compression' => 1,
			'sakura_imgopt_backup_enabled'     => 1,
			'sakura_imgopt_rename_enabled'     => 0,
			'sakura_imgopt_source_formats'     => array( 'jpg' ),
			'sakura_imgopt_compression_mode'   => 'compress_only',
		);
		$this->file = tempnam( sys_get_temp_dir(), 'sakura-upload-' ) . '.jpg';
		file_put_contents( $this->file, 'not-a-real-jpeg' );
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_backup_failure_aborts_and_deletes_staged_file(): void {
		$deleted = array();

		$backup = new class() implements Backup_Repository_Interface {
			public function store( string $origin_abs ): string|false {
				return false;
			}
			public function save_meta( int $attachment_id, string $backup_rel, string $origin_rel, string $converted_rel ): void {}
			public function get_meta( int $attachment_id ): ?array { return null; }
			public function has_backup( int $attachment_id ): bool { return false; }
			public function delete_backup( int $attachment_id ): void {}
			public function resolve_upload_path( string $relative_path, bool $require_backup_dir = false, bool $must_exist = false ): string|false { return false; }
			public function get_usage( bool $force = false ): array { return array( 'bytes' => 0, 'count' => 0 ); }
			public function purge_all(): array { return array( 'bytes' => 0, 'count' => 0 ); }
			public function clean_expired(): array { return array( 'bytes' => 0, 'count' => 0 ); }
		};

		$stats = new class() implements Stats_Repository_Interface {
			public function record( int $attachment_id, array $result, string $source_path, string $mode = 'upload' ): void {}
			public function get_stats(): array { return array(); }
			public function get_logs( int $attachment_id ): array { return array(); }
			public function get_recent_logs( int $limit = 50 ): array { return array(); }
			public function reset(): void {}
		};

		$files = new class( $deleted ) implements File_System_Interface {
			/** @var array<int,string> */
			public $deleted;
			public function __construct( array &$deleted ) { $this->deleted = &$deleted; }
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool { return true; }
			public function read( string $path ): string|false { return false; }
			public function copy( string $source, string $destination ): bool { return true; }
			public function move( string $source, string $destination ): bool { return true; }
			public function delete( string $path ): bool { $this->deleted[] = $path; return true; }
			public function remove_dir( string $path ): bool { return true; }
			public function chmod( string $path, int $mode ): bool { return true; }
		};

		$handler = new Upload_Handler( new File_Processor(), $backup, $stats, $files );
		$upload  = array( 'file' => $this->file, 'type' => 'image/jpeg', 'url' => 'https://example.test/x.jpg' );
		$result  = $handler->handle_upload( $upload, 'upload' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( '原图备份失败', $result['error'] );
		$this->assertSame( array( $this->file ), $deleted );
	}
}
