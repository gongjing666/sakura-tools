<?php
/**
 * User upload pipeline: policy → quota reservation → attachment → manifest.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Only files that travel through this method become managed rows; WordPress
 * uploads made elsewhere are never adopted.
 */
final class Uploader {

	/**
	 * True while a REST-pipeline upload runs through `handle()`.
	 *
	 * The media-library prefilter shares the same settings, so it skips files
	 * that already passed the stricter checks above.
	 *
	 * @var bool
	 */
	private static bool $pipeline_uploads = false;

	/**
	 * Is the current `wp_handle_upload()` call driven by the REST pipeline?
	 *
	 * @return bool
	 */
	public static function in_pipeline(): bool {
		return self::$pipeline_uploads;
	}

	/**
	 * Files repository.
	 *
	 * @var Files
	 */
	private Files $files;

	/**
	 * Folders repository.
	 *
	 * @var Folders
	 */
	private Folders $folders;

	/**
	 * Quota ledger.
	 *
	 * @var Quota
	 */
	private Quota $quota;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Audit log.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Wire dependencies.
	 *
	 * @param Files        $files   Files.
	 * @param Folders      $folders Folders.
	 * @param Quota        $quota   Quota.
	 * @param Rate_Limiter $limiter Limiter.
	 * @param Events       $events  Events.
	 */
	public function __construct( Files $files, Folders $folders, Quota $quota, Rate_Limiter $limiter, Events $events ) {
		$this->files   = $files;
		$this->folders = $folders;
		$this->quota   = $quota;
		$this->limiter = $limiter;
		$this->events  = $events;
	}

