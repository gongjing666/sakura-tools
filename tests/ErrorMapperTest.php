<?php
/**
 * S3 error mapper tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Error_Mapper;

/**
 * Covers stable error codes and readable messages.
 */
final class ErrorMapperTest extends TestCase {

	/**
	 * Known codes map to stable plugin codes.
	 */
	public function test_known_codes(): void {
		$this->assertSame( 'sakura_s3_access_denied', Error_Mapper::code_for( 'AccessDenied' ) );
		$this->assertSame( 'sakura_s3_auth_failed', Error_Mapper::code_for( 'SignatureDoesNotMatch' ) );
		$this->assertSame( 'sakura_s3_not_found', Error_Mapper::code_for( 'NoSuchBucket' ) );
		$this->assertSame( 'sakura_s3_throttled', Error_Mapper::code_for( 'SlowDown' ) );
		$this->assertSame( 'sakura_s3_timeout', Error_Mapper::code_for( 'RequestTimeout' ) );
		$this->assertSame( 'sakura_s3_error', Error_Mapper::code_for( '' ) );
	}

	/**
	 * Messages name the operation and AWS code, without secrets.
	 */
	public function test_message_and_wp_error(): void {
		$message = Error_Mapper::message_for( 'AccessDenied', '上传' );
		$this->assertStringContainsString( '上传', $message );
		$this->assertStringContainsString( 'AccessDenied', $message );
		$this->assertStringNotContainsString( 'minioadmin', $message );

		$error = Error_Mapper::to_wp_error( new RuntimeException( 'network down' ), '删除' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'sakura_s3_error', $error->get_error_code() );
	}
}
