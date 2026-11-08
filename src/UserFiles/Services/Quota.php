<?php
/**
 * Quota ledger: used / reserved bytes per owner.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Support\Db_Transaction;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Requests and approvals never free bytes. Only a per-artifact verified purge
 * commits `used_bytes` down; everything else is a reservation with a lease.
 *
 * Every mutation is a short transaction that re-reads the row with FOR UPDATE,
 * so two concurrent uploads cannot both pass the availability check.
 */
final class Quota {

	/**
	 * Reservation lifetime before the watchdog may reclaim it.
	 */
	private const LEASE_SECONDS = 900;

	/**
	 * Ensure the ledger row exists.
	 *
	 * @param int $owner_id Owner.
	 * @return void
	 */
	public function ensure( int $owner_id ): void {
		global $wpdb;

		$table = Schema::table( 'usage' );
		$now   = Time::now();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (owner_id,used_bytes,reserved_bytes,revision,updated_at) VALUES (%d,0,0,0,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$now
			)
		);
	}

	/**
	 * Ledger snapshot for one owner.
	 *
	 * @param int $owner_id Owner.
	 * @return array{used:int,reserved:int,revision:int,quota:int,available:int}
	 */
	public function snapshot( int $owner_id ): array {
		global $wpdb;

		$table = Schema::table( 'usage' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT used_bytes,reserved_bytes,revision FROM {$table} WHERE owner_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id
			)
		);

		// Only pay for the INSERT IGNORE when the ledger row is genuinely absent.
		// Reads are far more frequent than first-time owners, and a write on every
		// page view also dirties the row for every concurrent reader.
		if ( ! $row ) {
			$this->ensure( $owner_id );

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT used_bytes,reserved_bytes,revision FROM {$table} WHERE owner_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$owner_id
				)
			);
		}

		$used     = $row ? (int) $row->used_bytes : 0;
		$reserved = $row ? (int) $row->reserved_bytes : 0;
		$quota    = Settings::quota_for( $owner_id );

		return array(
			'used'      => $used,
			'reserved'  => $reserved,
			'revision'  => $row ? (int) $row->revision : 0,
			'quota'     => $quota,
			'available' => max( 0, $quota - $used - $reserved ),
		);
	}

	/**
	 * Snapshot many owners with a single query.
	 *
	 * The admin user list renders up to 100 rows and used to call snapshot() per
	 * row, which cost four queries per user (audit A08). A list view must not
	 * create ledger rows either, so missing owners simply read as zero here.
	 *
	 * @param array<int> $owner_ids Owner ids.
	 * @return array<int, array<string, int>>
	 */
	public function snapshots( array $owner_ids ): array {
		global $wpdb;

		$owner_ids = array_values( array_unique( array_filter( array_map( 'intval', $owner_ids ) ) ) );

		if ( ! $owner_ids ) {
			return array();
		}

		$table = Schema::table( 'usage' );
		$marks = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT owner_id,used_bytes,reserved_bytes,revision FROM {$table} WHERE owner_id IN ({$marks})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_ids
			)
		);

		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[ (int) $row->owner_id ] = array(
				'used'     => (int) $row->used_bytes,
				'reserved' => (int) $row->reserved_bytes,
				'revision' => (int) $row->revision,
			);
		}

		$out = array();

		foreach ( $owner_ids as $id ) {
			$row   = isset( $found[ $id ] ) ? $found[ $id ] : array(
				'used'     => 0,
				'reserved' => 0,
				'revision' => 0,
			);
			$quota = Settings::quota_for( $id );

			$out[ $id ] = array(
				'used'      => $row['used'],
				'reserved'  => $row['reserved'],
				'revision'  => $row['revision'],
				'quota'     => $quota,
				'available' => max( 0, $quota - $row['used'] - $row['reserved'] ),
			);
		}

		return $out;
	}

	/**
	 * Reserve bytes for one upload attempt.
	 *
	 * @param int    $owner_id       Owner.
	 * @param string $idempotency_key Client key, unique.
	 * @param int    $bytes          Bytes to reserve.
	 * @return array{id:int,fence:int,bytes:int}|WP_Error
	 */
	public function reserve( int $owner_id, string $idempotency_key, int $bytes ) {
		global $wpdb;

		$bytes = max( 0, $bytes );
		$key   = substr( sanitize_text_field( $idempotency_key ), 0, 160 );

		// Validate before namespacing: prefixing first made this guard
		// unreachable and degraded an empty key to the constant "{owner}:",
		// which then locked the owner out (or reused a stale reservation).
		if ( '' === $key ) {
			return new WP_Error( 'sufm_key_missing', __( '缺少上传幂等键，请刷新页面重试。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$key = $owner_id . ':' . $key;

		if ( $bytes > Settings::quota_for( $owner_id ) ) {
			return new WP_Error( 'sufm_file_too_large', __( '文件超过单人容量上限。', 'sakura-imgopt' ), array( 'status' => 413 ) );
		}

		$this->ensure( $owner_id );

		$table = Schema::table( 'reservations' );
		$now   = Time::now();

		$usage_table = Schema::table( 'usage' );
		$table       = Schema::table( 'reservations' );
		$now         = Time::now();
		$quota       = Settings::quota_for( $owner_id );

		// The usage row is the per-owner mutex: serialising here keeps concurrent
		// uploads from colliding on the reservations unique index.
		Db_Transaction::begin();

		try {
			$usage = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT used_bytes, reserved_bytes FROM {$usage_table} WHERE owner_id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$owner_id
				)
			);

			if ( ! $usage ) {
				Db_Transaction::rollback();

				return new WP_Error( 'sufm_quota_failed', __( '容量账本不可用，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE idempotency_key=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key
				)
			);

			if ( $existing ) {
				if ( (int) $existing->owner_id !== $owner_id || (int) $existing->bytes !== $bytes ) {
					Db_Transaction::rollback();

					return new WP_Error( 'sufm_key_conflict', __( '幂等键已被占用，请刷新页面后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}

				Db_Transaction::commit();

				if ( 'reserved' === (string) $existing->state ) {
					return array(
						'id'    => (int) $existing->id,
						'fence' => (int) $existing->fence,
						'bytes' => (int) $existing->bytes,
					);
				}

				return new WP_Error( 'sufm_key_used', __( '该上传已经处理过，请勿重复提交。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$available = $quota - (int) $usage->used_bytes - (int) $usage->reserved_bytes;

			if ( $bytes > $available ) {
				Db_Transaction::rollback();

				return new WP_Error( 'sufm_quota', __( '可用空间不足，请先删除文件释放空间。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			// Site-wide budget: an administrator-set ceiling across all owners.
			// Zero disables it; the per-owner check above still applies.
			// SUM() over other owners' rows is not covered by this owner's row
			// lock, so the check is serialised with a named lock.
			$budget = Settings::site_storage_budget();

			if ( $budget > 0 ) {
				$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'sufm_site_budget', 2 ) );

				$site = $wpdb->get_row(
					"SELECT COALESCE(SUM(used_bytes),0) AS used_bytes, COALESCE(SUM(reserved_bytes),0) AS reserved_bytes FROM {$usage_table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);

				$site_total = $site ? (int) $site->used_bytes + (int) $site->reserved_bytes : 0;
				$exceeded   = $site_total + $bytes > $budget;

				if ( 1 === $locked ) {
					$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'sufm_site_budget' ) );
				}

				if ( $exceeded ) {
					Db_Transaction::rollback();

					return new WP_Error( 'sufm_site_budget', __( '全站存储预算已用尽，暂时无法上传。', 'sakura-imgopt' ), array( 'status' => 507 ) );
				}
			}

			$inserted = $wpdb->insert(
				$table,
				array(
					'owner_id'        => $owner_id,
					'idempotency_key' => $key,
					'bytes'           => $bytes,
					'state'           => 'reserved',
					'lease_until'     => Time::in( self::LEASE_SECONDS ),
					'fence'           => 1,
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
			);

			$id = (int) $wpdb->insert_id;

			if ( ! $inserted || $id <= 0 ) {
				Db_Transaction::rollback();

				return new WP_Error( 'sufm_key_conflict', __( '幂等键已被占用，请刷新页面后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$ok = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$usage_table} SET reserved_bytes = reserved_bytes + %d, revision = revision + 1, updated_at = %s WHERE owner_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$bytes,
					$now,
					$owner_id
				)
			);

			if ( 1 !== (int) $ok ) {
				Db_Transaction::rollback();

				return new WP_Error( 'sufm_quota_failed', __( '预留容量失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
			}

			Db_Transaction::commit();

			return array(
				'id'    => $id,
				'fence' => 1,
				'bytes' => $bytes,
			);
		} catch ( \Throwable $e ) {
			Db_Transaction::rollback();

			return new WP_Error( 'sufm_quota_failed', __( '预留容量失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Turn a reservation into used bytes.
	 *
	 * @param int $reservation_id Reservation id.
	 * @param int $charged        Actually charged bytes.
	 * @return bool
	 */
	public function commit( int $reservation_id, int $charged ): bool {
		global $wpdb;

		$reservations = Schema::table( 'reservations' );
		$usage        = Schema::table( 'usage' );
		$now          = Time::now();

		Db_Transaction::begin();

		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$reservations} WHERE id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$reservation_id
				)
			);

			if ( ! $row || 'reserved' !== (string) $row->state ) {
				Db_Transaction::rollback();

				return false;
			}

			$owner  = (int) $row->owner_id;
			$amount = max( 0, $charged );

			$ok = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$usage} SET reserved_bytes = GREATEST(0, reserved_bytes - %d), used_bytes = used_bytes + %d, revision = revision + 1, updated_at = %s WHERE owner_id = %d AND used_bytes + %d <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $row->bytes,
					$amount,
					$now,
					$owner,
					$amount,
					Settings::quota_for( $owner )
				)
			);

			if ( 1 !== $ok ) {
				Db_Transaction::rollback();

				return false;
			}

			$updated = $wpdb->update(
				$reservations,
				array(
					'state'      => 'committed',
					'bytes'      => $amount,
					'updated_at' => $now,
				),
				array( 'id' => $reservation_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			// The usage row was already charged; if the reservation flip did
			// not land the transaction rolls back and the caller may retry.
			// Committing regardless charged the same bytes twice.
			if ( 1 !== (int) $updated ) {
				Db_Transaction::rollback();

				return false;
			}

			Db_Transaction::commit();

			return true;
		} catch ( \Throwable $e ) {
			Db_Transaction::rollback();

			return false;
		}
	}

	/**
	 * Return a reservation to the pool without charging anything.
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $state          Final state (released|expired).
	 * @return void
	 */
	public function release( int $reservation_id, string $state = 'released' ): void {
		global $wpdb;

		$reservations = Schema::table( 'reservations' );
		$usage        = Schema::table( 'usage' );
		$now          = Time::now();

		// The flip and the decrement must be one unit: a crash between them
		// left the owner's reserved_bytes permanently inflated (the row was
		// no longer 'reserved', so no retry could ever release it).
		Db_Transaction::begin();

		// The state flip is the lock: exactly one caller may return the bytes.
		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$reservations} SET state=%s, updated_at=%s WHERE id=%d AND state='reserved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state,
				$now,
				$reservation_id
			)
		);

		if ( 1 !== $claimed ) {
			Db_Transaction::rollback();

			return;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$reservations} WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$reservation_id
			)
		);

		if ( ! $row ) {
			Db_Transaction::rollback();

			return;
		}

		$released = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$usage} SET reserved_bytes = GREATEST(0, reserved_bytes - %d), revision = revision + 1, updated_at = %s WHERE owner_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row->bytes,
				$now,
				(int) $row->owner_id
			)
		);

		if ( 1 !== $released ) {
			Db_Transaction::rollback();

			return;
		}

		Db_Transaction::commit();
	}

	/**
	 * Charge an already-stored artifact (used by purge adjustments).
	 *
	 * @param int $owner_id Owner.
	 * @param int $bytes    Bytes to remove from used (negative adds).
	 * @return bool False when the ledger row could not be updated.
	 */
	public function adjust_used( int $owner_id, int $bytes ): bool {
		global $wpdb;

		if ( 0 === $bytes ) {
			return true;
		}

		$this->ensure( $owner_id );

		$usage = Schema::table( 'usage' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$usage} SET used_bytes = GREATEST(0, CAST(used_bytes AS SIGNED) + %d), revision = revision + 1, updated_at = %s WHERE owner_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bytes,
				Time::now(),
				$owner_id
			)
		);

		// Zero rows is legitimate: GREATEST(0, ...) clamps a charge that would
		// go negative, and MySQL then reports "no change" for an existing row.
		return false !== $updated;
	}

	/**
	 * Reclaim expired reservations; called by the cron watchdog.
	 *
	 * @return int Rows reclaimed.
	 */
	public function reclaim_expired(): int {
		global $wpdb;

		$table = Schema::table( 'reservations' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE state='reserved' AND lease_until < %s LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::now()
			)
		);

		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->release( (int) $row->id, 'expired' );
			++$count;
		}

		return $count;
	}
}
