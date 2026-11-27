<?php
/**
 * In-request attachment upload and verification.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Filesystem\File_System;
use Sakura\ImageOptimizer\Sync\Index_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Finalizes one attachment inside the request that created it.
 *
 * The old flow split work between a shutdown flush, a cron worker and a retry
 * service, which produced partial object sets and background uploads. This
 * class owns the whole attachment: resolve the file list, upload every file,
 * verify each one, persist the object/version list, and only then delete local
 * copies. A single failed file leaves the attachment failed and local, so the
 * REST/AJAX response never advertises a half-populated srcset.
 */
final class Synchronous_Uploader {

	/**
	 * Per-request attempt cache keyed by key|version.
	 *
	 * @var array<string,true|string>
	 */
	private static array $attempts = array();

	/**
	 * Attachments whose metadata generation finished in this request.
	 *
	 * WordPress saves metadata twice while creating sub-sizes: once with an
	 * empty `sizes` array, then again after the sub-sizes exist. Removing the
	 * local files during the first pass deletes the source the generation
	 * still needs (audit A01), so cleanup waits for the generation filter.
	 *
	 * @var array<int,true>
	 */
	private static array $generation_done = array();

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Adapter.
	 *
	 * @var Storage_Adapter
	 */
	private Storage_Adapter $adapter;

	/**
	 * Status store.
	 *
	 * @var Sync_Status
	 */
	private Sync_Status $status;

	/**
	 * Sync index, resolved on demand.
	 *
	 * @var Index_Repository|null
	 */
	private ?Index_Repository $index = null;

	/**
	 * Constructor.
	 *
	 * @param Options              $options Storage options.
	 * @param Storage_Adapter      $adapter Adapter.
	 * @param Sync_Status          $status  Status store.
	 * @param Index_Repository|null $index  Sync index; resolved on demand when omitted.
	 */
	public function __construct( Options $options, Storage_Adapter $adapter, Sync_Status $status, ?Index_Repository $index = null ) {
		$this->options = $options;
		$this->adapter = $adapter;
		$this->status  = $status;
		$this->index   = $index;
	}

	/**
	 * Sync index.
	 */
	private function index(): Index_Repository {
		if ( null === $this->index ) {
			$this->index = new Index_Repository();
		}
		return $this->index;
	}

	/**
	 * Resolve the files that must exist remotely for one attachment.
	 *
	 * Metadata may be empty for SVG and other non-image attachments. Falling
	 * back to the attached file at that point is mandatory: waiting for
	 * metadata that will never arrive was the reason those uploads were left to
	 * the background queue.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param array|null  $metadata      Attachment metadata.
	 * @return array<string,array{path:string,version:string,size:int}>
	 */
	public function manifest( int $attachment_id, ?array $metadata = null ): array {
		$manifest = array();
		foreach ( $this->paths( $attachment_id, $metadata ) as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$key = $this->adapter->key_from_path( $path );
			if ( false === $key || '' === $key ) {
				continue;
			}
			$size  = (int) filesize( $path );
			$mtime = (int) filemtime( $path );
			$manifest[ (string) $key ] = array(
				'path'    => $path,
				'version' => $size . ':' . $mtime,
				'size'    => $size,
			);
		}

		return $manifest;
	}

	/**
	 * Absolute paths described by metadata (whether or not they still exist).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Attachment metadata.
	 * @return array<int,string>
	 */
	private function paths( int $attachment_id, ?array $metadata = null ): array {
		$metadata = is_array( $metadata ) ? $metadata : get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$uploads  = wp_upload_dir( null, false );
		$base     = trailingslashit( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ) );

		$paths = array();
		$main  = '';

