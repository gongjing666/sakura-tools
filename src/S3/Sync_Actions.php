<?php
/**
 * Explicit row actions for the S3 reconciliation screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Services\Deletion;
use Nboke\UserFiles\Services\Deletion_Context;
use Nboke\UserFiles\Services\Deletion_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Upload, download and delete one object selected from the inventory.
 *
 * The controller validates the key against the current snapshot and enforces
 * capability, nonce and the delete confirmation token before these methods run.
 */
final class Sync_Actions {

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Storage adapter.
	 *
	 * @var Storage_Adapter
	 */
	private Storage_Adapter $adapter;

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options Options.
	 * @param Storage_Adapter|null $adapter Adapter.
	 */
	public function __construct( ?Options $options = null, ?Storage_Adapter $adapter = null ) {
		$this->options = $options ?? new Options();
		$this->adapter = $adapter ?? new Storage_Adapter( $this->options );
	}

	/**
	 * Upload one local file under the configured uploads root.
	 *
	 * @param string $key Object key.
	 * @return true|\WP_Error
	 */
	public function upload( string $key ) {
		$path = $this->local_path( $key, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return $this->adapter->upload_file( $key, $path, false );
	}

	/**
	 * Download one cloud object to its local path.
	 *
	 * Existing local files are never overwritten.
	 *
	 * @param string $key Object key.
	 * @return true|\WP_Error
	 */
	public function download( string $key ) {
		$path = $this->local_path( $key, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( file_exists( $path ) ) {
			return new \WP_Error( 'sakura_s3_local_exists', __( '本地文件已存在，已取消下载以免覆盖。', 'sakura-imgopt' ) );
		}

		$result = $this->adapter->download_file( $key, $path );
		if ( ! is_wp_error( $result ) && is_file( $path ) ) {
			clearstatcache( true, $path );
			( new \Sakura\ImageOptimizer\Sync\Index_Repository() )->record_downloaded(
				$this->options->active_profile_id(),
				$key,
				$path,
				(int) filesize( $path ),
				(int) filemtime( $path )
			);

			// A restored object without an attachment row would be invisible in
			// the media library; adopt it so the two views agree.
			$adopter       = new \Sakura\ImageOptimizer\Sync\Attachment_Adopter();
			$attachment_id = 0;
			if ( $adopter->can_adopt( $key ) ) {
				$adopted = $adopter->adopt( $key, get_current_user_id() );
				if ( ! is_wp_error( $adopted ) ) {
					$attachment_id = (int) $adopted;
				}
			}
			if ( $attachment_id <= 0 ) {
				$attachment_id = (int) $this->adapter->attachment_id_for_key( $key );
			}
			$this->mark_downloaded( $attachment_id, $key );
		}

		return $result;
	}

	/**
	 * Mirror a finished download into attachment sync state.
	 *
	 * `add_attachment` marks every new row pending, and the adopted row skips
	 * finalize, so a restored attachment would stay pending forever: the URL
	 * filter then falls back to the local uploads URL even though the object is
	 * sitting in the active bucket. The bookkeeping itself lives in
	 * Action_Worker::write_back() so the inline and queued paths stamp exactly
	 * the same meta.
	 *
	 * @param int    $attachment_id Attachment id (0 when unknown).
	 * @param string $key           Object key that was just restored.
	 * @return void
	 */
	private function mark_downloaded( int $attachment_id, string $key ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		( new \Sakura\ImageOptimizer\Sync\Action_Worker(
			$this->options,
			$this->adapter,
			new \Sakura\ImageOptimizer\Sync\Index_Repository()
		) )->write_back(
			$attachment_id,
			$key,
			'download',
			array(
				'options' => $this->options,
				'adapter' => $this->adapter,
			),
			Synchronous_Uploader::target_hash_for( $this->options ),
			$this->options->active_profile_id()
		);

		$this->charge_owner( $attachment_id );
	}

	/**
	 * Put the restored attachment into the user file space ledger.
	 *
	 * Attachment_Adopter suppresses adoption on purpose - the `add_attachment`
	 * hook fires while the row is still being written, before the author is
	 * known - so nothing else ever adds the ledger row: the file then counts
	 * against no quota and sits in the media library's "unassigned media" view
	 * forever. The download is the moment the attachment becomes real, and the
	 * bytes are already on local disk, so this is where the charge belongs.
	 *
	 * The user file space owns its own tables and services; keep the dependency
	 * one-way and optional so the storage module still works on a site where
	 * that code is absent.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return void
	 */
	private function charge_owner( int $attachment_id ): void {
		$class = '\Nboke\UserFiles\Services\Media_Adoption';

		if ( ! class_exists( $class ) ) {
			return;
		}

		( new $class() )->adopt( $attachment_id );
	}

	/**
	 * Delete one object through the plugin's deletion kernel.
	 *
	 * The sync centre is the one caller that can finish a delete inside the
	 * request: objects are removed synchronously and proven gone before the row
	 * says 已删除. When the key still belongs to an active ledger row the whole
	 * attachment goes (D7) — leaving the attachment behind while its objects
	 * vanish is exactly the "文件没了库还在" state the kernel exists to prevent.
	 * Orphan keys, thumbnails of unmanaged uploads and rows whose ledger entry
	 * is already settled are cloud-only.
	 *
	 * @param string $key      Object key.
	 * @param int    $actor_id Acting user id (0 for the queue worker).
	 * @return true|\WP_Error
	 */
	public function delete( string $key, int $actor_id = 0 ) {
		$key = $this->adapter->normalize_key( $key );

		if ( ! is_string( $key ) || '' === $key ) {
			return new \WP_Error( 'sakura_s3_invalid_key', __( '对象 Key 无效。', 'sakura-imgopt' ) );
		}

		/* The storage module works on its own: when the user file space is not
		 * loaded there is no ledger to settle and a plain object delete is the
		 * whole job. */
		if ( ! class_exists( Deletion::class ) || ! class_exists( Files::class ) ) {
			return $this->adapter->delete_keys( array( $key ) );
		}

		$attachment = $this->adapter->attachment_id_for_key( $key );
		$mode       = $this->removes_attachment( $attachment ) ? Deletion_Context::MODE_FULL : Deletion_Context::MODE_REMOTE_ONLY;

		$ctx = new Deletion_Context(
			$mode,
			$actor_id,
			Deletion::REASON_OPERATOR,
			true,
			false,
			Deletion::VIA_PLUGIN
		);

		$result = ( new Deletion( new Files() ) )->delete(
			array(
				array(
					'key'           => $key,
					'attachment_id' => $attachment,
				),
			),
			$ctx
		);

		$status = $result->first_status();

		if ( Deletion_Result::DELETED === $status || Deletion_Result::MISSING === $status ) {
			return true;
		}

		$error = $result->first_error();

		return new \WP_Error(
			'sakura_s3_delete_failed',
			'' !== $error ? $error : $result->message()
		);
	}

	/**
	 * Whether this attachment still has to be removed along with its objects.
	 *
	 * An `active` ledger row means the file counts against a user's quota, and a
	 * `deleting` row means something already claimed the removal: both must go
	 * through the kernel so the second caller is told "in progress" instead of
	 * deleting the cloud copy behind the first caller's back. A settled row is
	 * already accounted for, and a row without any ledger entry never was, so
	 * both of those may lose their objects without touching the media library
	 * record.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function removes_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || ! class_exists( Files::class ) ) {
			return false;
		}

		$file = ( new Files() )->get_by_attachment( $attachment_id );

		if ( ! $file ) {
			return false;
		}

		return in_array( (string) $file->lifecycle, array( Files::LIFECYCLE_ACTIVE, Files::LIFECYCLE_DELETING ), true );
	}

	/**
	 * Resolve an object key to a path inside the uploads directory.
	 *
	 * @param string $key        Object key.
	 * @param bool   $must_exist Whether the local file must already exist.
	 * @return string|\WP_Error
	 */
	public function local_path( string $key, bool $must_exist ) {
		$key = $this->adapter->normalize_key( $key );
		if ( false === $key ) {
			return new \WP_Error( 'sakura_s3_invalid_key', __( '对象 Key 无效。', 'sakura-imgopt' ) );
		}

		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		if ( false === $base ) {
			return new \WP_Error( 'sakura_s3_uploads_missing', __( '上传目录不存在或不可读。', 'sakura-imgopt' ) );
		}
		$base = trailingslashit( wp_normalize_path( $base ) );

		$candidate = wp_normalize_path( trailingslashit( wp_normalize_path( ABSPATH ) ) . $key );
		if ( 0 !== strpos( $candidate, $base ) ) {
			return new \WP_Error( 'sakura_s3_outside_uploads', __( '该对象不在 WordPress 上传目录内，已拒绝操作。', 'sakura-imgopt' ) );
		}

		if ( $must_exist ) {
			$real = realpath( $candidate );
			if ( false === $real || ! is_file( $real ) || 0 !== strpos( wp_normalize_path( $real ), $base ) ) {
				return new \WP_Error( 'sakura_s3_local_missing', __( '本地文件不存在或不可读。', 'sakura-imgopt' ) );
			}
			return wp_normalize_path( $real );
		}

		// The destination may not exist yet; validate the nearest existing parent.
		$probe = dirname( $candidate );
		while ( ! is_dir( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		$real = realpath( $probe );
		if ( false === $real ) {
			return new \WP_Error( 'sakura_s3_outside_uploads', __( '该对象不在 WordPress 上传目录内，已拒绝操作。', 'sakura-imgopt' ) );
		}
		$real = trailingslashit( wp_normalize_path( $real ) );
		if ( 0 !== strpos( $real, $base ) ) {
			return new \WP_Error( 'sakura_s3_outside_uploads', __( '该对象不在 WordPress 上传目录内，已拒绝操作。', 'sakura-imgopt' ) );
		}

		return $candidate;
	}
}
