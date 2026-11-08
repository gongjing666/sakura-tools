<?php
/**
 * Per-attachment S3 sync state and failed-job persistence.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Stores sync state, uploaded object keys and retry jobs as attachment meta.
 *
 * The queue intentionally lives in post meta rather than a custom table: media
 * libraries are already indexed by attachment ID, and this keeps uninstall,
 * multisite and backup semantics aligned with the rest of the plugin.
 */
final class Sync_Status {

	public const STATUS_META   = '_sakura_imgopt_s3_status';
	public const ERROR_META    = '_sakura_imgopt_s3_error';
	public const OBJECTS_META  = '_sakura_imgopt_s3_objects';
	public const SYNCED_META   = '_sakura_imgopt_s3_synced_at';
	public const JOBS_META     = '_sakura_imgopt_s3_jobs';

	/**
	 * Target fingerprint that produced the current synced object set.
	 */
	public const TARGET_META   = '_sakura_imgopt_s3_target';

	/**
	 * Non-runnable failure records (no next/attempts: never executed by cron).
	 */
	public const FAILURES_META = '_sakura_imgopt_s3_failures';

	/**
	 * Local size:mtime per uploaded key, used to detect re-optimized files.
	 */
	public const VERSIONS_META = '_sakura_imgopt_s3_object_versions';

	public const PENDING = 'pending';
	public const SYNCED  = 'synced';
	public const FAILED  = 'failed';
	public const DEAD    = 'dead';

	/**
	 * Mark an attachment as waiting for sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mark_pending( int $attachment_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		update_post_meta( $attachment_id, self::STATUS_META, self::PENDING );
	}

	/**
	 * Mark an attachment as fully synced for one storage target.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $target_hash   Provider/endpoint/bucket/prefix fingerprint.
	 */
	public function mark_synced( int $attachment_id, string $target_hash = '' ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		update_post_meta( $attachment_id, self::STATUS_META, self::SYNCED );
		update_post_meta( $attachment_id, self::SYNCED_META, time() );
		if ( '' !== $target_hash ) {
			update_post_meta( $attachment_id, self::TARGET_META, $target_hash );
		} else {
			delete_post_meta( $attachment_id, self::TARGET_META );
		}
		delete_post_meta( $attachment_id, self::ERROR_META );
		delete_post_meta( $attachment_id, self::FAILURES_META );
	}

	/**
	 * Target fingerprint stored with the last successful object set.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function target_hash( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::TARGET_META, true );
	}

	/**
	 * Record a failure and keep it visible to the admin.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param string                    $error         Error message.
	 * @param array<int,array<string,mixed>> $failures Non-runnable per-file records.
	 */
	public function mark_failed( int $attachment_id, string $error, array $failures = array() ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		update_post_meta( $attachment_id, self::STATUS_META, self::FAILED );
		update_post_meta( $attachment_id, self::ERROR_META, Text::clip( wp_strip_all_tags( $error ), 500 ) );
		if ( $failures ) {
			$this->record_failures( $attachment_id, $failures );
		}
	}

