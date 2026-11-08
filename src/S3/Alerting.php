<?php
/**
 * Upload failure-rate alerting: threshold evaluation and JSON webhook delivery.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates upload health and posts JSON webhooks.
 *
 * Alerts are intentionally conservative: the operator enables them, picks a
 * failure-rate threshold with a minimum sample size, and sets a cooldown so a
 * broken bucket cannot turn into a webhook storm.
 */
final class Alerting implements Module {

	public const OPTION = 'sakura_imgopt_s3_alerts';

	public const HOOK = 'sakura_imgopt_s3_alert_check';

	public const SCHEDULE = 'sakura_imgopt_15min';

	public const COOLDOWN_TRANSIENT = 'sakura_imgopt_s3_alert_last';

	/**
	 * Default configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => 0,
			'webhook'      => '',
			'fail_rate'    => 25,
			'min_attempts' => 20,
			'cooldown'     => 30,
		);
	}

	/**
	 * Current sanitized configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Persist a sanitized configuration.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 * @return array<string,mixed>
	 */
	public static function save( array $raw ): array {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Sanitize configuration input.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$defaults = self::defaults();
		$webhook  = isset( $raw['webhook'] ) ? esc_url_raw( trim( (string) $raw['webhook'] ) ) : '';
		$webhook  = self::valid_url( $webhook ) ? $webhook : '';
		if ( '' !== $webhook && ! self::is_public_host( $webhook ) && ! apply_filters( 'sakura_imgopt_alert_allow_private', false, $webhook ) ) {
			$webhook = '';
		}

		return array(
			'enabled'      => empty( $raw['enabled'] ) ? 0 : 1,
			'webhook'      => $webhook,
			'fail_rate'    => self::clamp( $raw['fail_rate'] ?? $defaults['fail_rate'], 1, 100 ),
			'min_attempts' => self::clamp( $raw['min_attempts'] ?? $defaults['min_attempts'], 1, 100000 ),
			'cooldown'     => self::clamp( $raw['cooldown'] ?? $defaults['cooldown'], 5, 1440 ),
		);
	}

