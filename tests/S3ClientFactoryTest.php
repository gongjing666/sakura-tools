<?php
/**
 * S3 client factory tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Client_Factory;

/**
 * Covers the endpoint addressing decision, which silently breaks a correct
 * bucket/endpoint pair when it goes the wrong way.
 */
final class S3ClientFactoryTest extends TestCase {

	/**
	 * Bucket-in-host providers must not be addressed path style.
	 */
	public function test_virtual_host_providers(): void {
		foreach ( array( 'aliyun', 'tencent', 'qiniu', 'upyun', 'baidu', 'backblaze', 'bitiful', 'dogecloud' ) as $provider ) {
			$this->assertFalse(
				Client_Factory::path_style_for( $provider, 'https://s3.example.com' ),
				$provider . ' must use the virtual-host form'
			);
		}
	}

	/**
	 * 多吉云 runs on a COS backend: a path-style request is refused with
	 * 403 PathStyleDomainForbidden even though key and bucket are correct.
	 */
	public function test_doge_cloud_is_virtual_host(): void {
		$this->assertFalse( Client_Factory::path_style_for( 'dogecloud', 'https://cos.ap-chengdu.myqcloud.com' ) );
	}

	/**
	 * Self-hosted gateways keep the path form, which is why an endpoint alone
	 * used to decide the question.
	 */
	public function test_generic_providers_stay_path_style(): void {
		$this->assertTrue( Client_Factory::path_style_for( 'minio', 'https://minio.example.com' ) );
		$this->assertTrue( Client_Factory::path_style_for( 'cloudflare', 'https://account.r2.cloudflarestorage.com' ) );
	}

