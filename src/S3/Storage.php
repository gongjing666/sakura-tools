<?php
/**
 * S3 storage runtime module.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Feature;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Sync\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Registers S3 hooks and coordinates queueing with instance dependencies.
 */
final class Storage implements Module {

	/**
	 * Option recording the one-time retirement of the upload queue.
	 */
	public const MIGRATION_OPTION = 'sakura_imgopt_sync_migration';

	/**
	 * Option holding attachments whose restore has not been reconciled yet.
	 */
	public const RESTORE_PENDING_OPTION = 'sakura_imgopt_restore_pending';

	/**
	 * Seconds a stale object is kept after a restore before it is deleted.
	 *
	 * The replacement upload is queued first; the delay makes sure the new
	 * bytes are in the bucket (and verified) before the old ones disappear.
	 */
	private const RESTORE_DELETE_DELAY = 300;

	/**
	 * Attachments currently being restored in this request.
	 *
	 * Their upload jobs must keep the local file so the restore can be
	 * verified and repeated, even when `no_local_saving` is enabled.
	 *
	 * @var array<int,bool>
	 */
	private array $restoring = array();

	/**
	 * Resolved options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Upload adapter.
	 *
	 * @var Storage_Adapter
	 */
	private Storage_Adapter $adapter;

	/**
	 * Per-attachment sync state.
	 *
	 * @var Sync_Status
	 */
	private Sync_Status $status;

	/**
	 * In-request uploader.
	 *
	 * @var Synchronous_Uploader
	 */
	private Synchronous_Uploader $uploader;

	/**
	 * Persistent remote-delete queue.
	 *
	 * @var Delete_Jobs
	 */
	private Delete_Jobs $deletes;

	/**
	 * Per-request answers from the sync index about the active bucket.
	 *
	 * A confirmed attachment has its fingerprint rewritten, so only a miss can
	 * repeat: caching it keeps one attachment at one lookup per request.
	 *
	 * @var array<int,bool>
	 */
	private array $index_confirmed = array();

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options Optional options for tests.
	 * @param Storage_Adapter|null $adapter Optional adapter for tests.
	 * @param Sync_Status|null     $status  Optional state store for tests.
	 */
	public function __construct( ?Options $options = null, ?Storage_Adapter $adapter = null, ?Sync_Status $status = null ) {
		$this->options  = $options ?? new Options();
		$this->adapter  = $adapter ?? new Storage_Adapter( $this->options );
		$this->status   = $status ?? new Sync_Status();
		$this->uploader = new Synchronous_Uploader( $this->options, $this->adapter, $this->status );
		$this->deletes  = new Delete_Jobs();
	}

