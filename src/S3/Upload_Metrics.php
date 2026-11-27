<?php
/**
 * Upload observability: durations, failure rate and last error.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Appends upload outcomes to an event table and aggregates them with SQL.
 *
 * A single option used to be read, mutated and written back for every batch.
 * Two concurrent requests could both read the same value and the later write
 * silently discarded the earlier samples (up to 75% loss in a four-process
 * probe). Events are now inserted row by row, so concurrent writers never
 * share a read-modify-write cycle; snapshots aggregate the rows in MySQL.
 *
 * The legacy option is read as a frozen baseline. It is no longer written:
 * sites that upgrade keep their historical totals, and reset() clears both
 * the baseline and the event table.
 */
final class Upload_Metrics {

	/**
	 * Legacy option name, now a read-only baseline.
	 */
	public const OPTION = 'sakura_imgopt_s3_upload_metrics';

	/**
	 * Schema marker for the event table.
	 */
	public const SCHEMA_OPTION = 'sakura_imgopt_s3_upload_schema';

	/**
	 * Schema version.
	 */
	public const VERSION = '2.0.0';

	/**
	 * Days kept in the daily read model.
	 */
	public const KEEP_DAYS = 14;

	/**
	 * Option lock that keeps two prune runs from double-counting rows.
	 */
	public const PRUNE_LOCK = 'sakura_imgopt_s3_upload_prune';

	/**
	 * How long the prune lock is honoured before it is treated as stale.
	 */
	private const PRUNE_LOCK_TTL = 300;

	/**
	 * Event table suffix.
	 */
	private const TABLE = 'sio_s3_upload_events';

	/**
	 * Event table name for this site.
	 */
	public static function table_name(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) ? (string) ( $wpdb->prefix ?? '' ) : '';

