<?php
/**
 * 多吉云 credential bridge tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3_Doge_Cloud;

/**
 * 多吉云 reports failures inside a HTTP 200 body (code / msg), so a plain
 * false return left the caller with "无法获取多吉云临时凭据" and no way to tell a
 * typo in the Access Key from a disabled one. last_error() carries the reason.
 */
final class DogeCloudCredentialsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_http_response'] );
		parent::tearDown();
	}

	/**
	 * Feed one canned response body to the bridge.
	 *
	 * @param array<string,mixed> $body Decoded body.
	 * @return void
	 */
	private function respond( array $body ): void {
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $body ),
		);
	}

	/**
	 * A formatting error in the key must name itself.
	 */
	public function test_bad_key_reports_the_provider_message(): void {
		$this->respond(
			array(
				'code'     => 403,
				'err_code' => 'ERROR_AUTHORIZATION_FAILED',
				'msg'      => 'AccessKey Format Error',
			)
		);

		$bridge = new S3_Doge_Cloud( 'AAAAAAAAAAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB' );

		$this->assertFalse( $bridge->get_credentials() );
		$this->assertStringContainsString( 'AccessKey Format Error', $bridge->last_error() );
		$this->assertStringContainsString( '403', $bridge->last_error() );
	}

	/**
	 * A response without msg still has to explain itself.
	 */
	public function test_error_without_message_falls_back_to_the_code(): void {
		$this->respond(
			array(
				'code' => 401,
				'msg'  => '',
			)
		);

		$bridge = new S3_Doge_Cloud( 'k', 's' );

		$this->assertFalse( $bridge->get_credentials() );
		$this->assertStringContainsString( '401', $bridge->last_error() );
	}

	/**
	 * Success leaves no stale error behind.
	 */
	public function test_success_clears_the_error(): void {
		$this->respond(
			array(
				'code' => 200,
				'msg'  => 'OK',
				'data' => array(
					'Credentials' => array(
						'accessKeyId'     => 'AKID',
						'secretAccessKey' => 'SECRET',
						'sessionToken'    => 'TOKEN',
					),
					'ExpiredAt'   => time() + 3600,
					'Buckets'     => array(),
				),
			)
		);

		$bridge = new S3_Doge_Cloud( 'key', 'secret' );
		$creds  = $bridge->get_credentials();

		$this->assertIsArray( $creds );
		$this->assertSame( 'AKID', $creds['accessKeyId'] );
		$this->assertSame( '', $bridge->last_error() );
	}

	/**
	 * An unparsable body is not a silent false either.
	 */
	public function test_garbage_body_explains_itself(): void {
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>502</html>',
		);

		$bridge = new S3_Doge_Cloud( 'k', 's' );

		$this->assertFalse( $bridge->get_credentials() );
		$this->assertNotSame( '', $bridge->last_error() );
	}
}
