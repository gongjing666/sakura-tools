<?php
/**
 * Restore service tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Image\Restore_Service;

final class RestoreServiceTest extends TestCase {

	public function test_invalid_attachment_is_rejected(): void {
		$service = new Restore_Service( new Restore_Fake_Repository() );
		$result  = $service->restore( 0 );
		self::assertFalse( $result['success'] );
		self::assertSame( '无效的附件 ID', $result['message'] );
	}

	public function test_missing_backup_meta_is_rejected(): void {
		$service = new Restore_Service( new Restore_Fake_Repository() );
		$result  = $service->restore( 42 );
		self::assertFalse( $result['success'] );
		self::assertSame( '该附件没有可还原的原图备份', $result['message'] );
	}

	public function test_restoring_flag_is_false_outside_restore(): void {
		self::assertFalse( Restore_Service::is_restoring() );
	}

	public function test_image_processor_accepts_injected_repository(): void {
		$processor = new \Sakura\ImageOptimizer\Image_Processor( new Restore_Fake_Repository() );
		$result    = $processor->restore_attachment( 0 );
		self::assertFalse( $result['success'] );
		self::assertSame( '无效的附件 ID', $result['message'] );
	}
}

/**
 * In-memory repository for the restore service tests.
 */
final class Restore_Fake_Repository implements Backup_Repository_Interface {

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
}
