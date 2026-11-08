<?php
/**
 * Checksum helpers tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Checksums;

/**
 * Normalize S3 ETags and hash local files under a deadline.
 */
final class ChecksumsTest extends TestCase {

	/**
	 * Bare and quoted ETags normalize to lowercase hex.
	 */
	public function test_normalize_etag(): void {
		$this->assertSame( 'd41d8cd98f00b204e9800998ecf8427e', Checksums::normalize_etag( '"D41D8CD98F00B204E9800998ECF8427E"' ) );
		$this->assertSame( 'abc-3', Checksums::normalize_etag( " \n\"ABC-3\"\t" ) );
		$this->assertSame( '', Checksums::normalize_etag( '""' ) );
	}

	/**
	 * Only bare 32-hex values are MD5-comparable.
	 */
	public function test_is_md5_etag(): void {
		$this->assertTrue( Checksums::is_md5_etag( 'd41d8cd98f00b204e9800998ecf8427e' ) );
		$this->assertFalse( Checksums::is_md5_etag( 'd41d8cd98f00b204e9800998ecf8427e-2' ) );
		$this->assertFalse( Checksums::is_md5_etag( str_repeat( 'a', 31 ) ) );
		$this->assertFalse( Checksums::is_md5_etag( '' ) );
	}

	/**
	 * Streaming hash equals md5_file and stops on a spent deadline.
	 */
	public function test_hash_file_matches_md5_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'sio-md5-' );
		$this->assertIsString( $path );
		file_put_contents( $path, str_repeat( 'sakura-verify-', 200000 ) ); // ~2.8 MB, multi-chunk.

		try {
			$expected = md5_file( $path );
			$this->assertIsString( $expected );
			$this->assertSame( $expected, Checksums::hash_file( $path, microtime( true ) + 30 ) );
			$this->assertSame( '', Checksums::hash_file( $path, microtime( true ) - 1 ) );
			$this->assertFalse( Checksums::hash_file( $path . '.missing', microtime( true ) + 30 ) );
		} finally {
			@unlink( $path );
		}
	}
}
