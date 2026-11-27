<?php
/**
 * Attachment-level index writes.
 *
 * @package Sakura\ImageOptimizer
 */

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors media writes into the sync index as they happen.
 *
 * Without this, a freshly uploaded attachment is invisible in the S3 sync
 * center until the next full reconciliation. The hook path only touches the
 * attachment's own keys (original + generated sizes) and updates the counts
 * table incrementally, so one upload costs a handful of primary-key writes
 * instead of a full GROUP BY.
 */
final class Attachment_Indexer implements Module {

	private Options $options;
	private Storage_Adapter $adapter;
	private Index_Repository $index;

	/**
	 * Constructor.
	 *
	 * @param Options          $options S3 options.
	 * @param Storage_Adapter  $adapter Storage adapter.
	 * @param Index_Repository $index   Index repository.
	 */
	public function __construct( Options $options, Storage_Adapter $adapter, Index_Repository $index ) {
		$this->options = $options;
		$this->adapter = $adapter;
		$this->index   = $index;
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'add_attachment', array( $this, 'index_attachment' ), 20, 1 );
		$hooks->add_action( 'wp_update_attachment_metadata', array( $this, 'on_metadata' ), 20, 2 );
		$hooks->add_action( 'delete_attachment', array( $this, 'forget_attachment' ), 10, 1 );
		$hooks->add_action( 'sakura_imgopt_s3_object_deleted', array( $this, 'forget_remote' ), 10, 2 );
	}

	/**
	 * Re-index after metadata (sizes) changed.
	 *
	 * @param mixed $data    Attachment metadata.
	 * @param int   $post_id Attachment ID.
	 * @return mixed
	 */
	public function on_metadata( $data, $post_id ) {
		$this->index_attachment( (int) $post_id );
		return $data;
	}

	/**
	 * Upsert the attachment's local files into the index.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function index_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || ! $this->ready() ) {
			return;
		}

		$rows = $this->rows_for_attachment( $attachment_id );
		if ( $rows ) {
			$this->index->record_local( $this->options->active_profile_id(), $rows );
		}
	}

	/**
	 * Forget the attachment's local files when it is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function forget_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || ! $this->ready() ) {
			return;
		}

		$profile = $this->options->active_profile_id();
		$keys    = array_keys( $this->keys_for_attachment( $attachment_id ) );

		/*
		 * A restore writes the original extension back and a later optimisation
		 * converts it again, so the index can hold rows for both spellings of the
		 * same file. The metadata only names the current one; without this the older
		 * row survives the delete and shows up as a phantom "synced" entry.
		 */
		$primary = $this->primary_key( $attachment_id );
		if ( '' !== $primary ) {
			$keys = array_merge( $keys, $this->index->keys_by_stem( $profile, $primary ) );
		}

		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );

		if ( $keys ) {
			$this->index->forget_local( $profile, $keys );
		}
	}

	/**
	 * Object key of the attachment's current primary file.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string Key, or '' when the attachment has no file.
	 */
	private function primary_key( int $attachment_id ): string {
		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $relative ) {
			return '';
		}

		$prefix = trim( str_replace( '\\', '/', $this->options->upload_path() ), '/' );

		return '' === $prefix ? $relative : $prefix . '/' . $relative;
	}

	/**
	 * Drop the index row once the remote copy is gone.
	 *
	 * forget_attachment() only clears the local side, which leaves the row
	 * claiming the object is cloud-only; the delete worker settles the remote
	 * copy asynchronously and fires this hook when it is really gone.
	 *
	 * @param mixed $key        Object key.
	 * @param mixed $profile_id Profile the delete job was created with.
	 */
	public function forget_remote( $key, $profile_id ): void {
		$key = (string) $key;
		if ( '' === $key || ! Feature::enabled() ) {
			return;
		}

		$this->index->apply_action( (string) $profile_id, $key, 'delete' );
	}

	/**
	 * Whether the index hooks are relevant for this site.
	 */
	private function ready(): bool {
		if ( ! Feature::enabled() || ! $this->options->is_enabled() || '' === $this->options->active_profile_id() ) {
			return false;
		}
		if ( ! $this->options->is_configured() ) {
			return false;
		}
		return (bool) apply_filters( 'sakura_imgopt_sync_attachment_index', true );
	}

	/**
	 * Rows (key/path/size/mtime) for an attachment that exist on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,array{key:string,path:string,size:int,mtime:int}>
	 */
	private function rows_for_attachment( int $attachment_id ): array {
		$rows = array();
		foreach ( $this->keys_for_attachment( $attachment_id ) as $key => $path ) {
			$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are skipped below.
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $size || false === $mtime ) {
				continue;
			}
			$rows[] = array(
				'key'   => $key,
				'path'  => wp_normalize_path( $path ),
				'size'  => (int) $size,
				'mtime' => (int) $mtime,
			);
		}
		return $rows;
	}

	/**
	 * Object key => local path for every file the attachment owns.
	 *
	 * Keys are derived even when the file is already gone, so the delete hook
	 * can clean up rows without depending on file existence.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,string>
	 */
	private function keys_for_attachment( int $attachment_id ): array {
		$keys    = array();
		$uploads = wp_upload_dir( null, false );
		$base    = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$prefix  = trim( str_replace( '\\', '/', $this->options->upload_path() ), '/' );
		if ( '' === $base ) {
			return $keys;
		}

		foreach ( $this->adapter->attachment_files( $attachment_id ) as $path ) {
			$path = wp_normalize_path( (string) $path );
			if ( 0 !== strpos( $path, trailingslashit( $base ) ) ) {
				continue;
			}
			$relative = ltrim( substr( $path, strlen( trailingslashit( $base ) ) ), '/' );
			if ( '' === $relative || $this->is_ignored( $relative ) ) {
				continue;
			}
			$key           = '' === $prefix ? $relative : $prefix . '/' . $relative;
			$keys[ $key ] = $path;
		}

		return $keys;
	}

	/**
	 * Skip plugin-owned directories so hooks match the scanner's filters.
	 *
	 * @param string $relative Path relative to uploads.
	 */
	private function is_ignored( string $relative ): bool {
		$relative = '/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
		return false !== strpos( $relative, '/sakura-imgopt-backup/' )
			|| false !== strpos( $relative, '/.sakura-s3-cache/' );
	}
}
