<?php
/**
 * S3 client construction.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\Vendor\Aws\S3\S3Client;
use Sakura\ImageOptimizer\S3_Doge_Cloud;

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

		$credentials = array(
			'key'    => (string) $options['key'],
			'secret' => (string) $options['secret'],
		);
		if ( 'dogecloud' === ( $options['provider'] ?? '' ) ) {
			$bridge    = new S3_Doge_Cloud( $credentials['key'], $credentials['secret'] );
			$temporary = $bridge->get_credentials();
			if ( ! is_array( $temporary ) ) {
				// The message travels to wp_send_json and lands in a modal via textContent.
				$reason = $bridge->last_error();
				if ( '' !== $reason ) {
					throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as plain text, escaping would print entities.
				}
				throw new \RuntimeException( esc_html__( '无法获取多吉云临时凭据。', 'sakura-imgopt' ) );
			}
			$credentials = array(
				'key'    => (string) ( $temporary['accessKeyId'] ?? '' ),
				'secret' => (string) ( $temporary['secretAccessKey'] ?? '' ),
				'token'  => (string) ( $temporary['sessionToken'] ?? '' ),
			);
		}

		$path_style = self::path_style_for( (string) ( $options['provider'] ?? '' ), (string) ( $options['endpoint'] ?? '' ) );

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
		);
		if ( ! empty( $options['endpoint'] ) ) {
			$config['endpoint'] = (string) $options['endpoint'];
		}

		self::$clients[ $cache_key ] = new S3Client( $config );
		return self::$clients[ $cache_key ];
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
