<?php
/**
 * Original-image restore domain service.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Backup\Backup;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Filesystem\File_System_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a converted attachment in place.
 *
 * Invariants kept from the previous Image_Processor implementation:
 * - the attachment ID never changes (no delete/reinsert);
 * - while metadata is regenerated, Image_Processor must not compress again;
 * - a successful restore consumes the backup and its metadata.
 */
final class Restore_Service {

	/**
	 * Shared restore-in-progress flag.
	 *
	 * Image_Processor checks this flag in handle_upload()/process_metadata().
	 * It is static because WordPress may call those hooks on the same request
	 * from code paths that do not hold the same service instance.
	 *
	 * @var bool
	 */
	private static bool $restoring = false;

	/**
	 * Backup persistence boundary.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Constructor.
	 *
	 * @param Backup_Repository_Interface $backup Backup repository.
	 * @param File_System_Interface|null  $files  Filesystem repository.
	 */
	public function __construct( Backup_Repository_Interface $backup, ?File_System_Interface $files = null ) {
		$this->backup = $backup;
		$this->files  = $files ?? new File_System_Repository();
	}

	/**
	 * Whether a restore is currently regenerating attachment metadata.
	 */
	public static function is_restoring(): bool {
		return self::$restoring;
	}

	/**
	 * Restore an attachment to its backed-up original.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{success:bool,message:string}
	 */
	public function restore( int $attachment_id ): array {
		if ( $attachment_id <= 0 || get_post_type( $attachment_id ) !== 'attachment' ) {
			return array(
				'success' => false,
				'message' => __( '无效的附件 ID', 'sakura-imgopt' ),
			);
		}

		$meta = $this->backup->get_meta( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['backup_rel'] ) || empty( $meta['origin_rel'] ) ) {
			return array(
				'success' => false,
				'message' => __( '该附件没有可还原的原图备份', 'sakura-imgopt' ),
			);
		}

		$backup_rel = Backup::normalize_relative_path( $meta['backup_rel'] );
		$origin_rel = Backup::normalize_relative_path( $meta['origin_rel'] );
		if ( false === $backup_rel || false === $origin_rel || 0 !== strpos( $backup_rel, Backup::DIR . '/' ) || 0 === strpos( $origin_rel, Backup::DIR . '/' ) ) {
			return array(
				'success' => false,
				'message' => __( '备份路径元数据无效，无法还原', 'sakura-imgopt' ),
			);
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = rtrim( $upload_dir['basedir'], '/' );
		$backup_abs = $this->backup->resolve_upload_path( $backup_rel, true, true );
		$origin_abs = $this->backup->resolve_upload_path( $origin_rel );
		if ( false === $backup_abs || false === $origin_abs ) {
			return array(
				'success' => false,
				'message' => __( '备份文件已丢失或还原路径不可用，无法还原', 'sakura-imgopt' ),
			);
		}

		self::$restoring = true;
		try {
			$origin_parent = dirname( $origin_abs );
			if ( ! is_dir( $origin_parent ) && ! wp_mkdir_p( $origin_parent ) ) {
				return array(
					'success' => false,
					'message' => __( '无法创建原图目录', 'sakura-imgopt' ),
				);
			}

			$origin_abs = $this->backup->resolve_upload_path( $origin_rel );
			if ( false === $origin_abs || ! $this->files->copy( $backup_abs, $origin_abs ) ) {
				return array(
					'success' => false,
					'message' => __( '原图写回失败（磁盘权限？）', 'sakura-imgopt' ),
				);
			}

			$old_meta     = wp_get_attachment_metadata( $attachment_id );
			$old_attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

			update_post_meta( $attachment_id, '_wp_attached_file', $origin_rel );
			$origin_url = trailingslashit( $upload_dir['baseurl'] ) . $origin_rel;
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'guid'           => $origin_url,
					'post_mime_type' => $this->guess_mime( $origin_abs ),
				)
			);

			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Listeners (S3 storage) keep the restored files local and remember the
			// attachment so an interrupted reconciliation can be resumed.
			do_action( 'sakura_imgopt_restore_started', $attachment_id );

