<?php
/**
 * Production stats repository.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Stats;

use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Stats as Stats_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Thin adapter around the existing Stats implementation.
 */
final class Stats_Repository implements Stats_Repository_Interface {

	/**
	 * Record one processing result.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $result        Processing result.
	 * @param string               $source_path   Source path.
	 * @param string               $mode          upload|batch.
	 * @return void
	 */
	public function record( int $attachment_id, array $result, string $source_path, string $mode = 'upload' ): void {
		Stats_Service::record( $attachment_id, $result, $source_path, $mode );
	}

	/**
	 * Aggregate totals.
	 *
	 * @return array<string, mixed>
	 */
	public function get_stats(): array {
		return Stats_Service::get_stats();
	}

	/**
	 * Per-attachment history.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs( int $attachment_id ): array {
		return Stats_Service::get_logs( $attachment_id );
	}

	/**
	 * Recent history across attachments.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_logs( int $limit = 50 ): array {
		return Stats_Service::get_recent_logs( $limit );
	}

	/**
	 * Reset aggregate totals.
	 *
	 * @return void
	 */
	public function reset(): void {
		Stats_Service::reset();
	}
}
