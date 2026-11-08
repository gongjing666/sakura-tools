<?php
/**
 * Per-attachment time budget for sub-size compression (P1-4).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Image\File_Processor;
use Sakura\ImageOptimizer\Image\Metadata_Processor;

final class MetadataProcessorBudgetTest extends TestCase {

	/** @var string */
	private $root = '';

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
		$this->root                     = sys_get_temp_dir() . '/sakura-budget-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/uploads/2026/10', 0777, true );
		file_put_contents( $this->root . '/uploads/2026/10/img.jpg', 'main' );
		for ( $i = 1; $i <= 5; $i++ ) {
			file_put_contents( $this->root . '/uploads/2026/10/img-' . $i . '.jpg', 'size-' . $i );
		}
		$GLOBALS['sakura_test_upload_dir'] = $this->root . '/uploads';
		$GLOBALS['sakura_test_actions']    = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
		unset( $GLOBALS['sakura_test_upload_dir'] );
		$GLOBALS['sakura_test_actions'] = array();
		if ( '' !== $this->root && is_dir( $this->root ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->root );
		}
	}

	public function test_stops_after_budget_and_records_one_skip(): void {
		$processor = $this->processor( 30000 );
		$stats     = $this->stats();
		$metadata  = $this->metadata();
		$subject   = new Metadata_Processor( $processor, $this->backup(), $stats, $this->files(), 0.05 );

		$result = $subject->process( $metadata, 123 );

		$this->assertLessThan( 5, $processor->calls, 'budget must stop remaining sizes' );
		$this->assertCount( 1, $stats->records, 'exactly one budget record' );
		$this->assertSame( 'budget', $stats->records[0]['result']['skip_reason'] );
		$this->assertStringContainsString( '跳过 ' . ( 5 - $processor->calls ) . ' 个尺寸', $stats->records[0]['result']['error'] );
		$this->assertSame( 'img-3.jpg', $result['sizes']['s3']['file'], 'unprocessed sizes keep their file' );

		$fired = array_filter(
			$GLOBALS['sakura_test_actions'],
			static function ( $action ) {
				return 'sakura_imgopt_metadata_budget_exhausted' === $action[0];
			}
		);
		$this->assertCount( 1, $fired, 'budget action must fire once' );
	}

	public function test_zero_budget_disables_the_limit(): void {
		$processor = $this->processor( 0 );
		$stats     = $this->stats();
		$subject   = new Metadata_Processor( $processor, $this->backup(), $stats, $this->files(), 0.0 );

		$subject->process( $this->metadata(), 124 );

		$this->assertSame( 5, $processor->calls );
		$this->assertCount( 0, $stats->records );
	}

	public function test_budget_boundaries(): void {
		$this->assertFalse( Metadata_Processor::budget_exhausted( 19.99, 20.0 ) );
		$this->assertTrue( Metadata_Processor::budget_exhausted( 20.0, 20.0 ) );
		$this->assertFalse( Metadata_Processor::budget_exhausted( 99.0, 0.0 ) );
	}

	/**
	 * Fake processor that records calls and optionally sleeps.
	 */
	private function processor( int $sleep_us ): File_Processor {
		return new class( $sleep_us ) extends File_Processor {
			/** @var int */
			public $calls = 0;

			/** @var int */
			private $sleep_us;

			public function __construct( int $sleep_us ) {
				$this->sleep_us = $sleep_us;
			}

			public function is_compression_enabled(): bool {
				return true;
			}

			public function should_process_file( $file_path ): bool {
				return true;
			}

			public function process_file( $file_path ) {
				++$this->calls;
				if ( $this->sleep_us > 0 ) {
					usleep( $this->sleep_us );
				}
				return array(
					'success'     => true,
					'path'        => $file_path,
					'size_before' => 100,
					'size_after'  => 40,
					'engine'      => 'fake',
				);
			}

			public function mime_from_path( $path ): string {
				return 'image/webp';
			}
		};
	}

	private function metadata(): array {
		$sizes = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$sizes[ 's' . $i ] = array(
				'file'      => 'img-' . $i . '.jpg',
				'mime-type' => 'image/jpeg',
			);
		}
		return array(
			'file'  => '2026/10/img.jpg',
			'sizes' => $sizes,
		);
	}

	private function stats(): Stats_Repository_Interface {
		return new class() implements Stats_Repository_Interface {
			/** @var array<int,array> */
			public $records = array();

			public function record( int $attachment_id, array $result, string $source_path, string $mode = 'upload' ): void {
				$this->records[] = array(
					'attachment_id' => $attachment_id,
					'result'        => $result,
					'source_path'   => $source_path,
					'mode'          => $mode,
				);
			}

			public function get_stats(): array {
				return array();
			}

			public function get_logs( int $attachment_id ): array {
				return array();
			}

			public function get_recent_logs( int $limit = 50 ): array {
				return array();
			}

			public function reset(): void {}
		};
	}

	private function backup(): Backup_Repository_Interface {
		return new class() implements Backup_Repository_Interface {
			public function store( string $origin_abs ): string|false {
				return false;
			}
			public function save_meta( int $attachment_id, string $backup_rel, string $origin_rel, string $converted_rel ): void {}
			public function get_meta( int $attachment_id ): ?array {
				return null;
			}
			public function has_backup( int $attachment_id ): bool {
				return false;
			}
			public function delete_backup( int $attachment_id ): void {}
			public function resolve_upload_path( string $relative_path, bool $require_backup_dir = false, bool $must_exist = false ): string|false {
				return false;
			}
			public function get_usage( bool $force = false ): array {
				return array( 'bytes' => 0, 'count' => 0 );
			}
			public function purge_all(): array {
				return array( 'bytes' => 0, 'count' => 0 );
			}
			public function clean_expired(): array {
				return array( 'bytes' => 0, 'count' => 0 );
			}
		};
	}

	private function files(): File_System_Interface {
		return new class() implements File_System_Interface {
			public function write( string $path, string $contents, int $flags = LOCK_EX ): bool {
				return true;
			}
			public function read( string $path ): string|false {
				return false;
			}
			public function copy( string $source, string $destination ): bool {
				return true;
			}
			public function move( string $source, string $destination ): bool {
				return true;
			}
			public function delete( string $path ): bool {
				return true;
			}
			public function remove_dir( string $path ): bool {
				return true;
			}
			public function chmod( string $path, int $mode ): bool {
				return true;
			}
		};
	}
}
