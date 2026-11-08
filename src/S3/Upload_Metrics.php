<?php
/**
 * Upload queue observability: durations, failure rate, backlog and last error.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates upload outcomes into a single option.
 *
 * The worker records one sample per queue row and flushes the batch once per
 * tick, so a busy queue does not add an option write per file. Daily buckets
 * keep the last two weeks; totals survive pruning and answer "since install"
 * questions.
 */
final class Upload_Metrics {

	/**
	 * Option name.
	 */
	public const OPTION = 'sakura_imgopt_s3_upload_metrics';

	/**
	 * Schema version.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Days kept in the daily buckets.
	 */
	public const KEEP_DAYS = 14;

	/**
	 * Default payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'    => self::VERSION,
			'totals'     => array(
				'ok'       => 0,
				'fail'     => 0,
				'dead'     => 0,
				'bytes'    => 0,
				'ms_sum'   => 0,
				'max_ms'   => 0,
				'attempts' => 0,
			),
			'days'       => array(),
			'last_error' => array(
				'at'         => 0,
				'message'    => '',
				'attachment' => 0,
				'key'        => '',
			),
			'updated_at' => 0,
		);
	}

	/**
	 * Record one batch of samples.
	 *
	 * Sample keys: ok (bool), ms (int), bytes (int), error (string),
	 * attachment (int), key (string), dead (bool).
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 */
	public function record_batch( array $samples ): void {
		if ( ! $samples ) {
			return;
		}

		$data  = $this->load();
		$today = self::today_key();
		$now   = time();

		foreach ( $samples as $sample ) {
			$ok   = ! empty( $sample['ok'] );
			$ms   = max( 0, (int) ( $sample['ms'] ?? 0 ) );
			$size = max( 0, (int) ( $sample['bytes'] ?? 0 ) );
			$dead = ! empty( $sample['dead'] );

			$data['totals']['attempts']++;
			$data['totals']['ms_sum'] += $ms;
			$data['totals']['max_ms']  = max( (int) $data['totals']['max_ms'], $ms );

			if ( $ok ) {
				$data['totals']['ok']++;
				$data['totals']['bytes'] += $size;
			} else {
				$data['totals']['fail']++;
				if ( $dead ) {
					$data['totals']['dead']++;
				}
				$data['last_error'] = array(
					'at'         => $now,
					'message'    => (string) ( $sample['error'] ?? '' ),
					'attachment' => (int) ( $sample['attachment'] ?? 0 ),
					'key'        => (string) ( $sample['key'] ?? '' ),
				);
			}

			if ( ! isset( $data['days'][ $today ] ) ) {
				$data['days'][ $today ] = self::empty_day();
			}
			$day = &$data['days'][ $today ];
			$day['attempts']++;
			$day['ms_sum'] += $ms;
			$day['max_ms']  = max( (int) $day['max_ms'], $ms );
			if ( $ok ) {
				$day['ok']++;
				$day['bytes'] += $size;
			} else {
				$day['fail']++;
				if ( $dead ) {
					$day['dead']++;
				}
			}
			unset( $day );
		}

		$data['updated_at'] = $now;
		$data['days']       = $this->prune( $data['days'] );

		update_option( self::OPTION, $data, false );
	}

	/**
	 * Build the read model used by the admin card and CLI.
	 *
	 * @param array<string,int>|null $counts Optional backlog counters (tests).
	 * @return array<string,mixed>
	 */
	public function snapshot( ?array $counts = null ): array {
		$data = $this->load();

		$today_key = self::today_key();
		$today     = isset( $data['days'][ $today_key ] ) ? $this->with_formats( $data['days'][ $today_key ] ) : $this->with_formats( self::empty_day() );

		$week = self::empty_day();
		$from = gmdate( 'Y-m-d', time() - ( self::KEEP_DAYS - 1 ) * DAY_IN_SECONDS );
		foreach ( $data['days'] as $date => $bucket ) {
			if ( $date < $from ) {
				continue;
			}
			foreach ( array( 'ok', 'fail', 'dead', 'bytes', 'ms_sum' ) as $field ) {
				$week[ $field ] += (int) ( $bucket[ $field ] ?? 0 );
			}
			$week['attempts'] += (int) ( $bucket['attempts'] ?? 0 );
			$week['max_ms']    = max( (int) $week['max_ms'], (int) ( $bucket['max_ms'] ?? 0 ) );
		}

		$totals = array_merge( $data['totals'], array( 'attempts' => (int) $data['totals']['attempts'] ) );

		$counts  = $counts ?? array();
		$counts  = array_merge(
			array(
				'queued'    => 0,
				'running'   => 0,
				'dead'      => 0,
				'retrying'  => 0,
				'due'       => 0,
				'backlog'   => 0,
				'oldest_at' => 0,
				'oldest'    => 0,
			),
			$counts
		);
		$last    = $data['last_error'];
		$updated = (int) $data['updated_at'];

		return array(
			'version'    => self::VERSION,
			'totals'     => $this->with_formats( $totals ),
			'today'      => $today,
			'week'       => $this->with_formats( $week ),
			'backlog'    => $counts,
			'last_error' => array(
				'at'         => (int) $last['at'],
				'message'    => (string) $last['message'],
				'attachment' => (int) $last['attachment'],
				'key'        => (string) $last['key'],
				'has'        => (int) $last['at'] > 0,
				'ago'        => (int) $last['at'] > 0 ? self::ago( (int) $last['at'] ) : '',
			),
			'updated_at' => $updated,
			'updated'    => array(
				'at'  => $updated,
				'ago' => $updated > 0 ? self::ago( $updated ) : '',
			),
		);
	}

