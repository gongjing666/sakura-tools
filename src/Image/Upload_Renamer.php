<?php
/**
 * Upload filename renaming and attachment title synchronization.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the configured rename rule before/after WordPress moves an upload.
 */
final class Upload_Renamer {

	/**
	 * Renamed filename stem awaiting attachment-title sync.
	 *
	 * @var string
	 */
	private string $renamed_title = '';

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param File_System_Interface                $files    Filesystem repository.
	 * @param Settings_Repository_Interface|null   $settings Settings repository.
	 */
	public function __construct( File_System_Interface $files, ?Settings_Repository_Interface $settings = null ) {
		$this->files    = $files;
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * Delete the replaced original file.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete_replaced_file( string $path ): bool {
		return $this->files->delete( $path );
	}

	/**
	 * Rename before WordPress moves the uploaded file.
	 *
	 * @param array $file Upload item.
	 * @return array
	 */
	public function pre_upload_rename( $file ) {
		$enabled = (bool) $this->settings->rename_enabled();
		if ( ! $enabled ) {
			return $file;
		}

		$rule = $this->settings->rename_rule();
		if ( empty( $rule ) || ! isset( $file['name'] ) ) {
			return $file;
		}

		$new_name = $this->generate_renamed_filename( (string) $file['name'] );
		if ( '' === $new_name ) {
			return $file;
		}

		$this->renamed_title = pathinfo( $new_name, PATHINFO_FILENAME );
		$file['name']        = $new_name;
		return $file;
	}

	/**
	 * Fallback rename after the file has been moved into uploads.
	 *
	 * @param array $upload Upload result (passed by reference).
	 * @return string Final path.
	 */
	public function ensure_uploaded_file_renamed( &$upload ) {
		if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload['file'] ?? '';
		}

		$enabled = (bool) $this->settings->rename_enabled();
		if ( ! $enabled ) {
			return $upload['file'];
		}

		$current_path   = $upload['file'];
		$current_name   = wp_basename( $current_path );
		$generated_name = $this->generate_renamed_filename( $current_name );
		if ( '' === $generated_name || $generated_name === $current_name ) {
			return $current_path;
		}

		$target_path = trailingslashit( dirname( $current_path ) ) . $generated_name;
		$target_path = wp_unique_filename( dirname( $current_path ), wp_basename( $target_path ) );
		$target_path = trailingslashit( dirname( $current_path ) ) . $target_path;

		if ( ! $this->files->move( $current_path, $target_path ) ) {
			return $current_path;
		}

		$upload['file'] = $target_path;
		if ( ! empty( $upload['url'] ) ) {
			$upload['url'] = str_replace( $current_name, wp_basename( $target_path ), $upload['url'] );
		}
		$this->renamed_title = pathinfo( wp_basename( $target_path ), PATHINFO_FILENAME );

		return $target_path;
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
		unset( $postarr, $unsanitized_postarr );

		if ( $update || '' === $this->renamed_title ) {
			return $data;
		}

		if ( ( $data['post_type'] ?? '' ) !== 'attachment' ) {
			return $data;
		}

		$title = sanitize_text_field( $this->renamed_title );
		if ( '' === $title ) {
			return $data;
		}

		$data['post_title']  = $title;
		$data['post_name']   = sanitize_title( $title );
		$this->renamed_title = '';

		return $data;
	}

	/**
	 * Generate a filename from the rename rule.
	 *
	 * @param string $filename Original filename.
	 * @return string
	 */
	private function generate_renamed_filename( string $filename ): string {
		$rule = $this->settings->rename_rule();
		if ( empty( $rule ) ) {
			return '';
		}

		$info = pathinfo( $filename );
		$ext  = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

		$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'apng', 'bmp', 'tiff', 'tif', 'ico' );
		if ( ! in_array( $ext, $image_exts, true ) ) {
			return '';
		}

		$original_name = $info['filename'];
		$replacers     = array(
			'{filename}' => $original_name,
			'{ext}'      => $ext,
			'{date}'     => current_time( 'Ymd' ),
			'{time}'     => current_time( 'His' ),
			'{year}'     => current_time( 'Y' ),
			'{month}'    => current_time( 'm' ),
			'{day}'      => current_time( 'd' ),
			'{rand}'     => self::random_string( 5 ),
			'{rand8}'    => self::random_string( 8 ),
		);

		$new_name = str_replace( array_keys( $replacers ), array_values( $replacers ), $rule );
		$new_name = sanitize_file_name( $new_name );
		if ( empty( $new_name ) ) {
			$new_name = $original_name;
		}

		if ( strtolower( pathinfo( $new_name, PATHINFO_EXTENSION ) ) !== $ext ) {
			$new_name .= '.' . $ext;
		}

		return $new_name;
	}

	/**
	 * Random lowercase/uppercase/digits string without ambiguous characters.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random_string( int $length = 5 ): string {
		$chars  = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$max    = strlen( $chars ) - 1;
		$result = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$result .= $chars[ random_int( 0, $max ) ];
		}
		return $result;
	}
}
