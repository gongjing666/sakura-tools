<?php
/**
 * S3 client construction.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\Vendor\Aws\Credentials\Credentials;
use Sakura\Vendor\Aws\S3\S3Client;
use Sakura\Vendor\GuzzleHttp\Promise\Create;
use Sakura\ImageOptimizer\S3\Doge_Cloud;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and caches AWS SDK clients from an options array.
 */
final class Client_Factory {

	/**
	 * Providers that reject path-style addressing.
	 *
	 * Filling in an endpoint normally means "not AWS", so path style is the safe
	 * default: MinIO, R2 and self-hosted gateways all answer
	 * `<endpoint>/<bucket>/<key>`. These services instead put the bucket in the
	 * host name (`<bucket>.<endpoint>/<key>`) and answer the other form with an
	 * error — 多吉云 runs on a COS backend and returns
	 * `403 PathStyleDomainForbidden`, so it must be listed here or a correct
	 * bucket/endpoint pair still fails the connection test.
	 *
	 * @var array<int,string>
	 */
	private const VIRTUAL_HOST_PROVIDERS = array( 'aliyun', 'tencent', 'qiniu', 'upyun', 'baidu', 'backblaze', 'bitiful', 'dogecloud' );

	/**
	 * Client cache keyed by options hash.
	 *
	 * @var array<string, S3Client>
	 */
	private static array $clients = array();

	/**
	 * Whether the bundled SDK can run requests in parallel.
	 *
	 * Parallel uploads need curl's multi handler. Without it every transfer must
	 * stay sequential so the request still completes and verifies.
	 */
	public static function supports_parallel(): bool {
		if ( ! function_exists( 'curl_multi_exec' ) || ! extension_loaded( 'curl' ) ) {
			return false;
		}

		$autoload = SAKURA_TOOLS_PATH . 'vendor/s3/vendor-prefixed/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return false;
		}
		require_once $autoload;