	/**
	 * No endpoint means AWS itself, where the SDK already picks the host form.
	 */
	public function test_no_endpoint_never_forces_path_style(): void {
		$this->assertFalse( Client_Factory::path_style_for( 'minio', '' ) );
		$this->assertFalse( Client_Factory::path_style_for( 'dogecloud', '' ) );
	}
	/**
	 * 建客户端这件事本身不该联网。
	 *
	 * 惰性刷新的全部意义就在这里：拿 token 挪到签名那一刻，所以构造客户端
	 * 成功不代表凭据可用——真正的校验交给 preflight()。
	 */
	public function test_make_does_not_fetch_a_doge_token(): void {
		if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
			define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
		}
		unset( $GLOBALS['sakura_test_last_http'] );
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'code' => 500, 'msg' => 'must not be called' ) ),
		);

		try {
			Client_Factory::make( $this->doge_options( 'AKID-MAKE' ) );
			$this->assertFalse( isset( $GLOBALS['sakura_test_last_http'] ), 'make() 不该为了拿 token 发请求' );
		} finally {
			unset( $GLOBALS['sakura_test_http_response'], $GLOBALS['sakura_test_last_http'] );
		}
	}

	/**
	 * 凭据是在签名时才解析的，而且必须带过期时间。
	 */
	public function test_credentials_resolve_lazily_with_an_expiry(): void {
		if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
			define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
		}
		$expires = time() + 7200;
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array(
					'code' => 200,
					'msg'  => 'OK',
					'data' => array(
						'Credentials' => array(
							'accessKeyId'     => 'AKID-LAZY',
							'secretAccessKey' => 'SECRET-LAZY',
							'sessionToken'    => 'TOKEN-LAZY',
						),
						'ExpiredAt'   => $expires,
						'Buckets'     => array(),
					),
				)
			),
		);

		try {
			$client = Client_Factory::make( $this->doge_options( 'AKID-LAZY' ) );
			$creds  = $client->getCredentials()->wait();

			$this->assertSame( 'AKID-LAZY', $creds->getAccessKeyId() );
			$this->assertSame( 'TOKEN-LAZY', $creds->getSecurityToken() );
			$this->assertSame( $expires, $creds->getExpiration(), '不传过期时间，SDK 会把临时凭据当永久的' );
		} finally {
			unset( $GLOBALS['sakura_test_http_response'], $GLOBALS['sakura_test_last_http'] );
			delete_transient( 'sakura_imgopt_doge_token_' . md5( 'AKID-LAZY' ) );
		}
	}

	/**
	 * 真走一次签名，确认闭包这条链在预签名路径上也成立。
	 *
	 * 预签名自己会 wait() 一次凭据，所以它能证明三件事：闭包被调用、返回的
	 * 是 Promise、Credential 带上了 session token。上传与删除走的正是同一个
	 * 签名中间件。
	 */
	public function test_presigning_uses_the_lazy_credentials(): void {
		if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
			define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
		}
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array(
					'code' => 200,
					'msg'  => 'OK',
					'data' => array(
						'Credentials' => array(
							'accessKeyId'     => 'AKID-PRESIGN',
							'secretAccessKey' => 'SECRET-PRESIGN',
							'sessionToken'    => 'TOKEN-PRESIGN',
						),
						'ExpiredAt'   => time() + 7200,
						'Buckets'     => array(),
					),
				)
			),
		);

		try {
			$client = Client_Factory::make( $this->doge_options( 'AKID-PRESIGN' ) );
			$url    = (string) $client->createPresignedRequest(
				$client->getCommand( 'GetObject', array( 'Bucket' => 'doss-v2-test', 'Key' => 'a/b.jpg' ) ),
				'+10 minutes'
			)->getUri();

			$this->assertStringContainsString( 'AKID-PRESIGN', $url );
			$this->assertStringContainsString( 'TOKEN-PRESIGN', $url, '临时凭据的 session token 必须出现在签名里' );
		} finally {
			unset( $GLOBALS['sakura_test_http_response'], $GLOBALS['sakura_test_last_http'] );
			delete_transient( 'sakura_imgopt_doge_token_' . md5( 'AKID-PRESIGN' ) );
		}
	}

	/**
	 * preflight() 是「用户在等结论」的入口用的：取不到就当面说清楚。
	 */
	public function test_preflight_reports_a_dead_key(): void {
		$GLOBALS['sakura_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'code' => 403, 'msg' => 'AccessKey Format Error' ) ),
		);

		try {
			$this->expectException( \Sakura\ImageOptimizer\S3\S3_Credential_Error::class );
			$this->expectExceptionMessage( 'AccessKey Format Error' );
			Client_Factory::preflight( $this->doge_options( 'AKID-DEAD-KEY' ) );
		} finally {
			unset( $GLOBALS['sakura_test_http_response'], $GLOBALS['sakura_test_last_http'] );
			delete_transient( 'sakura_imgopt_doge_backoff_' . md5( 'AKID-DEAD-KEY' ) );
		}
	}

	/**
	 * 别的厂商没有这一步，preflight() 不能凭空联网。
	 */
	public function test_preflight_ignores_other_providers(): void {
		unset( $GLOBALS['sakura_test_last_http'] );
		Client_Factory::preflight(
			array(
				'provider' => 'tencent',
				'bucket'   => 'b',
				'key'      => 'k',
				'secret'   => 's',
				'endpoint' => 'https://cos.ap-shanghai.myqcloud.com',
			)
		);
		$this->assertFalse( isset( $GLOBALS['sakura_test_last_http'] ) );
		$this->assertTrue( true );
	}

	/**
	 * 一份能过 is_configured() 的多吉云配置。
	 *
	 * @param string $key Access key id.
	 * @return array<string,string>
	 */
	private function doge_options( string $key ): array {
		return array(
			'provider' => 'dogecloud',
			'bucket'   => 'doss-v2-test',
			'key'      => $key,
			'secret'   => 'SECRET-KEY',
			'endpoint' => 'https://cos.ap-chengdu.myqcloud.com',
			'region'   => 'ap-chengdu',
		);
	}
}