			$new_meta = wp_generate_attachment_metadata( $attachment_id, $origin_abs );

			// Nothing is deleted until the replacement set exists: a failed
			// regeneration used to drop the old sizes and then the backup too.
			if ( ! is_array( $new_meta ) || empty( $new_meta['file'] ) ) {
				if ( '' !== $old_attached ) {
					update_post_meta( $attachment_id, '_wp_attached_file', $old_attached );
				}

				if ( is_array( $old_meta ) ) {
					wp_update_attachment_metadata( $attachment_id, $old_meta );
				}

				return array(
					'success' => false,
					'message' => __( '原图已写回，但缩略图重建失败；备份已保留，可稍后重试。', 'sakura-imgopt' ),
				);
			}

			$this->delete_generated_files( $old_meta, $origin_abs, $this->metadata_paths( $new_meta ) );
			wp_update_attachment_metadata( $attachment_id, $new_meta );

			// The metadata now describes the restored file set; storage listeners
			// queue the replacement uploads and remove keys nothing references.
			do_action( 'sakura_imgopt_restored', $attachment_id );

			$this->backup->delete_backup( $attachment_id );
		} finally {
			self::$restoring = false;
		}

		return array(
			'success' => true,
			'message' => __( '已还原为原图', 'sakura-imgopt' ),
		);
	}

	/**
	 * Delete the current main file and generated sub-sizes before restoring.
	 *
	 * @param mixed  $meta     Attachment metadata.
	 * @param string $keep_abs Original file just written back; never delete it.
	 */
	private function delete_generated_files( $meta, string $keep_abs, array $keep_paths = array() ): void {
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return;
		}

		$main_abs = $this->backup->resolve_upload_path( (string) $meta['file'] );
		if ( false === $main_abs ) {
			return;
		}

		$skip = static function ( string $path ) use ( $keep_abs, $keep_paths ): bool {
			return $path === $keep_abs || in_array( wp_normalize_path( $path ), $keep_paths, true );
		};

		$sub_dir = dirname( $main_abs );
		if ( ! $skip( $main_abs ) && file_exists( $main_abs ) ) {
			$this->files->delete( $main_abs );
		}

		if ( isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) && '' !== $meta['original_image'] ) {
			$orig_abs = $sub_dir . '/' . wp_basename( $meta['original_image'] );
			if ( ! $skip( $orig_abs ) && file_exists( $orig_abs ) ) {
				$this->files->delete( $orig_abs );
			}
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
					continue;
				}
				$size_abs = $sub_dir . '/' . wp_basename( $size['file'] );
				if ( ! $skip( $size_abs ) && file_exists( $size_abs ) ) {
					$this->files->delete( $size_abs );
				}
			}
		}
	}

	/**
	 * Absolute paths described by an attachment metadata array.
	 *
	 * @param array<string,mixed> $meta Metadata.
	 * @return array<int,string>
	 */
	private function metadata_paths( array $meta ): array {
		$main_abs = $this->backup->resolve_upload_path( (string) ( $meta['file'] ?? '' ) );

		if ( false === $main_abs ) {
			return array();
		}

		$paths = array( wp_normalize_path( $main_abs ) );
		$dir   = dirname( $main_abs );

		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$paths[] = wp_normalize_path( $dir . '/' . wp_basename( $meta['original_image'] ) );
		}

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$paths[] = wp_normalize_path( $dir . '/' . wp_basename( $size['file'] ) );
				}
			}
		}

		return $paths;
	}

	/**
	 * Guess the MIME type of the restored original.
	 *
	 * @param string $file_abs Absolute file path.
	 */
	private function guess_mime( string $file_abs ): string {
		$type = wp_check_filetype( $file_abs );
		return ! empty( $type['type'] ) ? (string) $type['type'] : 'image/jpeg';
	}
}
