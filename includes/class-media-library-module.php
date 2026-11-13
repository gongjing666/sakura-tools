<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Backup\Exposure_Probe;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Filesystem\File_System_Repository;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 媒体库增强：格式筛选 + 异步批量优化
 *
 * 批量优化流程：
 *   选中图片 → 「图像优化」→ 存入 transient 队列
 *   → 跳转进度页 → JS 循环发 AJAX（每次处理 BATCH_SIZE 张）
 *   → 完成后显示汇总 + 返回媒体库
 */
class Media_Library_Module implements Module {

	/**
	 * Image processor shared with upload restore/optimize flows.
	 *
	 * @var Image_Processor
	 */
	private Image_Processor $processor;

	/**
	 * Backup persistence boundary.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Stats persistence boundary.
	 *
	 * @var Stats_Repository_Interface
	 */
	private Stats_Repository_Interface $stats;

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Settings persistence boundary.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Progress page hook suffix, if registered.
	 *
	 * @var string
	 */
	private string $progress_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Image_Processor|null              $processor Image processor.
	 * @param Backup_Repository_Interface|null  $backup    Backup repository.
	 * @param Stats_Repository_Interface|null   $stats     Stats repository.
	 * @param File_System_Interface|null        $files     Filesystem repository.
	 * @param Settings_Repository_Interface|null $settings Settings repository.
	 */
	public function __construct(
		?Image_Processor $processor = null,
		?Backup_Repository_Interface $backup = null,
		?Stats_Repository_Interface $stats = null,
		?File_System_Interface $files = null,
		?Settings_Repository_Interface $settings = null
	) {
		$this->backup    = $backup ?? new Backup_Repository();
		$this->stats     = $stats ?? new Stats_Repository();
		$this->files     = $files ?? new File_System_Repository();
		$this->settings  = $settings ?? new Settings_Repository();
		$this->processor = $processor ?? new Image_Processor( $this->backup, null, null, $this->stats, $this->files, $this->settings );
	}

	/**
	 * 每批处理张数：读后台设置，范围 1–20，默认 1
	 */
	private function get_batch_size() {
		$v = (int) $this->settings->batch_size();
		return max( 1, min( 20, $v ) );
	}

	/**
	 * 批次间休息毫秒数：读后台设置，范围 0–2000，默认 500
	 */
	private function get_ajax_delay() {
		$v = (int) $this->settings->ajax_delay();
		return max( 0, min( 2000, $v ) );
	}