		return class_exists( 'Sakura\\Vendor\\GuzzleHttp\\Handler\\CurlMultiHandler' );
	}

	/**
	 * Build or reuse a client.
	 *
	 * @param array<string, mixed> $options S3 options.
	 * @return S3Client
	 */
	public static function make( array $options ): S3Client {
		$resolved = new Options( $options );
		if ( ! $resolved->is_configured() ) {
			throw new \RuntimeException( esc_html__( 'S3 配置不完整：Bucket、Key、Secret 与 Endpoint 必须齐备。', 'sakura-imgopt' ) );
		}

		$cache_key = hash( 'sha256', (string) wp_json_encode( $options ) );
		if ( isset( self::$clients[ $cache_key ] ) ) {
			return self::$clients[ $cache_key ];
		}

		$autoload = SAKURA_TOOLS_PATH . 'vendor/s3/vendor-prefixed/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			throw new \RuntimeException( esc_html__( 'S3 SDK 不完整，请重新安装插件。', 'sakura-imgopt' ) );
		}
		require_once $autoload;

		$provider = (string) ( $options['provider'] ?? '' );
		$static   = array(
			'key'    => (string) $options['key'],
			'secret' => (string) $options['secret'],
		);

		/*
		 * 多吉云只发两小时有效的临时凭据，所以这里不能像别的厂商那样把 key
		 * 写死在 config 里：客户端一建好就该能活过任意长度的批量任务。
		 *
		 * 传闭包而不是数组是关键——SDK 的 ClientResolver::_apply_credentials()
		 * 见到 callable 就原样放行、不包 CredentialProvider，签名中间件于是
		 * **每签一次名**都调一次这个闭包（Middleware::signer()）。续期就发生在
		 * 这里：把「还剩多久」交给 Doge_Cloud 自己判断，工厂不管。
		 *
		 * 两条硬约束，改的时候别丢：
		 * - 必须返回 Promise（Create::promiseFor / rejectionFor），签名中间件
		 *   拿到就 .then()，返回数组会直接 fatal；
		 * - Credentials 必须带 $expires。不传的话 SDK 视为永久凭据，永不刷新。
		 */
		$credentials = $static;
		if ( 'dogecloud' === $provider ) {
			$credentials = static function () use ( $static ) {
				$bridge = new Doge_Cloud( $static['key'], $static['secret'] );
				$token  = $bridge->get_credentials_with_expiry();

				if ( ! is_array( $token ) ) {
					$reason = $bridge->last_error();
					// 专门一类：让上层能把「凭据链断了」和「网络/桶不对」分开说。
					return Create::rejectionFor(
						new S3_Credential_Error(
							'' !== $reason
								? $reason // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as plain text, escaping would print entities.
								: esc_html__( '无法获取多吉云临时凭据。', 'sakura-imgopt' )
						)
					);
				}

				return Create::promiseFor(
					new Credentials( $token['key'], $token['secret'], $token['token'], $token['expires'] )
				);
			};
		}

		$path_style = self::path_style_for( $provider, (string) ( $options['endpoint'] ?? '' ) );

		$config = array(
			'version'                 => 'latest',
			'region'                  => ! empty( $options['region'] ) ? (string) $options['region'] : 'us-east-1',
			'credentials'             => $credentials,
			'use_path_style_endpoint' => $path_style,
			'http'                    => array(
				'connect_timeout' => 10,
				'timeout'         => 60,
			),
			'retries'                 => 2,
			// 第三方 S3 兼容服务通常不实现 SDK 默认的 CRC32 校验头；仅在协议要求时计算。
			'request_checksum_calculation'  => 'when_required',
			'response_checksum_validation' => 'when_required',
			// 凭据一律由上面的 $credentials 显式给出，绝不读服务器的 ~/.aws/config：
			// 一是可能悄悄用上运维机器的默认配置，二是 open_basedir 把家目录挡在外面时
			// SDK 会对 is_readable() 打 PHP Warning（宝塔站点实测会进 debug.log）。
			'use_aws_shared_config_files'  => false,
		);
		if ( ! empty( $options['endpoint'] ) ) {
			$config['endpoint'] = (string) $options['endpoint'];
		}

		self::$clients[ $cache_key ] = new S3Client( $config );
		return self::$clients[ $cache_key ];
	}

	/**
	 * 立刻验证这组配置拿得到凭据，拿不到就抛。
	 *
	 * 惰性刷新的代价：make() 不再联网，所以「密钥填错了」这件事不会在保存
	 * 配置或点「测试连接」时暴露，而要等第一次真实请求才报出来——那时错误
	 * 已经变成 S3Exception 或 WP_Error，连接诊断里「Endpoint 通不通、桶在不在」
	 * 的信息就都问不出来了，用户只会看到一句含糊的失败。
	 *
	 * 所以凡是有用户在等结论的入口（设置页测试连接、同步中心连接测试、
	 * 各配置卡的探测），在 make() 之后补一次这里：先把凭据问题挑出来，
	 * 剩下的网络与桶的问题再交给 Connection_Probe 判断。
	 *
	 * 非多吉云配置什么都不做，也不联网。
	 *
	 * @param array<string, mixed> $options S3 options.
	 * @return void
	 * @throws S3_Credential_Error 凭据取不到时。
	 */
	public static function preflight( array $options ): void {
		if ( 'dogecloud' !== ( $options['provider'] ?? '' ) ) {
			return;
		}

		$bridge = new Doge_Cloud( (string) ( $options['key'] ?? '' ), (string) ( $options['secret'] ?? '' ) );
		if ( is_array( $bridge->get_credentials_with_expiry() ) ) {
			return;
		}

		$reason = $bridge->last_error();
		if ( '' !== $reason ) {
			throw new S3_Credential_Error( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as plain text, escaping would print entities.
		}

		throw new S3_Credential_Error( esc_html__( '无法获取多吉云临时凭据。', 'sakura-imgopt' ) );
	}

	/**
	 * Whether requests must address the bucket as a path segment.
	 *
	 * Only a custom endpoint forces the decision at all: without one the SDK
	 * talks to AWS and addresses the bucket in the host name itself.
	 *
	 * @param string $provider Provider key.
	 * @param string $endpoint Endpoint URL, may be empty.
	 * @return bool
	 */
	public static function path_style_for( string $provider, string $endpoint ): bool {
		if ( '' === $endpoint ) {
			return false;
		}

		return ! in_array( $provider, self::VIRTUAL_HOST_PROVIDERS, true );
	}
}
