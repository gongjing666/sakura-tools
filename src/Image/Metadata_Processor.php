<?php
/**
 * Sub-size metadata processing and pending-stat consumption.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Backup;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Processes generated sub-sizes and persists upload stats/backup metadata.
 *
 * Pending records are registered by Upload_Handler after the original upload
 * is converted, then consumed when WordPress generates the attachment metadata.
 */
final class Metadata_Processor {

	/**
	 * Pending upload results keyed by final absolute path.
	 *
	 * @var array<string,array{result:array,source:string,backup_rel:string|false}>
	 */
	private array $pending_records = array();

	/**
	 * File processing policy.
	 *
	 * @var File_Processor
	 */
	private File_Processor $processor;

	/**
	 * Backup repository.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Stats repository.
	 *
	 * @var Stats_Repository_Interface
	 */
	private Stats_Repository_Interface $stats;

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Per-attachment time budget override in seconds (0 disables the limit).
	 *
	 * @var float|null
	 */
	private ?float $time_budget;

	/**
	 * Constructor.
	 *
	 * @param File_Processor                  $processor   File processor.
	 * @param Backup_Repository_Interface      $backup      Backup repository.
	 * @param Stats_Repository_Interface       $stats       Stats repository.
	 * @param File_System_Interface            $files       Filesystem repository.
	 * @param float|null                      $time_budget Budget override in seconds; null uses the filter.
	 */
	public function __construct(
		File_Processor $processor,
		Backup_Repository_Interface $backup,
		Stats_Repository_Interface $stats,
		File_System_Interface $files,
		?float $time_budget = null
	) {
		$this->processor   = $processor;
		$this->backup      = $backup;
		$this->stats       = $stats;
		$this->files       = $files;
		$this->time_budget = $time_budget;
	}

	/**
	 * Register one pending upload record.
	 *
	 * @param string $final_path Final absolute path.
	 * @param array  $record     Result/source/backup metadata.
	 * @return void
	 */
	public function add_pending( string $final_path, array $record ): void {
		$this->pending_records[ $final_path ] = $record;
	}

	/**
	 * Process generated sub-sizes and flush upload stats.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function process( $metadata, $attachment_id ) {
		if ( \Sakura\ImageOptimizer\Image\Restore_Service::is_restoring() ) {
			return $metadata;
		}

		if ( ! $this->processor->is_compression_enabled() ) {
			return $metadata;
		}

		if ( ! isset( $metadata['file'] ) ) {
			return $metadata;
		}

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir   = $upload_dir['basedir'];
			$file_path  = dirname( $base_dir . '/' . $metadata['file'] );
			$main_path  = $base_dir . '/' . $metadata['file'];
			$budget     = $this->time_budget();
			$started    = microtime( true );
			$total      = count( $metadata['sizes'] );
			$position   = 0;

			foreach ( $metadata['sizes'] as $size_key => &$size_data ) {
				// Sub-sizes are compressed inside the upload request; stop once the
				// budget is spent so one huge image cannot hold the request open.
				if ( self::budget_exhausted( microtime( true ) - $started, $budget ) ) {
					$remaining = $total - $position;
					$this->record_budget_skip( (int) $attachment_id, $main_path, $remaining );
					do_action( 'sakura_imgopt_metadata_budget_exhausted', (int) $attachment_id, $position, $remaining );
					break;
				}

				++$position;
				$sub_file = $file_path . '/' . $size_data['file'];
				if ( file_exists( $sub_file ) && $this->processor->should_process_file( $sub_file ) ) {
					$result = $this->processor->process_file( $sub_file );
					if ( $result['success'] ) {
						$new_filename = basename( $result['path'] );
						if ( $new_filename !== $size_data['file'] ) {
							$this->files->delete( $sub_file );
						}
						$size_data['file']      = $new_filename;
						$size_data['mime-type'] = $this->processor->mime_from_path( $result['path'] );
					}
				}
			}
			unset( $size_data, $size_key );
		}

		$this->flush_pending_stats( $metadata, $attachment_id );

		return $metadata;
	}

	/**
	 * Whether the per-attachment processing budget has been spent.
	 *
	 * @param float $elapsed Seconds spent so far.
	 * @param float $budget  Budget in seconds; 0 disables the limit.
	 */
	public static function budget_exhausted( float $elapsed, float $budget ): bool {
		return $budget > 0.0 && $elapsed >= $budget;
	}

