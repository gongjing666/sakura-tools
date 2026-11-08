<?php
/**
 * Upload verification helpers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Upload_Verify;

/**
 * Pure ETag/size comparison rules.
 */
final class UploadVerifyTest extends TestCase {

	/**
	 * Quotes, case and whitespace are normalized.
	 */
	public function test_normalize_etag(): void {
		$this->assertSame( 'abc123', Upload_Verify::normalize_etag( ' "ABC123" ' ) );
	}

	/**
	 * Multipart ETag is MD5 of concatenated binary part MD5s plus -N.
	 */
	public function test_multipart_etag(): void {
		$a = md5( 'part-one' );
		$b = md5( 'part-two' );
		$expected = md5( hex2bin( $a ) . hex2bin( $b ) ) . '-2';

		$this->assertSame( $expected, Upload_Verify::multipart_etag( array( $a, $b ) ) );
	}

	/**
	 * Invalid part hashes cannot produce an ETag.
	 */
	public function test_multipart_etag_rejects_bad_hash(): void {
		$this->assertSame( '', Upload_Verify::multipart_etag( array( 'not-md5' ) ) );
		$this->assertSame( '', Upload_Verify::multipart_etag( array() ) );
	}

	/**
	 * Size mismatch is the first failure.
	 */
	public function test_compare_size(): void {
		$result = Upload_Verify::compare( 10, 9, md5( 'x' ), md5( 'x' ) );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'Content-Length', (string) $result );
	}

	/**
	 * ETag mismatch is reported when sizes agree.
	 */
	public function test_compare_etag(): void {
		$result = Upload_Verify::compare( 10, 10, md5( 'x' ), md5( 'y' ) );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'ETag', (string) $result );
	}

	/**
	 * Matching size and ETag (quoted) is clean.
	 */
	public function test_compare_match(): void {
		$etag = md5( 'payload' );
		$this->assertNull( Upload_Verify::compare( 7, 7, $etag, '"' . strtoupper( $etag ) . '"' ) );
	}

	/**
	 * A missing ETag is a failure.
	 */
	public function test_compare_missing_etag(): void {
		$this->assertNotNull( Upload_Verify::compare( 7, 7, md5( 'payload' ), '' ) );
	}
}