		if ( ! empty( $metadata['file'] ) ) {
			$main    = wp_normalize_path( $base . ltrim( (string) $metadata['file'], '/' ) );
			$paths[] = $main;
			$dir     = trailingslashit( dirname( $main ) );

			$original = self::filename( $metadata['original_image'] ?? null );
			if ( '' !== $original ) {
				$paths[] = $dir . $original;
			}

			if ( 'true' !== (string) ( $this->options->get( 'no_thumb', 'false' ) ) ) {
				foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
					$size_file = self::filename( is_array( $size ) ? ( $size['file'] ?? null ) : null );
					if ( '' !== $size_file ) {
						$paths[] = $dir . $size_file;
					}
				}
			}
		}

		$attached = get_attached_file( $attachment_id, true );
		if ( is_string( $attached ) && '' !== $attached ) {
			$paths[] = wp_normalize_path( $attached );
			if ( '' === $main ) {
				$main = wp_normalize_path( $attached );
			}
		}

		$dir = '' !== $main ? trailingslashit( dirname( $main ) ) : '';
		foreach ( (array) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) as $size ) {
			$size_file = self::filename( is_array( $size ) ? ( $size['file'] ?? null ) : null );
			if ( '' !== $size_file && '' !== $dir ) {
				$paths[] = $dir . $size_file;
			}
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Finalize one attachment: upload, verify, persist, then clean up.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata passed by the filter.
	 * @param bool       $manual        Admin-requested retry; keeps local files.
	 * @param bool       $preserve_local Restore/optimizer path; keeps local files.
	 * @param bool       $keep_recorded Keep the recorded key list for restore reconciliation.
	 * @return array{status:string,keys:array<int,string>,failed:array<string,string>,message:string}
	 */
	public function finalize( int $attachment_id, ?array $metadata = null, bool $manual = false, bool $preserve_local = false, bool $keep_recorded = false ): array {
		$result = array(
			'status'  => 'failed',
			'keys'    => array(),
			'failed'  => array(),
			'message' => '',
		);

		if ( $attachment_id <= 0 ) {
			$result['message'] = __( '附件 ID 无效。', 'sakura-imgopt' );
			return $result;
		}

		// Adopting a local file into the media library is a local repair step;
		// it must not trigger an upload of every generated size (the sync
		// center can do that explicitly later if the site offloads files).
		if ( apply_filters( 'sakura_imgopt_s3_skip_finalize', false, $attachment_id ) ) {
			$result['status']  = 'skipped';
			$result['message'] = __( '仅恢复媒体库记录，稍后可在同步中心补传。', 'sakura-imgopt' );
			return $result;
		}

		$manifest = $this->manifest( $attachment_id, $metadata );
		$keys     = array_keys( $manifest );
		$result['keys'] = $keys;

		if ( ! $manifest ) {
			// WordPress and the optimizer may save metadata again after the
			// files were already uploaded and removed locally. Nothing is
			// missing in that case: the attachment is confirmed (audit A01).
			if ( Sync_Status::SYNCED === $this->status->status( $attachment_id ) ) {
				$result['status']  = 'skipped';
				$result['message'] = __( '附件已同步，本地文件已清理。', 'sakura-imgopt' );
				return $result;
			}

			$result['message'] = __( '未找到可上传的附件文件。', 'sakura-imgopt' );
			$this->status->mark_failed( $attachment_id, $result['message'] );
			return $result;
		}

		$versions     = $this->versions( $manifest );
		$target_hash  = $this->target_hash();
		// R19.6: "is this object already in this bucket" is answered by the
		// confirmation service, which falls back to the sync index when the
		// stored fingerprint names another destination. Asking the fingerprint
		// alone meant a standby bucket that already held every object was
		// re-uploaded in full after a failover switch.
		//
		// A row created under a different profile that points at this same
		// bucket still counts; see target_hashes_for().
		$target_match = ( new Bucket_Confirmation( $this->options, $this->status ) )->confirms( $attachment_id );
		if ( Sync_Status::SYNCED === $this->status->status( $attachment_id ) && $this->status->versions( $attachment_id ) === $versions && $target_match ) {
			// The object set is already confirmed. The intermediate pass may
			// have marked it synced before sub-size generation finished, so the
			// cleanup is retried here once the metadata is final (audit A01).
			if ( $this->should_cleanup( $manual, $preserve_local, $attachment_id, $metadata ) ) {
				$this->cleanup_local( $attachment_id, $manifest );
			}

			$result['status']  = 'skipped';
			$result['message'] = __( '附件已同步且版本一致。', 'sakura-imgopt' );
			return $result;
		}

		if ( $manual ) {
			foreach ( $manifest as $key => $file ) {
				unset( self::$attempts[ md5( $key . '|' . $file['version'] ) ] );
			}
		}

		$this->status->mark_pending( $attachment_id );

		$upload  = array();
		$failed  = array();
		$errors  = array();
		foreach ( $manifest as $key => $file ) {
			$cache_key = md5( $key . '|' . $file['version'] );
			if ( isset( self::$attempts[ $cache_key ] ) ) {
				if ( true === self::$attempts[ $cache_key ] ) {
					continue;
				}
				$failed[ $key ] = (string) self::$attempts[ $cache_key ];
				continue;
			}
			$upload[] = array(
				'key'  => $key,
				'path' => $file['path'],
			);
		}

		if ( $upload ) {
			$concurrency = Upload_Capabilities::effective( Upload_Capabilities::requested() );
			$results     = $this->adapter->upload_many( $upload, $concurrency, $attachment_id );
			foreach ( $upload as $file ) {
				$key       = (string) $file['key'];
				$cache_key = md5( $key . '|' . $manifest[ $key ]['version'] );
				$outcome   = $results[ $key ] ?? new \WP_Error( 'sakura_s3_upload_missing_result', __( '上传结果缺失。', 'sakura-imgopt' ) );
				if ( is_wp_error( $outcome ) ) {
					$message              = $outcome->get_error_message();
					$failed[ $key ]       = $message;
					$errors[ $key ]       = $outcome;
					self::$attempts[ $cache_key ] = $message;
					continue;
				}
				self::$attempts[ $cache_key ] = true;
			}
		}

		if ( $failed ) {
			$first           = (string) reset( $failed );
			$result['failed'] = $failed;
			$result['message'] = sprintf(
				/* translators: 1: failed file count, 2: first error. */
				__( '%1$d 个文件上传失败：%2$s', 'sakura-imgopt' ),
				count( $failed ),
				$first
			);
			$entries = array();
			foreach ( $failed as $key => $message ) {
				$error     = $errors[ $key ] ?? null;
				$entries[] = array(
					'key'     => $key,
					'path'    => Sync_Status::relative_path( $manifest[ $key ]['path'] ),
					'version' => $manifest[ $key ]['version'],
					'profile' => $this->options->active_profile_id(),
					'target'  => $this->target_hash(),
					'code'    => is_wp_error( $error ) ? $error->get_error_code() : 'upload_failed',
					'message' => $message,
					'time'    => time(),
					'held'    => true,
				);
			}
			$this->status->mark_failed( $attachment_id, $result['message'], $entries );
			return $result;
		}

		// A file changed while it was uploading: the object set no longer
		// matches the attachment, so do not claim success.
		$recheck = $this->manifest( $attachment_id, $metadata );
		if ( $this->versions( $recheck ) !== $versions ) {
			$result['message'] = __( '上传期间附件文件发生变化，已保留本地文件，请重试。', 'sakura-imgopt' );
			$entries           = array();
			foreach ( $manifest as $key => $file ) {
				$entries[] = array(
					'key'     => $key,
					'path'    => Sync_Status::relative_path( $file['path'] ),
					'version' => $file['version'],
					'profile' => $this->options->active_profile_id(),
					'target'  => $this->target_hash(),
					'code'    => 'version_changed',
					'message' => $result['message'],
					'time'    => time(),
					'held'    => true,
				);
			}
			$this->status->mark_failed( $attachment_id, $result['message'], $entries );
			return $result;
		}

		// A restore keeps the pre-restore object list until reconcile_attachment()
		// has turned the difference into durable delete jobs. Replacing it here
		// would make the old WebP/JPEG keys invisible and leak them in the bucket.
		if ( ! $keep_recorded ) {
			$this->status->set_objects( $attachment_id, $keys );
		}
		$this->status->set_versions( $attachment_id, $versions );
		$this->status->mark_synced( $attachment_id, $target_hash, $this->options->active_profile_id() );

		// Persistence is a side effect too: re-read it instead of trusting the
		// return value of update_post_meta().
		if ( Sync_Status::SYNCED !== $this->status->status( $attachment_id ) ) {
			$result['message'] = __( '同步状态写入失败，已保留本地文件。', 'sakura-imgopt' );
			$this->status->mark_failed( $attachment_id, $result['message'] );
			return $result;
		}

		$result['status']  = 'synced';
		$result['message'] = __( '附件已上传并校验。', 'sakura-imgopt' );

		// R3 (07-S3存储生命周期): one confirmed upload binds the site to this
		// bucket. Written here — after the synced state was read back, before any
		// local file is deleted — so "bound" always means "an object really landed". A
		// manual or restore upload never binds: those keep the local copy and are
		// re-runnable, which is the opposite of the guarantee the lock is for.
		if ( ! $manual && ! $preserve_local ) {
			Options::lock_binding(
				array(
					'provider'        => (string) $this->options->get( 'provider', '' ),
					'bucket'          => (string) $this->options->get( 'bucket', '' ),
					'endpoint'        => (string) $this->options->get( 'endpoint', '' ),
					'region'          => (string) $this->options->get( 'region', '' ),
					'upload_path'     => (string) $this->options->get( 'upload_path', '' ),
					'no_local_saving' => (string) $this->options->get( 'no_local_saving', 'false' ),
					'no_thumb'        => (string) $this->options->get( 'no_thumb', 'false' ),
				),
				(string) $this->options->active_profile_id(),
				get_current_user_id()
			);
		}

		// R11: read the switch through the freeze, not straight from the option, so a
		// hand-edited database cannot delete local copies the binding said to keep.
		$behaviour    = $this->options->behaviour_snapshot();
		$delete_local = 'true' === $behaviour['no_local_saving'];
		if ( $delete_local && ! $manual && ! $preserve_local && $this->metadata_is_final( $attachment_id, $metadata ) ) {
			foreach ( $manifest as $key => $file ) {
				if ( ! File_System::delete( $file['path'] ) ) {
					// Cleanup is best-effort: the object is confirmed, so warn
					// instead of turning a successful sync into a failure.
					do_action( 'sakura_imgopt_s3_cleanup_failed', $attachment_id, $file['path'], $key );
				}
			}
		}

		return $result;
	}

	/**
	 * Whether local files may be removed for this attachment.
	 *
	 * @param bool       $manual         Admin retry; keeps local files.
	 * @param bool       $preserve_local Restore/optimizer path; keeps local files.
	 * @param int        $attachment_id  Attachment ID.
	 * @param array|null $metadata       Metadata passed by the filter.
	 */
	private function should_cleanup( bool $manual, bool $preserve_local, int $attachment_id, ?array $metadata ): bool {
		if ( $manual || $preserve_local ) {
			return false;
		}

		if ( 'true' !== (string) $this->options->get( 'no_local_saving', 'false' ) ) {
			return false;
		}

		return $this->metadata_is_final( $attachment_id, $metadata );
	}

	/**
	 * Remove the local copies of an already confirmed object set.
	 *
	 * @param int                                $attachment_id Attachment ID.
	 * @param array<string,array{path?:string}> $manifest      Confirmed files.
	 */
	private function cleanup_local( int $attachment_id, array $manifest ): void {
		$removed = array();
		foreach ( $manifest as $key => $file ) {
			if ( ! File_System::delete( (string) $file['path'] ) ) {
				// Cleanup is best-effort: the object is confirmed, so warn
				// instead of turning a successful sync into a failure.
				do_action( 'sakura_imgopt_s3_cleanup_failed', $attachment_id, $file['path'], $key );
				continue;
			}
			$removed[] = (string) $key;
		}

		if ( ! $removed ) {
			return;
		}

		// Tell the index the local twin is gone on purpose: without this the
		// sync centre keeps counting files the policy just deleted, and the next
		// reconcile demotes the rows to "only in the cloud".
		$this->index()->mark_local_removed( (string) $this->options->active_profile_id(), $removed );
	}

	/**
	 * Remember that sub-size generation finished for one attachment.
	 *
	 * Fires at the end of wp_generate_attachment_metadata(), after WordPress has
	 * created every registered sub-size and saved the complete metadata.
	 *
	 * @param mixed $metadata      Metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function note_generation_done( $metadata, $attachment_id = 0 ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 ) {
			self::$generation_done[ $attachment_id ] = true;
		}

		return $metadata;
	}

	/**
	 * Whether the metadata being saved describes the final file set.
	 *
	 * A non-image attachment has no sub-sizes to wait for. For images, the
	 * intermediate save carries an empty `sizes` array and happens before the
	 * generation filter runs, so local files must survive that pass (audit A01).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata passed by the filter.
	 */
	private function metadata_is_final( int $attachment_id, ?array $metadata ): bool {
		if ( null === $metadata ) {
			return true;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return true;
		}

		if ( ! empty( $metadata['sizes'] ) ) {
			return true;
		}

		return isset( self::$generation_done[ $attachment_id ] );
	}

	/**
	 * Keys that should exist remotely for an attachment.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata.
	 * @return array<int,string>
	 */
	public function desired_keys( int $attachment_id, ?array $metadata = null ): array {
		$keys = array();
		foreach ( $this->paths( $attachment_id, $metadata ) as $path ) {
			$key = $this->adapter->key_from_path( (string) $path );
			if ( is_string( $key ) && '' !== $key ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Target fingerprint: a job created for one target must never run against
	 * a profile/bucket switched in later.
	 */
	public function target_hash(): string {
		return self::target_hash_for( $this->options );
	}

	/**
	 * Fingerprint of the bucket one attachment is uploaded to.
	 *
	 * Stored with every queued manual action so a later settings switch cannot
	 * redirect that action into a different bucket.
	 *
	 * The field list lives in Bucket_Identity (R19.2): the destination is
	 * provider, bucket, endpoint and object prefix, and deliberately not the
	 * profile row id. Two saved profiles that point at the same bucket are the
	 * same target; binding the row id made a switch between them look like a
	 * bucket change, which reverted every synced attachment to the local URL
	 * and re-queued uploads that were confirmed.
	 *
	 * The output is byte for byte what the previous inline implementation
	 * produced: changing the algorithm invalidates hashes already stored in
	 * post meta, sio_sync_jobs and sio_s3_deletes.
	 *
	 * @param Options $options Options snapshot.
	 */
	public static function target_hash_for( Options $options ): string {
		return Bucket_Identity::from_options( $options )->hash();
	}

	/**
	 * Every fingerprint that still describes the bucket the options point at.
	 *
	 * The current identity first, then the legacy form for each profile row the
	 * site knows about: hashes written before the row id was dropped bind one
	 * specific row, so that row has to stay acceptable without rewriting the
	 * post meta of every attachment on the site.
	 *
	 * @param Options $options    Options snapshot.
	 * @param string  $profile_id Extra row id to accept (queue row snapshot).
	 * @return array<int,string>
	 */
	public static function target_hashes_for( Options $options, string $profile_id = '' ): array {
		$identity = Bucket_Identity::from_options( $options );

		$ids = array_map( 'strval', array_keys( $options->profiles() ) );
		if ( '' !== $profile_id ) {
			$ids[] = $profile_id;
		}
		$active = $options->active_profile_id();
		if ( '' !== $active ) {
			$ids[] = $active;
		}

		$hashes = array( $identity->hash() );
		foreach ( array_unique( $ids ) as $id ) {
			if ( '' === $id ) {
				continue;
			}
			$hashes[] = $identity->hash( $id );
		}

		return array_values( array_unique( $hashes ) );
	}

	/**
	 * Whether a stored fingerprint still describes the options' bucket.
	 *
	 * @param string  $stored     Fingerprint from post meta or a queue row.
	 * @param Options $options    Options snapshot.
	 * @param string  $profile_id Extra row id to accept.
	 */
	public static function target_matches( string $stored, Options $options, string $profile_id = '' ): bool {
		if ( '' === $stored ) {
			return false;
		}

		return in_array( $stored, self::target_hashes_for( $options, $profile_id ), true );
	}

	/**
	 * Version map for a manifest.
	 *
	 * @param array<string,array{version:string}> $manifest Manifest.
	 * @return array<string,string>
	 */
	private function versions( array $manifest ): array {
		$versions = array();
		foreach ( $manifest as $key => $file ) {
			$versions[ (string) $key ] = (string) $file['version'];
		}
		return $versions;
	}

	/**
	 * Basename-only metadata filename.
	 *
	 * @param mixed $value Metadata value.
	 */
	private static function filename( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return wp_basename( $value );
	}
}
