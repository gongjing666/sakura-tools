<?php
/**
 * Index row normalisation tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Index_Repository;

/**
 * Pins the row shape shared by the table endpoint and the preview endpoint.
 *
 * The list endpoint and the details dialog used to describe a row differently:
 * the keyset page produced the shape the browser expects, while the single-row
 * lookup returned the raw database row. Reusing one normaliser is what keeps
 * the preview payload readable.
 */
final class IndexRowPayloadTest extends TestCase {

	/**
	 * A raw index row becomes the shape the browser consumes.
	 */
	public function test_normalizes_a_stored_row(): void {
		$payload = Index_Repository::row_payload(
			array(
				'object_key'    => 'wp-content/uploads/2026/11/photo.webp',
				'kind'          => 'image',
				'local_path'    => '/www/example/wp-content/uploads/2026/11/photo.webp',
				'local_size'    => '2906',
				'remote_size'   => '0',
				'local_mtime'   => '2026-11-15 07:12:13',
				'remote_mtime'  => null,
				'status'        => 'pending',
				'error_message' => '',
				'seen_local'    => '1',
				'seen_remote'   => '0',
			)
		);

		$this->assertSame( 'wp-content/uploads/2026/11/photo.webp', $payload['key'] );
		$this->assertSame( 'photo.webp', $payload['name'] );
		$this->assertSame( '/www/example/wp-content/uploads/2026/11/photo.webp', $payload['path'] );
		$this->assertSame( 'image', $payload['type'] );
		$this->assertSame( 2906, $payload['size'] );
		$this->assertSame( 0, $payload['cloud_size'] );
		$this->assertSame( 'pending', $payload['status'] );
		$this->assertTrue( $payload['local'] );
		$this->assertFalse( $payload['cloud'] );
		$this->assertSame( 0, $payload['id'] );
	}

	/**
	 * The timestamp is the later of the two sides, read as UTC.
	 */
	public function test_mtime_prefers_the_newer_side(): void {
		$payload = Index_Repository::row_payload(
			array(
				'object_key'   => 'a/b.webp',
				'local_mtime'  => '2026-11-01 00:00:00',
				'remote_mtime' => '2026-11-14 23:12:14',
			)
		);

		$this->assertSame( strtotime( '2026-11-14 23:12:14 UTC' ), $payload['mtime'] );
	}

	/**
	 * Missing timestamps do not reach the browser as the epoch.
	 */
	public function test_missing_mtime_stays_zero(): void {
		$payload = Index_Repository::row_payload(
			array(
				'object_key'   => 'a/b.webp',
				'local_mtime'  => null,
				'remote_mtime' => '',
			)
		);

		$this->assertSame( 0, $payload['mtime'] );
	}

	/**
	 * A key without a stored kind still gets one from the extension.
	 */
	public function test_kind_falls_back_to_the_extension(): void {
		$payload = Index_Repository::row_payload( array( 'object_key' => 'wp-content/uploads/clip.mp4' ) );

		$this->assertSame( 'video', $payload['type'] );
		$this->assertSame( 'clip.mp4', $payload['name'] );
		$this->assertSame( 'wp-content/uploads/clip.mp4', $payload['path'] );
	}

	/**
	 * Both endpoints must build rows through the same normaliser.
	 */
	public function test_the_table_and_the_lookup_share_one_normaliser(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/src/Sync/Index_Repository.php' );

		$this->assertNotFalse( $source );
		$this->assertSame( 1, substr_count( $source, 'public static function row_payload(' ) );
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, 'self::row_payload(' ) );
	}
}
