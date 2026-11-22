<?php
/**
 * Notification outbox.
 *
 * Delivery never blocks or rolls back the domain transition: a failed theme
 * message leaves the row retryable.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Repositories;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * At-least-once delivery keyed by (request, status) so repeated events merge.
 */
final class Notifications {

	/**
	 * Put one owner-facing summary into the outbox.
	 *
	 * @param int                  $owner_id  Owner.
	 * @param int                  $request_id Request, 0 for events that have none.
	 * @param string               $status    New request status.
	 * @param array<string, mixed> $summary   Path-free summary.
	 * @param string               $dedupe    Explicit dedupe key; empty keeps the (request, status) default.
	 * @return void
	 */
	public function enqueue( int $owner_id, int $request_id, string $status, array $summary = array(), string $dedupe = '' ): void {
		global $wpdb;

		$table = Schema::table( 'notifications' );
		$now   = Time::now();

		$payload = wp_json_encode(
			array(
				'request_id' => $request_id,
				'status'     => $status,
				'count'      => (int) ( $summary['count'] ?? 0 ),
				'bytes'      => (int) ( $summary['bytes'] ?? 0 ),
				'reason'     => isset( $summary['reason'] ) ? mb_substr( (string) $summary['reason'], 0, 120 ) : '',
				// Decisions carry their own sentence: the type and the window are
				// on the sanction row, so the copy must travel with the event
				// instead of being re-derived from a status string here.
				'title'      => isset( $summary['title'] ) ? mb_substr( (string) $summary['title'], 0, 120 ) : '',
				'body'       => isset( $summary['body'] ) ? mb_substr( (string) $summary['body'], 0, 500 ) : '',
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (blog_id,owner_id,request_id,event_id,event_revision,dedupe_key,status,payload_summary,attempts,next_at,created_at,updated_at) VALUES (%d,%d,%d,%d,%d,%s,'pending',%s,0,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				get_current_blog_id(),
				$owner_id,
				$request_id,
				(int) ( $summary['event_id'] ?? 0 ),
				(int) ( $summary['revision'] ?? 1 ),
				'' !== $dedupe ? substr( $dedupe, 0, 191 ) : sprintf( 'r%d:%s', $request_id, substr( $status, 0, 20 ) ),
				(string) $payload,
				$now,
				$now,
				$now
			)
		);
	}

	/**
	 * Rows ready for delivery.
	 *
	 * @param int $limit Row cap.
	 * @return array<int, object>
	 */
	public function due( int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::table( 'notifications' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status='pending' AND next_at<=%s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::now(),
				max( 1, min( 100, $limit ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark one row delivered.
	 *
	 * @param int $id         Notification id.
	 * @param int $message_id Theme message id.
	 * @return void
	 */
	public function mark_sent( int $id, int $message_id = 0 ): void {
		global $wpdb;

		$table = Schema::table( 'notifications' );

		$wpdb->update(
			$table,
			array(
				'status'           => 'sent',
				'theme_message_id' => $message_id,
				'attempts'         => 1,
				'updated_at'       => Time::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failed attempt with a short diagnostic.
	 *
	 * @param int    $id       Notification id.
	 * @param string $error    Safe error text.
	 * @param int    $attempts Attempt count.
	 * @return void
	 */
	public function mark_failed( int $id, string $error, int $attempts ): void {
		global $wpdb;

		$table = Schema::table( 'notifications' );
		$delay = min( 3600, 60 * ( 2 ** max( 0, min( 6, $attempts ) ) ) );

		$wpdb->update(
			$table,
			array(
				'status'     => $attempts >= 6 ? 'dead' : 'pending',
				'attempts'   => $attempts,
				'next_at'    => Time::in( $delay ),
				'updated_at' => Time::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Owner-facing notification list.
	 *
	 * @param int $owner_id Owner.
	 * @param int $limit    Row cap.
	 * @return array<int, object>
	 */
	public function for_owner( int $owner_id, int $limit = 30 ): array {
		global $wpdb;

		$table = Schema::table( 'notifications' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id=%d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				max( 1, min( 100, $limit ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Claim due rows with a state CAS so two workers never send the same row.
	 *
	 * @param int $limit Batch size.
	 * @return object[]
	 */
	public function claim_due( int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::table( 'notifications' );
		$limit = max( 1, min( 100, $limit ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status='pending' AND next_at<=%s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::now(),
				$limit
			)
		);

		$claimed = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$ok = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='sending', updated_at=%s WHERE id=%d AND status='pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					Time::now(),
					(int) $id
				)
			);

			if ( 1 !== $ok ) {
				continue;
			}

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $row ) {
				$claimed[] = $row;
			}
		}

		return $claimed;
	}

	/**
	 * Requeue rows stuck in 'sending' because the worker died.
	 *
	 * @param int $minutes Age in minutes.
	 * @return void
	 */
	public function reclaim_stale( int $minutes = 10 ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'notifications' ) . " SET status='pending' WHERE status='sending' AND updated_at < %s LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Time::in( -60 * $minutes )
			)
		);
	}
}