	/**
	 * Store non-runnable per-file failure records.
	 *
	 * These records are evidence for the admin and for the sync center; they
	 * carry no schedule and no attempt counter, so no cron ever executes them.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<int,array<string,mixed>> $entries Per-file records.
	 */
	public function record_failures( int $attachment_id, array $entries ): void {
		if ( $attachment_id <= 0 || ! $entries ) {
			return;
		}
		$records = $this->failures( $attachment_id );
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) ) {
				continue;
			}
			$hash             = md5( (string) $entry['key'] );
			$entry['time']    = (int) ( $entry['time'] ?? time() );
			$entry['held']    = true;
			$records[ $hash ] = $entry;
		}
		update_post_meta( $attachment_id, self::FAILURES_META, $records );
	}

	/**
	 * Per-file failure records.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,array<string,mixed>>
	 */
	public function failures( int $attachment_id ): array {
		$records = get_post_meta( $attachment_id, self::FAILURES_META, true );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Stored local versions keyed by object key (`size:mtime`).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,string>
	 */
	public function versions( int $attachment_id ): array {
		$versions = get_post_meta( $attachment_id, self::VERSIONS_META, true );
		if ( ! is_array( $versions ) ) {
			return array();
		}
		$out = array();
		foreach ( $versions as $key => $version ) {
			if ( is_string( $key ) && '' !== $key ) {
				$out[ $key ] = (string) $version;
			}
		}
		return $out;
	}

	/**
	 * Persist the local versions that were uploaded and verified.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string,string> $versions    Key => size:mtime.
	 */
	public function set_versions( int $attachment_id, array $versions ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		if ( ! $versions ) {
			delete_post_meta( $attachment_id, self::VERSIONS_META );
			return;
		}
		update_post_meta( $attachment_id, self::VERSIONS_META, $versions );
	}

	/**
	 * Drop one key from the recorded object list (after a manual delete).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           Object key.
	 */
	public function remove_object( int $attachment_id, string $key ): void {
		$keys = array_values( array_diff( $this->objects( $attachment_id ), array( $key ) ) );
		$this->set_objects( $attachment_id, $keys );
	}

	/**
	 * Record that a managed remote object was removed while the attachment remains.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           Object key.
	 * @return void
	 */
	public function mark_object_removed( int $attachment_id, string $key ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$this->remove_object( $attachment_id, $key );
		if ( get_post( $attachment_id ) ) {
			$this->mark_failed( $attachment_id, __( '云端对象已删除，附件需要重新同步。', 'sakura-imgopt' ) );
		}
	}

	/**
	 * Current status.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function status( int $attachment_id ): string {
		$status = (string) get_post_meta( $attachment_id, self::STATUS_META, true );
		return in_array( $status, array( self::PENDING, self::SYNCED, self::FAILED, self::DEAD ), true ) ? $status : '';
	}

	/**
	 * Last error.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function error( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::ERROR_META, true );
	}

	/**
	 * Record one successfully uploaded object key.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           Object key.
	 */
	public function record_object( int $attachment_id, string $key ): void {
		if ( $attachment_id <= 0 || '' === $key ) {
			return;
		}
		$keys = $this->objects( $attachment_id );
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_post_meta( $attachment_id, self::OBJECTS_META, array_values( array_unique( $keys ) ) );
		}
	}

	/**
	 * Object keys uploaded by this plugin.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public function objects( int $attachment_id ): array {
		$keys = get_post_meta( $attachment_id, self::OBJECTS_META, true );
		return is_array( $keys ) ? array_values( array_filter( array_map( 'strval', $keys ) ) ) : array();
	}

	/**
	 * Replace the recorded object keys for one attachment.
	 *
	 * `record_object()` only appends, which is right for first uploads but wrong
	 * after a restore: the converted formats and old sub-sizes are no longer
	 * referenced, and keeping them would grow the meta forever and make a later
	 * attachment delete try to remove keys that no longer exist.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<int,string>  $keys          Authoritative key list.
	 */
	public function set_objects( int $attachment_id, array $keys ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		$normalized = Restore_Plan::normalize( $keys );
		if ( ! $normalized ) {
			delete_post_meta( $attachment_id, self::OBJECTS_META );
			return;
		}

		update_post_meta( $attachment_id, self::OBJECTS_META, $normalized );
	}

	/**
	 * Add or refresh a persistent failed upload job.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           Object key.
	 * @param string $path          Absolute local path.
	 * @param string $error         Error message.
	 */
	public function enqueue( int $attachment_id, string $key, string $path, string $error ): void {
		if ( $attachment_id <= 0 || '' === $key ) {
			return;
		}
		$jobs = $this->jobs( $attachment_id );
		$hash = md5( $key );
		$path = self::relative_path( $path );

		$jobs[ $hash ] = array(
			'key'      => $key,
			'path'     => $path,
			'attempts' => 0,
			'next'     => time(),
			'error'    => Text::clip( wp_strip_all_tags( $error ), 500 ),
			'time'     => time(),
		);

		update_post_meta( $attachment_id, self::JOBS_META, $jobs );
		$this->mark_failed( $attachment_id, $error );
	}

	/**
	 * Failed jobs for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,array<string,mixed>>
	 */
	public function jobs( int $attachment_id ): array {
		$jobs = get_post_meta( $attachment_id, self::JOBS_META, true );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Record a failed retry attempt.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hash          Job hash.
	 * @param string $error         Error message.
	 */
	public function fail_job( int $attachment_id, string $hash, string $error ): void {
		$jobs = $this->jobs( $attachment_id );
		if ( ! isset( $jobs[ $hash ] ) ) {
			return;
		}

		$attempts              = (int) ( $jobs[ $hash ]['attempts'] ?? 0 ) + 1;
		$jobs[ $hash ]['attempts'] = $attempts;
		$jobs[ $hash ]['next']     = time() + min( 3600, 60 * ( 2 ** min( 5, $attempts ) ) );
		$jobs[ $hash ]['error']    = Text::clip( wp_strip_all_tags( $error ), 500 );

		update_post_meta( $attachment_id, self::JOBS_META, $jobs );
		if ( $attempts >= 8 ) {
			update_post_meta( $attachment_id, self::STATUS_META, self::DEAD );
			update_post_meta( $attachment_id, self::ERROR_META, __( '远程同步连续失败，已停止自动重试，请手动重试或检查配置。', 'sakura-imgopt' ) );
		} else {
			$this->mark_failed( $attachment_id, $error );
		}
	}

	/**
	 * Delete all sync state for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function clear( int $attachment_id ): void {
		foreach ( array( self::STATUS_META, self::ERROR_META, self::OBJECTS_META, self::SYNCED_META, self::JOBS_META, self::FAILURES_META, self::VERSIONS_META, self::TARGET_META ) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	/**
	 * Attachments with pending retry jobs, newest first.
	 *
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	public function failed_attachments( int $limit = 20 ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => max( 1, min( 100, $limit ) ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::STATUS_META,
						'value'   => array( self::FAILED, self::DEAD ),
						'compare' => 'IN',
					),
				),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Count attachments that need an admin's attention.
	 */
	public function failed_count(): int {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::STATUS_META,
						'value'   => array( self::FAILED, self::DEAD ),
						'compare' => 'IN',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Human label for a status.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function label( int $attachment_id ): string {
		$status = $this->status( $attachment_id );
		switch ( $status ) {
			case self::SYNCED:
				return __( '已同步', 'sakura-imgopt' );
			case self::PENDING:
				return __( '等待同步', 'sakura-imgopt' );
			case self::FAILED:
				return __( '同步失败', 'sakura-imgopt' );
			case self::DEAD:
				return __( '已停止重试', 'sakura-imgopt' );
			default:
				return __( '未同步', 'sakura-imgopt' );
		}
	}

	/**
	 * Convert an absolute path to a WordPress-root-relative path.
	 *
	 * @param string $path Absolute path.
	 */
	public static function relative_path( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		return 0 === strpos( $path, $root ) ? ltrim( substr( $path, strlen( $root ) ), '/' ) : '';
	}
}
