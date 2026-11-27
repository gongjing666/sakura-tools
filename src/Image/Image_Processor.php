<?php
/**
 * Upload processing hooks and image conversion orchestration.
 *
 * The heavy lifting now lives in dedicated services:
 * - Upload_Handler  rename/backup/upload/sub-size metadata
 * - File_Processor  policy, skip rules and engine dispatch
 * - Restore_Service backup restore and metadata rebuild
 *
 * This class is the thin WordPress hook adapter and public compatibility facade.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Filesystem\File_System_Repository;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\Image\Engine_Manager;
use Sakura\ImageOptimizer\Image\File_Processor;
use Sakura\ImageOptimizer\Image\Restore_Service;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Image\Upload_Handler;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Hook adapter for upload conversion, sub-size optimization and restore.
 */
class Image_Processor implements Module {

	/**
	 * Upload orchestration service.
	 *
	 * @var Upload_Handler
	 */
	private Upload_Handler $uploads;

	/**
	 * Policy and engine dispatch service.
	 *
	 * @var File_Processor
	 */
	private File_Processor $files_processor;

	/**
	 * Restore service.
	 *
	 * @var Restore_Service
	 */
	private Restore_Service $restore;

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
	 * Engine manager.
	 *
	 * @var Engine_Manager
	 */
	private Engine_Manager $engine;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param Backup_Repository_Interface|null    $backup          Backup repository.
	 * @param Engine_Manager|null                 $engine          Engine manager.
	 * @param Restore_Service|null                $restore_service Restore service.
	 * @param Stats_Repository_Interface|null     $stats           Stats repository.
	 * @param File_System_Interface|null          $files           Filesystem repository.
	 * @param Settings_Repository_Interface|null  $settings        Settings repository.
	 */
	public function __construct(
		?Backup_Repository_Interface $backup = null,
		?Engine_Manager $engine = null,
		?Restore_Service $restore_service = null,
		?Stats_Repository_Interface $stats = null,
		?File_System_Interface $files = null,
		?Settings_Repository_Interface $settings = null
	) {
		$this->backup   = $backup ?? new Backup_Repository();
		$this->stats    = $stats ?? new Stats_Repository();
		$this->files    = $files ?? new File_System_Repository();
		$this->engine   = $engine ?? new Engine_Manager();
		$this->settings = $settings ?? new Settings_Repository();
		$this->restore  = $restore_service ?? new Restore_Service( $this->backup, $this->files );

		$this->files_processor = new File_Processor( $this->engine, $this->settings );
		$this->uploads         = new Upload_Handler(
			$this->files_processor,
			$this->backup,
			$this->stats,
			$this->files,
			$this->settings
		);
	}

	/**
	 * Register WordPress hooks with the module loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_filter( 'wp_handle_upload_prefilter', array( $this, 'pre_upload_rename' ), PHP_INT_MAX );
		$hooks->add_filter( 'wp_handle_sideload_prefilter', array( $this, 'pre_upload_rename' ), PHP_INT_MAX );

		$hooks->add_filter( 'wp_insert_attachment_data', array( $this, 'sync_attachment_title' ), PHP_INT_MAX, 4 );

		/*
		 * Priority PHP_INT_MAX so this runs after WordPress has written the file.
		 * S3 uploads hooks wp_handle_upload later at priority 100, so the file is
		 * converted before it is pushed to object storage.
		 */
		$hooks->add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), PHP_INT_MAX, 2 );
		$hooks->add_filter( 'wp_handle_sideload', array( $this, 'handle_upload' ), PHP_INT_MAX, 2 );

		/*
		 * Priority 99: run before S3 Uploads' metadata hook (100) so generated
		 * sub-sizes are converted before they are uploaded to the bucket.
		 */
		$hooks->add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_metadata' ), 99, 2 );

		$hooks->add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 10, 1 );
	}

	/**
	 * Rename an upload before WordPress moves it into uploads.
	 *
	 * @param array $file Upload item.
	 * @return array
	 */
	public function pre_upload_rename( $file ) {
		return $this->uploads->pre_upload_rename( $file );
	}

	/**
	 * Sync the attachment title with the renamed file.
	 *
	 * @param array $data                Attachment data.
	 * @param array $postarr             Raw attachment data.
	 * @param array $unsanitized_postarr Unslashed data.
	 * @param bool  $update              Whether this is an update.
	 * @return array
	 */
	public function sync_attachment_title( $data, $postarr, $unsanitized_postarr = array(), $update = false ) {
		return $this->uploads->sync_attachment_title( $data, $postarr, $unsanitized_postarr, $update );
	}

	/**
	 * Fallback rename after WordPress moved the file.
	 *
	 * @param array $upload Upload result (by reference).
	 * @return string
	 */
	public function ensure_uploaded_file_renamed( &$upload ) {
		return $this->uploads->ensure_uploaded_file_renamed( $upload );
	}

	/**
	 * Process the original uploaded image and replace it if converted.
	 *
	 * @param array  $upload  Upload result.
	 * @param string $context Upload context.
	 * @return array
	 */
	public function handle_upload( $upload, $context ) {
		return $this->uploads->handle_upload( $upload, $context );
	}

	/**
	 * Process generated sub-sizes and flush the pending upload stats.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function process_metadata( $metadata, $attachment_id ) {
		return $this->uploads->process_metadata( $metadata, $attachment_id );
	}

	/**
	 * Process one file (used by the upload and batch paths).
	 *
	 * @param string $file_path Absolute path.
	 * @return array
	 */
	public function process_file( $file_path ) {
		return $this->files_processor->process_file( $file_path );
	}

	/**
	 * Restore an attachment from its pre-optimization backup.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{success:bool,message:string}
	 */
	public function restore_attachment( $attachment_id ) {
		return $this->restore->restore( $attachment_id );
	}

	/**
	 * Delete backup data when an attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ) {
		$this->backup->delete_backup( (int) $attachment_id );
	}

	/**
	 * Why a file would be skipped; empty means processable.
	 *
	 * @param string $file_path Absolute path.
	 * @return string
	 */
	public function get_processing_skip_reason( $file_path ) {
		return $this->files_processor->get_processing_skip_reason( $file_path );
	}

	/**
	 * Whether the source extension is selected for processing.
	 *
	 * @param string $file_path Absolute path.
	 * @return bool
	 */
	public function should_process_file( $file_path ) {
		return $this->files_processor->should_process_file( $file_path );
	}

	/**
	 * Whether global compression is enabled.
	 *
	 * @return bool
	 */
	public function is_compression_enabled() {
		return $this->files_processor->is_compression_enabled();
	}

	/**
	 * Whether backups are enabled.
	 *
	 * @return bool
	 */
	public function is_backup_enabled() {
		return $this->uploads->is_backup_enabled();
	}

	/**
	 * Maximum allowed pixels per image.
	 *
	 * @return int
	 */
	public static function get_max_pixels() {
		return File_Processor::get_max_pixels();
	}

	/**
	 * Map a processed file extension to its MIME type.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public function mime_from_path( $path ) {
		return $this->files_processor->mime_from_path( $path );
	}

	/**
	 * Run the best available engine (compatibility wrapper).
	 *
	 * @param string $file_path Absolute path.
	 * @param array  $opts      Processing options.
	 * @return array
	 */
	public function process_with_best_engine( $file_path, $opts ) {
		return $this->engine->process( $file_path, $opts );
	}
}
