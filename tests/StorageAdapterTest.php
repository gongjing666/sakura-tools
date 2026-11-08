<?php
/**
 * S3 Storage adapter tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;

final class StorageAdapterTest extends TestCase {

	public function test_key_from_path_uses_abspath(): void {
		$adapter = new Storage_Adapter( new Options( array() ) );

		$this->assertSame(
			'wp-content/uploads/2026/10/a.jpg',
			$adapter->key_from_path( ABSPATH . 'wp-content/uploads/2026/10/a.jpg' )
		);
	}

	public function test_normalize_key_rejects_traversal_and_collapses_slashes(): void {
		$adapter = new Storage_Adapter( new Options( array() ) );

		$this->assertFalse( $adapter->normalize_key( '../a.jpg' ) );
		$this->assertSame( 'a/b.jpg', $adapter->normalize_key( '/a//b.jpg' ) );
	}

	public function test_upload_rejects_empty_key_without_client(): void {
		$adapter = new Storage_Adapter( new Options( array() ) );

		$result = $adapter->upload_file( '', ABSPATH . 'missing.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sakura_s3_invalid_file', $result->get_error_code() );
	}
}
