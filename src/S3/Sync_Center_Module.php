<?php
/**
 * Read-only S3 synchronization dashboard.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Feature;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Sync\Kind;
use Sakura\ImageOptimizer\Sync\Metrics;
use Sakura\ImageOptimizer\Sync\Scan_Service;
use Sakura\ImageOptimizer\Sync\Scan_Worker;
use Sakura\ImageOptimizer\Sync\Search_Token;
use Sakura\ImageOptimizer\Sync\Sync_Calibrator;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the S3 sync center screen and its read-only scan/export endpoints.
 */
final class Sync_Center_Module implements Module {

	/**
	 * Admin page slug.
	 */
	public const MENU_SLUG = 'sakura-tools-storage';

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
	 * Sync state.
	 *
	 * @var Sync_Status
	 */
	private Sync_Status $status;

	/**
	 * Index repository.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $index;

	/**
	 * Scan service.
	 *
	 * @var Scan_Service
	 */
	private Scan_Service $scanner;

	/**
	 * Background worker.
	 *
	 * @var Scan_Worker
	 */
	private Scan_Worker $worker;

	/**
	 * Cached view data shared by the enqueue config and the renderer.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options Options.
	 * @param Storage_Adapter|null $adapter Adapter.
	 * @param Sync_Status|null     $status  Sync state.
	 * @param Index_Repository|null $index   Index repository.
	 * @param Scan_Service|null    $scanner Scan service.
	 * @param Scan_Worker|null     $worker  Background worker.
	 */
	public function __construct( ?Options $options = null, ?Storage_Adapter $adapter = null, ?Sync_Status $status = null, ?Index_Repository $index = null, ?Scan_Service $scanner = null, ?Scan_Worker $worker = null ) {
		$this->options   = $options ?? new Options();
		$this->adapter   = $adapter ?? new Storage_Adapter( $this->options );
		$this->status    = $status ?? new Sync_Status();
		$this->index     = $index ?? new Index_Repository();
		$this->scanner   = $scanner ?? new Scan_Service( $this->options, $this->adapter, $this->index );
		$this->worker    = $worker ?? new Scan_Worker( $this->scanner, $this->index );
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_scan', array( $this, 'ajax_scan' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_scan_step', array( $this, 'ajax_scan_step' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_cancel', array( $this, 'ajax_cancel' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_resume', array( $this, 'ajax_resume' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_page', array( $this, 'ajax_page' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_preview', array( $this, 'ajax_preview' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_test', array( $this, 'ajax_test' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_action', array( $this, 'ajax_action' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_refresh', array( $this, 'ajax_refresh' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_sync_toggle_index', array( $this, 'ajax_toggle_index' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_config_notice', array( $this, 'ajax_dismiss_config_notice' ) );
		$hooks->add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		$hooks->add_action( 'admin_post_sakura_s3_sync_export', array( $this, 'export_csv' ) );
		$hooks->add_action( 'admin_post_sakura_s3_ignored_save', array( $this, 'save_ignored_paths' ) );
		$hooks->add_action( 'admin_post_sakura_s3_media_download', array( $this, 'download_media' ) );
	}

	/**
	 * Persist the custom ignore rules from the sync center card.
	 */
	public function save_ignored_paths(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}

		check_admin_referer( 'sakura_s3_ignored_save' );

		$raw = isset( $_POST['ignored_paths'] ) ? wp_unslash( $_POST['ignored_paths'] ) : '';
		Ignored_Paths::save( $raw );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&t=sync&ignored_saved=1' ) );
		exit;
	}

	/**
	 * Enqueue only on the sync screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'storage', 'sync' ) ) {
			return;
		}

		unset( $hook );

		\Sakura\ImageOptimizer\Admin\Page_Assets::set_data( 'storage-sync', $this->page_config() );
		\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();
	}

	/**
	 * View data, computed once per request for both enqueue config and render.
	 *
	 * @return array<string,mixed>
	 */
	private function page_data(): array {
		if ( null === $this->data_cache ) {
			$this->data_cache = $this->view_data();
		}

		return $this->data_cache;
	}

	/**
	 * Format sync events for the client-side log list.
	 *
	 * @param array<int,array<string,mixed>> $logs      Raw log rows.
	 * @param int                            $scan_time Last scan timestamp.
	 * @return array<int,array<string,string>>
	 */
	private function log_payload( array $logs, int $scan_time ): array {
		$payload = array();

		foreach ( $logs as $log ) {
			$level           = (string) ( $log['level'] ?? 'info' );
			$badge           = 'error' === $level ? 'bad' : ( 'warning' === $level ? 'warn' : 'info' );
			$label           = 'error' === $level ? __( '错误', 'sakura-imgopt' ) : ( 'warning' === $level ? __( '警告', 'sakura-imgopt' ) : __( '信息', 'sakura-imgopt' ) );
			$payload[]       = array(
				'time'    => wp_date( 'H:i:s', (int) ( $log['time'] ?? 0 ) ),
				'level'   => $level,
				'message' => (string) ( $log['message'] ?? '' ),
				'badge'   => $badge,
				'label'   => $label,
				'scan'    => 'scan: ' . ( $scan_time ? gmdate( 'Ymd-His', $scan_time ) : '—' ),
			);
		}

		return $payload;
	}

	/**
	 * Data handed to the sync page controller through window.sakuraPage.data.
	 *
	 * @return array<string,mixed>
	 */
	private function page_config(): array {
		$data    = $this->page_data();
		$profile = (string) $this->options->active_profile_id();
		$token   = '' !== $profile ? Search_Token::create( (int) get_current_user_id(), $profile, 1800 ) : null;

		return array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'searchUrl'    => SAKURA_TOOLS_URL . 'fast-search.php',
			'searchToken'  => $token,
			'mediaUrl'     => admin_url( 'upload.php' ),
			'pageSize'     => 25,
			'pageSizes'    => array( 25, 50, 100 ),
			'logs'         => $this->log_payload( (array) $data['logs'], (int) ( $data['snapshot']['scanned_at'] ?? 0 ) ),
			'indexEnabled' => Feature::enabled(),
			'scanNonce'    => wp_create_nonce( 'sakura_s3_sync_scan' ),
			'retryNonce'   => wp_create_nonce( 'sakura_s3_retry' ),
			'testNonce'    => wp_create_nonce( 'sakura_s3_sync_test' ),
			'actionNonce'  => wp_create_nonce( 'sakura_s3_sync_action' ),
			'pageNonce'    => wp_create_nonce( 'sakura_s3_sync_page' ),
			'previewNonce' => wp_create_nonce( 'sakura_s3_sync_preview' ),
			'cancelNonce'  => wp_create_nonce( 'sakura_s3_sync_cancel' ),
			'resumeNonce'  => wp_create_nonce( 'sakura_s3_sync_resume' ),
			'refreshNonce' => wp_create_nonce( 'sakura_s3_sync_refresh' ),
			'toggleNonce'  => wp_create_nonce( 'sakura_s3_sync_toggle' ),
			'strings'      => array(
				'unknownError'    => __( '操作失败，请稍后重试。', 'sakura-imgopt' ),
				'scanning'        => __( '正在对账…', 'sakura-imgopt' ),
				'cancelling'      => __( '正在停止对账…', 'sakura-imgopt' ),
				'cancelled'       => __( '对账已停止，可随时继续。', 'sakura-imgopt' ),
				'resuming'        => __( '正在继续对账…', 'sakura-imgopt' ),
				'resumeMissing'   => __( '没有可继续的对账任务，请重新开始。', 'sakura-imgopt' ),
				'cancelFailed'    => __( '停止对账失败，请稍后重试。', 'sakura-imgopt' ),
				'refreshing'      => __( '正在重算差异…', 'sakura-imgopt' ),
				'refreshed'       => __( '差异已按现有索引重算（未列举云端）。', 'sakura-imgopt' ),
				'toggling'        => __( '正在切换索引状态…', 'sakura-imgopt' ),
				'retrying'        => __( '正在重试…', 'sakura-imgopt' ),
				'done'            => __( '对账完成。', 'sakura-imgopt' ),
				'retryDone'       => __( '重试完成。', 'sakura-imgopt' ),
				'uploading'       => __( '正在上传…', 'sakura-imgopt' ),
				'downloading'     => __( '正在下载…', 'sakura-imgopt' ),
				'deleting'        => __( '正在删除…', 'sakura-imgopt' ),
				'actionDone'      => __( '操作完成。', 'sakura-imgopt' ),
				'queued'          => __( '已加入队列，稍后自动刷新。', 'sakura-imgopt' ),
				/* translators: %d: selected item count */
				'batchSelected'   => __( '已选 %d 项', 'sakura-imgopt' ),
				'batchLimit'      => __( '最多选择 100 项', 'sakura-imgopt' ),
				'batchWorking'    => __( '正在处理', 'sakura-imgopt' ),
				'batchLeaveWarning' => __( '批量上传/下载仍在进行。离开本页会中断，已完成的项目会保留。', 'sakura-imgopt' ),
				'batchEmpty'      => __( '所选对象没有符合该操作的状态。', 'sakura-imgopt' ),
				/* translators: %d: number of items already on the remote */
				'batchReplace'    => __( '选中项中有 %d 个云端已存在，继续会覆盖远端文件。确认批量覆盖？', 'sakura-imgopt' ),
				'batchDeleteTitle' => __( '从云端永久删除这些对象？', 'sakura-imgopt' ),
				'batchDeleteDesc' => __( '将删除所选的全部云端对象，不可撤销；本地文件不会删除，但使用 CDN 的页面可能出现 404。', 'sakura-imgopt' ),
				/* translators: %d: number of objects */
				'batchDeleteConfirm' => __( '永久删除 %d 个对象', 'sakura-imgopt' ),
				'deleteTitle'     => __( '删除云端文件', 'sakura-imgopt' ),
				'deleteDesc'      => __( '将从云端永久删除该对象，不可撤销；本地文件不受影响。', 'sakura-imgopt' ),
				'deleteConfirm'   => __( '永久删除', 'sakura-imgopt' ),
				'replaceTitle'    => __( '覆盖云端文件', 'sakura-imgopt' ),
				'replaceDesc'     => __( '云端已存在同名文件，继续会上传本地文件并覆盖云端内容。', 'sakura-imgopt' ),
				'replaceConfirm'  => __( '覆盖并上传', 'sakura-imgopt' ),
				'adoptRunning'    => __( '正在补建媒体库记录…', 'sakura-imgopt' ),
				'adoptDone'       => __( '媒体库记录已补建。', 'sakura-imgopt' ),
				'testing'         => __( '测试中…', 'sakura-imgopt' ),
				'testDone'        => __( '连接正常。', 'sakura-imgopt' ),
				/* translators: 1: first item index, 2: last item index, 3: total items */
				'summary'         => __( '显示 %1$s–%2$s 项，共 %3$s 项', 'sakura-imgopt' ),
				/* translators: 1: first item index, 2: last item index, 3: total before filtering */
				'summaryFiltered' => __( '显示 %1$s–%2$s 项，筛选自 %3$s 项', 'sakura-imgopt' ),
				'pageError'       => __( '加载文件列表失败。', 'sakura-imgopt' ),
				'previewError'    => __( '加载预览失败。', 'sakura-imgopt' ),
				'logsEmpty'       => __( '暂无匹配日志。', 'sakura-imgopt' ),
				/* translators: %d: number of hidden log entries */
				'logsMore'        => __( '查询更多日志（还有 %d 条）', 'sakura-imgopt' ),
				'logsNoMore'      => __( '没有更多日志', 'sakura-imgopt' ),
			),
			'labels'       => array(
				'upload'   => __( '上传', 'sakura-imgopt' ),
				'reupload' => __( '重新上传', 'sakura-imgopt' ),
				'download' => __( '下载', 'sakura-imgopt' ),
				'delete'   => __( '删除远端', 'sakura-imgopt' ),
				'retry'    => __( '重试', 'sakura-imgopt' ),
				'detail'   => __( '详情', 'sakura-imgopt' ),
				'noaction' => __( '—', 'sakura-imgopt' ),
				'exists'   => __( '已存在', 'sakura-imgopt' ),
				'missing'  => __( '缺失', 'sakura-imgopt' ),
				'diffsize' => __( '内容不一致', 'sakura-imgopt' ),
			),
			'statusLabels' => array(
				'synced'   => __( '已同步', 'sakura-imgopt' ),
				'pending'  => __( '待上传', 'sakura-imgopt' ),
				'failed'   => __( '同步失败', 'sakura-imgopt' ),
				'remote'   => __( '仅云端', 'sakura-imgopt' ),
				'mismatch' => __( '内容不一致', 'sakura-imgopt' ),
			),
			'typeLabels'   => array(
				'image'   => __( '图片', 'sakura-imgopt' ),
				'video'   => __( '视频', 'sakura-imgopt' ),
				'archive' => __( '压缩包', 'sakura-imgopt' ),
				'other'   => __( '其他', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$data = $this->page_data();

		$snapshot = $data['snapshot'];
		$rows = $data['rows'];
		$counts = $data['counts'];
		$total = $data['total'];
		$failed = $data['failed'];
		$failed_total = $data['failed_total'];
		$jobs = $data['jobs'];
		$logs = $data['logs'];
		$options = $data['options'];
		$settings_url = $data['settings_url'];
		$action_nonce = $data['action_nonce'];
		$scan_nonce = $data['scan_nonce'];
		$page_nonce = $data['page_nonce'];
		$page_cursor = $data['page_cursor'];
		$page_more = $data['page_more'];
		$summary = $data['summary'];
		$scan = $data['scan'];
		$scan_id = $data['scan_id'];
		$scan_state = $data['scan_state'];
		$scan_phase = $data['scan_phase'];
		$scan_resumable = $data['scan_resumable'];
		$scan_busy = $data['scan_busy'];
		$retry_nonce = $data['retry_nonce'];
		$test_nonce = $data['test_nonce'];
		$export_url = $data['export_url'];
		$uploads = $data['uploads'];
		$is_configured = $data['is_configured'];
		$storage_enabled = $data['is_enabled'];
		$index_enabled = $data['index_enabled'];
		$legacy_error = $data['legacy_error'];
		$legacy_built = $data['legacy_built'];
		$metrics = $data['metrics'];
		$jobs_summary = $data['jobs_summary'];
		$job_rows = $data['job_rows'];
		$toggle_nonce = $data['toggle_nonce'];
		$refresh_nonce = $data['refresh_nonce'];

		// WP-Cron only advances on traffic, so a site that sat idle for a month
		// can land here before the monthly event ever ran. Queueing the same
		// calibration on page view closes that gap; the single event carries
		// WordPress' own ten-minute duplicate guard.
		if ( $this->is_calibration_stale( $data ) ) {
			Sync_Calibrator::schedule_soon();
		}

		require __DIR__ . '/../../includes/views/s3-sync-center.php';
	}

	/**
	 * Whether the stored cloud numbers are older than the calibration window.
	 *
	 * @param array<string,mixed> $data View data.
	 * @return bool
	 */
	private function is_calibration_stale( array $data ): bool {
		if ( empty( $data['index_enabled'] ) || empty( $data['is_enabled'] ) ) {
			return false;
		}

		$scan = is_object( $data['scan'] ) ? $data['scan'] : null;
		if ( ! $scan || in_array( (string) $scan->state, array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$scanned = (int) ( $data['snapshot']['scanned_at'] ?? 0 );
		if ( $scanned <= 0 ) {
			return false;
		}

		return ( time() - $scanned ) >= Sync_Calibrator::STALE_SECONDS;
	}

	/**
	 * How long one browser-driven tick may run before yielding to WP-Cron.
	 */
	private const AJAX_SECONDS = 12;

	/**
	 * Objects larger than this are queued instead of uploaded inline.
	 */
	private const QUEUE_THRESHOLD = 52428800;

	/**
	 * AJAX: start a fresh inventory scan.
	 */
	public function ajax_scan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		check_ajax_referer( 'sakura_s3_sync_scan', 'nonce' );

		$scan_id = $this->scanner->start();
		if ( is_wp_error( $scan_id ) ) {
			wp_send_json_error( array( 'message' => $scan_id->get_error_message(), 'code' => $scan_id->get_error_code() ), 409 );
		}
		$result = $this->scanner->tick( (int) $scan_id, 0, self::AJAX_SECONDS );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 500 );
		}
		$this->worker->schedule( (int) $scan_id, 2 );
		wp_send_json_success(
			array(
				'scan_id'  => (int) $scan_id,
				'state'    => $result['state'] ?? 'running',
				'phase'    => $result['phase'] ?? '',
				'busy'     => ! empty( $result['busy'] ),
				'progress' => (int) ( $result['progress'] ?? 0 ),
				'message'  => $result['message'] ?? '',
			)
		);
	}

	/**
	 * AJAX: advance one scan step.
	 */
	public function ajax_scan_step(): void {
		check_ajax_referer( 'sakura_s3_sync_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;
		$result  = $this->scanner->tick( $scan_id, 0, self::AJAX_SECONDS );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 500 );
		}
		$this->worker->schedule( $scan_id, 2 );
		$summary = isset( $result['summary'] ) ? (array) $result['summary'] : array();
		wp_send_json_success(
			array(
				'scan_id'    => $scan_id,
				'state'      => $result['state'] ?? 'running',
				'phase'      => $result['phase'] ?? '',
				'busy'       => ! empty( $result['busy'] ),
				'progress'   => (int) ( $result['progress'] ?? 0 ),
				'message'    => $result['message'] ?? '',
				'scanned_at' => (int) ( $summary['scanned_at'] ?? 0 ),
				'counts'     => $summary['counts'] ?? array(),
				'total'      => (int) ( $summary['total'] ?? 0 ),
				'resumable'  => ! empty( $summary['resumable'] ),
			)
		);
	}

	/**
	 * AJAX: request cancellation of the running scan.
	 */
	public function ajax_cancel(): void {
		check_ajax_referer( 'sakura_s3_sync_cancel', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;
		if ( $scan_id <= 0 || ! $this->index->cancel_scan( $scan_id ) ) {
			wp_send_json_error( array( 'message' => __( '该任务已结束，无需停止。', 'sakura-imgopt' ), 'code' => 'sio_not_cancellable' ), 409 );
		}
		$scan = $this->index->scan( $scan_id );
		wp_send_json_success(
			array(
				'scan_id' => $scan_id,
				'state'   => 'cancelled',
				'phase'   => $scan ? (string) $scan->phase : '',
				'message' => __( '已请求停止，当前批次结束后生效。', 'sakura-imgopt' ),
			)
		);
	}

	/**
	 * AJAX: resume the most recent interrupted scan.
	 */
	public function ajax_resume(): void {
		check_ajax_referer( 'sakura_s3_sync_resume', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		$scan_id = $this->scanner->resume();
		if ( is_wp_error( $scan_id ) ) {
			wp_send_json_error( array( 'message' => $scan_id->get_error_message(), 'code' => $scan_id->get_error_code() ), 409 );
		}
		$result = $this->scanner->tick( (int) $scan_id, 0, self::AJAX_SECONDS );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 500 );
		}
		$this->worker->schedule( (int) $scan_id, 2 );
		wp_send_json_success(
			array(
				'scan_id'  => (int) $scan_id,
				'state'    => $result['state'] ?? 'running',
				'phase'    => $result['phase'] ?? '',
				'busy'     => ! empty( $result['busy'] ),
				'progress' => (int) ( $result['progress'] ?? 0 ),
				'message'  => $result['message'] ?? '',
			)
		);
	}

	/**
	 * AJAX: recompute statuses from the existing index without listing S3.
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'sakura_s3_sync_refresh', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		if ( ! Feature::enabled() ) {
			wp_send_json_error( array( 'message' => __( '索引已停用，无法刷新差异。', 'sakura-imgopt' ) ), 409 );
		}
		$summary = $this->scanner->refresh();
		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( array( 'message' => $summary->get_error_message(), 'code' => $summary->get_error_code() ), 422 );
		}
		wp_send_json_success(
			array(
				'message' => __( '已按现有索引重算差异（未列举云端）。', 'sakura-imgopt' ),
				'counts'  => $summary['counts'],
				'total'   => (int) $summary['total'],
			)
		);
	}

	/**
	 * AJAX: enable or disable the persistent index (rollback switch).
	 */
	public function ajax_toggle_index(): void {
		check_ajax_referer( 'sakura_s3_sync_toggle', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );
		Feature::set( $enabled );
		Legacy_Snapshot::flush();
		Log::write( $enabled ? 'Sync index enabled.' : 'Sync index disabled (compat snapshot).' );
		wp_send_json_success(
			array(
				'enabled' => $enabled,
				'message' => $enabled ? __( '已启用持久索引。', 'sakura-imgopt' ) : __( '已停用持久索引，页面切换到兼容快照。', 'sakura-imgopt' ),
			)
		);
	}

	/**
	 * AJAX: remember that this operator never wants the dialog again.
	 *
	 * Closing the dialog any other way is deliberately not persisted: the
	 * prompt is the only thing on the screen that says a configuration is
	 * broken or a bucket stopped receiving files, so it has to keep asking
	 * until the condition is actually gone or the operator mutes it.
	 *
	 * Older cached scripts still post snooze=1; accepting and ignoring it keeps
	 * them from resurrecting the retired twelve-hour window.
	 */
	public function ajax_dismiss_config_notice(): void {
		check_ajax_referer( 'sakura_s3_config_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		$dismiss = isset( $_POST['dismiss'] ) && is_string( $_POST['dismiss'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['dismiss'] ) );

		$user_id = get_current_user_id();

		if ( ! $dismiss ) {
			delete_user_meta( $user_id, Config_Notice::SNOOZE_META_KEY );
			wp_send_json_success( array( 'dismissed' => false ) );
		}

		update_user_meta( $user_id, Config_Notice::META_KEY, '1' );
		delete_user_meta( $user_id, Config_Notice::SNOOZE_META_KEY );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Admin notice for failed scans, dead jobs and compat mode.
	 */
	public function admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		/*
		 * Both conditions below are failures, so every entry is rendered with the
		 * bad tone. Keep the list as plain messages: a tone column that is always
		 * the same value is the kind of thing a static analyser (rightly) flags as
		 * a dead branch.
		 */
		$notices = array();
		$profile = (string) $this->options->active_profile_id();
		if ( '' !== $profile ) {
			$scan = $this->index->latest_scan( $profile );
			if ( $scan && 'failed' === (string) $scan->state && ( time() - (int) strtotime( (string) $scan->updated_at . ' UTC' ) ) < DAY_IN_SECONDS ) {
				$notices[] = sprintf( /* translators: %s: error message. */ __( '最近一次对账失败：%s', 'sakura-imgopt' ), (string) $scan->error_message );
			}
		}
		$dead = $this->index->dead_jobs_count();
		if ( $dead > 0 ) {
			$notices[] = sprintf( /* translators: %d: dead jobs. */ __( '动作队列有 %d 个任务已标记 dead，请在任务中心处理。', 'sakura-imgopt' ), $dead );
		}

		$link = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );

		foreach ( $notices as $message ) {
			$html = \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html(
				esc_html( $message ),
				'bad',
				esc_url( $link ),
				esc_html__( '打开同步中心', 'sakura-imgopt' )
			);
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
		}
	}

	/**
	 * AJAX: keyset page for the reconciliation table.
	 */
	public function ajax_page(): void {
		check_ajax_referer( 'sakura_s3_sync_page', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		$profile = (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			wp_send_json_error( array( 'message' => __( '尚未选择 S3 配置。', 'sakura-imgopt' ) ), 400 );
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'all';
		$days   = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0;
		$cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '';
		$limit  = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 25;

		$page    = $this->index->page( $profile, $status, $search, $type, $days, $cursor, $limit );
		$summary = $this->index->summary( $profile );

		wp_send_json_success(
			array(
				'rows'           => $this->decorate_rows( $page['rows'] ),
				'cursor'         => $page['cursor'],
				'has_more'       => $page['has_more'],
				'counts'         => $summary['counts'],
				'total'          => $summary['total'],
				'filtered_total' => $page['matched'],
			)
		);
	}

	/**
	 * AJAX: full preview payload for one object.
	 *
	 * The fast list endpoint cannot build this: it runs under SHORTINIT, where
	 * the translation, URL and nonce helpers are unavailable, and signing a
	 * URL per row would slow down the whole page. The table therefore asks for
	 * one row's preview the moment a visitor opens the details dialog.
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'sakura_s3_sync_preview', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		$profile = (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			wp_send_json_error( array( 'message' => __( '尚未选择 S3 配置。', 'sakura-imgopt' ) ), 400 );
		}

		$key = $this->adapter->normalize_key( isset( $_POST['object_key'] ) ? (string) wp_unslash( $_POST['object_key'] ) : '' );
		if ( false === $key ) {
			wp_send_json_error( array( 'message' => __( '对象 Key 无效。', 'sakura-imgopt' ) ), 400 );
		}

		$row = $this->index->find_row( $profile, $key );
		if ( null === $row ) {
			wp_send_json_error( array( 'message' => __( '索引中没有找到这个文件，请重新对账后再试。', 'sakura-imgopt' ) ), 404 );
		}

		$rows = $this->decorate_rows( array( $row ) );

		wp_send_json_success( array( 'preview' => $rows[0]['preview'] ) );
	}

	/**
	 * Measure object-store connectivity with a HEAD bucket request.
	 */
	public function ajax_test(): void {
		check_ajax_referer( 'sakura_s3_sync_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		if ( ! $this->options->is_configured() ) {
			wp_send_json_error( array( 'message' => __( '请先保存完整的 S3 连接配置。', 'sakura-imgopt' ) ), 400 );
		}
		$options = $this->options->all();

		try {
			$client = Client_Factory::make( $options );
			// 凭据惰性取，制造客户端不等于凭据可用；同步中心的测试也要当面验一次。
			Client_Factory::preflight( $options );
		} catch ( \Throwable $e ) {
			Log::write( __( '同步中心连接测试失败：', 'sakura-imgopt' ) . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		/*
		 * Same probe as the settings-page test, so both screens explain a
		 * failure the same way instead of printing a generic line.
		 */
		$probe = Connection_Probe::diagnose(
			$client,
			(string) $options['bucket'],
			array( (string) ( $options['key'] ?? '' ), (string) ( $options['secret'] ?? '' ) )
		);

		if ( ! $probe['ok'] ) {
			Log::write( __( '同步中心连接测试失败：', 'sakura-imgopt' ) . $probe['message'] );
			wp_send_json_error( array( 'message' => $probe['message'], 'code' => $probe['code'] ), 500 );
		}

		wp_send_json_success(
			array(
				'latency' => $probe['latency'],
				'message' => '' !== $probe['message']
					? $probe['message']
					: sprintf( /* translators: %d: milliseconds. */ __( '连接正常，headBucket 耗时 %d ms。', 'sakura-imgopt' ), $probe['latency'] ),
			)
		);
	}

	/**
	 * AJAX: upload, download or delete one object from the current snapshot.
	 */
	public function ajax_action(): void {
		check_ajax_referer( 'sakura_s3_sync_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		if ( ! $this->options->is_enabled() || ! $this->options->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'S3 配置不完整，无法执行操作。', 'sakura-imgopt' ) ), 400 );
		}
		if ( ! Feature::enabled() ) {
			wp_send_json_error( array( 'message' => __( '持久索引已停用，无法执行行内动作。', 'sakura-imgopt' ) ), 409 );
		}

		$type    = isset( $_POST['sync_action'] ) ? sanitize_key( wp_unslash( $_POST['sync_action'] ) ) : '';
		$key     = isset( $_POST['object_key'] ) ? (string) wp_unslash( $_POST['object_key'] ) : '';
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		$keys    = array();
		if ( isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ) {
			foreach ( array_slice( array_map( 'strval', wp_unslash( $_POST['keys'] ) ), 0, 200 ) as $batch_key ) {
				$batch_key = ltrim( str_replace( '\\', '/', $batch_key ), '/' );
				if ( '' !== $batch_key ) {
					$keys[] = $batch_key;
				}
			}
		}
		$key     = ltrim( str_replace( '\\', '/', $key ), '/' );

		if ( ! in_array( $type, array( 'upload', 'download', 'delete', 'adopt' ), true ) || ( '' === $key && empty( $keys ) && 'adopt' !== $type ) ) {
			wp_send_json_error( array( 'message' => __( '操作参数不完整。', 'sakura-imgopt' ) ), 400 );
		}

		$profile = (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			wp_send_json_error( array( 'message' => __( '尚未选择 S3 配置。', 'sakura-imgopt' ) ), 400 );
		}

		if ( 'adopt' === $type ) {
			$seconds = isset( $_POST['seconds'] ) ? (float) wp_unslash( $_POST['seconds'] ) : 20.0;
			$result  = $this->adopt_missing( min( 60.0, max( 3.0, $seconds ) ) );

			$this->index->event(
				$profile,
				'info',
				'media_adopt',
				sprintf(
					/* translators: 1: restored attachments, 2: skipped, 3: failed. */
					__( '媒体库核对：恢复 %1$d，跳过 %2$d，失败 %3$d。', 'sakura-imgopt' ),
					$result['adopted'],
					$result['skipped'],
					$result['failed']
				),
				array(
					'adopted'   => $result['adopted'],
					'remaining' => $result['remaining'],
				)
			);

			$result['message'] = $this->adopt_message( $result );
			$result['refresh'] = (bool) $result['done'];
			wp_send_json_success( $result );
		}

		if ( ! empty( $keys ) ) {
			if ( 'delete' === $type && 'DELETE' !== $confirm ) {
				wp_send_json_error( array( 'message' => __( '批量删除需要输入 DELETE 二次确认。', 'sakura-imgopt' ) ), 400 );
			}

			$eligible = array();
			$skipped  = 0;
			$replace  = 0;

			foreach ( $keys as $batch_key ) {
				$batch_row = $this->index->find( $profile, $batch_key );
				if ( ! is_array( $batch_row ) ) {
					++$skipped;
					continue;
				}
				$has_local = ! empty( $batch_row['seen_local'] );
				$has_cloud = ! empty( $batch_row['seen_remote'] );
				if ( 'upload' === $type && ! $has_local ) {
					++$skipped;
					continue;
				}
				if ( 'upload' === $type && $has_cloud ) {
					++$replace;
				}
				if ( 'download' === $type && ( ! $has_cloud || $has_local ) ) {
					++$skipped;
					continue;
				}
				if ( 'delete' === $type && ! $has_cloud ) {
					++$skipped;
					continue;
				}
				$eligible[] = $batch_key;
			}

			if ( 'upload' === $type && $replace > 0 && 'REPLACE' !== $confirm ) {
				wp_send_json_error( array( 'message' => __( '选中项中有云端已存在的对象；批量覆盖需要再次确认。', 'sakura-imgopt' ) ), 409 );
			}

			if ( 'upload' === $type ) {
				$offset  = isset( $_POST['offset'] ) ? max( 0, (int) wp_unslash( $_POST['offset'] ) ) : 0;
				$seconds = isset( $_POST['seconds'] ) ? (float) wp_unslash( $_POST['seconds'] ) : 20.0;
				$result  = $this->upload_keys( $eligible, $offset, min( 60.0, max( 3.0, $seconds ) ) );

				$this->index->event(
					$profile,
					'info',
					'action_batch_upload',
					sprintf(
						/* translators: 1: uploaded attachments, 2: failed, 3: skipped. */
						__( '批量上传：成功 %1$d，失败 %2$d，跳过 %3$d。', 'sakura-imgopt' ),
						$result['uploaded'],
						$result['failed'],
						$result['skipped']
					),
					array(
						'offset' => $result['offset'],
						'total'  => $result['total'],
						'failed' => $result['failed'],
					)
				);

				$result['message'] = $this->upload_message( $result );
				$result['refresh'] = (bool) $result['done'];
				wp_send_json_success( $result );
			}

			if ( 'download' === $type ) {
				$offset  = isset( $_POST['offset'] ) ? max( 0, (int) wp_unslash( $_POST['offset'] ) ) : 0;
				$seconds = isset( $_POST['seconds'] ) ? (float) wp_unslash( $_POST['seconds'] ) : 20.0;
				$result  = $this->download_keys( $eligible, $offset, min( 60.0, max( 3.0, $seconds ) ) );

				$this->index->event(
					$profile,
					'info',
					'action_batch_download',
					sprintf(
						/* translators: 1: downloaded objects, 2: failed, 3: skipped. */
						__( '批量下载：成功 %1$d，失败 %2$d，跳过 %3$d。', 'sakura-imgopt' ),
						$result['downloaded'],
						$result['failed'],
						$result['skipped']
					),
					array(
						'offset' => $result['offset'],
						'total'  => $result['total'],
						'failed' => $result['failed'],
					)
				);

				$result['message'] = $this->download_message( $result );
				$result['refresh'] = (bool) $result['done'];
				wp_send_json_success( $result );
			}

			$queued = 0;
			foreach ( $eligible as $batch_key ) {
				$job = $this->index->enqueue_job( $profile, $batch_key, $type, $queued, $this->adapter->attachment_id_for_key( $batch_key ), \Sakura\ImageOptimizer\S3\Synchronous_Uploader::target_hash_for( $this->options ) );
				if ( ! is_wp_error( $job ) ) {
					++$queued;
				}
			}
			if ( 0 === $queued ) {
				wp_send_json_error( array( 'message' => __( '没有可入队的对象。', 'sakura-imgopt' ) ), 400 );
			}

			Action_Worker::schedule( 15 );
			$this->index->event(
				$profile,
				// Upload and download return above; only destructive deletes reach the queue branch.
				'warning',
				'action_batch_' . $type,
				sprintf(
					/* translators: 1: queued jobs, 2: action name. */
					__( '批量%2$s：%1$d 个对象已入队。', 'sakura-imgopt' ),
					$queued,
					$type
				),
				array( 'action' => $type, 'queued' => $queued, 'skipped' => $skipped )
			);
			wp_send_json_success(
				array(
					'queued'  => $queued,
					'skipped' => $skipped,
					'message' => $skipped > 0
						? sprintf( /* translators: 1: queued, 2: skipped. */ __( '已将 %1$d 个对象加入后台队列，%2$d 个不符合条件已跳过。', 'sakura-imgopt' ), $queued, $skipped )
						: sprintf( /* translators: %d: jobs queued. */ __( '已将 %d 个对象加入后台队列。', 'sakura-imgopt' ), $queued ),
					'refresh' => true,
				)
			);
		}
		$row = $this->index->find( $profile, $key );
		if ( ! is_array( $row ) ) {
			wp_send_json_error( array( 'message' => __( '该对象不在当前对账索引中，请重新对账后再操作。', 'sakura-imgopt' ) ), 409 );
		}
		$row['local']      = (bool) $row['seen_local'];
		$row['cloud']      = (bool) $row['seen_remote'];
		$row['size']       = (int) $row['local_size'];
		$row['cloud_size'] = (int) $row['remote_size'];

		if ( 'upload' === $type && 0 === (int) $this->adapter->attachment_id_for_key( $key ) && (int) $row['local_size'] > self::QUEUE_THRESHOLD ) {
			$job = $this->index->enqueue_job( $profile, $key, $type, 0, $this->adapter->attachment_id_for_key( $key ), \Sakura\ImageOptimizer\S3\Synchronous_Uploader::target_hash_for( $this->options ) );
			if ( is_wp_error( $job ) ) {
				wp_send_json_error( array( 'message' => $job->get_error_message(), 'code' => $job->get_error_code() ), 422 );
			}
			Action_Worker::schedule( 15 );
			$this->index->event( $profile, 'info', 'action_queued', __( '大文件已加入后台上传队列。', 'sakura-imgopt' ), array( 'key' => $key, 'job' => (int) $job ) );
			wp_send_json_success(
				array(
					'queued'  => true,
					'message' => __( '文件超过 50 MB，已加入后台队列，完成后刷新即可看到结果。', 'sakura-imgopt' ),
					'refresh' => true,
				)
			);
		}

		$actions = new Sync_Actions( $this->options, $this->adapter );
		if ( 'upload' === $type ) {
			if ( empty( $row['local'] ) ) {
				wp_send_json_error( array( 'message' => __( '本地没有该文件，无法上传。', 'sakura-imgopt' ) ), 400 );
			}
			if ( ! empty( $row['cloud'] ) && 'REPLACE' !== $confirm ) {
				wp_send_json_error( array( 'message' => __( '云端已存在该对象。重新上传会覆盖远端文件，请确认后再试。', 'sakura-imgopt' ) ), 409 );
			}
			$attachment = (int) $this->adapter->attachment_id_for_key( $key );
			if ( $attachment > 0 ) {
				$uploader = new Synchronous_Uploader( $this->options, $this->adapter, $this->status );
				$result   = $uploader->finalize( $attachment, null, true, true );

				if ( 'failed' === (string) ( $result['status'] ?? '' ) ) {
					$result = new \WP_Error(
						'sio_upload_failed',
						(string) ( $result['message'] ?? '' ) !== '' ? (string) $result['message'] : __( '附件上传失败，请查看同步日志。', 'sakura-imgopt' )
					);
				}
				$done = __( '附件及全部尺寸已上传并校验。', 'sakura-imgopt' );
			} else {
				$result = $actions->upload( $key );
				$done   = __( '对象已上传到云端。', 'sakura-imgopt' );
			}
		} elseif ( 'download' === $type ) {
			if ( empty( $row['cloud'] ) || ! empty( $row['local'] ) ) {
				wp_send_json_error( array( 'message' => __( '该对象不是“仅云端”状态，无需下载。', 'sakura-imgopt' ) ), 400 );
			}
			$result = $actions->download( $key );
			$done   = __( '对象已下载到本地。', 'sakura-imgopt' );
		} else {
			if ( empty( $row['cloud'] ) ) {
				wp_send_json_error( array( 'message' => __( '云端没有该对象，无法删除。', 'sakura-imgopt' ) ), 400 );
			}
			if ( 'DELETE' !== $confirm ) {
				wp_send_json_error( array( 'message' => __( '删除远端对象需要二次确认。', 'sakura-imgopt' ) ), 400 );
			}
			$result = $actions->delete( $key );
			$done   = __( '云端对象已删除。', 'sakura-imgopt' );
		}

		if ( 'delete' === $type ) {
			$this->index->event( $profile, 'warning', 'confirm', __( '已确认删除远端对象。', 'sakura-imgopt' ), array( 'key' => $key ) );
		}

		if ( is_wp_error( $result ) ) {
			Metrics::bump( array( 'actions_failed' => 1 ) );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 422 );
		}

		Metrics::bump( array( 'actions_ok' => 1 ) );
		$this->index->apply_action( $profile, $key, $type );

		// 行内上传与大文件队列走同一套附件状态回写，避免同一个动作出现
		// “媒体库仍显示未同步、之后又被重传一遍”的分叉（同步中心-提交上传）。
		if ( 'upload' === $type ) {
			$attachment_id = (int) $this->adapter->attachment_id_for_key( $key );
			if ( $attachment_id > 0 ) {
				( new Action_Worker( $this->options, $this->adapter, $this->index ) )->write_back(
					$attachment_id,
					$key,
					'upload',
					array( 'options' => $this->options, 'adapter' => $this->adapter ),
					\Sakura\ImageOptimizer\S3\Synchronous_Uploader::target_hash_for( $this->options ),
					$profile
				);
			}
		}

		$this->index->event( $profile, 'info', 'action_' . $type, $done, array( 'key' => $key ) );
		Log::write( sprintf( '%s [%s]', $done, $key ) );
		wp_send_json_success( array( 'message' => $done, 'refresh' => true ) );
	}

	/**
	 * Stream the current difference table as CSV.
	 */
	/**
	 * Upload the attachments behind a list of object keys.
	 *
	 * Each attachment goes through Synchronous_Uploader::finalize(), the same
	 * path the historical migration uses: every registered size is uploaded and
	 * verified, and the attachment status is written back. Keys that do not
	 * belong to an attachment fall back to a single-object upload.
	 *
	 * @param string[] $keys        Selected object keys.
	 * @param int      $offset      Item offset, for resumable browser loops.
	 * @param float    $max_seconds Time budget for this request.
	 * @return array<string,mixed>
	 */
	private function upload_keys( array $keys, int $offset, float $max_seconds ): array {
		$items = array();
		$seen  = array();

		foreach ( $keys as $key ) {
			$key        = (string) $key;
			$attachment = (int) $this->adapter->attachment_id_for_key( $key );
			$token      = $attachment > 0 ? 'a:' . $attachment : 'k:' . $key;

			if ( isset( $seen[ $token ] ) ) {
				continue;
			}

			$seen[ $token ] = true;
			$items[]        = array(
				'key'        => $key,
				'attachment' => $attachment,
			);
		}

		$total    = count( $items );
		$offset   = max( 0, min( $offset, $total ) );
		$deadline = microtime( true ) + $max_seconds;
		$uploaded = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();
		$uploader = new Synchronous_Uploader( $this->options, $this->adapter, $this->status );
		$actions  = null;

		for ( $i = $offset; $i < $total; $i++ ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$item = $items[ $i ];

			if ( (int) $item['attachment'] > 0 ) {
				$result = $uploader->finalize( (int) $item['attachment'], null, true, true );
				$status = (string) ( $result['status'] ?? '' );

				if ( 'synced' === $status ) {
					++$uploaded;
				} elseif ( 'skipped' === $status ) {
					++$skipped;
				} else {
					++$failed;
					if ( count( $errors ) < 3 ) {
						$errors[] = sprintf(
							/* translators: 1: attachment ID, 2: error message. */
							__( '附件 #%1$d：%2$s', 'sakura-imgopt' ),
							(int) $item['attachment'],
							(string) ( $result['message'] ?? '' )
						);
					}
				}
			} else {
				if ( null === $actions ) {
					$actions = new Sync_Actions( $this->options, $this->adapter );
				}

				$result = $actions->upload( (string) $item['key'] );

				if ( is_wp_error( $result ) ) {
					++$failed;
					if ( count( $errors ) < 3 ) {
						$errors[] = (string) $item['key'] . '：' . $result->get_error_message();
					}
				} else {
					++$uploaded;
				}
			}

			$offset = $i + 1;
		}

		return array(
			'done'     => $offset >= $total,
			'offset'   => $offset,
			'total'    => $total,
			'uploaded' => $uploaded,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'errors'   => $errors,
		);
	}

	/**
	 * Human readable progress for the browser loop.
	 *
	 * @param array<string,mixed> $result Upload result.
	 */
	private function upload_message( array $result ): string {
		if ( ! empty( $result['done'] ) ) {
			$message = sprintf(
				/* translators: 1: uploaded, 2: failed, 3: skipped. */
				__( '批量上传完成：成功 %1$d 项，失败 %2$d 项，跳过 %3$d 项。', 'sakura-imgopt' ),
				(int) $result['uploaded'],
				(int) $result['failed'],
				(int) $result['skipped']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . implode( '；', array_map( 'strval', (array) $result['errors'] ) );
			}

			return $message;
		}

		return sprintf(
			/* translators: 1: processed, 2: total. */
			__( '正在上传：已处理 %1$d / %2$d，继续中…', 'sakura-imgopt' ),
			(int) $result['offset'],
			(int) $result['total']
		);
	}

	/**
	 * Restore media-library rows for indexed local files that have none.
	 *
	 * Each pass adopts the first batch of unattached files and stops at a time
	 * budget; the browser calls again until nothing adoptable is left. Files the
	 * media library cannot represent (unknown mime, unreadable) stay listed and
	 * are reported as skipped instead of being retried forever.
	 *
	 * @param float $max_seconds Time budget for this request.
	 * @return array<string,mixed>
	 */
	private function adopt_missing( float $max_seconds ): array {
		$adopter  = new \Sakura\ImageOptimizer\Sync\Attachment_Adopter();
		$total    = $adopter->count_missing();
		$deadline = microtime( true ) + $max_seconds;
		$adopted  = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();
		$seen     = array();

		while ( microtime( true ) < $deadline ) {
			$batch = $adopter->missing( 50 );
			if ( empty( $batch ) ) {
				break;
			}

			$progress = false;
			foreach ( $batch as $relative ) {
				$adopt_key = 'wp-content/uploads/' . $relative;
				if ( isset( $seen[ $adopt_key ] ) ) {
					continue;
				}
				$seen[ $adopt_key ] = true;

				if ( ! $adopter->can_adopt( $adopt_key ) ) {
					++$skipped;
					continue;
				}

				$result = $adopter->adopt( $adopt_key, get_current_user_id() );
				if ( is_wp_error( $result ) ) {
					++$failed;
					if ( count( $errors ) < 3 ) {
						$errors[] = $relative . '：' . $result->get_error_message();
					}
					continue;
				}

				++$adopted;
				$progress = true;

				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
			}

			if ( ! $progress ) {
				break;
			}
		}

		$remaining = $adopter->count_missing();

		return array(
			'done'      => 0 === $remaining,
			'total'     => $total,
			'adopted'   => $adopted,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'remaining' => $remaining,
			'errors'    => $errors,
		);
	}

	/**
	 * Human-readable summary for one adopt pass.
	 *
	 * @param array<string,mixed> $result Adopt result.
	 */
	private function adopt_message( array $result ): string {
		if ( ! empty( $result['done'] ) ) {
			$message = sprintf(
				/* translators: 1: restored attachments, 2: skipped, 3: failed. */
				__( '媒体库核对完成：恢复 %1$d 个附件，跳过 %2$d 个（类型不支持或文件不可读），失败 %3$d 个。', 'sakura-imgopt' ),
				(int) $result['adopted'],
				(int) $result['skipped'],
				(int) $result['failed']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . implode( '；', array_map( 'strval', (array) $result['errors'] ) );
			}

			return $message;
		}

		return sprintf(
			/* translators: 1: files left, 2: total files to check. */
			__( '正在核对媒体库：剩余 %1$d / %2$d，继续中…', 'sakura-imgopt' ),
			(int) $result['remaining'],
			(int) $result['total']
		);
	}

	/**
	 * Download the selected cloud-only objects, resumable by offset.
	 *
	 * Downloading is a per-object HTTP copy, so a large selection can take
	 * longer than one request. Every request stops at a time budget and the
	 * browser resumes from the returned offset.
	 *
	 * @param string[] $keys        Selected object keys.
	 * @param int      $offset      Item offset, for resumable browser loops.
	 * @param float    $max_seconds Time budget for this request.
	 * @return array<string,mixed>
	 */
	private function download_keys( array $keys, int $offset, float $max_seconds ): array {
		$keys     = array_values( array_map( 'strval', $keys ) );
		$total    = count( $keys );
		$offset   = max( 0, min( $offset, $total ) );
		$deadline = microtime( true ) + $max_seconds;
		$saved    = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();
		$actions  = new Sync_Actions( $this->options, $this->adapter );

		for ( $i = $offset; $i < $total; $i++ ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$key    = $keys[ $i ];
			$result = $actions->download( $key );

			if ( is_wp_error( $result ) ) {
				if ( 'sakura_s3_local_exists' === $result->get_error_code() ) {
					++$skipped;
				} else {
					++$failed;
					if ( count( $errors ) < 3 ) {
						$errors[] = $key . '：' . $result->get_error_message();
					}
				}
			} else {
				++$saved;
			}

			$offset = $i + 1;
		}

		return array(
			'done'       => $offset >= $total,
			'offset'     => $offset,
			'total'      => $total,
			'downloaded' => $saved,
			'skipped'    => $skipped,
			'failed'     => $failed,
			'errors'     => $errors,
		);
	}

	/**
	 * Human readable progress for the download loop.
	 *
	 * @param array<string,mixed> $result Download result.
	 */
	private function download_message( array $result ): string {
		if ( ! empty( $result['done'] ) ) {
			$message = sprintf(
				/* translators: 1: downloaded, 2: failed, 3: skipped. */
				__( '批量下载完成：成功 %1$d 项，失败 %2$d 项，跳过 %3$d 项。', 'sakura-imgopt' ),
				(int) $result['downloaded'],
				(int) $result['failed'],
				(int) $result['skipped']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . implode( '；', array_map( 'strval', (array) $result['errors'] ) );
			}

			return $message;
		}

		return sprintf(
			/* translators: 1: processed, 2: total. */
			__( '正在下载：已处理 %1$d / %2$d，继续中…', 'sakura-imgopt' ),
			(int) $result['offset'],
			(int) $result['total']
		);
	}

	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_sync_export' );

		$profile = (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			wp_die( esc_html__( '尚未选择 S3 配置。', 'sakura-imgopt' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="s3-sync-diff-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all';
		$days   = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 0;

		$output = fopen( 'php://output', 'wb' );
		fputcsv( $output, array( 'object_key', 'path', 'type', 'local_bytes', 'cloud_bytes', 'status', 'error' ) );

		$cursor    = '';
		$guard     = 0;
		$limit     = 200000;
		$truncated = false;

		do {
			$page = $this->index->page( $profile, $status, $search, $type, $days, $cursor, 500 );
			foreach ( $page['rows'] as $row ) {
				fputcsv(
					$output,
					array(
						$this->csv_cell( (string) ( $row['key'] ?? '' ) ),
						$this->csv_cell( (string) ( $row['path'] ?? '' ) ),
						$this->csv_cell( (string) ( $row['type'] ?? '' ) ),
						(int) ( $row['size'] ?? 0 ),
						(int) ( $row['cloud_size'] ?? 0 ),
						$this->csv_cell( (string) ( $row['status'] ?? '' ) ),
						$this->csv_cell( (string) ( $row['error'] ?? '' ) ),
					)
				);
			}
			$cursor = (string) $page['cursor'];
			$guard += count( $page['rows'] );
		} while ( '' !== $cursor && $guard < $limit );

		// A silent cap produced a file that looks complete but is missing rows
		// (audit B13). Say so in the file itself, where the reader will see it.
		if ( '' !== $cursor ) {
			$truncated = true;
		}

		if ( $truncated ) {
			fputcsv(
				$output,
				array(
					'#truncated',
					'',
					'',
					0,
					0,
					'warning',
					sprintf( 'export stopped after %d rows; narrow the filters to see the rest', $guard ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Neutralise CSV formula injection in one cell (audit F-04).
	 *
	 * A leading = + - @ (or tab / carriage return) makes spreadsheet software
	 * treat the cell as a formula; prefixing an apostrophe keeps the text.
	 *
	 * @param string $value Raw cell.
	 * @return string
	 */
	private function csv_cell( string $value ): string {
		if ( '' !== $value && false !== strpbrk( $value[0], "=+-@\t\r" ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Stream the current row to the browser as an attachment.
	 *
	 * Local files are served by PHP so the request stays authenticated. Cloud
	 * objects are downloaded through a short-lived signed URL, which keeps the
	 * bytes out of PHP memory and lets the browser resume large transfers.
	 */
	public function download_media(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_media_download' );

		$raw_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$key     = $this->adapter->normalize_key( $raw_key );
		$profile = (string) $this->options->active_profile_id();
		if ( false === $key || '' === $key || '' === $profile ) {
			wp_die( esc_html__( '文件不存在或尚未选择 S3 配置。', 'sakura-imgopt' ), 404 );
		}

		$row = $this->index->find( $profile, $key );
		if ( ! is_array( $row ) ) {
			if ( Feature::enabled() ) {
				wp_die( esc_html__( '文件不存在或已被移动，请重新核对。', 'sakura-imgopt' ), 404 );
			}
			// Legacy snapshots have no index row; resolve the key directly.
			$row = array(
				'object_key' => $key,
				'local_path' => $key,
				'seen_local' => 1,
				'seen_remote' => 1,
			);
		}

		$name     = wp_basename( (string) ( $row['object_key'] ?? $key ) );
		$filetype = wp_check_filetype( $name );
		$mime     = (string) ( $filetype['type'] ?? '' );
		$local    = ! empty( $row['seen_local'] );

		if ( $local ) {
			$file = $this->local_media_path( $key, (string) ( $row['local_path'] ?? '' ) );
			if ( '' !== $file ) {
				nocache_headers();
				header( 'Content-Type: ' . ( '' !== $mime ? $mime : 'application/octet-stream' ) );
				header( 'Content-Length: ' . (string) filesize( $file ) );
				header( 'Content-Disposition: ' . $this->attachment_disposition( $name ) );
				header( 'X-Content-Type-Options: nosniff' );
				readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams an already validated uploads file.
				exit;
			}
		}

		if ( ! empty( $row['seen_remote'] ) ) {
			$url = $this->adapter->presigned_url( $key, '+10 minutes', $this->attachment_disposition( $name ), $mime );
			if ( '' !== $url ) {
				wp_redirect( $url, 302, 'Sakura Tools' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Signed URL is generated from the configured bucket.
				exit;
			}
		}

		wp_die( esc_html__( '本地与云端都找不到这个文件，请重新核对后再试。', 'sakura-imgopt' ), 404 );
	}

	/**
	 * Add browser preview and download metadata to index rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Index rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_rows( array $rows ): array {
		$options     = (array) $this->options->all();
		$upload_path = trim( (string) $this->options->upload_path(), '/' );
		$uploads     = wp_upload_dir( null, false );
		$upload_dir  = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( '' !== $upload_dir && 0 === strpos( $upload_dir, $content_dir ) ) {
			$local_base = untrailingslashit( content_url( substr( $upload_dir, strlen( $content_dir ) ) ) );
		} else {
			$local_base = untrailingslashit( content_url( '/uploads' ) );
		}
		$cloud_base  = untrailingslashit( (string) ( $options['upload_url_path'] ?? '' ) );
		$public      = 'public-read' === $this->options->object_acl();

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['preview'] = $this->preview_payload( $row, $upload_path, $local_base, $cloud_base, $public );
		}

		return $rows;
	}

	/**
	 * Build the payload consumed by admin.ui.mediaPreview().
	 *
	 * @param array<string,mixed> $row         Index row.
	 * @param string              $upload_path Object key prefix.
	 * @param string              $local_base  Local uploads URL base.
	 * @param string              $cloud_base  Public CDN URL base.
	 * @param bool                $is_public   Whether objects are public-read.
	 * @return array<string,mixed>
	 */
	private function preview_payload( array $row, string $upload_path, string $local_base, string $cloud_base, bool $is_public ): array {
		$key      = (string) ( $row['key'] ?? '' );
		$name     = (string) ( $row['name'] ?? wp_basename( $key ) );
		$path     = (string) ( $row['path'] ?? $key );
		$kind     = (string) ( $row['type'] ?? Kind::of( $key ) );
		$status   = (string) ( $row['status'] ?? 'synced' );
		$local    = ! empty( $row['local'] );
		$cloud    = ! empty( $row['cloud'] );
		$mtime    = (int) ( $row['mtime'] ?? 0 );
		$filetype = wp_check_filetype( $name );
		$mime     = (string) ( $filetype['type'] ?? '' );
		$size     = $local ? (int) ( $row['size'] ?? 0 ) : (int) ( $row['cloud_size'] ?? 0 );
		$size     = $size > 0 ? $size : max( (int) ( $row['size'] ?? 0 ), (int) ( $row['cloud_size'] ?? 0 ) );

		$url = '';
		if ( in_array( $kind, array( 'image', 'video' ), true ) ) {
			// A confirmed public object is served from the bucket, so the preview has
			// to show the CDN address the media library hands out. Falling back to
			// the local copy first hid a working CDN behind the site domain and made
			// the row look unsynced after a profile switch.
			if ( $cloud && $is_public && '' !== $cloud_base && 'synced' === $status ) {
				$url = $this->cloud_media_url( $key, $upload_path, $cloud_base );
			}
			if ( '' === $url && $local ) {
				$url = $this->local_media_url( $key, $upload_path, $local_base );
			}
			if ( '' === $url && $cloud ) {
				$url = $this->adapter->presigned_url( $key, '+1 hour', '', $mime );
			}
		}

		$kind_labels = array(
			'image'   => __( '图片', 'sakura-imgopt' ),
			'video'   => __( '视频', 'sakura-imgopt' ),
			'archive' => __( '压缩包', 'sakura-imgopt' ),
			'other'   => __( '文件', 'sakura-imgopt' ),
		);
		$status_labels = array(
			'synced'   => __( '已同步', 'sakura-imgopt' ),
			'pending'  => __( '待上传', 'sakura-imgopt' ),
			'failed'   => __( '同步失败', 'sakura-imgopt' ),
			'remote'   => __( '仅云端', 'sakura-imgopt' ),
			'mismatch' => __( '内容不一致', 'sakura-imgopt' ),
		);

		return array(
			'key'          => $key,
			'name'         => $name,
			'path'         => '' !== $key ? $key : $path,
			'kind'         => $kind,
			'kind_label'   => $kind_labels[ $kind ] ?? $kind_labels['other'],
			'mime'         => $mime,
			'ext'          => strtoupper( (string) pathinfo( $name, PATHINFO_EXTENSION ) ),
			'status'       => $status,
			'status_label' => $status_labels[ $status ] ?? $status,
			'size'         => $size,
			'size_label'   => Inventory::format_bytes( $size ),
			'mtime'        => $mtime,
			'mtime_label'  => $mtime ? wp_date( 'Y/m/d H:i', $mtime ) : '—',
			'local'        => $local,
			'cloud'        => $cloud,
			'url'          => $url,
			'download'     => wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'sakura_s3_media_download',
						'key'    => $key,
					),
					admin_url( 'admin-post.php' )
				),
				'sakura_s3_media_download'
			),
		);
	}

	/**
	 * Resolve an object key to its local uploads URL.
	 *
	 * @param string $key         Object key.
	 * @param string $upload_path Object key prefix.
	 * @param string $base        Local uploads URL base.
	 */
	private function local_media_url( string $key, string $upload_path, string $base ): string {
		if ( '' === $base ) {
			return '';
		}

		$relative = $this->relative_media_path( $key, $upload_path );
		if ( '' === $relative ) {
			return '';
		}

		return trailingslashit( $base ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * Resolve an object key to its public CDN URL.
	 *
	 * @param string $key         Object key.
	 * @param string $upload_path Object key prefix.
	 * @param string $base        Public URL base.
	 */
	private function cloud_media_url( string $key, string $upload_path, string $base ): string {
		if ( '' === $base ) {
			return '';
		}

		$relative = $this->relative_media_path( $key, $upload_path );
		if ( '' === $relative ) {
			return '';
		}

		return trailingslashit( $base ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * Strip the configured uploads prefix from an object key.
	 *
	 * @param string $key         Object key.
	 * @param string $upload_path Object key prefix.
	 */
	private function relative_media_path( string $key, string $upload_path ): string {
		$key = ltrim( $key, '/' );
		if ( '' === $key ) {
			return '';
		}
		if ( '' !== $upload_path && ( $key === $upload_path || 0 === strpos( $key, $upload_path . '/' ) ) ) {
			return ltrim( substr( $key, strlen( $upload_path ) ), '/' );
		}

		return $key;
	}

	/**
	 * Resolve a row's local filesystem path, restricted to the uploads tree.
	 *
	 * @param string $key        Object key.
	 * @param string $local_path Stored local path.
	 */
	private function local_media_path( string $key, string $local_path ): string {
		$path = '' !== $local_path ? $local_path : $key;
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ) );
		if ( '' === $base ) {
			return '';
		}

		if ( '/' !== $path[0] ) {
			$prefix = trim( (string) $this->options->upload_path(), '/' );
			if ( '' !== $prefix && 0 === strpos( $path, $prefix . '/' ) ) {
				$path = substr( $path, strlen( $prefix ) + 1 );
			}
			$path = $base . ltrim( $path, '/' );
		}

		$real = realpath( $path );
		$root = realpath( $base );
		if ( false === $real || false === $root || 0 !== strpos( $real, untrailingslashit( $root ) . '/' ) ) {
			return '';
		}

		return is_file( $real ) && is_readable( $real ) ? $real : '';
	}

	/**
	 * Build a safe attachment disposition for a possibly non-ASCII name.
	 *
	 * @param string $name File name.
	 */
	private function attachment_disposition( string $name ): string {
		$name     = str_replace( array( "\r", "\n" ), '', $name );
		$fallback = (string) preg_replace( '/[^\x20-\x7E]/', '_', $name );
		$fallback = str_replace( array( '\\', '"' ), '_', $fallback );
		if ( '' === $fallback ) {
			$fallback = 'download';
		}

		return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}

	/**
	 * Build view data from the cached snapshot and live sync state.
	 *
	 * @return array<string,mixed>
	 */
	private function view_data(): array {
		$uploads = wp_upload_dir( null, false );
		$failed  = array();
		$jobs    = 0;

		foreach ( $this->status->failed_attachments( 20 ) as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$attachment_jobs = $this->status->jobs( $attachment_id );
			$jobs += count( $attachment_jobs );
			$failed[] = array(
				'id'       => $attachment_id,
				'title'    => get_the_title( $attachment_id ),
				'edit'     => get_edit_post_link( $attachment_id, 'raw' ),
				'status'   => $this->status->label( $attachment_id ),
				'error'    => $this->status->error( $attachment_id ),
				'modified' => (int) get_post_modified_time( 'U', true, $attachment_id ),
				'jobs'     => count( $attachment_jobs ),
			);
		}

		$profile       = (string) $this->options->active_profile_id();
		$index_enabled = Feature::enabled();
		$legacy_error  = '';
		$legacy_built  = 0;

		if ( $index_enabled ) {
			$summary = $this->index->summary( $profile );
			$counts  = $summary['counts'];
			$total   = (int) $summary['total'];
			$page    = $this->index->page( $profile, 'all', '', 'all', 0, '', 25 );
			$rows    = $page['rows'];
			$events  = $this->index->events( $profile, 100 );

			$scan       = is_object( $summary['scan'] ) ? $summary['scan'] : null;
			$scan_id    = $scan ? (int) $scan->id : 0;
			$scan_state = (string) ( $summary['scan_state'] ?? '' );
			$scan_phase = (string) ( $summary['scan_phase'] ?? '' );

			$snapshot = array(
				'local'      => array(
					'files' => (int) $summary['local_files'],
					'bytes' => (int) $summary['local_bytes'],
				),
				'cloud'      => array(
					'objects' => (int) $summary['cloud_objects'],
					'bytes'   => (int) $summary['cloud_bytes'],
					'types'   => $summary['cloud_types'],
				),
				'counts'     => $counts,
				'total'      => $total,
				'scanned_at' => (int) $summary['scanned_at'],
				'scan'       => $scan,
			);
		} else {
			$legacy       = ( new Legacy_Snapshot( $this->options, $this->adapter ) )->get();
			$legacy_error = (string) ( $legacy['error'] ?? '' );
			$legacy_built = (int) ( $legacy['built_at'] ?? 0 );
			$counts       = (array) $legacy['counts'];
			$total        = (int) $legacy['total'];
			$rows         = (array) $legacy['rows'];
			$events       = array();
			$page         = array(
				'cursor'   => '',
				'has_more' => false,
			);
			$scan        = null;
			$scan_id     = 0;
			$scan_state  = 'compat';
			$scan_phase  = '';
			$summary     = array(
				'counts'        => $counts,
				'total'         => $total,
				'local_files'   => (int) $legacy['local']['files'],
				'local_bytes'   => (int) $legacy['local']['bytes'],
				'cloud_objects' => (int) $legacy['cloud']['objects'],
				'cloud_bytes'   => (int) $legacy['cloud']['bytes'],
				'cloud_types'   => (array) $legacy['cloud']['types'],
				'scanned_at'    => $legacy_built,
				'scan'          => null,
				'scan_state'    => 'compat',
				'scan_phase'    => '',
				'resumable'     => false,
				'busy'          => false,
			);
			$snapshot = array(
				'local'      => array(
					'files' => (int) $legacy['local']['files'],
					'bytes' => (int) $legacy['local']['bytes'],
				),
				'cloud'      => array(
					'objects' => (int) $legacy['cloud']['objects'],
					'bytes'   => (int) $legacy['cloud']['bytes'],
					'types'   => (array) $legacy['cloud']['types'],
				),
				'counts'     => $counts,
				'total'      => $total,
				'scanned_at' => $legacy_built,
				'scan'       => null,
			);
		}

		$failed_total = $this->status->failed_count();
		$action_nonce = wp_create_nonce( 'sakura_s3_sync_action' );

		$settings_url = add_query_arg(
			array(
				'page' => \Sakura\ImageOptimizer\S3_Storage_Module::MENU_SLUG,
			),
			admin_url( 'admin.php' )
		);

		$logs = array();
		foreach ( $events as $event ) {
			$logs[] = array(
				'time'    => (int) strtotime( (string) $event['created_at'] . ' UTC' ),
				'level'   => (string) $event['level'],
				'message' => (string) $event['message'],
			);
		}
		if ( 0 === $total && 'done' !== $scan_state ) {
			$logs[] = array(
				'time'    => time(),
				'level'   => 'info',
				'message' => __( '索引为空：点击“开始完整核对”扫描本地目录与对象存储。', 'sakura-imgopt' ),
			);
		}
		if ( 'done' === $scan_state ) {
			$pending_count = (int) ( $counts['pending'] ?? 0 );
			$remote_count  = (int) ( $counts['remote'] ?? 0 );
			if ( $pending_count > 0 ) {
				$logs[] = array(
					'time'    => (int) $snapshot['scanned_at'],
					'level'   => 'warning',
					'message' => sprintf( /* translators: %d: pending files. */ __( '发现 %d 个本地文件尚未上传到对象存储。', 'sakura-imgopt' ), $pending_count ),
				);
			}
			if ( $remote_count > 0 ) {
				$logs[] = array(
					'time'    => (int) $snapshot['scanned_at'],
					'level'   => 'info',
					'message' => sprintf( /* translators: %d: remote objects. */ __( '发现 %d 个云端对象在本地没有对应文件；不会自动删除。', 'sakura-imgopt' ), $remote_count ),
				);
			}
		}
		foreach ( $failed as $item ) {
			$logs[] = array(
				'time'    => $item['modified'],
				'level'   => 'error',
				'message' => sprintf(
					/* translators: 1: attachment title, 2: error. */
					__( '同步失败：%1$s — %2$s', 'sakura-imgopt' ),
					$item['title'],
					$item['error'] ? $item['error'] : __( '未知错误', 'sakura-imgopt' )
				),
			);
		}
		if ( $failed_total > count( $failed ) ) {
			$logs[] = array(
				'time'    => time(),
				'level'   => 'warning',
				'message' => sprintf(
					/* translators: %d: failed count. */
					__( '另有 %d 个附件等待处理，可在任务中心查看。', 'sakura-imgopt' ),
					$failed_total - count( $failed )
				),
			);
		}

		// Newest first; the view shows six initially and can reveal more.
		usort(
			$logs,
			static function ( $a, $b ) {
				return (int) $b['time'] <=> (int) $a['time'];
			}
		);
		$logs = array_slice( $logs, 0, 100 );

		$rows = $this->decorate_rows( $rows );

		return array(
			'snapshot'     => $snapshot,
			'rows'         => $rows,
			'counts'       => $counts,
			'total'        => $total,
			'failed'       => $failed,
			'failed_total' => $failed_total,
			'jobs'         => $jobs,
			'logs'         => $logs,
			'options'      => $this->options,
			'settings_url' => $settings_url,
			'action_nonce' => $action_nonce,
			'scan_nonce'   => wp_create_nonce( 'sakura_s3_sync_scan' ),
			'page_nonce'   => wp_create_nonce( 'sakura_s3_sync_page' ),
			'page_cursor'  => $page['cursor'],
			'page_more'    => $page['has_more'],
			'summary'      => $summary,
			'scan'         => $scan,
			'scan_id'      => $scan_id,
			'scan_state'   => $scan_state,
			'scan_phase'   => $scan_phase,
			'scan_resumable' => ! empty( $summary['resumable'] ),
			'scan_busy'    => ! empty( $summary['busy'] ),
			'retry_nonce'  => wp_create_nonce( 'sakura_s3_retry' ),
			'test_nonce'   => wp_create_nonce( 'sakura_s3_sync_test' ),
			'export_url'   => wp_nonce_url(
				add_query_arg( array( 'action' => 'sakura_s3_sync_export' ), admin_url( 'admin-post.php' ) ),
				'sakura_s3_sync_export'
			),
			'uploads'      => $uploads,
			// Two separate facts: the fields can be complete while the master
			// switch is off. Folding them together made "paused" look like a
			// broken configuration on this screen.
			'is_configured' => $this->options->is_configured(),
			'is_enabled'    => $this->options->is_enabled(),
			'index_enabled' => $index_enabled,
			'legacy_error'  => $legacy_error,
			'legacy_built'  => $legacy_built,
			'metrics'       => Metrics::snapshot(),
			'jobs_summary'  => $this->index->jobs_summary( $profile ),
			'job_rows'      => $this->index->recent_jobs( $profile, 6 ),
			'toggle_nonce'  => wp_create_nonce( 'sakura_s3_sync_toggle' ),
			'refresh_nonce' => wp_create_nonce( 'sakura_s3_sync_refresh' ),
		);
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Sync_Center_Module', __NAMESPACE__ . '\\Sync_Center' );
