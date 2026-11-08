<?php
/**
 * Append-only audit events.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Repositories;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;
use Sakura\ImageOptimizer\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The audit trail never stores paths, object keys, credentials, nonces or file
 * bodies; `safe_detail` carries short opaque identifiers only.
 */
final class Events {

	/**
	 * Append one event row.
	 *
	 * @param array<string, mixed> $data Event fields.
	 * @return int Insert id (0 on failure).
	 */
	public function log( array $data ): int {
		global $wpdb;

		$table = Schema::table( 'events' );

		$ok = $wpdb->insert(
			$table,
			array(
				'actor_id'         => (int) ( $data['actor_id'] ?? 0 ),
				'owner_id'         => (int) ( $data['owner_id'] ?? 0 ),
				'request_id'       => (int) ( $data['request_id'] ?? 0 ),
				'approval_revision' => (int) ( $data['approval_revision'] ?? 0 ),
				'batch_id'         => (int) ( $data['batch_id'] ?? 0 ),
				'operation_id'     => substr( (string) ( $data['operation_id'] ?? '' ), 0, 64 ),
				'subject_id'       => (int) ( $data['subject_id'] ?? 0 ),
				'action'           => substr( (string) ( $data['action'] ?? '' ), 0, 48 ),
				'result'           => substr( (string) ( $data['result'] ?? '' ), 0, 24 ),
				'safe_detail'      => Text::clip( (string) ( $data['safe_detail'] ?? '' ), 500 ),
				'created_at'       => Time::now(),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Paged audit log, newest first.
	 *
	 * @param int $page Page number (1-based).
	 * @param int $per  Rows per page.
	 * @return array{rows: array<int, object>, total: int}
	 */
	public function paged( int $page = 1, int $per = 25 ): array {
		global $wpdb;

		$table = Schema::table( 'events' );
		$page  = max( 1, $page );
		$per   = max( 5, min( 100, $per ) );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per,
				( $page - 1 ) * $per
			)
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}
}
