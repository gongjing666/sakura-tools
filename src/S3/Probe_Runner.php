<?php
/**
 * Connection probes and the 多吉云 space resolver, without a transport.
 *
 * Every probe used to answer with wp_send_json_*(), which made it impossible to
 * call from REST. The verdicts now live here; the admin-ajax handlers and the
 * React REST controller both wrap these methods.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the dry-run probes and the SDK parameter lookup.
 */
final class Probe_Runner {

	/**
	 * Probe the values currently typed into the form.
	 *
	 * Nothing is written: a wrong endpoint costs a save, a failed upload and a
	 * cleanup otherwise. A blank credential means "keep the saved one" because
	 * the form masks the stored key and never re-submits it unchanged.
	 *
	 * @param array<string,mixed> $input Form values (already unslashed).
	 * @return array<string,mixed>
	 */
	public static function run_form( array $input ): array {
		$probe = ( new Options() )->all();

		if ( array_key_exists( 'provider', $input ) ) {
			$probe['provider'] = sanitize_key( (string) $input['provider'] );
		}

		foreach ( array( 'bucket', 'region' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$probe[ $field ] = sanitize_text_field( (string) $input[ $field ] );
			}
		}

		if ( array_key_exists( 'endpoint', $input ) ) {
			$probe['endpoint'] = esc_url_raw( trim( (string) $input['endpoint'] ), array( 'http', 'https' ) );
		}

		foreach ( array( 'key', 'secret' ) as $field ) {
			$typed = array_key_exists( $field, $input ) ? Settings_Saver::clean_credential( (string) $input[ $field ] ) : '';
			if ( '' !== $typed ) {
				$probe[ $field ] = $typed;
			}
		}

		$candidate = new Options( $probe );
		$missing   = $candidate->configuration_errors();
		if ( array() !== $missing ) {
			return self::verdict(
				false,
				self::missing_fields_message( $missing ),
				'missing_fields',
				0,
				0
			);
		}

		try {
			$client = Client_Factory::make( $probe );
			// 凭据惰性取：不预检的话，取不到 token 会被下面的探针说成 Endpoint 不通。
			Client_Factory::preflight( $probe );
		} catch ( \Throwable $error ) {
			// The verdict still reaches the operator: a client that never got
			// built is a failed test, not a broken request.
			return self::verdict( false, $error->getMessage(), '', 0, 0 );
		}

		return Connection_Probe::diagnose(
			$client,
			(string) $probe['bucket'],
			array( (string) $probe['key'], (string) $probe['secret'] )
		);
	}

	/**
	 * Probe one saved profile with its own stored credentials.
	 *
	 * @param string $profile_id Profile id.
	 * @return array<string,mixed>
	 */
	public static function run_profile( string $profile_id ): array {
		$options  = new Options();
		$snapshot = $options->profile_options( $profile_id );
		if ( ! is_array( $snapshot ) ) {
			return self::verdict( false, __( '找不到这份配置，它可能已经被删除了。', 'sakura-imgopt' ), 'missing_profile', 404, 0 );
		}

		/*
		 * The snapshot carries ciphertext by design (that is what the queue
		 * worker stores). Constructing Options is what decrypts it, and the
		 * client factory must never see the raw row: it reads key and secret
		 * straight off the array it is handed, and a ciphertext pair would fail
		 * the signature check while looking like a wrong key.
		 */
		$candidate = new Options( $snapshot );
		$resolved  = $candidate->all();
		$missing   = $candidate->configuration_errors();
		if ( array() !== $missing ) {
			return self::verdict( false, self::missing_fields_message( $missing ), 'missing_fields', 200, 0 );
		}

		try {
			$client = Client_Factory::make( $resolved );
			// 同上：这份配置可能是惰性凭据的，预检才能把取 token 的失败讲清楚。
			Client_Factory::preflight( $resolved );
		} catch ( \Throwable $error ) {
			return self::verdict( false, $error->getMessage(), '', 200, 0 );
		}

		return Connection_Probe::diagnose(
			$client,
			(string) $resolved['bucket'],
			array( (string) $resolved['key'], (string) $resolved['secret'] )
		);
	}