	/**
	 * Validate one uploaded file and store it as a managed attachment.
	 *
	 * @param int                  $owner_id  Owner.
	 * @param int                  $folder_id Target folder.
	 * @param array<string, mixed> $file      $_FILES entry.
	 * @param string               $key       Idempotency key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function handle( int $owner_id, int $folder_id, array $file, string $key ) {
		$settings = Settings::get();

		if ( Settings::quota_for( $owner_id ) <= 0 ) {
			return new WP_Error( 'sufm_quota_blocked', __( '当前账号未分配文件空间，暂时不能上传。', 'sakura-imgopt' ), array( 'status' => 403 ) );
		}

		$available = (int) $this->quota->snapshot( $owner_id )['available'];
		if ( $available <= 0 ) {
			return new WP_Error( 'sufm_quota_exceeded', __( '已使用容量超过上限，请联系网站管理员扩容或删除多余文件！', 'sakura-imgopt' ), array( 'status' => 403 ) );
		}

		if ( ! $this->folders->get( $owner_id, $folder_id ) && $folder_id > 0 ) {
			return new WP_Error( 'sufm_folder_missing', __( '目标文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( $folder_id > 0 ) {
			foreach ( $this->folders->breadcrumb( $owner_id, $folder_id ) as $ancestor ) {
				if ( (int) $ancestor->pending_request_id > 0 ) {
					return new WP_Error( 'sufm_folder_locked', __( '该文件夹正在删除申请中，暂不能上传。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}
			}
		}

		if ( $this->files->count( $owner_id ) >= (int) $settings['max_user_files'] ) {
			return new WP_Error( 'sufm_file_count', __( '文件数量已达到上限，请先删除部分文件。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$day = function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );
		if ( $this->limiter->exceeded( $owner_id, 'daily_files', $day, (int) $settings['daily_upload_files'], 0, 1 ) ) {
			return new WP_Error( 'sufm_daily_files', __( '今日上传文件数量已达上限，请明天再试。', 'sakura-imgopt' ), array( 'status' => 429 ) );
		}

		$minute = function_exists( 'wp_date' ) ? wp_date( 'YmdHi' ) : gmdate( 'YmdHi' );
		if ( $this->limiter->exceeded( $owner_id, 'uploads_minute', $minute, (int) $settings['upload_requests_per_minute'], 0, 1 ) ) {
			return new WP_Error( 'sufm_rate', __( '上传过于频繁，请稍后再试。', 'sakura-imgopt' ), array( 'status' => 429 ) );
		}

		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$name = (string) ( $file['name'] ?? '' );
		$size = (int) ( $file['size'] ?? 0 );

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'sufm_upload_missing', __( '没有收到上传文件。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( $size <= 0 || $size > (int) $settings['max_file_bytes'] ) {
			return new WP_Error(
				'sufm_file_size',
				sprintf(
					/* translators: %s: human readable size */
					__( '单个文件不能超过 %s。', 'sakura-imgopt' ),
					size_format( (int) $settings['max_file_bytes'] )
				),
				array( 'status' => 413 )
			);
		}

		if ( $this->limiter->exceeded( $owner_id, 'daily_bytes', $day, 0, (int) $settings['daily_upload_bytes'], 0, $size ) ) {
			return new WP_Error( 'sufm_daily_bytes', __( '今日上传总量已达上限。', 'sakura-imgopt' ), array( 'status' => 429 ) );
		}

		$check = wp_check_filetype_and_ext( $tmp, $name );

		if ( empty( $check['type'] ) || ! Settings::is_allowed_type( (string) $check['type'] ) ) {
			return new WP_Error(
				'sufm_type',
				__( '不支持该文件类型。允许：JPEG/PNG/WebP/GIF/PDF/TXT/ZIP/MP4/MP3。', 'sakura-imgopt' ),
				array( 'status' => 415 )
			);
		}

		$reservation = $this->quota->reserve( $owner_id, 'upload:' . $key, $size );

		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload_args = array(
			'name'     => $name,
			'type'     => (string) $check['type'],
			'tmp_name' => $tmp,
			'error'    => (int) ( $file['error'] ?? 0 ),
			'size'     => $size,
		);

		$display = mb_substr( sanitize_file_name( basename( $name ) ), 0, 120 );
		$title   = '' !== $display ? preg_replace( '/\.[^.]*$/', '', $display ) : __( '文件', 'sakura-imgopt' );

		// The REST pipeline already validated size, type, quota and throttles.
		// Flag it so the shared media-library prefilter does not re-check (and
		// never double-report) the same file.
		//
		// The flag must stay on until wp_insert_attachment() has run: that call
		// fires `add_attachment`, where Media_Hooks adopts unmanaged attachments.
		// Clearing it earlier lets the adoption hook insert its own sufm_files
		// row (source=media); Files::create() below then collides with the
		// attachment_id unique key and the upload dies with 409 sufm_file_conflict.
		self::$pipeline_uploads = true;

		$attachment_id = 0;
		$upload        = null;

		try {
			$upload = wp_handle_upload(
				$upload_args,
				array(
					'test_form' => false,
					'mimes'     => $this->mime_map( $settings ),
				)
			);

			if ( is_array( $upload ) && ! isset( $upload['error'] ) ) {
				$attachment_id = wp_insert_attachment(
					array(
						'post_mime_type' => (string) ( $upload['type'] ?? $check['type'] ),
						'post_title'     => (string) $title,
						'post_content'   => '',
						'post_status'    => 'inherit',
						'post_author'    => $owner_id,
						'guid'           => (string) ( $upload['url'] ?? '' ),
					),
					(string) $upload['file'],
					0,
					true
				);
			}
		} finally {
			self::$pipeline_uploads = false;
		}

		if ( ! is_array( $upload ) || isset( $upload['error'] ) ) {
			$this->quota->release( (int) $reservation['id'] );

			$message = (string) ( is_array( $upload ) ? ( $upload['error'] ?? __( '上传失败。', 'sakura-imgopt' ) ) : __( '上传失败。', 'sakura-imgopt' ) );

			// The theme's own frequency guard fires before ours when its limits are
			// lower than the plugin's; surface it as a retryable limit, not a 500.
			//
			// These are the theme's raw strings, deliberately NOT __() calls: the
			// theme prints its own copy, which does not follow this plugin's text
			// domain. Translating the needle made the match fail as soon as the
			// site language was not Chinese, turning a retryable 429 into a 500.
			$needles = array(
				'过于频繁',
				'今日上限',
				'上传上限',
				'达到上限',
				'too frequent',
				'too many',
				'upload limit',
				'daily limit',
				'rate limit',
			);

			$theme_limit = false;

			foreach ( $needles as $needle ) {
				if ( false !== mb_stripos( $message, $needle ) ) {
					$theme_limit = true;
					break;
				}
			}

			if ( $theme_limit ) {
				$daily = false !== mb_stripos( $message, '今日上限' ) || false !== mb_stripos( $message, 'daily limit' );

				return new WP_Error(
					'sufm_theme_limit',
					$daily
						? __( '主题的每日上传上限已触发，请明日再试或在插件设置里提高主题限制。', 'sakura-imgopt' )
						: __( '主题上传频率限制已触发，请稍后再试。若经常出现，请在插件设置里对齐主题限制。', 'sakura-imgopt' ),
					array(
						'status'      => 429,
						'retry_after' => $daily ? 3600 : 600,
					)
				);
			}

			return new WP_Error( 'sufm_upload_failed', $message, array( 'status' => 500 ) );
		}

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( (string) $upload['file'] );
			$this->quota->release( (int) $reservation['id'] );

			return new WP_Error( 'sufm_attachment_failed', __( '写入媒体库失败，请重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$attachment_id = (int) $attachment_id;

		$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $upload['file'] );
		$metadata = is_array( $metadata ) ? $metadata : array();

		// wp_update_attachment_metadata() may hand the local files to remote
		// storage and delete them. Sizes are snapshotted first so billing still
		// reflects what was stored instead of zero (audit A02).
		$known = $this->snapshot_files( (string) $upload['file'], $metadata );

		if ( $metadata ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$manifest = $this->manifest( $attachment_id, (string) $upload['file'], $metadata, $known );
		$charged  = 0;
		foreach ( $manifest as $artifact ) {
			$charged += (int) $artifact['bytes'];
		}

		$row = $this->files->create(
			array(
				'attachment_id'  => $attachment_id,
				'owner_id'       => $owner_id,
				'folder_id'      => $folder_id,
				'display_name'   => $display,
				'source'         => Files::SOURCE,
				'storage_state'  => 'local',
				'charged_bytes'  => $charged,
				'manifest_version' => 1,
			)
		);

		if ( is_wp_error( $row ) ) {
			$this->discard_upload( $attachment_id, (string) $upload['file'], null );
			$this->quota->release( (int) $reservation['id'] );

			return $row;
		}

		$this->store_artifacts( (int) $row->id, $manifest );

		$buckets = array(
			array( 'daily_files', $day, (int) $settings['daily_upload_files'], 0, 1, 0 ),
			array( 'daily_bytes', $day, 0, (int) $settings['daily_upload_bytes'], 0, $size ),
			array( 'uploads_minute', $minute, (int) $settings['upload_requests_per_minute'], 0, 1, 0 ),
		);

		$consumed = array();

		foreach ( $buckets as $bucket ) {
			$ok = $this->limiter->consume( $owner_id, (string) $bucket[0], (string) $bucket[1], (int) $bucket[2], (int) $bucket[3], (int) $bucket[4], (int) $bucket[5] );

			if ( $ok ) {
				$consumed[] = $bucket;
				continue;
			}

			foreach ( $consumed as $done ) {
				$this->limiter->refund( $owner_id, (string) $done[0], (string) $done[1], (int) $done[4], (int) $done[5] );
			}

			$this->discard_upload( $attachment_id, (string) $upload['file'], $row );
			$this->quota->release( (int) $reservation['id'], 'rejected' );

			return new WP_Error( 'sufm_rate_limit', __( '上传过于频繁或超出每日额度，请稍后再试。', 'sakura-imgopt' ), array( 'status' => 429 ) );
		}

		if ( ! $this->quota->commit( (int) $reservation['id'], $charged ) ) {
			// Strict staging: the stored bytes never became chargeable, so
			// remove the attachment, artifacts and the reservation together.
			$this->discard_upload( $attachment_id, (string) $upload['file'], $row );
			$this->quota->release( (int) $reservation['id'], 'rejected' );

			foreach ( $consumed as $done ) {
				$this->limiter->refund( $owner_id, (string) $done[0], (string) $done[1], (int) $done[4], (int) $done[5] );
			}

			return new WP_Error( 'sufm_quota_commit', __( '容量不足，文件未保存。请先删除部分文件释放空间。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}


		$this->events->log(
			array(
				'actor_id'   => $owner_id,
				'owner_id'   => $owner_id,
				'subject_id' => (int) $row->id,
				'action'     => 'file_uploaded',
				'result'     => 'ok',
				'safe_detail' => sprintf( 'file=%d bytes=%d', (int) $row->id, $charged ),
			)
		);

		return array(
			'id'             => (int) $row->id,
			'attachment_id'  => $attachment_id,
			'name'           => $display,
			'bytes'          => $charged,
			'mime'           => (string) ( $upload['type'] ?? '' ),
			'url'            => (string) wp_get_attachment_url( $attachment_id ),
			'folder_id'      => $folder_id,
		);
	}

	/**
	 * 清理一次失败上传留下的附件、账本行与物理文件。
	 *
	 * 不能直接调用 wp_delete_attachment()：它会经过 Media_Hooks 的删除守卫，
	 * 守卫对「还没有账本行」的附件会先 adopt_unmanaged() 现场采纳——写入一条
	 * source=media 的账本行并占用配额——随后又因为普通用户没有
	 * sufm_delete_files 而返回 false。结果是一次失败的上传留下孤儿附件、
	 * 孤儿账本行，并且配额已被扣掉。
	 *
	 * 这里用插件自己的 `sufm_allow_attachment_delete` 过滤器在本次调用内放行，
	 * 删的是本请求刚刚创建、尚未对外可见的附件。
	 *
	 * @param int         $attachment_id Attachment id.
	 * @param string      $path          Uploaded absolute path.
	 * @param object|null $row           Ledger row created by this request, if any.
	 * @return void
	 */
	private function discard_upload( int $attachment_id, string $path, ?object $row ): void {
		global $wpdb;

		if ( $row ) {
			$wpdb->delete( Schema::table( 'artifacts' ), array( 'file_id' => (int) $row->id ), array( '%d' ) );
		}

		$allow = static function () {
			return true;
		};

		add_filter( 'sufm_allow_attachment_delete', $allow, 99 );

		try {
			wp_delete_attachment( $attachment_id, true );
		} finally {
			remove_filter( 'sufm_allow_attachment_delete', $allow, 99 );
		}

		// 核心在文件被占用等情况下可能拒绝删除；至少清掉物理文件与附件行，
		// 避免失败上传留下孤儿。
		if ( get_post( $attachment_id ) ) {
			wp_delete_file( $path );
			wp_delete_post( $attachment_id, true );
		}

		if ( $row ) {
			$wpdb->delete( Schema::table( 'files' ), array( 'id' => (int) $row->id ), array( '%d' ) );
		}
	}

	/**
	 * Absolute artifact list for a stored attachment.
	 *
	 * @param int                  $attachment_id Attachment.
	 * @param string               $original      Original absolute path.
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param array<string,int>    $known         Path => bytes snapshot taken
	 *                                            before remote storage could
	 *                                            delete the local files.
	 * @return array<int, array{key:string,kind:string,bytes:int,path:string}>
	 */
	private function manifest( int $attachment_id, string $original, array $metadata, array $known = array() ): array {
		$out = array();

		$bytes = $this->artifact_bytes( $original, $known );
		if ( null !== $bytes ) {
			$out[] = array(
				'key'   => 'att:' . $attachment_id . ':0:original',
				'kind'  => 'original',
				'bytes' => $bytes,
				'path'  => $original,
			);
		}

		$base       = dirname( $original );
		$generation = 1;

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size => $info ) {
			++$generation;
			$path = $base . '/' . (string) ( $info['file'] ?? '' );

			$bytes = $this->artifact_bytes( $path, $known );
			if ( null === $bytes ) {
				continue;
			}

			$out[] = array(
				'key'   => 'att:' . $attachment_id . ':' . $generation . ':' . sanitize_key( (string) $size ),
				'kind'  => 'size',
				'bytes' => $bytes,
				'path'  => $path,
			);
		}

		return $out;
	}

	/**
	 * Bytes for one artifact, falling back to a pre-cleanup snapshot.
	 *
	 * @param string            $path  Absolute path.
	 * @param array<string,int> $known Snapshot.
	 */
	private function artifact_bytes( string $path, array $known ): ?int {
		if ( '' === $path ) {
			return null;
		}

		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}

		return array_key_exists( $path, $known ) ? (int) $known[ $path ] : null;
	}

	/**
	 * Snapshot file sizes before metadata persistence can remove them.
	 *
	 * @param string               $original Original absolute path.
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array<string,int>
	 */
	private function snapshot_files( string $original, array $metadata ): array {
		$out = array();

		if ( is_file( $original ) ) {
			$out[ $original ] = (int) filesize( $original );
		}

		$base = dirname( $original );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $info ) {
			$file = (string) ( is_array( $info ) ? ( $info['file'] ?? '' ) : '' );

			if ( '' === $file ) {
				continue;
			}

			$path = $base . '/' . $file;

			if ( is_file( $path ) ) {
				$out[ $path ] = (int) filesize( $path );
			}
		}

		return $out;
	}

	/**
	 * Persist artifact rows.
	 *
	 * @param int                                  $file_id  Managed file id.
	 * @param array<int, array<string, mixed>>     $manifest Artifacts.
	 * @return void
	 */
	private function store_artifacts( int $file_id, array $manifest ): void {
		global $wpdb;

		$table = Schema::table( 'artifacts' );
		$now   = Time::now();
		$gen   = 0;

		foreach ( $manifest as $artifact ) {
			++$gen;

			$wpdb->insert(
				$table,
				array(
					'file_id'       => $file_id,
					'generation'    => $gen,
					'artifact_key'  => (string) $artifact['key'],
					'kind'          => (string) $artifact['kind'],
					'logical_bytes' => (int) $artifact['bytes'],
					'local_path'    => (string) $artifact['path'],
					'remote_context' => '',
					'checksum'      => '',
					'cleanup_state' => 'present',
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Extension allow list for wp_handle_upload.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	private function mime_map( array $settings ): array {
		$map = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'gif'      => 'image/gif',
			'pdf'      => 'application/pdf',
			'txt'      => 'text/plain',
			'zip'      => 'application/zip',
			'mp4'      => 'video/mp4',
			'mp3'      => 'audio/mpeg',
		);

		$allowed = (array) $settings['allowed_types'];
		$out     = array();

		foreach ( $map as $extensions => $mime ) {
			if ( in_array( $mime, $allowed, true ) ) {
				$out[ $extensions ] = $mime;
			}
		}

		return $out;
	}
}