	/**
	 * Remove the option (tests/manual reset).
	 */
	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Load and normalize the option.
	 *
	 * @return array<string,mixed>
	 */
	private function load(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$data           = self::defaults();
		$data['totals'] = array_merge( $data['totals'], is_array( $raw['totals'] ?? null ) ? $raw['totals'] : array() );
		$data['days']   = is_array( $raw['days'] ?? null ) ? $raw['days'] : array();

		if ( is_array( $raw['last_error'] ?? null ) ) {
			$data['last_error'] = array_merge( $data['last_error'], $raw['last_error'] );
		}

		$data['updated_at'] = (int) ( $raw['updated_at'] ?? 0 );

		$data['totals'] = array_map( 'intval', $data['totals'] );
		$data['days']   = $this->prune( $data['days'] );

		return $data;
	}

	/**
	 * Drop buckets outside the retention window.
	 *
	 * @param array<string,mixed> $days Buckets.
	 * @return array<string,mixed>
	 */
	private function prune( array $days ): array {
		$from = gmdate( 'Y-m-d', time() - ( self::KEEP_DAYS - 1 ) * DAY_IN_SECONDS );
		foreach ( array_keys( $days ) as $date ) {
			if ( ! is_string( $date ) || $date < $from ) {
				unset( $days[ $date ] );
			}
		}
		ksort( $days );
		return $days;
	}

	/**
	 * Empty bucket.
	 *
	 * @return array<string,int>
	 */
	private static function empty_day(): array {
		return array(
			'ok'       => 0,
			'fail'     => 0,
			'dead'     => 0,
			'bytes'    => 0,
			'ms_sum'   => 0,
			'max_ms'   => 0,
			'attempts' => 0,
		);
	}

	/**
	 * Add display strings and derived metrics.
	 *
	 * @param array<string,mixed> $bucket Bucket.
	 * @return array<string,mixed>
	 */
	private function with_formats( array $bucket ): array {
		$attempts = (int) ( $bucket['attempts'] ?? 0 );
		$fail     = (int) ( $bucket['fail'] ?? 0 );
		$ms_sum   = (int) ( $bucket['ms_sum'] ?? 0 );

		$bucket['attempts']  = $attempts;
		$bucket['rate']      = $attempts > 0 ? $fail / $attempts * 100 : 0.0;
		$bucket['avg_ms']    = $attempts > 0 ? (int) round( $ms_sum / $attempts ) : 0;
		$bucket['rate_text'] = $attempts > 0 ? number_format( $bucket['rate'], 1 ) . '%' : '—';
		$bucket['avg_text']  = $attempts > 0 ? self::duration( (int) $bucket['avg_ms'] ) : '—';
		$bucket['max_text']  = (int) $bucket['max_ms'] > 0 ? self::duration( (int) $bucket['max_ms'] ) : '—';
		$bucket['bytes_text'] = self::bytes( (int) ( $bucket['bytes'] ?? 0 ) );

		return $bucket;
	}

	/**
	 * Human duration.
	 *
	 * @param int $ms Milliseconds.
	 */
	public static function duration( int $ms ): string {
		if ( $ms < 1000 ) {
			return $ms . ' ms';
		}
		if ( $ms < 60000 ) {
			return number_format( $ms / 1000, 2 ) . ' s';
		}
		return number_format( $ms / 60000, 1 ) . ' min';
	}

	/**
	 * Human bytes.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function bytes( int $bytes ): string {
		if ( $bytes >= GB_IN_BYTES ) {
			return number_format( $bytes / GB_IN_BYTES, 2 ) . ' GB';
		}
		if ( $bytes >= MB_IN_BYTES ) {
			return number_format( $bytes / MB_IN_BYTES, 2 ) . ' MB';
		}
		if ( $bytes >= KB_IN_BYTES ) {
			return number_format( $bytes / KB_IN_BYTES, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * "x minutes ago" with a test-safe fallback.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function ago( int $timestamp ): string {
		if ( function_exists( 'human_time_diff' ) ) {
			/* translators: %s: human time diff. */
			return sprintf( __( '%s前', 'sakura-imgopt' ), human_time_diff( $timestamp, time() ) );
		}
		$diff = max( 0, time() - $timestamp );
		return $diff . 's';
	}

	/**
	 * Today's bucket key (UTC, matching queue timestamps).
	 */
	private static function today_key(): string {
		return gmdate( 'Y-m-d' );
	}
}
