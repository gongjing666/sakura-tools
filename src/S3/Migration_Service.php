<?php
/**
 * Batched migration of existing attachments to object storage.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure migration loop with injected attachment and upload callbacks.
 */
final class Migration_Service {

	/**
	 * Attachment-files provider.
	 *
	 * @var callable
	 */
	private $attachment_files;

	/**
	 * Object-key resolver.
	 *
	 * @var callable
	 */
	private $key_from_path;

	/**
	 * Upload callback.
	 *
	 * @var callable
	 */
	private $upload_file;

	/**
	 * Batch-query callback.
	 *
	 * @var callable
	 */
	private $query_batch;

	/**
	 * Uploaded callback: (int $attachment_id, string $key).
	 *
	 * @var callable|null
	 */
	private $on_uploaded;

	/**
	 * Failed callback: (int $attachment_id, string $key, string $file, \WP_Error $error).
	 *
	 * @var callable|null
	 */
	private $on_failed;

	/**
	 * Per-attachment completion callback: (int $attachment_id, bool $ok, string $error).
	 *
	 * @var callable|null
	 */
	private $on_done;

	/**
	 * Constructor.
	 *
	 * @param callable      $attachment_files Returns files for an attachment ID.
	 * @param callable      $key_from_path    Maps a local path to an object key.
	 * @param callable      $upload_file      Uploads a key/path without deleting local.
	 * @param callable      $query_batch      Returns attachment IDs after a cursor.
	 * @param callable|null $on_uploaded      Optional success callback.
	 * @param callable|null $on_failed        Optional failure callback.
	 * @param callable|null $on_done          Optional per-attachment completion callback.
	 */
	public function __construct( callable $attachment_files, callable $key_from_path, callable $upload_file, callable $query_batch, ?callable $on_uploaded = null, ?callable $on_failed = null, ?callable $on_done = null ) {
		$this->attachment_files = $attachment_files;
		$this->key_from_path    = $key_from_path;
		$this->upload_file      = $upload_file;
		$this->query_batch      = $query_batch;
		$this->on_uploaded      = $on_uploaded;
		$this->on_failed        = $on_failed;
		$this->on_done          = $on_done;
	}

	/**
	 * Migrate one batch and return the AJAX response payload.
	 *
	 * @param int $cursor     Last processed attachment ID.
	 * @param int $batch_size Attachments per request.
	 * @return array{cursor:int,attachments:int,uploaded:int,failed:string[],done:bool}
	 */
	public function migrate_batch( $cursor, $batch_size = 3 ) {
		$cursor     = max( 0, (int) $cursor );
		$batch_size = max( 1, (int) $batch_size );
		$ids        = (array) call_user_func( $this->query_batch, $cursor, $batch_size );

		$uploaded = 0;
		$failed   = array();
		$last_id  = $cursor;

		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$last_id       = max( $last_id, $attachment_id );
			$attachment_ok = true;
			$last_error    = '';

			foreach ( (array) call_user_func( $this->attachment_files, $attachment_id ) as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}
				$key = call_user_func( $this->key_from_path, $file );
				if ( false === $key ) {
					$attachment_ok = false;
					$last_error    = __( '文件路径无法映射。', 'sakura-imgopt' );
					$failed[]      = sprintf(
						/* translators: %d: Attachment ID. */
						__( '附件 #%d 的文件路径无法映射。', 'sakura-imgopt' ),
						$attachment_id
					);
					continue;
				}
				$result = call_user_func( $this->upload_file, $key, $file, false );
				if ( is_wp_error( $result ) ) {
					$attachment_ok = false;
					$last_error    = $result->get_error_message();
					$failed[]      = sprintf(
						/* translators: 1: Attachment ID. 2: Upload error message. */
						__( '附件 #%1$d：%2$s', 'sakura-imgopt' ),
						$attachment_id,
						$result->get_error_message()
					);
					if ( $this->on_failed ) {
						( $this->on_failed )( $attachment_id, (string) $key, (string) $file, $result );
					}
				} else {
					++$uploaded;
					if ( $this->on_uploaded ) {
						( $this->on_uploaded )( $attachment_id, (string) $key );
					}
				}
			}

			if ( $this->on_done ) {
				( $this->on_done )( $attachment_id, $attachment_ok, $last_error );
			}
		}

		return array(
			'cursor'      => $last_id,
			'attachments' => count( $ids ),
			'uploaded'    => $uploaded,
			'failed'      => $failed,
			'done'        => count( $ids ) < $batch_size,
		);
	}
}
