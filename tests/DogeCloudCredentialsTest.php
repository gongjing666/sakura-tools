<?php
/**
 * 多吉云 credential bridge tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Doge_Cloud;

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

		$bridge = new Doge_Cloud( 'AAAAAAAAAAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB' );

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

		$bridge = new Doge_Cloud( 'k', 's' );

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

		$bridge = new Doge_Cloud( 'key', 'secret' );
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

		$bridge = new Doge_Cloud( 'k', 's' );

		$this->assertFalse( $bridge->get_credentials() );
		$this->assertNotSame( '', $bridge->last_error() );
	}

	/**
	 * 缓存里还剩一大截有效期时，不该为了「保险」去打扰服务商。
	 *
	 * 多吉云每天只给 80000 次调用额度，按上传次数取 token 会把额度很快烧完。
	 */
	public function test_does_not_refresh_while_more_than_the_margin_remains(): void {
		$key    = 'AKID-QUIET';
		$expiry = time() + 2400;
		$bridge = new Doge_Cloud( $key, 'SECRET' );
		$this->seed( $key, $expiry );
		unset( $GLOBALS['sakura_test_last_http'] );

		$token = $bridge->get_credentials_with_expiry();

		$this->assertIsArray( $token );
		$this->assertSame( $expiry, $token['expires'] );
		$this->assertFalse( isset( $GLOBALS['sakura_test_last_http'] ), '凭据还够用就不该发请求' );
	}

	/**
	 * 进入提前量之后，必须去换一份新的。
	 */
	public function test_refreshes_inside_the_margin(): void {
		$key     = 'AKID-REFRESH';
		$expires = time() + 7200;
		$bridge  = new Doge_Cloud( $key, 'SECRET' );
		$this->seed( $key, time() + 1200 );
		$this->respond( $this->success_body( 'AKID-NEW', 'SECRET-NEW', 'TOKEN-NEW', $expires ) );
		unset( $GLOBALS['sakura_test_last_http'] );

		$token = $bridge->get_credentials_with_expiry();

		$this->assertIsArray( $token );
		$this->assertSame( 'AKID-NEW', $token['key'] );
		$this->assertSame( $expires, $token['expires'] );
		$this->assertTrue( isset( $GLOBALS['sakura_test_last_http'] ), '进入提前量就该刷新一次' );
	}

	/**
	 * 续期失败但旧凭据还没过期：继续用旧的，别把跑了一半的批量任务打断。
	 */
	public function test_keeps_the_unexpired_token_when_refresh_fails(): void {
		$key    = 'AKID-FALLBACK';
		$expiry = time() + 600;
		$bridge = new Doge_Cloud( $key, 'SECRET' );
		$this->seed( $key, $expiry );
		$this->respond(
			array(
				'code' => 500,
				'msg'  => 'internal error',
			)
		);

		$token = $bridge->get_credentials_with_expiry();

		$this->assertIsArray( $token );
		$this->assertSame( 'AKID-OLD', $token['key'] );
		$this->assertSame( $expiry, $token['expires'] );
		$this->assertSame( '', $bridge->last_error(), '还能用的时候不该给用户看错误' );
	}

	/**
	 * 旧凭据已经过期、新的又取不到，这时才允许失败。
	 */
	public function test_reports_failure_when_the_expired_token_cannot_be_refreshed(): void {
		$key    = 'AKID-DEAD';
		$bridge = new Doge_Cloud( $key, 'SECRET' );
		$this->seed( $key, time() - 10 );
		$this->respond(
			array(
				'code' => 403,
				'msg'  => 'space not found',
			)
		);

		$this->assertFalse( $bridge->get_credentials_with_expiry() );
		$this->assertStringContainsString( 'space not found', $bridge->last_error() );
	}

	/**
	 * 续期失败后的一小段时间内不再重试，别让一次接口抖动打满额度。
	 */
	public function test_backs_off_after_a_failure(): void {
		$key    = 'AKID-BACKOFF';
		$bridge = new Doge_Cloud( $key, 'SECRET' );
		$this->respond(
			array(
				'code' => 503,
				'msg'  => 'busy',
			)
		);

		$this->assertFalse( $bridge->get_credentials_with_expiry() );
		unset( $GLOBALS['sakura_test_last_http'] );

		$again = new Doge_Cloud( $key, 'SECRET' );
		$this->assertFalse( $again->get_credentials_with_expiry() );
		$this->assertFalse( isset( $GLOBALS['sakura_test_last_http'] ), '退避窗口内不该再打接口' );
		$this->assertStringContainsString( 'busy', $again->last_error() );
	}

	/**
	 * 还在提前量里、接口又挂了：退避窗口内不许反复打接口。
	 *
	 * 这是批量上传最坏的形态——缓存还在（所以「有没有缓存」那道闸形同虚设），
	 * 但每一次签名都会进提前量。没有这道检查，一次接口抖动会被放大成成百上
	 * 千次调用，直接把每日额度烧穿。
	 */
	public function test_backs_off_even_when_a_usable_token_is_cached(): void {
		$key    = 'AKID-BACKOFF-CACHED';
		$expiry = time() + 1200;
		$this->seed( $key, $expiry );
		$this->respond(
			array(
				'code' => 503,
				'msg'  => 'busy',
			)
		);

		$first = new Doge_Cloud( $key, 'SECRET' );
		$this->assertIsArray( $first->get_credentials_with_expiry(), '旧凭据还没过期，照样能用' );

		unset( $GLOBALS['sakura_test_last_http'] );

		$second = new Doge_Cloud( $key, 'SECRET' );
		$token  = $second->get_credentials_with_expiry();

		$this->assertIsArray( $token );
		$this->assertSame( 'AKID-OLD', $token['key'] );
		$this->assertFalse( isset( $GLOBALS['sakura_test_last_http'] ), '退避窗口内不该再打接口' );
		$this->assertSame( '', $second->last_error(), '还能用就不该报错' );
	}

	/**
	 * 过期时间必须是服务商给的那个 unix 秒，不能是「现在加两小时」。
	 */
	public function test_expiry_is_the_provider_deadline(): void {
		$key     = 'AKID-EXPIRY';
		$expires = time() + 5400;
		$bridge  = new Doge_Cloud( $key, 'SECRET' );
		$this->respond( $this->success_body( 'AKID', 'SECRET', 'TOKEN', $expires ) );

		$token = $bridge->get_credentials_with_expiry();

		$this->assertIsArray( $token );
		$this->assertSame( $expires, $token['expires'] );
	}

	/**
	 * 预置一份缓存，直接跳过「先取一次」这一步。
	 *
	 * @param string $key       Access key the transient is keyed by.
	 * @param int    $expires   Expiry to advertise.
	 * @param string $token_key Access key id stored in the payload.
	 * @return void
	 */
	private function seed( string $key, int $expires, string $token_key = 'AKID-OLD' ): void {
		set_transient(
			'sakura_imgopt_doge_token_' . md5( $key ),
			array(
				'code' => 200,
				'msg'  => 'OK',
				'data' => array(
					'Credentials' => array(
						'accessKeyId'     => $token_key,
						'secretAccessKey' => 'SECRET-OLD',
						'sessionToken'    => 'TOKEN-OLD',
					),
					'ExpiredAt'   => $expires,
					'Buckets'     => array(),
				),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * 一份成功的凭据响应体。
	 *
	 * @param string $key     Access key id.
	 * @param string $secret  Secret key.
	 * @param string $token   Session token.
	 * @param int    $expires Expiry timestamp.
	 * @return array<string,mixed>
	 */
	private function success_body( string $key, string $secret, string $token, int $expires ): array {
		return array(
			'code' => 200,
			'msg'  => 'OK',
			'data' => array(
				'Credentials' => array(
					'accessKeyId'     => $key,
					'secretAccessKey' => $secret,
					'sessionToken'    => $token,
				),
				'ExpiredAt'   => $expires,
				'Buckets'     => array(),
			),
		);
	}
}