	/**
	 * Register storage hooks with the central loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		if ( ! $this->options->is_enabled() ) {
			// A disabled S3 configuration must not keep rewriting the media URL.
			Upload_URL::restore( (string) $this->options->get( 'upload_url_path', '' ) );
			return;
		}

		if ( ! $this->options->is_configured() ) {
			// Never rewrite upload URLs, queue media, or half-process an image for
			// an unusable bucket. Reject image uploads at the prefilter stage so
			// the media library cannot silently diverge from the configured store.
			Upload_URL::restore( (string) $this->options->get( 'upload_url_path', '' ) );
			$hooks->add_action( 'admin_notices', array( $this, 'render_incomplete_config_notice' ) );
			$hooks->add_filter( 'wp_handle_upload_prefilter', array( $this, 'block_incomplete_upload' ), PHP_INT_MAX, 1 );
			$hooks->add_filter( 'wp_handle_sideload_prefilter', array( $this, 'block_incomplete_upload' ), PHP_INT_MAX, 1 );
			return;
		}

		// Re-apply the saved CDN URL at runtime so activation/deactivation can stay
		// limited to defaults and cron cleanup.
		Upload_URL::apply( (string) $this->options->get( 'upload_url_path', '' ) );

		// The CDN prefix is applied for the whole request, so an attachment that
		// is still queued (or failed) would be served from a store that does not
		// have it yet. Keep those on the local URL until the worker marks them
		// synced; the switch then happens without any settings change.
		$hooks->add_filter( 'wp_get_attachment_url', array( $this, 'revert_unsynced_url' ), 25, 2 );

		// 所有附件都在产生它的请求内完成上传与校验。add_attachment 只登记
		// 状态；真正的文件清单由最后的 metadata 钩子推导（SVG 等无元数据
		// 的附件回退到实际文件路径），不再有 shutdown 兜底或后台首传。
		$hooks->add_action( 'add_attachment', array( $this, 'queue_attachment' ), 20, 1 );
		// 最后执行：先让图片优化与禁用尺寸模块完成 metadata，再上传最终保留的文件。
		$hooks->add_filter( 'wp_update_attachment_metadata', array( $this, 'upload_attachment_metadata' ), PHP_INT_MAX, 2 );
		// 子尺寸全部生成后才会触发；用于区分「中间态 metadata」与「最终 metadata」，
		// 避免在子尺寸生成前删掉本地源文件（audit A01）。
		$hooks->add_filter( 'wp_generate_attachment_metadata', array( $this->uploader, 'note_generation_done' ), PHP_INT_MAX, 2 );
		$hooks->add_action( 'delete_attachment', array( $this, 'delete_remote_attachment' ), 20, 1 );
		$hooks->add_action( 'admin_notices', array( $this, 'render_failed_notice' ) );
		$hooks->add_action( 'admin_init', array( $this, 'maybe_migrate' ) );

		// A restore rewrites the local file set. Reconcile the bucket afterwards
		// so converted formats and old sub-sizes do not outlive their metadata.
		$hooks->add_action( 'sakura_imgopt_restore_started', array( $this, 'on_restore_started' ), 10, 1 );
		$hooks->add_action( 'sakura_imgopt_restored', array( $this, 'reconcile_attachment' ), 10, 1 );
		$hooks->add_action( 'admin_init', array( $this, 'resume_pending_restores' ) );

		if ( '.' === $this->options->upload_path() ) {
			$hooks->add_filter( 'wp_get_attachment_url', array( $this, 'normalize_root_url' ), 30, 2 );
		}
	}

	/**
	 * Record a newly created attachment.
	 *
	 * This hook intentionally does not upload: the file list is only complete
	 * when metadata has been generated, and uploading twice per request would
	 * duplicate attempts. The metadata hook finalizes the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function queue_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || $this->skip_request() ) {
			return;
		}
		$this->status->mark_pending( $attachment_id );
	}

	/**
	 * Finalize an attachment in the current request.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function upload_attachment_metadata( $metadata, $attachment_id = 0 ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 && ! $this->skip_request() ) {
			// A restore regenerates metadata in the middle of the operation. Keep
			// the local files (the restore needs them as its verification source)
			// and keep the previous object list until reconcile_attachment() has
			// queued every stale key for deletion.
			$restoring = isset( $this->restoring[ $attachment_id ] );
			$this->uploader->finalize(
				$attachment_id,
				is_array( $metadata ) ? $metadata : null,
				false,
				$restoring,
				$restoring
			);
		}

		return $metadata;
	}

	/**
	 * Delete remote objects for a deleted attachment.
	 *
	 * Only keys recorded by this plugin are removed. Legacy attachments whose
	 * objects predate the status meta are left untouched; the local files are
	 * already removed by WordPress, and guessing keys could delete unrelated objects.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_remote_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$keys          = $this->status->objects( $attachment_id );

		if ( ! $keys ) {
			// No keys recorded: remove any retry jobs so a deleted attachment
			// cannot keep failing forever, but never guess remote keys.
			$this->status->clear( $attachment_id );
			return;
		}

		// Persist the keys before the one-shot delete. The worker owns the remote
		// mutation, so a request timeout cannot leave an untracked delete. The
		// target fingerprint freezes which bucket/endpoint this job belongs to
		// (audit A03).
		$profile_id  = (string) $this->options->active_profile_id();
		$target_hash = '' === $profile_id ? '' : Delete_Jobs::target_hash( (array) $this->options->profile_options( $profile_id ) );
		$this->deletes->enqueue( $attachment_id, $keys, 0, $profile_id, $target_hash );

		$this->status->clear( $attachment_id );
		Delete_Worker::kick();
	}

	/**
	 * Keys the attachment currently references.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,string>
	 */
	public function desired_keys( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array();
		}

