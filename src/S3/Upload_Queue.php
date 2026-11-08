<?php
/**
 * S3 attachment upload queue.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Deduplicates attachment uploads for one request and flushes the deferred
 * attachments at shutdown.
 *
 * Callbacks are injected so this service has no dependency on a concrete
 * storage class or its static helpers.
 */
final class Upload_Queue {

	/**
	 * Upload callback: (string $key, string $file, bool $delete_local).
	 *
	 * @var callable
	 */
	private $upload_file;

	/**
	 * Key resolver: (string $file) => string|false.
	 *
	 * @var callable
	 */
	private $key_from_path;

	/**
	 * Options provider: () => array<string,mixed>.
	 *
	 * @var callable
	 */
	private $options;

	/**
	 * Request guard: () => bool.
	 *
	 * @var callable
	 */
	private $skip_request;

	/**
	 * Uploaded fingerprints.
	 *
	 * @var array<string, bool>
	 */
	private array $uploaded = array();

	/**
	 * Deferred attachment IDs.
	 *
	 * @var int[]
	 */
	private array $pending = array();

	/**
	 * Guard against recursive metadata uploads.
	 *
	 * @var bool
	 */
	private bool $syncing = false;

	/**
	 * Success callback: (int $attachment_id, string $key).
	 *
	 * @var callable|null
	 */
	private $on_success;

	/**
	 * Failure callback: (int $attachment_id, string $key, string $file, \WP_Error $error).
	 *
	 * @var callable|null
	 */
	private $on_failure;

	/**
	 * Constructor.
	 *
	 * @param callable      $upload_file   Upload callback.
	 * @param callable      $key_from_path Object-key resolver.
	 * @param callable      $options       Options provider.
	 * @param callable      $skip_request  Request guard.
	 * @param callable|null $on_success    Optional success callback.
	 * @param callable|null $on_failure    Optional failure callback.
	 */
	public function __construct( callable $upload_file, callable $key_from_path, callable $options, callable $skip_request, ?callable $on_success = null, ?callable $on_failure = null ) {
		$this->upload_file   = $upload_file;
		$this->key_from_path = $key_from_path;
		$this->options       = $options;
		$this->skip_request  = $skip_request;
		$this->on_success    = $on_success;
		$this->on_failure    = $on_failure;
	}

	/**
	 * Queue an attachment for the shutdown flush.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function queue( int $attachment_id ): void {
		if ( ( $this->skip_request )() ) {
			return;
		}
		$this->pending[] = $attachment_id;
	}

	/**
	 * Upload deferred attachments.
	 */
	public function flush(): void {
		foreach ( array_unique( $this->pending ) as $attachment_id ) {
			$file = get_attached_file( $attachment_id, true );
			if ( is_string( $file ) && '' !== $file ) {
				$this->upload_path_once( $file, null, $attachment_id );
			}
		}
		$this->pending = array();
	}

	/**
	 * Upload the main file, original image and generated sizes.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function upload_metadata( $metadata, $attachment_id = 0 ) {
		$metadata = is_array( $metadata ) ? $metadata : array();
		if ( $this->syncing || empty( $metadata['file'] ) || ( $this->skip_request )() ) {
			return $metadata;
		}

		$this->syncing = true;
		try {
			$options      = (array) ( $this->options )();
			$uploads      = wp_upload_dir( null, false );
			$main         = trailingslashit( $uploads['basedir'] ) . ltrim( (string) $metadata['file'], '/' );
			$delete_local = 'true' === ( $options['no_local_saving'] ?? 'false' );
			$this->upload_path_once( $main, $delete_local, (int) $attachment_id );

			$dir            = trailingslashit( dirname( $main ) );
			$original_image = self::metadata_filename( $metadata['original_image'] ?? null );
			if ( '' !== $original_image ) {
				$this->upload_path_once( $dir . $original_image, $delete_local, (int) $attachment_id );
			}

			if ( 'true' !== ( $options['no_thumb'] ?? 'false' ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size ) {
					$size_file = self::metadata_filename( is_array( $size ) ? ( $size['file'] ?? null ) : null );
					if ( '' !== $size_file ) {
						$this->upload_path_once( $dir . $size_file, $delete_local, (int) $attachment_id );
					}
				}
			}
		} finally {
			$this->syncing = false;
		}

		return $metadata;
	}

	/**
	 * Upload one path once per request, using a size/mtime fingerprint.
	 *
	 * @param string    $file          Absolute file path.
	 * @param bool|null $delete_local  Whether to delete the local file after upload.
	 * @param int       $attachment_id Attachment ID for status callbacks.
	 * @return bool
	 */
	private function upload_path_once( string $file, ?bool $delete_local = null, int $attachment_id = 0 ): bool {
		$key = ( $this->key_from_path )( $file );
		if ( false === $key || ! is_file( $file ) ) {
			return false;
		}

		$size        = filesize( $file );
		$modified_at = filemtime( $file );
		if ( false === $size || false === $modified_at ) {
			return false;
		}

		$fingerprint = $key . '|' . (string) $size . '|' . (string) $modified_at;
		if ( isset( $this->uploaded[ $fingerprint ] ) ) {
			return true;
		}

		if ( null === $delete_local ) {
			$options      = (array) ( $this->options )();
			$delete_local = 'true' === ( $options['no_local_saving'] ?? 'false' );
		}

		$result = ( $this->upload_file )( $key, $file, $delete_local );
		if ( ! is_wp_error( $result ) ) {
			$this->uploaded[ $fingerprint ] = true;
			if ( $this->on_success ) {
				( $this->on_success )( $attachment_id, $key );
			}
			return true;
		}

		if ( $this->on_failure ) {
			( $this->on_failure )( $attachment_id, $key, $file, $result );
		}

		return false;
	}

	/**
	 * Return a basename-only metadata filename.
	 *
	 * @param mixed $value Metadata value.
	 * @return string
	 */
	private static function metadata_filename( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$filename = wp_basename( $value );
		return ( '' === $filename || '.' === $filename || '..' === $filename ) ? '' : $filename;
	}
}
