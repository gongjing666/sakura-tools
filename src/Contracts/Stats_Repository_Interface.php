<?php
/**
 * Stats persistence boundary.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregate + per-attachment optimization history.
 */
interface Stats_Repository_Interface {

	/**
	 * Record one processing result.
	 *
	 * @param int                  $attachment_id Attachment ID (0 = aggregate only).
	 * @param array<string, mixed> $result        process_file() result.
	 * @param string               $source_path   Original file path.
	 * @param string               $mode          upload|batch.
	 * @return void
	 */
	public function record( int $attachment_id, array $result, string $source_path, string $mode = 'upload' ): void;

	/**
	 * Aggregate totals with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_stats(): array;

	/**
	 * Per-attachment history.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs( int $attachment_id ): array;

	/**
	 * Latest history rows across all attachments.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_logs( int $limit = 50 ): array;

	/**
	 * Reset only the aggregate totals.
	 *
	 * @return void
	 */
	public function reset(): void;
}
