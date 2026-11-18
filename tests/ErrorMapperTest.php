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
	 * A path-style request against a virtual-host-only service, or a bucket in
	 * another region, is an endpoint problem rather than a credential one: the
	 * message has to say so, otherwise the operator edits the key again.
	 */
	public function test_endpoint_mismatch_codes(): void {
		$this->assertSame( 'sakura_s3_endpoint_mismatch', Error_Mapper::code_for( 'PathStyleDomainForbidden' ) );
		$this->assertSame( 'sakura_s3_endpoint_mismatch', Error_Mapper::code_for( 'PermanentRedirect' ) );
		$this->assertSame( 'sakura_s3_endpoint_mismatch', Error_Mapper::code_for( 'AuthorizationHeaderMalformed' ) );

		$message = Error_Mapper::message_for( 'PathStyleDomainForbidden', '连接测试' );
		$this->assertStringContainsString( '连接测试', $message );
		$this->assertStringContainsString( 'PathStyleDomainForbidden', $message );
		$this->assertStringContainsString( '服务地址', $message );
		$this->assertStringNotContainsString( 'Access Key', $message );
	}

	/**
	 * 临时凭据过期必须与「密钥填错」分开。
	 *
	 * 两者的修法完全不同：前者要去查续期链路（接口、时间同步），后者是去改
	 * Access Key。合成一句话会把人送去改一个没错的配置。
	 */
	public function test_expired_token_is_its_own_code(): void {
		$this->assertSame( 'sakura_s3_token_expired', Error_Mapper::code_for( 'ExpiredToken' ) );
		$this->assertSame( 'sakura_s3_token_expired', Error_Mapper::code_for( 'InvalidToken' ) );

		$message = Error_Mapper::message_for( 'ExpiredToken', '上传' );
		$this->assertStringContainsString( '上传', $message );
		$this->assertStringContainsString( '续期', $message );
		$this->assertStringNotContainsString( 'Access Key/Secret', $message, '这不是密钥填错' );
	}

	/**
	 * 凭据链断了的异常自带原因，不能被压成「远端存储请求失败（unknown）」。
	 */
	public function test_credential_error_keeps_its_reason(): void {
		$error = Error_Mapper::to_wp_error(
			new \Sakura\ImageOptimizer\S3\S3_Credential_Error( '多吉云接口返回错误（code 403）：AccessKey Format Error。' ),
			'上传'
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'sakura_s3_token_expired', $error->get_error_code() );
		$this->assertStringContainsString( 'AccessKey Format Error', $error->get_error_message() );
		$this->assertStringNotContainsString( 'unknown', $error->get_error_message() );
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