		return $prefix . self::TABLE;
	}

	/**
	 * Create the event table when the stored schema marker is stale.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Create the event table via dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			day date NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_key varbinary(1024) NOT NULL,
			result varchar(16) NOT NULL DEFAULT 'fail',
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message varchar(500) NOT NULL DEFAULT '',
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			profile_id varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY day_result (day,result),
			KEY created_at (created_at),
			KEY attachment_id (attachment_id),
			KEY profile_day (profile_id,day)
		) {$charset};";

		dbDelta( $sql );

		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! in_array( 'result', $columns, true ) || ! in_array( 'day', $columns, true ) || ! in_array( 'error_message', $columns, true ) ) {
			do_action( 'sakura_imgopt_s3_log', __( '上传指标事件表升级未完成，已保留旧版本标记。', 'sakura-imgopt' ) );
			return;
		}

		update_option( self::SCHEMA_OPTION, self::VERSION, false );
	}

	/**
	 * Default payload for the legacy option shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'    => self::VERSION,
			'totals'     => self::empty_day(),
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
	 * Append one batch of samples.
	 *
	 * Sample keys: ok (bool), ms (int), bytes (int), error (string),
	 * error_code (string), attachment (int), key (string), profile (string),
	 * dead (bool), time (int), user_id (int).
	 *
	 * Each valid sample becomes one INSERT. There is no shared read-modify-write
	 * cycle, so concurrent requests cannot overwrite each other's samples.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 */
	public function record_batch( array $samples ): void {
		if ( ! $samples ) {
			return;
		}

		self::maybe_install();

		global $wpdb;

		$table = self::table_name();
		$user  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $samples as $sample ) {
			if ( ! is_array( $sample ) ) {
				continue;
			}

			$ok     = ! empty( $sample['ok'] );
			$dead   = ! empty( $sample['dead'] );
			$time   = isset( $sample['time'] ) ? (int) $sample['time'] : time();
			$time   = $time > 0 ? $time : time();
			$ms     = max( 0, (int) ( $sample['ms'] ?? 0 ) );
			$size   = max( 0, (int) ( $sample['bytes'] ?? 0 ) );
			$result = $ok ? 'ok' : ( $dead ? 'dead' : 'fail' );

			$row = array(
				'created_at'    => gmdate( 'Y-m-d H:i:s', $time ),
				'day'           => gmdate( 'Y-m-d', $time ),
				'user_id'       => max( 0, (int) ( $sample['user_id'] ?? $sample['user'] ?? $user ) ),
				'attachment_id' => max( 0, (int) ( $sample['attachment'] ?? 0 ) ),
				'object_key'    => substr( (string) ( $sample['key'] ?? '' ), 0, 1024 ),
				'result'        => $result,
				'error_code'    => substr( (string) ( $sample['error_code'] ?? '' ), 0, 64 ),
				'error_message' => self::clip( (string) ( $sample['error'] ?? $sample['error_message'] ?? '' ), 500 ),
				'bytes'         => $size,
				'duration_ms'   => $ms,
				'profile_id'    => substr( (string) ( $sample['profile'] ?? $sample['profile_id'] ?? '' ), 0, 40 ),
			);

			$inserted = $wpdb->insert( $table, $row );
			if ( false === $inserted ) {
				$error = is_object( $wpdb ) ? (string) ( $wpdb->last_error ?? '' ) : '';
				Log::write( sprintf( /* translators: %s: database error. */ __( '上传指标写入失败：%s', 'sakura-imgopt' ), $error ) );
			}
		}
	}

	/**
	 * Build the read model used by the admin card, CLI and Prometheus.
	 *
	 * Queue counters are deliberately absent: the asynchronous upload queue was
	 * removed in 1.12.0, so there is no producer for queued/running/dead rows.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		self::maybe_install();

		global $wpdb;

		$table     = self::table_name();
		$today_key = self::today_key();
		$from      = gmdate( 'Y-m-d', time() - ( self::KEEP_DAYS - 1 ) * DAY_IN_SECONDS );
		$legacy    = $this->legacy();
		$totals    = $legacy['totals'];
		$days      = array();

		$rows = $wpdb->get_results( "SELECT result, COUNT(*) AS n, SUM(CASE WHEN result = 'ok' THEN bytes ELSE 0 END) AS bytes, SUM(duration_ms) AS ms_sum, MAX(duration_ms) AS max_ms FROM {$table} GROUP BY result" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no user input.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result = (string) ( $row->result ?? '' );
			if ( ! isset( $totals[ $result ] ) ) {
				continue;
			}
			$count              = (int) ( $row->n ?? 0 );
			$totals[ $result ] += $count;
			if ( 'dead' === $result ) {
				$totals['fail'] += $count;
			}
			$totals['attempts'] += $count;
			$totals['bytes']   += (int) ( $row->bytes ?? 0 );
			$totals['ms_sum']  += (int) ( $row->ms_sum ?? 0 );
			$totals['max_ms']   = max( (int) $totals['max_ms'], (int) ( $row->max_ms ?? 0 ) );
		}

		$rows = $wpdb->get_results( "SELECT day, result, COUNT(*) AS n, SUM(CASE WHEN result = 'ok' THEN bytes ELSE 0 END) AS bytes, SUM(duration_ms) AS ms_sum, MAX(duration_ms) AS max_ms FROM {$table} WHERE day >= '{$from}' GROUP BY day, result" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name and generated date, no user input.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$date   = (string) ( $row->day ?? '' );
			$result = (string) ( $row->result ?? '' );
			if ( '' === $date || ! isset( self::empty_day()[ $result ] ) ) {
				continue;
			}
			if ( ! isset( $days[ $date ] ) ) {
				$days[ $date ] = self::empty_day();
			}
			$days[ $date ][ $result ] += (int) ( $row->n ?? 0 );
			if ( 'dead' === $result ) {
				$days[ $date ]['fail'] += (int) ( $row->n ?? 0 );
			}
			$days[ $date ]['attempts'] += (int) ( $row->n ?? 0 );
			$days[ $date ]['bytes']    += (int) ( $row->bytes ?? 0 );
			$days[ $date ]['ms_sum']   += (int) ( $row->ms_sum ?? 0 );
			$days[ $date ]['max_ms']    = max( (int) $days[ $date ]['max_ms'], (int) ( $row->max_ms ?? 0 ) );
		}

		foreach ( $legacy['days'] as $date => $bucket ) {
			if ( ! is_string( $date ) || $date < $from ) {
				continue;
			}
			$days[ $date ] = isset( $days[ $date ] ) ? self::add_day( $days[ $date ], $bucket ) : self::normalise_day( $bucket );
		}

		$today = $days[ $today_key ] ?? self::empty_day();
		$week  = self::empty_day();
		foreach ( $days as $bucket ) {
			$week = self::add_day( $week, $bucket );
		}

		$last = $legacy['last_error'];
		$row  = $wpdb->get_row( "SELECT created_at, error_message, attachment_id, object_key FROM {$table} WHERE result IN ('fail','dead') ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no user input.
		if ( is_object( $row ) ) {
			$at = self::timestamp( (string) ( $row->created_at ?? '' ) );
			if ( $at >= (int) $last['at'] ) {
				$last = array(
					'at'         => $at,
					'message'    => (string) ( $row->error_message ?? '' ),
					'attachment' => (int) ( $row->attachment_id ?? 0 ),
					'key'        => (string) ( $row->object_key ?? '' ),
				);
			}
		}

		$updated = $legacy['updated_at'];
		$max     = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no user input.
		$updated = max( $updated, self::timestamp( (string) $max ) );

		return array(
			'version'    => self::VERSION,
			'totals'     => $this->with_formats( $totals ),
			'today'      => $this->with_formats( $today ),
			'week'       => $this->with_formats( $week ),
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
	 * Roll expired events into the frozen baseline and delete them.
	 *
	 * The event table is append-only: every upload writes one row and nothing
	 * removes them, so a busy site would grow it forever (about 50 rows a day in
	 * the first month of production, and much more under heavy uploads).
	 *
	 * Ageing a row out cannot simply delete it. The totals on the admin card,
	 * Prometheus and the CLI are cumulative, so forgetting an old row would make
	 * them go down over time — the opposite of a counter. Instead the aggregate is
	 * added into the frozen baseline option that legacy() already reads, and only
	 * then is the row deleted. Totals therefore stay monotonic while the daily
	 * read model keeps its KEEP_DAYS window.
	 *
	 * The baseline holds totals only: its 'days' map is deliberately left
	 * untouched, because a pruned day is older than the window anyway and writing
	 * it back would just be data nobody reads.
	 *
	 * @return int Rows removed.
	 */
	public function prune(): int {
		self::maybe_install();

		global $wpdb;

		$table = self::table_name();
		$cut   = gmdate( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS );

		// Two workers can be scheduled close together on a slow site; the lock is
		// what keeps the same rows from being counted into the baseline twice.
		$now = time();
		if ( ! add_option( self::PRUNE_LOCK, $now, '', false ) ) {
			$held = (int) get_option( self::PRUNE_LOCK, 0 );
			if ( $held > 0 && $held > $now - self::PRUNE_LOCK_TTL ) {
				return 0;
			}
			update_option( self::PRUNE_LOCK, $now, false );
		}

		$rows = $wpdb->get_results( "SELECT result, COUNT(*) AS n, SUM(CASE WHEN result = 'ok' THEN bytes ELSE 0 END) AS bytes, SUM(duration_ms) AS ms_sum, MAX(duration_ms) AS max_ms FROM {$table} WHERE day < '{$cut}' GROUP BY result" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name and generated date, no user input.

		$expired = 0;
		$totals  = self::empty_day();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result = (string) ( $row->result ?? '' );
			if ( ! isset( $totals[ $result ] ) ) {
				continue;
			}

			$count    = (int) ( $row->n ?? 0 );
			$expired += $count;
			$totals[ $result ] += $count;
			if ( 'dead' === $result ) {
				$totals['fail'] += $count;
			}
			$totals['attempts'] += $count;
			$totals['bytes']    += (int) ( $row->bytes ?? 0 );
			$totals['ms_sum']   += (int) ( $row->ms_sum ?? 0 );
			$totals['max_ms']    = max( (int) $totals['max_ms'], (int) ( $row->max_ms ?? 0 ) );
		}

		if ( $expired <= 0 ) {
			delete_option( self::PRUNE_LOCK );
			return 0;
		}

		$legacy = $this->legacy();
		foreach ( array_keys( $legacy['totals'] ) as $field ) {
			$legacy['totals'][ $field ] += (int) $totals[ $field ];
		}
		$legacy['updated_at'] = max( (int) $legacy['updated_at'], time() );
		update_option( self::OPTION, $legacy, false );

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, date passed through prepare().

		delete_option( self::PRUNE_LOCK );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
	/**
	 * Remove all metrics (tests/manual reset).
	 */
	public function reset(): void {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no user input.

		delete_option( self::OPTION );
	}

	/**
	 * Read the frozen option left behind by previous versions.
	 *
	 * @return array{totals:array<string,int>,days:array<string,array<string,int>>,last_error:array{at:int,message:string,attachment:int,key:string},updated_at:int}
	 */
	private function legacy(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$totals = self::empty_day();
		foreach ( array_keys( $totals ) as $field ) {
			$totals[ $field ] = max( 0, (int) ( $raw['totals'][ $field ] ?? 0 ) );
		}

		$days = array();
		if ( is_array( $raw['days'] ?? null ) ) {
			foreach ( $raw['days'] as $date => $bucket ) {
				if ( is_string( $date ) && is_array( $bucket ) ) {
					$days[ $date ] = self::normalise_day( $bucket );
				}
			}
		}

		$last = self::defaults()['last_error'];
		if ( is_array( $raw['last_error'] ?? null ) ) {
			$last = array(
				'at'         => max( 0, (int) ( $raw['last_error']['at'] ?? 0 ) ),
				'message'    => (string) ( $raw['last_error']['message'] ?? '' ),
				'attachment' => max( 0, (int) ( $raw['last_error']['attachment'] ?? 0 ) ),
				'key'        => (string) ( $raw['last_error']['key'] ?? '' ),
			);
		}

		return array(
			'totals'     => $totals,
			'days'       => $days,
			'last_error' => $last,
			'updated_at' => max( 0, (int) ( $raw['updated_at'] ?? 0 ) ),
		);
	}

	/**
	 * Empty daily bucket.
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
	 * Normalize a stored bucket to ints.
	 *
	 * @param array<string,mixed> $bucket Bucket.
	 * @return array<string,int>
	 */
	private static function normalise_day( array $bucket ): array {
		$day = self::empty_day();
		foreach ( array_keys( $day ) as $field ) {
			$day[ $field ] = max( 0, (int) ( $bucket[ $field ] ?? 0 ) );
		}

		return $day;
	}

	/**
	 * Add two buckets.
	 *
	 * @param array<string,int> $left  Left bucket.
	 * @param array<string,int> $right Right bucket.
	 * @return array<string,int>
	 */
	private static function add_day( array $left, array $right ): array {
		$sum = self::empty_day();
		foreach ( array_keys( $sum ) as $field ) {
			$sum[ $field ] = (int) ( $left[ $field ] ?? 0 ) + (int) ( $right[ $field ] ?? 0 );
		}
		$sum['max_ms'] = max( (int) ( $left['max_ms'] ?? 0 ), (int) ( $right['max_ms'] ?? 0 ) );

		return $sum;
	}

	/**
	 * Convert a stored UTC datetime to a Unix timestamp.
	 *
	 * @param string $value Datetime.
	 */
	private static function timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time ? 0 : $time;
	}

	/**
	 * Limit a string to a column width without splitting a multibyte character.
	 *
	 * @param string $value Value.
	 * @param int    $limit Maximum characters.
	 */
	private static function clip( string $value, int $limit ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}

		return substr( $value, 0, $limit );
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
	 * Today's bucket key (UTC, matching event timestamps).
	 */
	private static function today_key(): string {
		return gmdate( 'Y-m-d' );
	}
}
