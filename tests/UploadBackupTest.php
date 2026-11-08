<?php
/**
 * Upload backup semantics.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Image\Upload_Backup;

final class UploadBackupTest extends TestCase {

	private function backup( string|false $result ): Backup_Repository_Interface {
		return new class( $result ) implements Backup_Repository_Interface {
			private string|false $result;
			public function __construct( string|false $result ) { $this->result = $result; }
			public function store( string $origin_abs ): string|false { return $this->result; }
			public function save_meta( int $attachment_id, string $backup_rel, string $origin_rel, string $converted_rel ): void {}
			public function get_meta( int $attachment_id ): ?array { return null; }
			public function has_backup( int $attachment_id ): bool { return false; }
			public function delete_backup( int $attachment_id ): void {}
			public function resolve_upload_path( string $relative_path, bool $require_backup_dir = false, bool $must_exist = false ): string|false { return false; }
			public function get_usage( bool $force = false ): array { return array( 'bytes' => 0, 'count' => 0 ); }
			public function purge_all(): array { return array( 'bytes' => 0, 'count' => 0 ); }
			public function clean_expired(): array { return array( 'bytes' => 0, 'count' => 0 ); }
		};
	}

	public function test_disabled_backup_returns_empty_without_writing(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_backup_enabled'] = 0;
		$files = new class() implements File_System_Interface {
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool { return true; }
			public function read( string $path ): string|false { return false; }
			public function copy( string $source, string $destination ): bool { return true; }
			public function move( string $source, string $destination ): bool { return true; }
			public function delete( string $path ): bool { return false; }
			public function remove_dir( string $path ): bool { return true; }
			public function chmod( string $path, int $mode ): bool { return true; }
		};

		$backup = new Upload_Backup( $this->backup( '/tmp/backup.jpg' ), $files );

		$this->assertSame( '', $backup->store( '/tmp/source.jpg' ) );
	}

	public function test_failed_backup_deletes_staged_file(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_backup_enabled'] = 1;
		$deleted = array();
		$files   = new class( $deleted ) implements File_System_Interface {
			public array $deleted;
			public function __construct( array &$deleted ) { $this->deleted = &$deleted; }
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool { return true; }
			public function read( string $path ): string|false { return false; }
			public function copy( string $source, string $destination ): bool { return true; }
			public function move( string $source, string $destination ): bool { return true; }
			public function delete( string $path ): bool { $this->deleted[] = $path; return true; }
			public function remove_dir( string $path ): bool { return true; }
			public function chmod( string $path, int $mode ): bool { return true; }
		};

		$backup = new Upload_Backup( $this->backup( false ), $files );

		$this->assertFalse( $backup->store( '/tmp/source.jpg' ) );
		$this->assertSame( array( '/tmp/source.jpg' ), $deleted );
	}
}
