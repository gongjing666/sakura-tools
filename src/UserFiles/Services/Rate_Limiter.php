<?php
/**
 * Persistent counters for the limits in PROPOSAL.md §11.2.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Support\Db_Transaction;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * A cookie or transient can be discarded by the client, so the daily quota and
 * the per-minute request budget live in their own table with a unique key.
 */
final class Rate_Limiter {

	/**
	 * Current counter values.
	 *
	 * @param int    $owner_id Owner.
	 * @param string $bucket   Bucket name.
	 * @param string $period   Period key (e.g. 20261101 or 202611011830).
	 * @return array{count:int,bytes:int}
	 */
	public function current( int $owner_id, string $bucket, string $period ): array {
		global $wpdb;

		$table = Schema::table( 'rate_limits' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT count,bytes FROM {$table} WHERE owner_id=%d AND bucket=%s AND period_key=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				substr( $bucket, 0, 48 ),
				substr( $period, 0, 16 )
			)
		);

		return array(
			'count' => $row ? (int) $row->count : 0,
			'bytes' => $row ? (int) $row->bytes : 0,
		);
	}

	/**
	 * Would this increment cross a limit? Does not write.
	 *
	 * @param int    $owner_id Owner.
	 * @param string $bucket   Bucket.
	 * @param string $period   Period key.
	 * @param int    $count    Count limit (0 = unused).
	 * @param int    $bytes    Byte limit (0 = unused).
	 * @param int    $add      Count being added.
	 * @param int    $add_bytes Bytes being added.
	 * @return bool True when the limit would be exceeded.
	 */
	public function exceeded( int $owner_id, string $bucket, string $period, int $count, int $bytes, int $add = 1, int $add_bytes = 0 ): bool {
		$current = $this->current( $owner_id, $bucket, $period );

		if ( $count > 0 && $current['count'] + $add > $count ) {
			return true;
		}

		if ( $bytes > 0 && $current['bytes'] + $add_bytes > $bytes ) {
			return true;
		}

		return false;
	}

	/**
	 * Atomically check and increment a counter. Two concurrent requests can
	 * never both pass the same remaining slot.
	 *
	 * @param int    $owner_id  Owner.
	 * @param string $bucket    Bucket.
	 * @param string $period    Period key.
	 * @param int    $limit     Count limit (0 = unlimited).
	 * @param int    $byte_limit Byte limit (0 = unlimited).
	 * @param int    $add       Count added.
	 * @param int    $add_bytes Bytes added.
	 * @return bool True when the increment was applied.
	 */
	public function consume( int $owner_id, string $bucket, string $period, int $limit, int $byte_limit, int $add = 1, int $add_bytes = 0 ): bool {
		global $wpdb;

		$table = Schema::table( 'rate_limits' );
		$now   = Time::now();
		$bucket = substr( $bucket, 0, 48 );
		$period = substr( $period, 0, 16 );

		Db_Transaction::begin();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (owner_id,bucket,period_key,count,bytes,expires_at,created_at,updated_at) VALUES (%d,%s,%s,0,0,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$bucket,
				$period,
				Time::in( 172800 ),
				$now,
				$now
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT count,bytes FROM {$table} WHERE owner_id=%d AND bucket=%s AND period_key=%s FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$bucket,
				$period
			)
		);

		$count = $row ? (int) $row->count : 0;
		$bytes = $row ? (int) $row->bytes : 0;

		if ( ( $limit > 0 && $count + $add > $limit ) || ( $byte_limit > 0 && $bytes + $add_bytes > $byte_limit ) ) {
			Db_Transaction::rollback();

			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET count=count+%d, bytes=bytes+%d, updated_at=%s WHERE owner_id=%d AND bucket=%s AND period_key=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$add,
				$add_bytes,
				$now,
				$owner_id,
				$bucket,
				$period
			)
		);

		Db_Transaction::commit();

		return true;
	}

	/**
	 * Give back a consumed increment (upload aborted after the counter moved).
	 *
	 * @param int    $owner_id Owner.
	 * @param string $bucket   Bucket.
	 * @param string $period   Period key.
	 * @param int    $add      Count.
	 * @param int    $add_bytes Bytes.
	 * @return void
	 */
	public function refund( int $owner_id, string $bucket, string $period, int $add = 1, int $add_bytes = 0 ): void {
		global $wpdb;

		$table = Schema::table( 'rate_limits' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET count=GREATEST(0,count-%d), bytes=GREATEST(0,bytes-%d) WHERE owner_id=%d AND bucket=%s AND period_key=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$add,
				$add_bytes,
				$owner_id,
				substr( $bucket, 0, 48 ),
				substr( $period, 0, 16 )
			)
		);
	}

}