	/**
	 * Resolve the per-attachment budget in seconds.
	 */
	private function time_budget(): float {
		if ( null !== $this->time_budget ) {
			return max( 0.0, $this->time_budget );
		}

		return max( 0.0, (float) apply_filters( 'sakura_imgopt_metadata_budget', 20.0 ) );
	}

	/**
	 * Record one skipped batch for the sizes left unprocessed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_path   Main file path.
	 * @param int    $remaining     Number of sizes skipped.
	 */
	private function record_budget_skip( int $attachment_id, string $source_path, int $remaining ): void {
		$this->stats->record(
			$attachment_id,
			array(
				'success'     => false,
				'skipped'     => true,
				'skip_reason' => 'budget',
				'engine'      => '',
				'size_before' => 0,
				'size_after'  => 0,
				'error'       => sprintf(
					/* translators: %d: number of sub-sizes skipped. */
					__( '子尺寸处理超出时间预算，跳过 %d 个尺寸。', 'sakura-imgopt' ),
					$remaining
				),
			),
			$source_path,
			'upload'
		);
	}

	/**
	 * Consume pending upload results and write stats/backup metadata.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return void
	 */
	private function flush_pending_stats( $metadata, $attachment_id ) {
		if ( empty( $this->pending_records ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = rtrim( $upload_dir['basedir'], '/' );
		$main_path  = $base_dir . '/' . $metadata['file'];

		if ( isset( $this->pending_records[ $main_path ] ) ) {
			$this->consume_pending( $main_path, $attachment_id );
			unset( $this->pending_records[ $main_path ] );
			return;
		}

		if ( preg_match( '/^(.+)-scaled(\.[^.]+)$/', $main_path, $m ) ) {
			$origin_path = $m[1] . $m[2];
			if ( isset( $this->pending_records[ $origin_path ] ) ) {
				$this->consume_pending( $origin_path, $attachment_id );
				unset( $this->pending_records[ $origin_path ] );
				return;
			}
		}

		if ( count( $this->pending_records ) === 1 ) {
			$only_key = key( $this->pending_records );
			if ( self::filename_stems_related( $only_key, $main_path ) ) {
				$this->consume_pending( $only_key, $attachment_id );
			}
			$this->pending_records = array();
		}
	}

	/**
	 * Whether two filenames share a normalized stem.
	 *
	 * @param string $path_a Path A.
	 * @param string $path_b Path B.
	 * @return bool
	 */
	private static function filename_stems_related( string $path_a, string $path_b ): bool {
		$normalize = static function ( $path ) {
			$stem = pathinfo( (string) $path, PATHINFO_FILENAME );
			$stem = preg_replace( '/-scaled$/', '', $stem );
			return preg_replace( '/-\d+x\d+$/', '', $stem );
		};

		$a = $normalize( $path_a );
		$b = $normalize( $path_b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		return $a === $b;
	}

	/**
	 * Persist one pending record.
	 *
	 * @param string $key           Pending key.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	private function consume_pending( string $key, $attachment_id ): void {
		$pending = $this->pending_records[ $key ];

		$this->stats->record( $attachment_id, $pending['result'], $pending['source'], 'upload' );

		if ( ! empty( $pending['backup_rel'] ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir   = rtrim( $upload_dir['basedir'], '/' );
			$origin_rel = ltrim( substr( $pending['backup_rel'], strlen( Backup::DIR ) ), '/' );
			$converted_rel = ltrim( str_replace( $base_dir, '', $key ), '/' );
			$this->backup->save_meta( $attachment_id, $pending['backup_rel'], $origin_rel, $converted_rel );
		}
	}
}
