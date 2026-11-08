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

	public function __construct( $access_key, $secret_key ) {
		$this->access_key = (string) $access_key;
		$this->secret_key = (string) $secret_key;
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
		$this->cleanup_legacy_cache();

		$response = $this->get_cached_response();
		if ( ! $response ) {
			$response = $this->request_credentials();
			if ( is_array( $response ) ) {
				$this->cache_response( $response );
			}
		}

		if ( is_array( $response ) && 200 === (int) ( $response['code'] ?? 0 ) && ! empty( $response['data']['Credentials'] ) ) {
			return $response['data']['Credentials'];
		}
		return false;
	}
}
