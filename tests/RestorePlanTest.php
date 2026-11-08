<?php
/**
 * Restore reconciliation planning tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Restore_Plan;

final class RestorePlanTest extends TestCase {

	public function test_normalize_drops_empty_and_duplicate_keys(): void {
		$keys = array(
			' wp-content/uploads/a.jpg ',
			'',
			'wp-content/uploads/a.jpg',
			'wp-content/uploads/b.webp',
			null,
			123,
		);

		self::assertSame(
			array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/b.webp' ),
			Restore_Plan::normalize( $keys )
		);
	}

	public function test_stale_returns_keys_the_restored_metadata_no_longer_uses(): void {
		$recorded = array(
			'wp-content/uploads/2026/07/photo.jpg',
			'wp-content/uploads/2026/07/photo.webp',
			'wp-content/uploads/2026/07/photo-800x450.webp',
		);
		$desired = array(
			'wp-content/uploads/2026/07/photo.jpg',
			'wp-content/uploads/2026/07/photo-800x450.jpg',
		);

		self::assertSame(
			array(
				'wp-content/uploads/2026/07/photo.webp',
				'wp-content/uploads/2026/07/photo-800x450.webp',
			),
			Restore_Plan::stale( $recorded, $desired )
		);
	}

	public function test_stale_is_empty_for_compress_only_restore(): void {
		$recorded = array( 'wp-content/uploads/2026/07/photo.jpg' );
		$desired  = array( 'wp-content/uploads/2026/07/photo.jpg' );

		self::assertSame( array(), Restore_Plan::stale( $recorded, $desired ) );
	}

	public function test_stale_is_empty_without_recorded_keys(): void {
		self::assertSame( array(), Restore_Plan::stale( array(), array( 'a.jpg' ) ) );
	}

	public function test_stale_never_deletes_blind_when_metadata_is_unknown(): void {
		self::assertSame( array(), Restore_Plan::stale( array( 'a.jpg', 'b.webp' ), array() ) );
	}

	public function test_stale_ignores_keys_that_were_never_uploaded(): void {
		self::assertSame(
			array( 'b.webp' ),
			Restore_Plan::stale( array( 'b.webp' ), array( 'a.jpg' ) )
		);
	}

	public function test_is_referenced_matches_exact_keys_only(): void {
		$desired = array( 'wp-content/uploads/2026/07/photo.jpg' );

		self::assertTrue( Restore_Plan::is_referenced( 'wp-content/uploads/2026/07/photo.jpg', $desired ) );
		self::assertFalse( Restore_Plan::is_referenced( 'wp-content/uploads/2026/07/photo.webp', $desired ) );
		self::assertFalse( Restore_Plan::is_referenced( 'wp-content/uploads/2026/07/photo', $desired ) );
	}

	public function test_is_referenced_normalizes_both_sides(): void {
		self::assertTrue( Restore_Plan::is_referenced( ' photo.jpg ', array( 'photo.jpg ' ) ) );
		self::assertFalse( Restore_Plan::is_referenced( '', array( 'photo.jpg' ) ) );
		self::assertFalse( Restore_Plan::is_referenced( 'photo.jpg', array() ) );
	}
}