	/**
	 * Register media-library hooks with the central loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		// ── 格式筛选 ──────────────────────────────
		$hooks->add_action( 'restrict_manage_posts', array( $this, 'add_format_dropdown' ) );
		$hooks->add_action( 'pre_get_posts', array( $this, 'apply_format_filter' ) );
		$hooks->add_filter( 'ajax_query_attachments_args', array( $this, 'apply_grid_format_filter' ) );

		// ── 批量操作 ──────────────────────────────
		$hooks->add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
		$hooks->add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		$hooks->add_action( 'admin_notices', array( $this, 'render_restore_blocked_notice' ) );
		$hooks->add_action( 'admin_notices', array( $this, 'render_bulk_blocked_notice' ) );

		// ── 进度页 ────────────────────────────────
		$hooks->add_action( 'admin_menu', array( $this, 'add_progress_page' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_progress_assets' ) );
		$hooks->add_filter( 'admin_title', array( $this, 'filter_progress_admin_title' ), 10, 2 );

		// ── AJAX 处理 ─────────────────────────────
		$hooks->add_action( 'wp_ajax_sakura_process_batch', array( $this, 'ajax_process_batch' ) );
		$hooks->add_action( 'wp_ajax_sakura_get_progress', array( $this, 'ajax_get_progress' ) );

		// ── 单张还原：附件编辑页按钮 + AJAX ────────
		$hooks->add_filter( 'attachment_fields_to_edit', array( $this, 'add_restore_field' ), 10, 2 );
		$hooks->add_action( 'wp_ajax_sakura_restore_single', array( $this, 'ajax_restore_single' ) );

		// ── 备份管理：一键清空全部备份 ────────────
		$hooks->add_action( 'wp_ajax_sakura_purge_backup', array( $this, 'ajax_purge_backup' ) );
		// ── 备份管理：刷新占用统计 ────────────────
		$hooks->add_action( 'wp_ajax_sakura_refresh_backup', array( $this, 'ajax_refresh_backup' ) );
		// ── 备份管理：立即清理过期备份 ────────────
		$hooks->add_action( 'wp_ajax_sakura_clean_expired_backup', array( $this, 'ajax_clean_expired_backup' ) );
		// ── 备份管理：探测目录是否可被公网访问 ────
		$hooks->add_action( 'wp_ajax_sakura_probe_backup', array( $this, 'ajax_probe_backup' ) );

		// ── S3 同步状态列 ──────────────────────────
		$hooks->add_filter( 'manage_media_columns', array( $this, 'add_s3_sync_column' ) );
		$hooks->add_action( 'manage_media_custom_column', array( $this, 'render_s3_sync_column' ), 10, 2 );
	}

	/**
	 * Add the S3 sync column to the media list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_s3_sync_column( $columns ) {
		$columns['sakura_s3_sync'] = __( 'S3 同步', 'sakura-imgopt' );
		return $columns;
	}

	/**
	 * Render the S3 sync column.
	 *
	 * @param string $column        Column key.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function render_s3_sync_column( $column, $attachment_id ): void {
		if ( 'sakura_s3_sync' !== $column ) {
			return;
		}

		$status = new Sync_Status();
		$state  = $status->status( (int) $attachment_id );
		$label  = '' !== $state ? $status->label( (int) $attachment_id ) : '—';
		$error  = $status->error( (int) $attachment_id );
		echo '<span title="' . esc_attr( $error ) . '">' . esc_html( $label ) . '</span>';
	}

	// ══════════════════════════════════════════════════
	//  格式筛选（不变）
	// ══════════════════════════════════════════════════

	private function get_format_mime_map() {
		return array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'svg'  => 'image/svg+xml',
			'apng' => 'image/apng',
		);
	}

	public function add_format_dropdown() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only media-list filter; no state is changed.
		$current = isset( $_GET['sakura_format'] ) ? sanitize_text_field( wp_unslash( $_GET['sakura_format'] ) ) : '';
		$map     = $this->get_format_mime_map();

		echo '<select name="sakura_format" style="float:none;">';
		echo '<option value="">' . esc_html__( '所有格式', 'sakura-imgopt' ) . '</option>';
		foreach ( $map as $ext => $mime ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $ext ),
				selected( $current, $ext, false ),
				esc_html( strtoupper( $ext ) )
			);
		}
		echo '</select>';
	}

	public function apply_format_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only media-list filter; no state is changed.
		$format = isset( $_GET['sakura_format'] ) ? sanitize_text_field( wp_unslash( $_GET['sakura_format'] ) ) : '';
		if ( empty( $format ) ) {
			return;
		}

		$map = $this->get_format_mime_map();
		if ( ! isset( $map[ $format ] ) ) {
			return;
		}

		$query->set( 'post_mime_type', $map[ $format ] );
	}

	public function apply_grid_format_filter( $args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only media-grid filter; no state is changed.
		$format = isset( $_GET['sakura_format'] ) ? sanitize_text_field( wp_unslash( $_GET['sakura_format'] ) ) : '';
		if ( empty( $format ) ) {
			return $args;
		}

		$map = $this->get_format_mime_map();
		if ( ! isset( $map[ $format ] ) ) {
			return $args;
		}

		$args['post_mime_type'] = $map[ $format ];
		return $args;
	}

	// ══════════════════════════════════════════════════
	//  批量操作入口（只存队列，不处理）
	// ══════════════════════════════════════════════════

	public function register_bulk_action( $actions ) {
		$actions['sakura_optimize'] = __( '图像优化', 'sakura-imgopt' );
		$actions['sakura_restore']  = __( '图像还原', 'sakura-imgopt' );
		return $actions;
	}

	/**
	 * 不在此处处理图片，改为：
	 *   1. 把 attachment ID 列表写入 transient（附 mode 区分优化/还原）
	 *   2. 重定向到进度页
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_url;
		}

		if ( 'sakura_optimize' === $action ) {
			$mode = 'optimize';
		} elseif ( 'sakura_restore' === $action ) {
			$mode = 'restore';
		} else {
			return $redirect_url;
		}

		$post_ids = array_values( array_unique( array_map( 'intval', (array) $post_ids ) ) );
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				}
			)
		);

		if ( empty( $post_ids ) ) {
			return add_query_arg(
				'sakura_bulk_blocked',
				1,
				$redirect_url ? $redirect_url : admin_url( 'upload.php' )
			);
		}

		$skipped = 0;

		// 对象存储/CDN 附件不做还原：本地备份写回不会更新桶内对象，
		// 且还原触发的 metadata 重建可能被 S3 钩子再次回传，导致超时。
		if ( 'restore' === $mode ) {
			$restorable = array();
			foreach ( $post_ids as $post_id ) {
				if ( $this->is_local_attachment( $post_id ) ) {
					$restorable[] = $post_id;
				} else {
					++$skipped;
				}
			}

			if ( empty( $restorable ) ) {
				return add_query_arg(
					'sakura_restore_blocked',
					$skipped,
					$redirect_url ? $redirect_url : admin_url( 'upload.php' )
				);
			}

			$post_ids = $restorable;
		}

		$user_id = get_current_user_id();
		$key     = 'sakura_queue_' . $user_id;

		$queue = array(
			'mode'      => $mode,
			'ids'       => $post_ids,
			'total'     => count( $post_ids ),
			'processed' => 0,
			'success'   => 0,
			'fail'      => 0,
			'skipped'   => $skipped,
		);

		set_transient( $key, $queue, HOUR_IN_SECONDS );

		return add_query_arg(
			array( 'page' => 'sakura-imgopt-progress' ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * 批量还原全部被跳过时，在媒体库显示提示。
	 */
	public function render_restore_blocked_notice() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice; no state is changed.
		$blocked = isset( $_GET['sakura_restore_blocked'] ) ? absint( wp_unslash( $_GET['sakura_restore_blocked'] ) ) : 0;
		if ( $blocked < 1 ) {
			return;
		}

