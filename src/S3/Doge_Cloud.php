<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\S3\Log;
use Sakura\ImageOptimizer\Filesystem\File_System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 获取多吉云短期 S3 凭据。
 *
 * 凭据缓存放在 WordPress transient（数据库/对象缓存）中，不再写入
 * wp-content 下的 JSON 文件，避免 token 以明文文件形式落在 Web 根内。
 */
class Doge_Cloud {
	/**
	 * 提前多久续期（秒）。
	 *
	 * 临时凭据两小时一换，拖到最后一刻才续会留下一段很窄的窗口，而一次
	 * 批量上传或迁移动辄跑几十分钟。提前半小时续，等于把「凭据会不会在
	 * 任务中途失效」挪出关键路径。
	 *
	 * @var int
	 */
	const REFRESH_MARGIN = 1800;

	/**
	 * 续期失败后的静默窗口（秒）。
	 *
	 * 惰性刷新意味着每签一次名都可能触发一次续期。接口抖动时若不退避，
	 * 一次批量上传会把同一个失败请求打上几百遍，还会吃满多吉云每天的调用
	 * 额度。60 秒内复用上一次的失败结论，刚好盖住一轮重试。
	 *
	 * @var int
	 */
	const RETRY_BACKOFF = 60;

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

	/**
	 * 网络层失败的原文（WP_Error 的 message）。
	 *
	 * 地址不可用、DNS 被劫持、被 wp_http_validate_url() 判为非法这类失败都
	 * 发生在发包之前，响应体根本不存在，于是上层只会看到一句「没有返回可
	 * 解析的响应」。把 WP_Error 的原文留下来，才分得清是签名错、额度停用
	 * 还是本机代理的 fake-IP 段（198.18.0.0/15）被 WordPress 拦下。
	 *
	 * @var string
	 */
	private $last_transport_error = '';

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
	 * 续期失败的静默标记 key。
	 *
	 * @return string
	 */
	private function backoff_key() {
		return 'sakura_imgopt_doge_backoff_' . md5( $this->access_key );
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
	 * 读取缓存里的凭据响应，不管它还新鲜不新鲜。
	 *
	 * 旧实现把「只剩一分钟」当成「没有缓存」，这恰恰丢掉了续期失败时唯一
	 * 的退路：只要那一行还没真正过期，正在跑的批量任务就能靠它跑完。过期
	 * 与否交给调用方按 ExpiredAt 判断。
	 *
	 * @return array|false
	 */
	private function read_cache() {
		$cached = get_transient( $this->cache_key() );
		if ( is_array( $cached ) && ! empty( $cached['data']['ExpiredAt'] ) ) {
			return $cached;
		}
		return false;
	}

	/**
	 * 缓存凭据响应。
	 *
	 * TTL 取满 ExpiredAt，写入时不再扣安全窗口：安全窗口是**刷新判定**的事
	 * （REFRESH_MARGIN），不是缓存存活时间。两处都扣 60 秒的旧写法会让
	 * 「凭据其实还有效、缓存却已经没了」成为常态，续期失败时无旧值可退。
	 *
	 * @param array $response 已解码的响应体。
	 * @return void
	 */
	private function cache_response( $response ) {
		if ( ! is_array( $response ) || empty( $response['data']['ExpiredAt'] ) ) {
			return;
		}

		$ttl = (int) $response['data']['ExpiredAt'] - time();
		if ( $ttl > 0 ) {
			set_transient( $this->cache_key(), $response, min( $ttl, DAY_IN_SECONDS ) );
		}
	}

	private function request_credentials() {
		$this->last_transport_error = '';

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
		/*
		 * 用 wp_remote_post 而不是 wp_safe_remote_post：目标是硬编码的厂商
		 * 接口地址，不来自任何用户输入，SSRF 校验在这里换不来安全，却会让
		 * 整条凭据链在「域名被解析到 198.18.0.0/15」时整体失效——Clash /
		 * mihomo 的 fake-IP 模式正是把域名解析到这一段，而 WordPress 的
		 * wp_http_validate_url() 把它标成 benchmarking 网段，请求在发包之前
		 * 就被拒成「URL 无效」，用户看到的是「多吉云接口没有返回可解析的响应」。
		 */
		$response  = wp_remote_post(
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
			$this->last_transport_error = (string) $response->get_error_message();
			Log::write( __( '多吉云临时凭据请求失败：', 'sakura-imgopt' ) . $this->last_transport_error );
			return false;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			Log::write( __( '多吉云临时凭据请求返回非 200 状态。', 'sakura-imgopt' ) );
			return false;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : false;
	}

	/**
	 * 临时凭据，附带过期时间。
	 *
	 * 旧的 get_credentials() 只回 data.Credentials，把 ExpiredAt 丢掉了，于是
	 * 上层没有任何办法知道这组凭据什么时候作废——只能在「够不够用」这个问题上
	 * 靠猜。这里把 ExpiredAt 一起交给调用方：它要拿这个值填 Credentials::expires，
	 * 否则 SDK 会把临时凭据当成永久凭据（memoize() 里 expires 为 null 就永不刷新）。
	 *
	 * @return array{key:string,secret:string,token:string,expires:int}|false
	 */
	public function get_credentials_with_expiry() {
		$response = $this->response();
		if ( ! is_array( $response ) || empty( $response['data']['Credentials'] ) ) {
			return false;
		}

		$credentials = $response['data']['Credentials'];

		return array(
			'key'     => (string) ( $credentials['accessKeyId'] ?? '' ),
			'secret'  => (string) ( $credentials['secretAccessKey'] ?? '' ),
			'token'   => (string) ( $credentials['sessionToken'] ?? '' ),
			'expires' => (int) $response['data']['ExpiredAt'],
		);
	}

	/**
	 * 临时凭据本体（不含过期时间）。
	 *
	 * @return array<string,string>|false
	 */
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
	 * 取一份成功响应，必要时先续期。
	 *
	 * 惰性续期就发生在这里：调用方（签名中间件）每次要凭据都会进来，所以
	 * 「还剩多久」的判断必须便宜且准确——它跑在上传的关键路径上。
	 *
	 * 判定顺序：
	 *
	 * 1. 剩得比 REFRESH_MARGIN 多——直接用，不联网。
	 * 2. 落在提前量里——续期。成功换新的；失败但旧值还没真正过期时继续用旧的，
	 *    原因只进日志。正在跑的批量任务不该因为一次接口抖动整批失败。
	 * 3. 旧值也已过期，或根本没有缓存——失败就是失败，last_error 带原因。
	 *
	 * 退避标记必须在**这里**检查，不能只放在「没缓存」那一支：批量上传时
	 * 缓存通常还在（只是进了提前量），如果只看有没有缓存，接口挂掉的那几分钟
	 * 里每一次签名都会重新打一次接口，把每日额度烧穿。
	 *
	 * Access Key / Secret 错误时多吉云也返回 HTTP 200，错误体现在响应体的
	 * code 字段上，所以必须连 code 一起判断，不能只看状态码。
	 *
	 * @return array|false
	 */
	private function response() {
		$this->cleanup_legacy_cache();
		$this->last_error = '';

		$cached    = $this->read_cache();
		$remaining = $cached ? (int) $cached['data']['ExpiredAt'] - time() : 0;

		if ( $cached && $remaining > self::REFRESH_MARGIN ) {
			return $cached;
		}

		$backoff = get_transient( $this->backoff_key() );
		if ( is_string( $backoff ) && '' !== $backoff ) {
			if ( $cached && $remaining > 0 ) {
				// 退避窗口里，旧凭据还能用：静默继续，别把上一轮的失败再喊一遍。
				return $cached;
			}
			$this->last_error = $backoff;
			return false;
		}

		$fresh = $this->fetch();
		if ( is_array( $fresh ) ) {
			return $fresh;
		}

		if ( $cached && $remaining > 0 ) {
			/*
			 * 旧的还没过期，续期先失败了。继续用它，把原因交给日志：
			 * 签名请求会正常完成，用户看不到一句「凭据无效」。
			 */
			Log::write( __( '多吉云临时凭据续期失败，继续使用尚未过期的旧凭据：', 'sakura-imgopt' ) . $this->last_error );
			$this->last_error = '';
			return $cached;
		}

		return false;
	}

	/**
	 * 真正打一次接口：成功写缓存并清退避标记，失败写退避标记。
	 *
	 * @return array|false
	 */
	private function fetch() {
		$response = $this->request_credentials();
		if ( ! is_array( $response ) ) {
			$this->last_error = '' !== $this->last_transport_error
				? sprintf(
					/* translators: %s: transport-level error message */
					__( '多吉云接口请求失败：%s', 'sakura-imgopt' ),
					$this->last_transport_error
				)
				: __( '多吉云接口没有返回可解析的响应，请稍后重试。', 'sakura-imgopt' );
			$this->arm_backoff();
			return false;
		}
		if ( 200 !== (int) ( $response['code'] ?? 0 ) ) {
			$this->last_error = $this->describe_failure( $response );
			$this->arm_backoff();
			return false;
		}

		$this->cache_response( $response );
		delete_transient( $this->backoff_key() );
		return $response;
	}

	/**
	 * 记下这次失败，RETRY_BACKOFF 秒内不再重试。
	 *
	 * @return void
	 */
	private function arm_backoff() {
		set_transient( $this->backoff_key(), $this->last_error, self::RETRY_BACKOFF );
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