	/**
	 * Whether a string is a usable http(s) webhook URL.
	 *
	 * @param string $url URL.
	 */
	/**
	 * Whether a URL points at a public host.
	 *
	 * Alerting runs from the server, so a webhook aimed at localhost or a
	 * private range is an SSRF primitive. Hostnames are only checked for
	 * obvious internal suffixes; DNS resolution is skipped on purpose so saving
	 * settings never blocks on a resolver.
	 *
	 * @param string $url Valid http(s) URL.
	 */
	public static function is_public_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( trim( $host, '[]' ) );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		foreach ( array( 'localhost', '.local', '.internal', '.localhost', '.home.arpa', '.lan' ) as $suffix ) {
			if ( $host === ltrim( $suffix, '.' ) || str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		return true;
	}

	public static function valid_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		return isset( $parts['scheme'] ) && in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Evaluate alert reasons for a metrics snapshot.
	 *
	 * @param array<string,mixed> $snapshot Upload_Metrics::snapshot().
	 * @param array<string,mixed> $config   Sanitized configuration.
	 * @return array<int,string> Reasons: failure_rate.
	 */
	public static function evaluate( array $snapshot, array $config ): array {
		if ( empty( $config['enabled'] ) ) {
			return array();
		}

		$reasons = array();
		$week    = isset( $snapshot['week'] ) && is_array( $snapshot['week'] ) ? $snapshot['week'] : array();

		$attempts = isset( $week['attempts'] ) ? (int) $week['attempts'] : 0;
		// Upload_Metrics stores `week.rate` as a percentage (fail / attempts * 100),
		// while `fail_rate` is configured on the same 0-100 scale. Compare like units.
		$rate     = isset( $week['rate'] ) ? (float) $week['rate'] : 0.0;
		if ( $attempts >= (int) $config['min_attempts'] && $rate >= (float) $config['fail_rate'] ) {
			$reasons[] = 'failure_rate';
		}

		return $reasons;
	}

	/**
	 * Human-readable alert summary.
	 *
	 * @param array<int,string>   $reasons  Reasons.
	 * @param array<string,mixed> $snapshot Metrics snapshot.
	 */
	public static function describe( array $reasons, array $snapshot ): string {
		$week  = isset( $snapshot['week'] ) && is_array( $snapshot['week'] ) ? $snapshot['week'] : array();
		$parts = array();

		if ( in_array( 'failure_rate', $reasons, true ) ) {
			$parts[] = sprintf(
				/* translators: 1: failure rate, 2: attempts. */
				__( '近 14 天失败率 %1$s（%2$d 次尝试）', 'sakura-imgopt' ),
				(string) ( $week['rate_text'] ?? '—' ),
				(int) ( $week['attempts'] ?? 0 )
			);
		}

		if ( ! $parts ) {
			return __( '上传状态正常。', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: %s: comma separated metrics. */
			__( 'S3 上传异常：%s。', 'sakura-imgopt' ),
			implode( '；', $parts )
		);
	}

	/**
	 * Build the webhook payload.
	 *
	 * @param array<int,string>   $reasons  Reasons.
	 * @param array<string,mixed> $snapshot Metrics snapshot.
	 * @return array<string,mixed>
	 */
	public static function payload( array $reasons, array $snapshot ): array {
		$week = isset( $snapshot['week'] ) && is_array( $snapshot['week'] ) ? $snapshot['week'] : array();
		$last = isset( $snapshot['last_error'] ) && is_array( $snapshot['last_error'] ) ? $snapshot['last_error'] : array();
		$summary = self::describe( $reasons, $snapshot );

		$payload = array(
			'service' => 'sakura-tools',
			'event'   => 'upload_queue_alert',
			'site'    => home_url( '/' ),
			'time'    => gmdate( 'c' ),
			'reasons' => array_values( $reasons ),
			'summary' => $summary,
			'metrics' => array(
				'attempts'       => (int) ( $week['attempts'] ?? 0 ),
				'failures'       => (int) ( $week['fail'] ?? 0 ),
				'failure_rate'   => (float) ( $week['rate'] ?? 0 ),
				'last_error'     => (string) ( $last['message'] ?? '' ),
				'last_error_at'  => (int) ( $last['at'] ?? 0 ),
			),
			'text'    => $summary,
		);

		/**
		 * Filter the outgoing alert payload.
		 *
		 * @param array<string,mixed> $payload Webhook payload.
		 * @param array<int,string>   $reasons Alert reasons.
		 */
		return (array) apply_filters( 'sakura_imgopt_alert_payload', $payload, $reasons );
	}

	/**
	 * Evaluate and send an alert when not in cooldown.
	 *
	 * @param bool $force Ignore cooldown and disabled flag (test/manual).
	 */
	public static function maybe_alert( bool $force = false ): bool {
		$config = self::get();
		if ( ! $force && empty( $config['enabled'] ) ) {
			return false;
		}

		if ( '' === (string) $config['webhook'] || ! self::valid_url( (string) $config['webhook'] ) ) {
			return false;
		}

		$snapshot = ( new Upload_Metrics() )->snapshot();
		$reasons  = self::evaluate( $snapshot, $config );
		if ( ! $force && ! $reasons ) {
			return false;
		}

		if ( ! $force ) {
			$last = (int) get_transient( self::COOLDOWN_TRANSIENT );
			if ( $last > 0 && ( time() - $last ) < ( (int) $config['cooldown'] * MINUTE_IN_SECONDS ) ) {
				return false;
			}
		}

		return self::dispatch( self::payload( $reasons ? $reasons : array( 'test' ), $snapshot ), $config );
	}

	/**
	 * POST one payload to the configured webhook.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param array<string,mixed> $config  Configuration.
	 */
	public static function dispatch( array $payload, array $config ): bool {
		if ( '' === (string) $config['webhook'] ) {
			return false;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return false;
		}

		$response = wp_remote_post(
			(string) $config['webhook'],
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'User-Agent'   => 'Sakura-Image-Optimizer/' . SAKURA_TOOLS_VERSION,
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Log::write( 'alert webhook failed: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Log::write( 'alert webhook rejected with HTTP ' . $code );
			return false;
		}

		set_transient( self::COOLDOWN_TRANSIENT, time(), WEEK_IN_SECONDS );
		Log::write( 'alert webhook delivered: ' . implode( ',', array_map( 'strval', (array) ( $payload['reasons'] ?? array() ) ) ) );

		return true;
	}

	/**
	 * Send a manual test alert.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test(): array {
		$config = self::get();
		if ( '' === (string) $config['webhook'] || ! self::valid_url( (string) $config['webhook'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( '请先填写有效的 Webhook 地址。', 'sakura-imgopt' ),
			);
		}

		$snapshot = ( new Upload_Metrics() )->snapshot();
		$payload  = self::payload( array( 'test' ), $snapshot );
		$payload['test'] = true;
		$payload['text'] = __( '[测试] ', 'sakura-imgopt' ) . (string) $payload['text'];

		$ok = self::dispatch( $payload, $config );

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( '测试告警已发送，请检查接收端。', 'sakura-imgopt' )
				: __( '测试告警发送失败，请检查地址、DNS、防火墙或接收端状态。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Add the 15-minute schedule used by the alert check.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Sakura：每 15 分钟', 'sakura-imgopt' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring alert check.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/** Register hooks. */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		$hooks->add_action( self::HOOK, array( __CLASS__, 'check' ) );
		$hooks->add_action( 'init', array( __CLASS__, 'schedule' ) );
		$hooks->add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/** Cron callback. */
	public static function check(): void {
		self::maybe_alert();
	}

	/**
	 * Clamp a numeric value to an integer range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function clamp( mixed $value, int $min, int $max ): int {
		return max( $min, min( $max, absint( $value ) ) );
	}
}
