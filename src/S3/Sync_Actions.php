<?php
/**
 * Explicit row actions for the S3 reconciliation screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

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
			$adopter = new \Sakura\ImageOptimizer\Sync\Attachment_Adopter();
			if ( $adopter->can_adopt( $key ) ) {
				$adopter->adopt( $key, get_current_user_id() );
			}
		}

		return $result;
	}

	/**
	 * Delete one cloud object.
	 *
	 * @param string $key Object key.
	 * @return true|\WP_Error
	 */
	public function delete( string $key ) {
		return $this->adapter->delete_keys( array( $key ) );
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
