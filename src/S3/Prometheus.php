<?php
/**
 * Read-only Prometheus exposition endpoint for upload queue health.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a token-protected text/plain metrics document.
 *
 * The token is generated once, stored without autoload and can be rotated from
 * the S3 settings screen. Scrapers may pass it as `?token=` or as a bearer
 * token in the Authorization header (useful when the URL lands in logs).
 */
final class Prometheus implements Module {

	public const OPTION = 'sakura_imgopt_s3_prometheus_token';

	public const ACTION = 'sakura_imgopt_metrics';

	public const QUERY_VAR = 'sakura_imgopt_metrics';

	/**
	 * Existing token, generating it on first use.
	 */
	public static function token(): string {
		$token = (string) get_option( self::OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{32,64}$/', $token ) ) {
			$token = self::rotate();
		}

		return $token;
	}

	/**
	 * Generate and store a fresh token.
	 */
	public static function rotate(): string {
		$token = bin2hex( random_bytes( 20 ) );
		update_option( self::OPTION, $token, false );

		return $token;
	}

	/**
	 * Public scrape URL for the active site.
	 */
	public static function url(): string {
		return add_query_arg( self::QUERY_VAR, self::token(), home_url( '/' ) );
	}

	/**
	 * Constant-time token comparison.
	 *
	 * @param string $token Candidate token.
	 */
	public static function valid( string $token ): bool {
		$expected = self::token();

		return '' !== $token && '' !== $expected && hash_equals( $expected, $token );
	}

	/** Register the endpoint hooks. */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
		$hooks->add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'output' ) );
		$hooks->add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'output' ) );
	}

	/**
	 * Serve the token-protected document from the front-end query var.
	 *
	 * A front-end entry point avoids admin-area redirects and security plugins
	 * that block anonymous wp-admin requests, which makes scraping reliable.
	 */
	public static function maybe_serve(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only endpoint.
			return;
		}

		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		self::emit( $token );
	}

	/**
	 * Emit the exposition document and stop.
	 */
	public static function output(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only endpoint.
		self::emit( $token );
	}

	/**
	 * Emit the exposition document for a candidate token and stop.
	 *
	 * @param string $token Candidate token (empty falls back to Bearer auth).
	 */
	private static function emit( string $token ): void {
		if ( '' === $token ) {
			$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' );
			if ( preg_match( '/^Bearer\s+(\S+)$/i', $auth, $matches ) ) {
				$token = $matches[1];
			}
		}

		nocache_headers();
		header( 'Content-Type: text/plain; version=0.0.4; charset=utf-8' );

		if ( ! self::valid( $token ) ) {
			status_header( 403 );
			echo "# forbidden\n";
			exit;
		}

		$snapshot = ( new Upload_Metrics() )->snapshot();

		echo self::format( $snapshot ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text exposition format.
		exit;
	}

	/**
	 * Render the Prometheus text exposition document.
	 *
	 * @param array<string,mixed> $snapshot Upload_Metrics::snapshot().
	 */
	public static function format( array $snapshot ): string {
		$totals = isset( $snapshot['totals'] ) && is_array( $snapshot['totals'] ) ? $snapshot['totals'] : array();
		$last   = isset( $snapshot['last_error'] ) && is_array( $snapshot['last_error'] ) ? $snapshot['last_error'] : array();
		$lines  = array();

		$lines[] = '# HELP sakura_imgopt_upload_attempts_total Upload attempts by result since metrics were installed.';
		$lines[] = '# TYPE sakura_imgopt_upload_attempts_total counter';
		$lines[] = 'sakura_imgopt_upload_attempts_total{result="ok"} ' . self::num( $totals['ok'] ?? 0 );
		$lines[] = 'sakura_imgopt_upload_attempts_total{result="fail"} ' . self::num( $totals['fail'] ?? 0 );
		$lines[] = 'sakura_imgopt_upload_attempts_total{result="dead"} ' . self::num( $totals['dead'] ?? 0 );

		$lines[] = '# HELP sakura_imgopt_upload_bytes_total Bytes successfully uploaded since metrics were installed.';
		$lines[] = '# TYPE sakura_imgopt_upload_bytes_total counter';
		$lines[] = 'sakura_imgopt_upload_bytes_total ' . self::num( $totals['bytes'] ?? 0 );

		$lines[] = '# HELP sakura_imgopt_upload_duration_ms Upload duration in milliseconds (todays samples).';
		$lines[] = '# TYPE sakura_imgopt_upload_duration_ms gauge';
		$lines[] = 'sakura_imgopt_upload_duration_ms{stat="avg"} ' . self::num( $totals['avg_ms'] ?? 0 );
		$lines[] = 'sakura_imgopt_upload_duration_ms{stat="max"} ' . self::num( $totals['max_ms'] ?? 0 );

		$lines[] = '# HELP sakura_imgopt_upload_failure_ratio Failed attempts divided by attempts in the retained window, as a percentage (0-100).';
		$lines[] = '# TYPE sakura_imgopt_upload_failure_ratio gauge';
		$lines[] = 'sakura_imgopt_upload_failure_ratio ' . self::num( $totals['rate'] ?? 0 );

		$lines[] = '# HELP sakura_imgopt_upload_last_error_timestamp_seconds Unix timestamp of the last failed upload, 0 when none.';
		$lines[] = '# TYPE sakura_imgopt_upload_last_error_timestamp_seconds gauge';
		$lines[] = 'sakura_imgopt_upload_last_error_timestamp_seconds ' . self::num( $last['at'] ?? 0 );

		$lines[] = '# HELP sakura_imgopt_upload_metrics_updated_timestamp_seconds Unix timestamp of the last metrics flush.';
		$lines[] = '# TYPE sakura_imgopt_upload_metrics_updated_timestamp_seconds gauge';
		$lines[] = 'sakura_imgopt_upload_metrics_updated_timestamp_seconds ' . self::num( $snapshot['updated_at'] ?? 0 );

		$last_sent = (int) get_transient( Alerting::COOLDOWN_TRANSIENT );
		$lines[]   = '# HELP sakura_imgopt_alert_last_sent_timestamp_seconds Unix timestamp of the last delivered webhook alert.';
		$lines[]   = '# TYPE sakura_imgopt_alert_last_sent_timestamp_seconds gauge';
		$lines[]   = 'sakura_imgopt_alert_last_sent_timestamp_seconds ' . self::num( $last_sent );

		$lines[] = '# HELP sakura_imgopt_info Static build information.';
		$lines[] = '# TYPE sakura_imgopt_info gauge';
		$lines[] = 'sakura_imgopt_info{version="' . self::escape( (string) SAKURA_TOOLS_VERSION ) . '"} 1';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Numeric string with a stable decimal representation.
	 *
	 * @param mixed $value Value.
	 */
	private static function num( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( ! is_numeric( $value ) ) {
			return '0';
		}

		$number = (float) $value;
		if ( floor( $number ) === $number && abs( $number ) < 1e15 ) {
			return (string) (int) $number;
		}

		$text = sprintf( '%.6F', $number );

		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * Escape a Prometheus label value.
	 *
	 * @param string $value Value.
	 */
	private static function escape( string $value ): string {
		return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $value );
	}
}
