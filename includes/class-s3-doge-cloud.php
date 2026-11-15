<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\S3\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 获取多吉云短期 S3 凭据。
 *
 * 凭据缓存放在 WordPress transient（数据库/对象缓存）中，不再写入
 * wp-content 下的 JSON 文件，避免 token 以明文文件形式落在 Web 根内。
 */
class S3_Doge_Cloud {
	private $access_key;
	private $secret_key;

	/**
	 * 最近一次失败的原因，供上层原样展示给用户。
	 *
	 * 多吉云把错误写在响应体里（HTTP 仍是 200），此前只返回 false，
	 * 上层只能显示一句「无法获取多吉云临时凭据」，用户看不出是
	 * Access Key 格式错、签名错还是额度被停。
	 *
	 * @var string
	 */
	private $last_error = '';

	public function __construct( $access_key, $secret_key ) {
		$this->access_key = (string) $access_key;
		$this->secret_key = (string) $secret_key;
	}

	/**
	 * 最近一次失败原因；成功时为空串。
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * 当前 access key 对应的 transient key。
	 *
	 * @return string
	 */
	private function cache_key() {
		return 'sakura_imgopt_doge_token_' . md5( $this->access_key );
	}

	/**
	 * 清理旧版本遗留的磁盘缓存（仅 token 文件，目录与其他哨兵文件保留）。
	 *
	 * @return void
	 */
	private function cleanup_legacy_cache() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$uploads = wp_upload_dir( null, false );
		$base    = ! empty( $uploads['basedir'] ) ? dirname( $uploads['basedir'] ) : WP_CONTENT_DIR;
		$token   = trailingslashit( $base ) . '.sakura-s3-cache/dogecloud-token.json';

		if ( file_exists( $token ) ) {
			File_System::delete( $token );
		}
	}

	/**
	 * 从 transient 读取仍有效的凭据响应。
	 *
	 * @return array|false
	 */
	private function get_cached_response() {
		$cached = get_transient( $this->cache_key() );
		if (
			is_array( $cached )
			&& ! empty( $cached['data']['ExpiredAt'] )
			&& (int) $cached['data']['ExpiredAt'] > time() + 60
		) {
			return $cached;
		}
		return false;
	}

	/**
	 * 缓存凭据响应；TTL 取 ExpiredAt 前 60 秒，避免边界过期。
	 *
	 * @param array $response
	 * @return void
	 */
	private function cache_response( $response ) {
		if ( ! is_array( $response ) || empty( $response['data']['ExpiredAt'] ) ) {
			return;
		}

		$ttl = (int) $response['data']['ExpiredAt'] - time() - 60;
		if ( $ttl > 0 ) {
			set_transient( $this->cache_key(), $response, min( $ttl, DAY_IN_SECONDS ) );
		}
	}

	private function request_credentials() {
		$path = '/auth/tmp_token.json';
		$data = array(
			'channel' => 'OSS_FULL',
			'scopes'  => array( '*' ),
		);
		$body = wp_json_encode( $data );
		if ( false === $body ) {
			return false;
		}
		$signature = hash_hmac( 'sha1', $path . "\n" . $body, $this->secret_key );
		$response  = wp_safe_remote_post(
			'https://api.dogecloud.com' . $path,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'TOKEN ' . $this->access_key . ':' . $signature,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			Log::write( __( '多吉云临时凭据请求失败：', 'sakura-imgopt' ) . $response->get_error_message() );
			return false;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			Log::write( __( '多吉云临时凭据请求返回非 200 状态。', 'sakura-imgopt' ) );
			return false;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : false;
	}

	public function get_credentials() {
		$response = $this->response();
		if ( is_array( $response ) && ! empty( $response['data']['Credentials'] ) ) {
			return $response['data']['Credentials'];
		}
		return false;
	}

	/**
	 * 同一份响应里的存储空间列表。
	 *
	 * 凭据和 s3Bucket / s3Endpoint 出自同一个接口，此前只取 Credentials，
	 * 把 Buckets 丢掉了，于是用户只能对着控制台里那个「空间名称」手抄两个
	 * 长得完全不像它的值。这里把它读回来给表单做自动填充。
	 *
	 * @return array<int,array<string,mixed>>|false
	 */
	public function get_buckets() {
		$response = $this->response();
		if ( ! is_array( $response ) || ! isset( $response['data']['Buckets'] ) || ! is_array( $response['data']['Buckets'] ) ) {
			return false;
		}
		return array_values( $response['data']['Buckets'] );
	}

	/**
	 * 取一份成功响应：命中缓存就复用，否则重新请求并缓存。
	 *
	 * Access Key / Secret 错误时多吉云也返回 HTTP 200，错误体现在响应体的
	 * code 字段上，所以这里必须连 code 一起判断，不能只看状态码。
	 *
	 * @return array|false
	 */
	private function response() {
		$this->cleanup_legacy_cache();
		$this->last_error = '';

		$response = $this->get_cached_response();
		if ( ! $response ) {
			$response = $this->request_credentials();
			if ( ! is_array( $response ) ) {
				$this->last_error = __( '多吉云接口没有返回可解析的响应，请稍后重试。', 'sakura-imgopt' );
				return false;
			}
			if ( 200 !== (int) ( $response['code'] ?? 0 ) ) {
				$this->last_error = $this->describe_failure( $response );
				return false;
			}
			$this->cache_response( $response );
		}

		return $response;
	}

	/**
	 * 把多吉云的错误响应翻成一句可读的原因。
	 *
	 * @param array $response 已解码的响应体。
	 * @return string
	 */
	private function describe_failure( array $response ) {
		$message = trim( (string) ( $response['msg'] ?? '' ) );
		$code    = (int) ( $response['code'] ?? 0 );

		if ( '' === $message ) {
			return (string) sprintf(
				/* translators: %d: HTTP-like status code returned by 多吉云. */
				__( '多吉云接口返回错误（code %d），请检查 Access Key 和 Secret Key。', 'sakura-imgopt' ),
				$code
			);
		}

		return (string) sprintf(
			/* translators: 1: error message from 多吉云, 2: numeric code. */
			__( '多吉云接口返回错误（code %2$d）：%1$s。请检查 Access Key 和 Secret Key。', 'sakura-imgopt' ),
			$message,
			$code
		);
	}
}
