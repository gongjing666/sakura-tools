<?php
/**
 * Upload path orchestration.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Support\Profiler;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates rename → backup → convert → replace and sub-size metadata.
 */
class Upload_Handler {

	/**
	 * File processing policy.
	 *
	 * @var File_Processor
	 */
	private File_Processor $processor;

	/**
	 * Rename/title service.
	 *
	 * @var Upload_Renamer
	 */
	private Upload_Renamer $renamer;

	/**
	 * Backup service.
	 *
	 * @var Upload_Backup
	 */
	private Upload_Backup $backup;

	/**
	 * Metadata/sub-size service.
	 *
	 * @var Metadata_Processor
	 */
	private Metadata_Processor $metadata;

	/**
	 * Constructor.
	 *
	 * @param File_Processor                  $processor File processor.
	 * @param Backup_Repository_Interface      $backup    Backup repository.
	 * @param Stats_Repository_Interface       $stats     Stats repository.
	 * @param File_System_Interface            $files     Filesystem repository.
	 */
	public function __construct(
		File_Processor $processor,
		Backup_Repository_Interface $backup,
		Stats_Repository_Interface $stats,
		File_System_Interface $files,
		?Settings_Repository_Interface $settings = null
	) {
		$settings        = $settings ?? new Settings_Repository();
		$this->processor = $processor;
		$this->renamer   = new Upload_Renamer( $files, $settings );
		$this->backup    = new Upload_Backup( $backup, $files, $settings );
		$this->metadata  = new Metadata_Processor( $processor, $backup, $stats, $files );
	}

	/**
	 * Rename before WordPress moves the uploaded file.
	 *
	 * @param array $file Upload item.
	 * @return array
	 */
	public function pre_upload_rename( $file ) {
		return $this->renamer->pre_upload_rename( $file );
	}

	/**
	 * Fallback rename after the file has been moved into uploads.
	 *
	 * @param array $upload Upload result (passed by reference).
	 * @return string Final path.
	 */
	public function ensure_uploaded_file_renamed( &$upload ) {
		return $this->renamer->ensure_uploaded_file_renamed( $upload );
	}

	/**
	 * Sync the media-library title/slug with the renamed file.
	 *
	 * @param array $data Attachment data.
	 * @param array $postarr Raw attachment data.
	 * @param array $unsanitized_postarr Unslashed attachment data.
	 * @param bool  $update Whether this is an update.
	 * @return array
	 */
	public function sync_attachment_title( $data, $postarr, $unsanitized_postarr = array(), $update = false ) {
		return $this->renamer->sync_attachment_title( $data, $postarr, $unsanitized_postarr, $update );
	}

	/**
	 * Handle the original upload: skip, rename, back up, process and replace.
	 *
	 * @param array  $upload  Upload result.
	 * @param string $context Upload context.
	 * @return array
	 */
	public function handle_upload( $upload, $context ) {
		unset( $context );

		if ( \Sakura\ImageOptimizer\Image\Restore_Service::is_restoring() ) {
			return $upload;
		}

		if ( ! $this->processor->is_compression_enabled() ) {
			return $upload;
		}

		if ( ! isset( $upload['file'] ) || ! isset( $upload['type'] ) ) {
			return $upload;
		}

		if ( strpos( $upload['type'], 'image/' ) !== 0 ) {
			return $upload;
		}

		$skip_reason = $this->processor->get_processing_skip_reason( $upload['file'] );
		if ( '' !== $skip_reason ) {
			return $upload;
		}

		$file_path = $this->ensure_uploaded_file_renamed( $upload );

		$backup_rel = $this->backup->store( $file_path );
		if ( false === $backup_rel ) {
			$upload['error'] = __( '原图备份失败，已中止本次上传与优化。请检查 uploads/sakura-imgopt-backup 目录的写入权限后重试。', 'sakura-imgopt' );
			return $upload;
		}

		$result = Profiler::measure( 'upload process', fn(): array => $this->processor->process_file( $file_path ) );

		// M-08：体积未减小而保留原图时，原文件没有被替换，本次备份也没有存在意义。
		if ( ! empty( $result['skipped'] ) && is_string( $backup_rel ) && '' !== $backup_rel ) {
			$this->backup->delete( $backup_rel );
			$backup_rel = '';
		}

		if ( $result['success'] ) {
			$old_path       = $upload['file'];
			$upload['file'] = $result['path'];

			if ( $old_path !== $result['path'] ) {
				$upload['url'] = str_replace(
					wp_basename( $old_path ),
					wp_basename( $result['path'] ),
					$upload['url']
				);
				$this->renamer->delete_replaced_file( $old_path );
			}

			$new_mime = $this->processor->mime_from_path( $result['path'] );
			if ( '' !== $new_mime ) {
				$upload['type'] = $new_mime;
			}
		}

		$final_path = ( $result['success'] && ! empty( $result['path'] ) ) ? $result['path'] : $file_path;
		$this->metadata->add_pending(
			$final_path,
			array(
				'result'     => $result,
				'source'     => $file_path,
				'backup_rel' => $backup_rel,
			)
		);

		return $upload;
	}

	/**
	 * Process generated sub-sizes and flush upload stats.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function process_metadata( $metadata, $attachment_id ) {
		return Profiler::measure( 'upload subsizes', fn(): array => $this->metadata->process( $metadata, $attachment_id ) );
	}

	/**
	 * Whether backups are enabled (default on).
	 *
	 * @return bool
	 */
	public function is_backup_enabled() {
		return $this->backup->is_enabled();
	}
}