		$html = \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html(
			esc_html(
				sprintf(
					/* translators: %s: number of skipped attachments. */
					__( '已跳过 %s 个由对象存储/CDN 托管的附件：本地备份还原不会更新桶内对象，因此未执行还原。', 'sakura-imgopt' ),
					number_format_i18n( $blocked )
				)
			),
			'warn'
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
	}

	/**
	 * Show a notice when every selected attachment was not editable.
	 */
	public function render_bulk_blocked_notice() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
		$blocked = isset( $_GET['sakura_bulk_blocked'] ) ? absint( wp_unslash( $_GET['sakura_bulk_blocked'] ) ) : 0;
		if ( $blocked < 1 ) {
			return;
		}

		echo \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
			esc_html__( '没有可处理的附件：你没有编辑这些附件的权限。', 'sakura-imgopt' ),
			'bad'
		);
	}

	// ══════════════════════════════════════════════════
	//  进度页
	// ══════════════════════════════════════════════════

	public function add_progress_page() {
		$this->progress_hook = (string) add_submenu_page(
			'options.php',
			__( '图像优化进度', 'sakura-imgopt' ),
			'',
			'upload_files',
			'sakura-imgopt-progress',
			array( $this, 'render_progress_page' )
		);
		remove_submenu_page( 'options.php', 'sakura-imgopt-progress' );
	}

	/**
	 * Load the shared admin theme on the hidden batch progress page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_progress_assets( $hook ) {
		if ( '' === $this->progress_hook || $hook !== $this->progress_hook ) {
			return;
		}

		$queue = get_transient( 'sakura_queue_' . get_current_user_id() );
		$queue = is_array( $queue ) ? $queue : array();
		$ids   = isset( $queue['ids'] ) && is_array( $queue['ids'] ) ? $queue['ids'] : array();

		Page_Assets::set_data(
			'media-progress',
			array(
				'total'     => $ids ? max( 0, (int) ( $queue['total'] ?? 0 ) ) : 0,
				'processed' => $ids ? max( 0, (int) ( $queue['processed'] ?? 0 ) ) : 0,
				'success'   => $ids ? max( 0, (int) ( $queue['success'] ?? 0 ) ) : 0,
				'fail'      => $ids ? max( 0, (int) ( $queue['fail'] ?? 0 ) ) : 0,
				'skipped'   => $ids ? max( 0, (int) ( $queue['skipped'] ?? 0 ) ) : 0,
				'mode'      => $ids && 'restore' === ( $queue['mode'] ?? '' ) ? 'restore' : 'optimize',
				'batchSize' => $this->get_batch_size(),
				'delay'     => $this->get_ajax_delay(),
				'nonce'     => wp_create_nonce( 'sakura_process_batch' ),
				'strings'   => array(
					'preparing'     => __( '准备处理 {total} 张图片…', 'sakura-imgopt' ),
					'resume'        => __( '继续处理，已完成 {processed} / {total}…', 'sakura-imgopt' ),
					'processing'    => __( '正在处理 {processed} / {total}…', 'sakura-imgopt' ),
					'successCount'  => __( '成功 {count} 张', 'sakura-imgopt' ),
					'failCount'     => __( '失败 {count} 张', 'sakura-imgopt' ),
					'skippedCount'  => __( '跳过 {count} 张', 'sakura-imgopt' ),
					'errorStop'     => __( '发生错误，已停止。', 'sakura-imgopt' ),
					'requestFailed' => __( '请求失败，已停止。请检查网络后重试。', 'sakura-imgopt' ),
					'restore'       => __( '还原', 'sakura-imgopt' ),
					'optimize'      => __( '优化', 'sakura-imgopt' ),
					'doneTitle'     => __( '{verb}完成', 'sakura-imgopt' ),
					'skipRestore'   => __( '其中 {count} 张位于云存储或 CDN，未还原。', 'sakura-imgopt' ),
					'skipOptimize'  => __( '其中 {count} 张因动图、超过上限、格式不支持或体积没有变小而跳过。', 'sakura-imgopt' ),
				),
			)
		);

		Page_Assets::enqueue( 'media-progress' );
	}

	/**
	 * Give the hidden progress page a readable browser title.
	 *
	 * @param string $admin_title Full admin title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public function filter_progress_admin_title( $admin_title, $title ) {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'sakura-imgopt-progress' === $page ) {
			return __( '图像优化进度', 'sakura-imgopt' ) . $admin_title;
		}
		return $admin_title;
	}

	public function render_progress_page() {
		$batch_size = $this->get_batch_size();
		$delay      = $this->get_ajax_delay();
		require __DIR__ . '/views/media-progress.php';
	}

	// ══════════════════════════════════════════════════
	//  AJAX 处理
	// ══════════════════════════════════════════════════

	public function ajax_process_batch() {
		check_ajax_referer( 'sakura_process_batch' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		$user_id = get_current_user_id();
		$key     = 'sakura_queue_' . $user_id;
		$queue   = get_transient( $key );

		if ( empty( $queue ) || empty( $queue['ids'] ) ) {
			wp_send_json_error( __( '队列为空', 'sakura-imgopt' ) );
		}

		if ( ! isset( $queue['skipped'] ) ) {
			$queue['skipped'] = 0;
		}

		// 批量循环可能连续解码大图：抬升内存上限，并给单次请求一个可控的时间上限，
		// 避免 20 张 4000px 图片在同一个请求里把 PHP 进程拖到 500/超时。
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			$time_limit = (int) apply_filters( 'sakura_imgopt_batch_time_limit', 300 );
			if ( $time_limit > 0 ) {
				@set_time_limit( $time_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$processor = $this->processor;
		$mode      = isset( $queue['mode'] ) ? $queue['mode'] : 'optimize';

		$batch_size = $this->get_batch_size();
		for ( $i = 0; $i < $batch_size; $i++ ) {
			if ( empty( $queue['ids'] ) ) {
				break;
			}

			$post_id = (int) array_shift( $queue['ids'] );
			++$queue['processed'];

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$queue['fail'];
				continue;
			}

			// ── 还原模式：把原图写回，附件 ID 不变 ──
			if ( 'restore' === $mode ) {
				// 批量入口已过滤，这里兜底处理过期队列或手工构造的请求。
				if ( ! $this->is_local_attachment( $post_id ) ) {
					++$queue['fail'];
					continue;
				}

				$res = $processor->restore_attachment( $post_id );
				if ( ! empty( $res['success'] ) ) {
					++$queue['success'];
				} else {
					++$queue['fail'];
				}
				continue;
			}

			// ── 优化模式（默认）──
			$file = get_attached_file( $post_id );
			if ( ! $file || ! file_exists( $file ) ) {
				++$queue['fail'];
				// 记录失败：源文件不存在（云存储接管 / 文件被删等）
				$this->stats->record(
					$post_id,
					array(
						'success'     => false,
						'path'        => '',
						'error'       => __( '源文件不存在或无法访问', 'sakura-imgopt' ),
						'size_before' => 0,
						'size_after'  => 0,
						'engine'      => '',
					),
					(string) $file,
					'batch'
				);
				continue;
			}

			// 动图/超大图在上传链路会直接跳过；批量入口也必须先拦截，
			// 否则会先备份再处理失败，留下一份无意义的原图备份。
			$skip_reason = $processor->get_processing_skip_reason( $file );
			if ( '' !== $skip_reason ) {
				++$queue['skipped'];
				continue;
			}

			// 备份原图：与上传链路一致，处理前先留底，供后续一键还原。
			// 仅在该附件当前无有效备份、且用户未关闭备份时执行，避免覆盖更早的原始备份。
			$backup_rel = false;
			if ( $processor->is_backup_enabled() && ! $this->backup->has_backup( $post_id ) ) {
				$backup_rel = $this->backup->store( $file );
				if ( false === $backup_rel ) {
					// 备份失败必须跳过本张，绝不能在无备份的情况下继续压缩。
					++$queue['fail'];
					$this->stats->record(
						$post_id,
						array(
							'success'     => false,
							'path'        => '',
							'error'       => __( '原图备份失败，已跳过处理（原图未改动）', 'sakura-imgopt' ),
							'size_before' => (int) filesize( $file ),
							'size_after'  => 0,
							'engine'      => '',
						),
						(string) $file,
						'batch'
					);
					continue;
				}
			}

			$result = $processor->process_file( $file );

			// M-08：体积未减小而保留原图。计入 skipped，不算失败；本次新备份也一并删除。
			if ( ! empty( $result['skipped'] ) ) {
				++$queue['skipped'];
				if ( false !== $backup_rel ) {
					$backup_abs = $this->backup->resolve_upload_path( $backup_rel, true, true );
					if ( false !== $backup_abs ) {
						$this->files->delete( $backup_abs );
					}
				}
				$this->stats->record( $post_id, $result, $file, 'batch' );
				continue;
			}

			if ( ! $result['success'] ) {
				++$queue['fail'];
				// 处理失败：清理本次刚产生的孤儿备份（若有）。
				if ( false !== $backup_rel ) {
					$backup_abs = $this->backup->resolve_upload_path( $backup_rel, true, true );
					if ( false !== $backup_abs ) {
						$this->files->delete( $backup_abs );
					}
				}
				$this->stats->record( $post_id, $result, $file, 'batch' );
				continue;
			}

			$this->update_attachment_after_optimize( $post_id, $result, $file );

			// 处理成功且本次确实备份了原图，写入备份元数据供还原使用。
			if ( false !== $backup_rel ) {
				$upload        = wp_upload_dir();
				$base_dir      = rtrim( $upload['basedir'], '/' );
				$origin_rel    = ltrim( str_replace( $base_dir, '', $file ), '/' );
				$converted_rel = ltrim( str_replace( $base_dir, '', $result['path'] ), '/' );
				$this->backup->save_meta( $post_id, $backup_rel, $origin_rel, $converted_rel );
			}

			++$queue['success'];
			$this->stats->record( $post_id, $result, $file, 'batch' );
		}

		// 更新或删除 transient
		if ( empty( $queue['ids'] ) ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $queue, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'processed' => $queue['processed'],
				'success'   => $queue['success'],
				'fail'      => $queue['fail'],
				'skipped'   => (int) $queue['skipped'],
			)
		);
	}

	/**
	 * 查询当前进度（备用，前端目前通过 process_batch 的返回值更新）
	 */
	public function ajax_get_progress() {
		check_ajax_referer( 'sakura_process_batch' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		$user_id = get_current_user_id();
		$key     = 'sakura_queue_' . $user_id;
		$queue   = get_transient( $key );

		if ( empty( $queue ) ) {
			wp_send_json_success(
				array(
					'processed' => 0,
					'total'     => 0,
					'success'   => 0,
					'fail'      => 0,
					'skipped'   => 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'processed' => $queue['processed'],
				'total'     => $queue['total'],
				'success'   => $queue['success'],
				'fail'      => $queue['fail'],
				'skipped'   => isset( $queue['skipped'] ) ? (int) $queue['skipped'] : 0,
			)
		);
	}

	// ══════════════════════════════════════════════════
	//  单张还原：附件编辑页按钮 + AJAX
	// ══════════════════════════════════════════════════

	/**
	 * 判断附件是否仍托管在本站域名下（即本地 uploads 可被还原覆盖）。
	 *
	 * 防呆用途：当站点启用了 OSS / 对象存储插件后，附件 URL 会被过滤为对象存储域名，
	 * 此时本地备份还原对线上图片不生效，容易误导用户。检测图片 URL 的 host 与本站
	 * home_url 的 host 是否一致，不一致则视为已托管到外部存储，不应提供还原入口。
	 *
	 * 带 www. 前缀容错；并暴露 sakura_imgopt_is_local_attachment 过滤器供特殊场景覆盖。
	 *
	 * @param int $attachment_id
	 * @return bool true=本地域名（可还原），false=外部存储（应隐藏还原入口）
	 */
	private function is_local_attachment( $attachment_id ) {
		// 同一请求内按附件 ID 缓存结果，避免按钮渲染 + AJAX 校验重复查询 URL，
		// 也为将来用于批量列表场景兜底。
		static $cache  = array();
		$attachment_id = (int) $attachment_id;
		if ( isset( $cache[ $attachment_id ] ) ) {
			return $cache[ $attachment_id ];
		}

		$url = wp_get_attachment_url( $attachment_id );

		// 拿不到 URL 时保守放行，避免误伤正常本地图。
		$is_local = true;

		if ( ! empty( $url ) ) {
			$img_host  = wp_parse_url( $url, PHP_URL_HOST );
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

			// 相对路径 / 协议相对 URL 没有 host，视为本地。
			if ( ! empty( $img_host ) && ! empty( $site_host ) ) {
				$normalize = static function ( $host ) {
					return preg_replace( '/^www\./i', '', strtolower( $host ) );
				};
				$is_local  = ( $normalize( $img_host ) === $normalize( $site_host ) );
			}
		}

		/**
		 * 过滤是否将该附件视为「本地域名、可还原」。
		 *
		 * @param bool $is_local
		 * @param int  $attachment_id
		 * @param string $url
		 */
		$result = (bool) apply_filters( 'sakura_imgopt_is_local_attachment', $is_local, $attachment_id, $url );

		$cache[ $attachment_id ] = $result;

		return $result;
	}

	/**
	 * 在附件编辑界面追加「还原原图」按钮（仅当存在可用备份时显示）。
	 *
	 * @param array   $form_fields
	 * @param \WP_Post $post
	 * @return array
	 */
	public function add_restore_field( $form_fields, $post ) {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}
		if ( ! $this->backup->has_backup( $post->ID ) ) {
			return $form_fields;
		}
		// 防呆：图片已托管到 OSS/对象存储（域名非本站）时，本地还原无意义，隐藏按钮。
		if ( ! $this->is_local_attachment( $post->ID ) ) {
			return $form_fields;
		}

		$nonce  = wp_create_nonce( 'sakura_restore_single_' . $post->ID );
		$btn_id = 'sakura-restore-' . $post->ID;

		ob_start();
		?>
		<button type="button" class="button" id="<?php echo esc_attr( $btn_id ); ?>">
			<?php esc_html_e( '还原原图', 'sakura-imgopt' ); ?>
		</button>
		<span class="sakura-restore-msg" style="margin-left:8px;color:#666;"></span>
		<script>
		(function($){
			var $btn = $('#<?php echo esc_js( $btn_id ); ?>');
			if (!$btn.length || $btn.data('bound')) return;
			$btn.data('bound', true);
			$btn.on('click', function(){
				if (!window.confirm('<?php echo esc_js( __( '确定还原为压缩前的原图吗？当前压缩文件将被替换。', 'sakura-imgopt' ) ); ?>')) return;
				var $msg = $btn.siblings('.sakura-restore-msg');
				$btn.prop('disabled', true);
				$msg.css('color', '#666').text('<?php echo esc_js( __( '还原中...', 'sakura-imgopt' ) ); ?>');
				$.post(ajaxurl, {
					action: 'sakura_restore_single',
					attachment_id: <?php echo (int) $post->ID; ?>,
					_ajax_nonce: '<?php echo esc_js( $nonce ); ?>'
				}, function(res){
					if (res && res.success) {
						$msg.css('color', 'green').text(res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( '已还原', 'sakura-imgopt' ) ); ?>');
						setTimeout(function(){ location.reload(); }, 800);
					} else {
						$btn.prop('disabled', false);
						$msg.css('color', '#d63638').text(res && res.data ? res.data : '<?php echo esc_js( __( '还原失败', 'sakura-imgopt' ) ); ?>');
					}
				}).fail(function(){
					$btn.prop('disabled', false);
					$msg.css('color', '#d63638').text('<?php echo esc_js( __( '请求失败', 'sakura-imgopt' ) ); ?>');
				});
			});
		})(jQuery);
		</script>
		<?php
		$html = ob_get_clean();

		$form_fields['sakura_restore'] = array(
			'label' => __( '原图备份', 'sakura-imgopt' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * AJAX：还原单张附件的原图。
	 */
	public function ajax_restore_single() {
		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;

		check_ajax_referer( 'sakura_restore_single_' . $attachment_id );

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}
		if ( $attachment_id <= 0 ) {
			wp_send_json_error( __( '无效的附件 ID', 'sakura-imgopt' ) );
		}
		// 防呆：外部存储（OSS 等）图片的本地备份链路对不上，禁止还原。
		// 与按钮隐藏逻辑保持一致，防止手动构造请求绕过。
		if ( ! $this->is_local_attachment( $attachment_id ) ) {
			wp_send_json_error( __( '该图片由外部存储（如 OSS）托管，不支持还原原图。', 'sakura-imgopt' ) );
		}

		$processor = $this->processor;
		$res       = $processor->restore_attachment( $attachment_id );

		if ( ! empty( $res['success'] ) ) {
			wp_send_json_success( array( 'message' => $res['message'] ) );
		}
		wp_send_json_error( $res['message'] );
	}

	/**
	 * 一键清空全部备份原图。
	 *
	 * 安全：nonce 校验 + manage_options 权限，与其余 AJAX 处理一致。
	 */
	public function ajax_purge_backup() {
		check_ajax_referer( 'sakura_purge_backup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		$res = $this->backup->purge_all();

		// 清空后重算当前占用（强制跳过缓存），供前端刷新显示。
		$usage = $this->backup->get_usage( true );

		wp_send_json_success(
			array(
				// 清空后的当前占用（用于刷新「占用 / 文件数」显示）。
				'size'       => size_format( $usage['bytes'], 2 ),
				'count'      => number_format_i18n( $usage['count'] ),
				// 本次释放量（用于提示文案）。
				'freed'      => $res['bytes'],
				'freed_text' => size_format( $res['bytes'], 2 ),
				'message'    => sprintf(
					/* translators: 1: 文件数, 2: 释放空间 */
					__( '已清空 %1$d 个备份文件，释放 %2$s。', 'sakura-imgopt' ),
					$res['count'],
					size_format( $res['bytes'], 2 )
				),
			)
		);
	}

	/**
	 * 刷新备份占用统计（强制跳过缓存重算）。
	 */
	public function ajax_refresh_backup() {
		check_ajax_referer( 'sakura_refresh_backup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		$usage = $this->backup->get_usage( true );

		wp_send_json_success(
			array(
				'size'    => size_format( $usage['bytes'], 2 ),
				'count'   => number_format_i18n( $usage['count'] ),
				'message' => __( '已刷新。', 'sakura-imgopt' ),
			)
		);
	}

	/**
	 * 立即清理过期备份（按保留天数 + 容量上限策略）。
	 */
	public function ajax_clean_expired_backup() {
		check_ajax_referer( 'sakura_clean_expired_backup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		$res = $this->backup->clean_expired();

		// 清理后重算当前占用（强制跳过缓存）。
		$usage = $this->backup->get_usage( true );

		wp_send_json_success(
			array(
				'size'    => size_format( $usage['bytes'], 2 ),
				'count'   => number_format_i18n( $usage['count'] ),
				'message' => sprintf(
					/* translators: 1: 文件数, 2: 释放空间 */
					__( '已清理 %1$d 个过期备份，释放 %2$s。', 'sakura-imgopt' ),
					$res['count'],
					size_format( $res['bytes'], 2 )
				),
			)
		);
	}

	/**
	 * 探测备份目录是否可被公网直接访问。
	 *
	 * Nginx / IIS / Caddy 不读取 .htaccess，只能由服务器实际应答判定。
	 *
	 * @return void
	 */
	public function ajax_probe_backup() {
		check_ajax_referer( 'sakura_probe_backup' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'sakura-imgopt' ) );
		}

		try {
			$probe = new Exposure_Probe();
			wp_send_json_success( $probe->run( true ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	// ══════════════════════════════════════════════════
	//  附件数据同步（不变）
	// ══════════════════════════════════════════════════

	private function update_attachment_after_optimize( $post_id, $result, $old_path ) {
		$new_path = $result['path'];

		$mime = wp_get_image_mime( $new_path );
		if ( ! $mime ) {
			$mime = 'image/jpeg';
		}

		update_attached_file( $post_id, $new_path );

		wp_update_post(
			array(
				'ID'             => $post_id,
				'post_mime_type' => $mime,
			)
		);

		$metadata = wp_get_attachment_metadata( $post_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}
		$metadata['file'] = _wp_relative_upload_path( $new_path );

		$info = getimagesize( $new_path );
		if ( $info ) {
			$metadata['width']  = $info[0];
			$metadata['height'] = $info[1];
		}

		if ( ! empty( $metadata['sizes'] ) ) {
			$dir          = dirname( $new_path );
			$new_basename = wp_basename( $new_path );
			$new_stem     = pathinfo( $new_basename, PATHINFO_FILENAME );
			$ext          = strtolower( pathinfo( $new_basename, PATHINFO_EXTENSION ) );

			foreach ( $metadata['sizes'] as $size_name => &$size_data ) {
				$candidate = $dir . '/' . $new_stem . '-' . $size_data['width'] . 'x' . $size_data['height'];
				$test      = $candidate . '.' . $ext;
				if ( file_exists( $test ) ) {
					$size_data['file'] = wp_basename( $test );
				}
			}
			unset( $size_data );
		}

		wp_update_attachment_metadata( $post_id, $metadata );

		if ( $new_path !== $old_path ) {
			$this->files->delete( $old_path );
		}
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Media_Library_Module', __NAMESPACE__ . '\\Media_Library' );