		return Restore_Plan::normalize( $this->uploader->desired_keys( $attachment_id ) );
	}

	/**
	 * Remember that a restore is rewriting an attachment in this request.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_restore_started( $attachment_id ): void {
		$id = (int) $attachment_id;
		if ( $id <= 0 ) {
			return;
		}

		$this->restoring[ $id ] = true;

		// Persisted so a fatal error between the local restore and the bucket
		// reconciliation is retried on the next admin request.
		$pending        = $this->pending_restores();
		$pending[ $id ] = time();
		update_option( self::RESTORE_PENDING_OPTION, $pending, false );
	}

	/**
	 * Reconcile the bucket with the metadata a restore just regenerated.
	 *
	 * Uploads for the restored files were queued by the metadata hooks with
	 * `delete_local` disabled. This method then deletes only the keys the new
	 * metadata no longer references, and replaces the append-only recorded list
	 * so a later attachment delete does not try to remove dead keys.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function reconcile_attachment( $attachment_id ): void {
		$id = (int) $attachment_id;
		if ( $id <= 0 ) {
			return;
		}

		unset( $this->restoring[ $id ] );

		if ( ! $this->options->is_enabled() || ! $this->options->is_configured() ) {
			$this->clear_pending_restore( $id );
			return;
		}

		$desired  = $this->desired_keys( $id );
		$recorded = $this->status->objects( $id );
		$stale    = Restore_Plan::stale( $recorded, $desired );

		if ( ! $desired && ! $recorded ) {
			$this->clear_pending_restore( $id );
			return;
		}

		$synced = false;
		if ( $desired ) {
			// A restore keeps local files: they are the verification reference
			// and the only way to repeat the operation. The upload itself runs
			// before this method returns, so stale keys are only deleted after
			// the replacement set is confirmed in the bucket.
			$result = $this->uploader->finalize( $id, null, true, true );
			$synced = in_array( $result['status'], array( 'synced', 'skipped' ), true );
			if ( ! $synced ) {
				do_action(
					'sakura_imgopt_s3_log',
					sprintf( '[restore] attachment %d: upload not confirmed, stale keys kept: %s', $id, $result['message'] )
				);
			}
		}

		$queued = 0;
		if ( $synced && $stale ) {
			$profile_id  = (string) $this->options->active_profile_id();
			$target_hash = '' === $profile_id ? '' : Delete_Jobs::target_hash( (array) $this->options->profile_options( $profile_id ) );
			$queued      = $this->deletes->enqueue( $id, $stale, self::RESTORE_DELETE_DELAY, $profile_id, $target_hash );
			do_action( 'sakura_imgopt_s3_log', sprintf( '[restore] attachment %d: %d stale object(s) queued for deletion', $id, $queued ) );
		}

		// Replace the recorded object list only after the replacement upload is
		// confirmed and every stale key has a durable delete job. If recording
		// this fails, leave the pending marker so the next admin request retries
		// instead of silently dropping the only reference to the old objects.
		if ( $synced && $desired && ( ! $stale || $queued >= count( $stale ) ) ) {
			$this->status->set_objects( $id, $desired );
		} elseif ( $synced && $desired && $stale ) {
			do_action(
				'sakura_imgopt_s3_log',
				sprintf( '[restore] attachment %d: %d stale delete job(s) missing, recorded object set kept', $id, count( $stale ) - $queued )
			);
			return;
		}

		$this->clear_pending_restore( $id );
	}

	/**
	 * Finish reconciliations interrupted by a fatal error or request timeout.
	 */
	public function resume_pending_restores(): void {
		if ( ! $this->options->is_enabled() || ! $this->options->is_configured() ) {
			return;
		}

		$pending = $this->pending_restores();
		if ( ! $pending ) {
			return;
		}

		$now = time();
		foreach ( $pending as $id => $started ) {
			// A restore running in this request has not had time to finish yet.
			if ( $now - $started < 60 ) {
				continue;
			}

			$this->reconcile_attachment( $id );
		}
	}

	/**
	 * Attachments whose restore reconciliation is still pending.
	 *
	 * @return array<int,int>
	 */
	private function pending_restores(): array {
		$value = get_option( self::RESTORE_PENDING_OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$pending = array();
		foreach ( $value as $id => $started ) {
			if ( (int) $id > 0 ) {
				$pending[ (int) $id ] = (int) $started;
			}
		}

		return $pending;
	}

	/**
	 * Drop one pending restore marker.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function clear_pending_restore( int $attachment_id ): void {
		$pending = $this->pending_restores();
		if ( ! isset( $pending[ $attachment_id ] ) ) {
			return;
		}

		unset( $pending[ $attachment_id ] );
		if ( $pending ) {
			update_option( self::RESTORE_PENDING_OPTION, $pending, false );
			return;
		}

		delete_option( self::RESTORE_PENDING_OPTION );
	}

	/**
	 * Retry one attachment after an explicit admin action.
	 *
	 * There is no cron path for first uploads anymore: a failed attachment stays
	 * local and visible until an administrator asks for a retry. Local files are
	 * preserved even when `no_local_saving` is enabled.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function retry_attachment( int $attachment_id ): void {
		$this->deletes->retry_attachment( $attachment_id );
		if ( $attachment_id <= 0 || ! $this->options->is_enabled() || ! $this->options->is_configured() ) {
			return;
		}

		$index   = new Index_Repository();
		$entries = $this->status->failures( $attachment_id );
		if ( ! $entries ) {
			foreach ( $this->uploader->manifest( $attachment_id ) as $key => $file ) {
				$entries[] = array( 'key' => $key, 'version' => (string) $file['version'] );
			}
		}
		$target = $this->uploader->target_hash();
		$profile = (string) $this->options->active_profile_id();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) ) {
				continue;
			}
			$index->enqueue_job(
				'' !== (string) ( $entry['profile'] ?? '' ) ? (string) $entry['profile'] : $profile,
				(string) $entry['key'],
				'upload',
				0,
				$attachment_id,
				'' !== (string) ( $entry['target'] ?? '' ) ? (string) $entry['target'] : $target,
				(string) ( $entry['version'] ?? '' )
			);
		}
		Action_Worker::schedule( 15 );
	}

	/**
	 * Expose state to the admin panel.
	 */
	public function status(): Sync_Status {
		return $this->status;
	}

	/**
	 * One-time retirement of the upload queue and the old upload_mode option.
	 *
	 * Queue rows written by 1.11.0 become non-runnable failure records: they
	 * keep their key/path/profile/target evidence but carry no `next` field, so
	 * no cron can execute them. Existing attachments are never uploaded
	 * automatically; the administrator sees them as pending and decides.
	 */
	public function maybe_migrate(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === (string) get_option( self::MIGRATION_OPTION ) ) {
			return;
		}

		delete_option( 'sakura_imgopt_upload_mode' );

		$saved = get_option( 's3_options', array() );
		if ( is_array( $saved ) && isset( $saved['upload_mode'] ) ) {
			unset( $saved['upload_mode'] );
			update_option( 's3_options', $saved, false );
		}

		wp_clear_scheduled_hook( 'sakura_imgopt_s3_retry' );
		wp_clear_scheduled_hook( 'sakura_imgopt_s3_upload_watchdog' );

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 50",
				Sync_Status::JOBS_META
			)
		);

		$target = $this->uploader->target_hash();
		foreach ( (array) $rows as $row ) {
			$id   = (int) $row->post_id;
			$jobs = maybe_unserialize( $row->meta_value );
			if ( $id > 0 && is_array( $jobs ) ) {
				$entries = array();
				foreach ( $jobs as $job ) {
					if ( ! is_array( $job ) || empty( $job['key'] ) ) {
						continue;
					}
					$entries[] = array(
						'key'     => (string) $job['key'],
						'path'    => (string) ( $job['path'] ?? '' ),
						'version' => '',
						'profile' => (string) $this->options->active_profile_id(),
						'target'  => $target,
						'code'    => 'legacy_queue',
						'message' => (string) ( $job['error'] ?? __( '旧版本遗留的后台上传任务，已停止自动执行。', 'sakura-imgopt' ) ),
						'time'    => (int) ( $job['time'] ?? time() ),
						'held'    => true,
					);
				}
				if ( $entries ) {
					$this->status->record_failures( $id, $entries );
					$this->status->mark_failed( $id, __( '存在旧版本遗留的上传任务，请人工补传。', 'sakura-imgopt' ) );
				}
			}
			delete_post_meta( $id, Sync_Status::JOBS_META );
		}

		if ( count( (array) $rows ) < 50 ) {
			update_option( self::MIGRATION_OPTION, '1', false );
		}
	}

	/**
	 * Reject image uploads while S3 is enabled with an unusable configuration.
	 *
	 * @param array<string,mixed> $file Upload file array.
	 * @return array<string,mixed>
	 */
	public function block_incomplete_upload( $file ) {
		if ( ! is_array( $file ) || ! $this->options->is_enabled() || $this->options->is_configured() ) {
			return $file;
		}

		$type = isset( $file['type'] ) ? (string) $file['type'] : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'apng', 'bmp', 'tif', 'tiff', 'ico' );

		if ( 0 !== strpos( $type, 'image/' ) && ! in_array( $ext, $image_extensions, true ) ) {
			return $file;
		}

		$file['error'] = sprintf(
			/* translators: %s: comma-separated missing configuration fields. */
			__( 'S3 已启用但配置不完整（%s）。已阻止上传；请先在 S3 存储页补全配置。', 'sakura-imgopt' ),
			implode( ', ', $this->options->configuration_errors() )
		);

		return $file;
	}

	/**
	 * Warn when S3 is enabled but the configuration is incomplete.
	 */
	public function render_incomplete_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
			sprintf(
				/* translators: %s: comma-separated missing configuration fields. */
				esc_html__( '樱花工具箱：S3 已启用但配置不完整（%s），已暂停媒体 URL 接管、图片上传与手动同步。', 'sakura-imgopt' ),
				esc_html( implode( ', ', $this->options->configuration_errors() ) )
			),
			'bad'
		);
	}

	/**
	 * Show a compact failure notice on admin screens.
	 */
	public function render_failed_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->options->is_configured() ) {
			return;
		}

		$failed  = $this->status->failed_attachments( 1 );
		$orphans = $this->deletes->failure_count();
		$stale   = $this->deletes->stale_count();
		$drifted = $this->drifted_target_count();

		if ( ! $failed && 0 === $orphans && 0 === $stale && 0 === $drifted ) {
			return;
		}

		$messages = array();
		if ( $failed ) {
			$messages[] = esc_html__( '樱花工具箱：有附件未完成 S3 同步。', 'sakura-imgopt' );
		}
		if ( $orphans > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of remote objects. */
				esc_html__( '有 %d 个远端对象删除失败，可能继续产生存储费用，后台会按退避自动重试。', 'sakura-imgopt' ),
				$orphans
			);
		}
		if ( $stale > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of remote objects. */
				esc_html__( '有 %d 个远端对象补删任务超过 10 分钟未完成，请检查 WP-Cron 或系统定时任务。', 'sakura-imgopt' ),
				$stale
			);
		}

		if ( $drifted > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of remote objects. */
				esc_html__( '有 %d 个待删对象属于旧的存储目标（bucket/endpoint 已变更），不会自动重试；可用 wp sakura-s3 deletes --state=dead 查看，或 wp sakura-s3 deletes --retry --yes 按存档配置重试。', 'sakura-imgopt' ),
				$drifted
			);
		}

		// Messages are escaped where they are built (esc_html__ + sprintf), and the
		// helper runs the result through wp_kses_post, so nothing is output raw.
		$html = \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html(
			implode( ' ', $messages ),
			'warn',
			\Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'connection' ),
			esc_html__( '查看并重试', 'sakura-imgopt' )
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
	}

	/**
	 * Count pending deletes aimed at a storage target that no longer exists.
	 *
	 * @return int
	 */
	private function drifted_target_count(): int {
		$drifted = 0;

		foreach ( $this->deletes->target_groups() as $group ) {
			$current = Delete_Jobs::target_hash( (array) $this->options->profile_options( $group['profile_id'] ) );
			if ( ! hash_equals( $group['target_hash'], $current ) ) {
				$drifted += (int) $group['count'];
			}
		}

		return $drifted;
	}

	/**
	 * Keep not-yet-uploaded attachments on the local URL.
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function revert_unsynced_url( $url, $attachment_id ): string {
		$url = (string) $url;
		$id  = (int) $attachment_id;
		if ( '' === $url || $id <= 0 ) {
			return $url;
		}

		$cdn = (string) $this->options->get( 'upload_url_path', '' );
		if ( '' === $cdn ) {
			// The managed value may live only in WordPress' own option when a
			// legacy install never stored it on the plugin settings screen.
			$cdn = (string) get_option( 'upload_url_path', '' );
		}
		if ( '' === $cdn ) {
			return $url;
		}

		// Never point at a missing local file: media uploaded with
		// no_local_saving keeps working through the CDN only.
		$file   = get_attached_file( $id );
		$has    = is_string( $file ) && '' !== $file && is_file( $file );
		$status = (string) $this->status->status( $id );

		// Only a synced attachment with a local copy can fall back to the local
		// host, so only that case is worth confirming; it is the single question
		// that decides whether a bucket switch breaks every URL.
		$target_match = null;
		if ( Sync_Status::SYNCED === $status && $has ) {
			$target_match = $this->confirmed_in_active_bucket( $id );
		}
		if ( ! self::should_keep_local( $status, $has, $target_match ) ) {
			return $url;
		}

		return self::map_cdn_to_local( $url, $cdn, self::local_upload_base_url( $this->options ) );
	}

	/**
	 * Whether the active bucket really holds this attachment's objects now.
	 *
	 * The fingerprint in post meta records which destination produced the
	 * current object set, so a plain bucket switch makes every attachment look
	 * unconfirmed and sends its URL back to the local host. The sync index knows
	 * better: when a scan saw every recorded key in the active bucket with
	 * matching sizes, the objects are there and the attachment can stay on the
	 * CDN. The fingerprint is then rewritten once, so later requests answer from
	 * post meta alone.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when the current bucket is a valid target.
	 */
	private function confirmed_in_active_bucket( int $attachment_id ): bool {
		$stored = $this->status->target_hash( $attachment_id );
		if ( Synchronous_Uploader::target_matches( $stored, $this->options ) ) {
			return true;
		}
		if ( ! Feature::enabled() ) {
			return false;
		}
		if ( isset( $this->index_confirmed[ $attachment_id ] ) ) {
			return $this->index_confirmed[ $attachment_id ];
		}

		$confirmed = $this->index_confirms( $attachment_id );
		if ( $confirmed ) {
			$this->status->retarget( $attachment_id, $this->uploader->target_hash() );
		}

		$this->index_confirmed[ $attachment_id ] = $confirmed;

		return $confirmed;
	}

	/**
	 * Ask the sync index whether every recorded key is in the active bucket.
	 *
	 * A row reports the synced state only after a scan saw the object locally
	 * and in the bucket with the same size, so one agreement per key is enough.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function index_confirms( int $attachment_id ): bool {
		$keys    = Restore_Plan::normalize( $this->status->objects( $attachment_id ) );
		$profile = (string) $this->options->active_profile_id();
		$path    = (string) $this->options->get( 'upload_path', '' );

		if ( ! $keys || '' === $profile ) {
			return false;
		}

		// Without the schema option the index tables were never installed, and
		// querying them would be a wasted, failing round trip per attachment.
		if ( Schema::VERSION !== (string) get_option( Schema::OPTION, '' ) ) {
			return false;
		}

		$prefix = '' === $path || '.' === $path ? '' : untrailingslashit( ltrim( wp_normalize_path( $path ), '/' ) );
		$index  = new Index_Repository();

		foreach ( $keys as $key ) {
			if ( ! $this->index_has_key( $index, $profile, (string) $key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the index reports one recorded key as synced in this bucket.
	 *
	 * Recorded keys are usually the object key itself, but an install that
	 * scanned while uploads lived at the site root wrote them relative to the
	 * uploads directory, and a path rebuilt from disk can be absolute. Every
	 * form is tried before answering no, so a key written by any generation of
	 * the plugin still resolves to its row.
	 *
	 * @param Index_Repository $index   Repository to query.
	 * @param string           $profile Active profile.
	 * @param string           $key     Recorded key or path.
	 * @param string           $prefix  Normalized upload path, empty at the root.
	 * @return bool
	 */
	private function index_has_key( Index_Repository $index, string $profile, string $key, string $prefix ): bool {
		foreach ( self::key_candidates( $key, $prefix ) as $candidate ) {
			$row = $index->find( $profile, $candidate );
			if ( is_array( $row ) && Sync_Status::SYNCED === (string) ( $row['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Object keys one recorded key may correspond to.
	 *
	 * @param string $key    Recorded key or path.
	 * @param string $prefix Normalized upload path, empty when uploads go to the root.
	 * @return array<int,string>
	 */
	private static function key_candidates( string $key, string $prefix ): array {
		$key        = ltrim( wp_normalize_path( trim( $key ) ), '/' );
		$root       = ltrim( untrailingslashit( wp_normalize_path( ABSPATH ) ), '/' );
		$candidates = array( $key );

		if ( '' !== $root && 0 === strpos( $key, $root . '/' ) ) {
			$candidates[] = ltrim( substr( $key, strlen( $root ) ), '/' );
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $prefix || 0 === strpos( $candidate, $prefix . '/' ) ) {
				continue;
			}
			$candidates[] = $prefix . '/' . $candidate;
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Whether an attachment that is not confirmed in the bucket must stay local.
	 *
	 * Unknown status ("") keeps the legacy CDN URL: attachments uploaded before
	 * the plugin tracked state may already live in the bucket. Only explicit
	 * pending / failed / dead states, with a local file to serve, are reverted.
	 *
	 * @param bool|null $target_matches Whether the synced state belongs to the current target.
	 * @return bool
	 */
	public static function should_keep_local( string $status, bool $local_exists, ?bool $target_matches = null ): bool {
		if ( ! $local_exists || '' === $status ) {
			return false;
		}
		if ( Sync_Status::SYNCED === $status ) {
			return false === $target_matches;
		}
		return true;
	}

	/**
	 * Swap a CDN base for the local uploads base, leaving the path untouched.
	 *
	 * @param string $url        Attachment URL.
	 * @param string $cdn_base   Configured upload_url_path.
	 * @param string $local_base Local uploads URL base.
	 * @return string
	 */
	public static function map_cdn_to_local( string $url, string $cdn_base, string $local_base ): string {
		$cdn   = untrailingslashit( $cdn_base );
		$local = untrailingslashit( $local_base );
		if ( '' === $cdn || '' === $local || 0 !== strpos( $url, $cdn ) ) {
			return $url;
		}
		return $local . substr( $url, strlen( $cdn ) );
	}

	/**
	 * Swap the local uploads base for the CDN base, leaving the path untouched.
	 *
	 * The provider's image processor only exists on the media host, so a URL
	 * that still points at the site has to move there before it is probed.
	 *
	 * @param string $url        Attachment URL.
	 * @param string $local_base Local uploads URL base.
	 * @param string $cdn_base   Configured upload_url_path.
	 * @return string
	 */
	public static function map_local_to_cdn( string $url, string $local_base, string $cdn_base ): string {
		$cdn   = untrailingslashit( $cdn_base );
		$local = untrailingslashit( $local_base );
		if ( '' === $cdn || '' === $local || 0 !== strpos( $url, $local ) ) {
			return $url;
		}
		return $cdn . substr( $url, strlen( $local ) );
	}

	/**
	 * Local URL base that mirrors upload_url_path.
	 *
	 * @param Options|null $options Optional options.
	 */
	public static function local_upload_base_url( ?Options $options = null ): string {
		$options = $options ?? new Options();
		$previous = (string) get_option( Upload_URL::PREVIOUS_URL_OPTION, '' );
		if ( '' !== $previous ) {
			return untrailingslashit( $previous );
		}

		// "." stores objects at the site root; every other prefix mirrors the
		// uploads directory, so derive the base from basedir instead of trusting
		// upload_url_path (which currently points at the CDN).
		if ( '.' === $options->upload_path() ) {
			return untrailingslashit( site_url() );
		}

		$uploads = wp_upload_dir( null, false );
		$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$root    = wp_normalize_path( ABSPATH );
		if ( '' !== $basedir && 0 === strpos( $basedir, $content ) ) {
			return untrailingslashit( content_url( substr( $basedir, strlen( $content ) ) ) );
		}
		if ( '' !== $basedir && 0 === strpos( $basedir, $root ) ) {
			return untrailingslashit( site_url( substr( $basedir, strlen( $root ) ) ) );
		}
		return untrailingslashit( content_url( '/uploads' ) );
	}

	/**
	 * Normalize root-mode attachment URLs.
	 *
	 * @param string $url           URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function normalize_root_url( $url, $attachment_id = 0 ) {
		unset( $attachment_id );
		return str_replace( array( './', wp_normalize_path( ABSPATH ) ), '', (string) $url );
	}

	/**
	 * Whether the current request should skip remote sync.
	 */
	private function skip_request(): bool {
		global $pagenow;

		if ( in_array( $pagenow, array( 'update.php', 'plugin-install.php', 'theme-install.php' ), true ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether a request is skipped; it does not change state.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return in_array( $action, array( 'upload-plugin', 'upload-theme', 'update-plugin', 'update-theme' ), true );
	}
}