	/**
	 * Resolve 多吉云 SDK parameters for a storage-space name.
	 *
	 * 多吉云's console only exposes the space name; the S3 client needs the
	 * appid-suffixed s3Bucket and the COS endpoint, and both live in the same
	 * temporary-credential response the client already fetches. Asking the
	 * operator to hand-copy them is how a working account ends up pointed at
	 * api.dogecloud.com with a 404.
	 *
	 * @param array<string,mixed> $input Form values (already unslashed).
	 * @return array{ok:bool,message:string,data:array<string,mixed>,http:int}
	 */
	public static function doge_buckets( array $input ): array {
		$space  = isset( $input['space'] ) ? sanitize_text_field( (string) $input['space'] ) : '';
		$key    = isset( $input['key'] ) ? Settings_Saver::clean_credential( (string) $input['key'] ) : '';
		$secret = isset( $input['secret'] ) ? Settings_Saver::clean_credential( (string) $input['secret'] ) : '';

		if ( '' === $key || '' === $secret ) {
			$saved  = ( new Options() )->all();
			$key    = (string) ( $saved['key'] ?? '' );
			$secret = (string) ( $saved['secret'] ?? '' );
		}

		if ( '' === $key || '' === $secret ) {
			return array(
				'ok'      => false,
				'message' => __( '请先填写多吉云的 Access Key 和 Secret Key，或保存一份可用的配置。', 'sakura-imgopt' ),
				'data'    => array(),
				'http'    => 400,
			);
		}

		$buckets = ( new \Sakura\ImageOptimizer\S3\Doge_Cloud( $key, $secret ) )->get_buckets();
		if ( ! is_array( $buckets ) ) {
			return array(
				'ok'      => false,
				'message' => __( '多吉云接口没有返回存储空间列表，请检查 Access Key 和 Secret Key 是否正确。', 'sakura-imgopt' ),
				'data'    => array(),
				'http'    => 400,
			);
		}

		return array(
			'ok'      => true,
			'message' => '',
			'data'    => Doge_Cloud_Resolver::resolve( $buckets, $space ),
			'http'    => 200,
		);
	}

	/**
	 * Verdict shape shared with Connection_Probe::diagnose().
	 *
	 * @param bool   $ok      Whether the bucket answered.
	 * @param string $message Why not, when it did not.
	 * @param string $code    Machine-readable reason.
	 * @param int    $http    Suggested HTTP status.
	 * @param int    $latency Round trip in milliseconds.
	 * @return array<string,mixed>
	 */
	private static function verdict( bool $ok, string $message, string $code, int $http, int $latency ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'code'    => $code,
			'status'  => 0,
			'latency' => $latency,
			'http'    => $http,
		);
	}

	/**
	 * Name the fields the probe cannot run without.
	 *
	 * @param string[] $fields Error keys from Options::configuration_errors().
	 */
	private static function missing_fields_message( array $fields ): string {
		$labels = array(
			'provider' => __( '服务商', 'sakura-imgopt' ),
			'bucket'   => __( '存储桶名称', 'sakura-imgopt' ),
			'key'      => __( 'Access Key', 'sakura-imgopt' ),
			'secret'   => __( 'Secret Key', 'sakura-imgopt' ),
			'endpoint' => __( '服务地址', 'sakura-imgopt' ),
		);

		$names = array();
		foreach ( $fields as $field ) {
			$names[] = $labels[ $field ] ?? $field;
		}

		return sprintf(
			/* translators: %s: comma-separated field names. */
			__( '还差这些内容才能测试：%s。', 'sakura-imgopt' ),
			implode( '、', $names )
		);
	}
}
