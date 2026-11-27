<?php
/**
 * Backup repository contract tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Backup\Backup;
use Sakura\ImageOptimizer\Backup\Backup_Repository;

final class BackupRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_post_meta'] = array();
	}

	public function test_get_meta_returns_null_when_missing(): void {
		$repo = new Backup_Repository();
		self::assertNull( $repo->get_meta( 123 ) );
	}

	public function test_get_meta_returns_array_from_postmeta(): void {
		$GLOBALS['sakura_test_post_meta'][123][ Backup::META_KEY ] = array( 'backup_rel' => 'x.jpg' );
		$repo = new Backup_Repository();
		self::assertSame( array( 'backup_rel' => 'x.jpg' ), $repo->get_meta( 123 ) );
	}

	public function test_store_rejects_non_file(): void {
		$repo = new Backup_Repository();
		self::assertFalse( $repo->store( '/tmp/sakura-definitely-missing-file' ) );
	}

	public function test_resolve_rejects_traversal(): void {
		$repo = new Backup_Repository();
		self::assertFalse( $repo->resolve_upload_path( '../etc/passwd' ) );
		self::assertFalse( $repo->resolve_upload_path( '2026/06/x.jpg', true, false ) );
	}

	public function test_has_backup_false_without_meta(): void {
		$repo = new Backup_Repository();
		self::assertFalse( $repo->has_backup( 456 ) );
	}
}
