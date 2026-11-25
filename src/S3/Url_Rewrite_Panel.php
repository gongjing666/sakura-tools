<?php
/**
 * Database attachment URL rewrite screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the storage/rewrite tab: one scan, one run, one ledger.
 *
 * Every endpoint is capability- and nonce-checked here; the service underneath
 * owns cursors and time boxes, so the browser can drop a request at any point
 * and continue from the next click.
 */
final class Url_Rewrite_Panel implements Module {

	/**
	 * Nonce action shared by the screen and its endpoints.
	 */
	public const NONCE = 'sakura_url_rewrite';

	/**
	 * React island bundle, relative to the plugin root.
	 */
	public const BUNDLE = 'assets/ui/dist/rewrite.js';

	/**
	 * View data cache shared by enqueue and render.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data_cache = null;

	/**
	 * Resolved attachment scope, shared by enqueue and render.
	 *
	 * @var Url_Rewrite_Scope|null
	 */
	private ?Url_Rewrite_Scope $scope_cache = null;

	/**
	 * Service.
	 *
	 * @var Url_Rewrite_Service
	 */
	private Url_Rewrite_Service $service;

	/**
	 * Constructor.
	 *
	 * @param Url_Rewrite_Service|null $service Service.
	 */
	public function __construct( ?Url_Rewrite_Service $service = null ) {
		$this->service = $service ?? new Url_Rewrite_Service();
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_scan', array( $this, 'ajax_scan' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_scan_step', array( $this, 'ajax_scan_step' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_run', array( $this, 'ajax_run' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_preview', array( $this, 'ajax_preview' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_revert', array( $this, 'ajax_revert' ) );
		$hooks->add_action( 'wp_ajax_sakura_url_rewrite_reset', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Only load the page bundle on this tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		unset( $hook );

		if ( ! Registry::on( 'storage', 'rewrite' ) ) {
			return;
		}

		Page_Assets::set_data( 'storage-rewrite', $this->page_config() );
		Assets::enqueue_page();

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			wp_enqueue_script(
				'sakura-admin-rewrite',
				SAKURA_TOOLS_URL . self::BUNDLE,
				array( 'sakura-admin-core' ),
				Page_Assets::asset_version( self::BUNDLE ),
				true
			);
		}
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Url_Rewrite_Repository::maybe_install();

		$state        = $this->service->summary();
		$hosts        = $this->known_hosts();
		$current_host = $this->current_host();
		$scope        = $this->scope();

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			$data = ( new \Sakura\ImageOptimizer\Admin\Rewrite_Payload( $this ) )->build();

			require __DIR__ . '/../../includes/views/rewrite-island.php';

			return;
		}

		require __DIR__ . '/../../includes/views/storage-url-rewrite.php';
	}

	/**
	 * Config handed to assets/ui/js/pages/storage-rewrite.js.
	 *
	 * @return array<string,mixed>
	 */
	public function page_config(): array {
		if ( null === $this->data_cache ) {
			Url_Rewrite_Repository::maybe_install();

			$scope = $this->scope();

			$this->data_cache = array(
				'state'       => $this->service->summary(),
				'hosts'       => $this->known_hosts(),
				'currentHost' => $this->current_host(),
				'scope'       => $scope->payload(),
			);
		}

		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE ),
			'state'       => $this->data_cache['state'],
			'hosts'       => $this->data_cache['hosts'],
			'currentHost' => $this->data_cache['currentHost'],
			'scope'       => $this->data_cache['scope'],
			'batch'       => Url_Rewrite_Service::BATCH,
			'strings'     => array(
				'scanning'     => __( '正在扫描数据库…', 'sakura-imgopt' ),
				'scanDone'     => __( '扫描完成，请勾选要改写的值。', 'sakura-imgopt' ),
				'scanEmpty'    => __( '数据库里没有找到使用该域名的值。', 'sakura-imgopt' ),
				'running'      => __( '正在改写…', 'sakura-imgopt' ),
				'runDone'      => __( '改写完成。', 'sakura-imgopt' ),
				'reverting'    => __( '正在回滚…', 'sakura-imgopt' ),
				'revertDone'   => __( '回滚完成。', 'sakura-imgopt' ),
				'revertingAll' => __( '正在继续回滚…', 'sakura-imgopt' ),
				'resetting'    => __( '正在重置…', 'sakura-imgopt' ),
				'resetDone'    => __( '当前扫描已重置，改写记录仍保留。', 'sakura-imgopt' ),
				'previewing'   => __( '正在读取样例…', 'sakura-imgopt' ),
				'previewEmpty' => __( '没有可显示的样例。', 'sakura-imgopt' ),
				'unknown'      => __( '操作失败，请稍后重试。', 'sakura-imgopt' ),
				'noTasks'      => __( '请先扫描数据库。', 'sakura-imgopt' ),
				'noSelection'  => __( '请至少勾选一个要改写的位置。', 'sakura-imgopt' ),
				'hostsSame'    => __( '新旧域名不能相同。', 'sakura-imgopt' ),
				'badHost'      => __( '请填写有效域名，例如 bf.nboke.com。', 'sakura-imgopt' ),
				'statusIdle'   => __( '未扫描', 'sakura-imgopt' ),
				'statusScanning' => __( '扫描中', 'sakura-imgopt' ),
				'statusReady'  => __( '待执行', 'sakura-imgopt' ),
				'statusRunning' => __( '改写中', 'sakura-imgopt' ),
				'statusDone'   => __( '已完成', 'sakura-imgopt' ),
				/* translators: %d: number of selected rewrite locations. */
				'selected'     => __( '已选 %d 项', 'sakura-imgopt' ),
				'scopeLabel'      => __( '筛选路径', 'sakura-imgopt' ),
				'scanButton'      => __( '扫描数据库', 'sakura-imgopt' ),
				'resetScan'       => __( '重置当前扫描', 'sakura-imgopt' ),
				'selectColumn'    => __( '选择', 'sakura-imgopt' ),
				'statsLabels'     => array(
					'total'   => __( '待处理', 'sakura-imgopt' ),
					'done'    => __( '已检查', 'sakura-imgopt' ),
					'changed' => __( '已改写', 'sakura-imgopt' ),
					'skipped' => __( '已跳过', 'sakura-imgopt' ),
					'failed'  => __( '失败', 'sakura-imgopt' ),
				),
				'scopeSourceUrl'  => __( '来自图片地址前缀 upload_url_path', 'sakura-imgopt' ),
				'scopeSourcePath' => __( '来自云端路径前缀 upload_path', 'sakura-imgopt' ),
				'scopeHint'       => __( '只匹配旧域名下、路径以此前缀开头的附件 URL；同域名的其它路径不会命中。', 'sakura-imgopt' ),
				'scopeRoot'       => __( '根目录', 'sakura-imgopt' ),
				/* translators: 1: scan status, 2: number of positions, 3: attachment path. */
				'scanStatus'      => __( '当前状态：%1$s，已发现 %2$s 个包含 %3$s 的位置。', 'sakura-imgopt' ),
				'scanResume'      => __( '扫描未完成，打开页面会自动续跑。', 'sakura-imgopt' ),
				'runNotes'        => array(
					__( '扫描先按旧域名 + 路径前缀粗筛；执行时只改写精确匹配的附件 URL，粗筛到但未命中的行计入“已跳过”。', 'sakura-imgopt' ),
					__( '已跳过：值里是对象（unserialize 后为 object）、嵌套过深，或者替换前后完全一样。', 'sakura-imgopt' ),
					__( '失败：账本写入或数据库更新失败；该行原值保持不变，可以重试。', 'sakura-imgopt' ),
					__( '插件配置：默认不勾选 sakura-imgopt_* 与插件自建表；需要一起改时手动勾选。', 'sakura-imgopt' ),
				),
				'revertAction'    => __( '回滚', 'sakura-imgopt' ),
				'cancel'          => __( '取消', 'sakura-imgopt' ),
				'historyNote'     => __( '“冲突”表示这一行在改写之后又被别的操作改过，回滚不会覆盖它；需要时请手工处理。', 'sakura-imgopt' ),
				'fromLabel'       => __( '旧域名', 'sakura-imgopt' ),
				'fromHint'        => __( '填域名即可，例如 bf.nboke.com；也可以粘贴完整 URL。', 'sakura-imgopt' ),
				'toLabel'         => __( '新域名', 'sakura-imgopt' ),
				'toHint'          => __( '当前接管媒体 URL 的域名已预填；换行请确认新域名已经在对象存储里可用。', 'sakura-imgopt' ),
				'behaviorAlert'   => __( '只改写旧域名下、路径以筛选前缀开头的附件 URL；同域名的其它路径和非附件链接保持原样。仍会修正 PHP 序列化数据的长度前缀；反序列化后是对象的值会跳过。', 'sakura-imgopt' ),
				'backupTitle'     => __( '先备份，再执行', 'sakura-imgopt' ),
				'backupAlert'     => __( '改动直接写进数据库。每一行写入前都会先记入回滚账本；但仍然建议先确认数据库备份可用，再执行。', 'sakura-imgopt' ),
				'previewButton'   => __( '查看', 'sakura-imgopt' ),
				'previewTitle'    => __( '样例', 'sakura-imgopt' ),
				'previewClose'    => __( '收起', 'sakura-imgopt' ),
				'emptyTasksTitle' => __( '还没有扫描结果', 'sakura-imgopt' ),
				'emptyTasksBody'  => __( '填写新旧域名后点“扫描数据库”。只统计包含当前附件路径的位置；扫描只读，不会改动任何数据。', 'sakura-imgopt' ),
				'emptyRunsTitle'  => __( '还没有改写记录', 'sakura-imgopt' ),
				'emptyRunsBody'   => __( '执行一次改写后，这里按批次列出账本；回滚会按记录把值改回原样。', 'sakura-imgopt' ),
				'runButton'       => __( '执行改写', 'sakura-imgopt' ),
				'runContinue'     => __( '继续改写', 'sakura-imgopt' ),
				'presetDefault'   => __( '内容 + 主题', 'sakura-imgopt' ),
				'presetAll'       => __( '全选所有位置', 'sakura-imgopt' ),
				'selectAll'       => __( '全选', 'sakura-imgopt' ),
				'noteIdle'        => __( '勾选上方的位置后执行；每行会先写入回滚账本，再更新原值。', 'sakura-imgopt' ),
				/* translators: %d: number of rows waiting for rewrite. */
				'noteReady'       => __( '已扫描到 %d 行待处理；勾选位置后执行。', 'sakura-imgopt' ),
				'noteRunning'     => __( '改写进行中：关闭页面后可以点“继续改写”接着跑。', 'sakura-imgopt' ),
				'noteDone'        => __( '改写已完成。需要撤销时用右侧回滚账本，按记录逐行改回原值。', 'sakura-imgopt' ),
				'columns'         => array(
					'position' => __( '位置', 'sakura-imgopt' ),
					'group'    => __( '分组', 'sakura-imgopt' ),
					'rows'     => __( '命中行数', 'sakura-imgopt' ),
					'sample'   => __( '样例', 'sakura-imgopt' ),
					'time'     => __( '时间', 'sakura-imgopt' ),
					'rewrite'  => __( '改写', 'sakura-imgopt' ),
					'ledger'   => __( '账本行数', 'sakura-imgopt' ),
					'pending'  => __( '待回滚', 'sakura-imgopt' ),
					'conflict' => __( '冲突', 'sakura-imgopt' ),
					'action'   => __( '操作', 'sakura-imgopt' ),
				),
				'confirmRun'   => __( '确认改写所选位置？改写前会逐行记录原值，可在下方回滚。', 'sakura-imgopt' ),
				'confirmTitle' => __( '执行数据库改写', 'sakura-imgopt' ),
				'confirmButton'=> __( '开始改写', 'sakura-imgopt' ),
				'revertTitle'  => __( '回滚这次改写', 'sakura-imgopt' ),
				'revertButton' => __( '确认回滚', 'sakura-imgopt' ),
				'resetTitle'   => __( '重置当前扫描', 'sakura-imgopt' ),
				'resetButton'  => __( '重置', 'sakura-imgopt' ),
				'resetDesc'    => __( '只清除扫描结果和进度，已写入的改写记录与回滚账本不受影响。', 'sakura-imgopt' ),
				'revertDesc'   => __( '将按账本把值改回改写前的样子。改写之后又被手工改过的行会被标记为冲突并跳过。', 'sakura-imgopt' ),
				'groupLabels'  => array(
					'content' => __( '内容', 'sakura-imgopt' ),
					'theme'   => __( '主题数据', 'sakura-imgopt' ),
					'users'   => __( '用户数据', 'sakura-imgopt' ),
					'plugin'  => __( '插件配置', 'sakura-imgopt' ),
					'other'   => __( '其他', 'sakura-imgopt' ),
				),
			),
		);
	}

	/**
	 * Attachment scope from the active storage profile.
	 */
	private function scope(): Url_Rewrite_Scope {
		if ( null === $this->scope_cache ) {
			$this->scope_cache = Url_Rewrite_Scope::current();
		}

		return $this->scope_cache;
	}

	/**
	 * Known hosts from saved storage config, for the datalist.
	 *
	 * @return array<int,string>
	 */
	private function known_hosts(): array {
		$hosts = array();
		$push  = static function ( string $value ) use ( &$hosts ): void {
			if ( '' === $value ) {
				return;
			}

			$host = Link_Rewriter::normalize_host( $value );

			if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		};

		$push( (string) get_option( 'upload_url_path', '' ) );

		$s3 = new Options();
		foreach ( $s3->saved_profiles() as $profile ) {
			$push( (string) ( $profile['upload_url_path'] ?? '' ) );
		}

		if ( class_exists( '\Sakura\ImageOptimizer\Thumbnail\Settings' ) ) {
			$push( (string) \Sakura\ImageOptimizer\Thumbnail\Settings::media_host() );
		}

		$probe = get_option( 'sakura_imgopt_backup_probe', array() );
		if ( is_array( $probe ) ) {
			$push( (string) ( $probe['url'] ?? '' ) );
		}

		return $hosts;
	}

	/**
	 * Host uploads currently point at.
	 */
	private function current_host(): string {
		$host = Link_Rewriter::normalize_host( (string) get_option( 'upload_url_path', '' ) );

		if ( '' === $host && class_exists( '\Sakura\ImageOptimizer\Thumbnail\Settings' ) ) {
			$host = Link_Rewriter::normalize_host( (string) \Sakura\ImageOptimizer\Thumbnail\Settings::media_host() );
		}

		return $host;
	}

	/**
	 * Shared guard for every endpoint.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Send a service result or its error.
	 *
	 * @param array<string,mixed>|\WP_Error $result Result.
	 */
	private function respond( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				409
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Post scalar helper.
	 *
	 * @param string $key Field.
	 */
	private function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Post integer helper.
	 *
	 * @param string $key Field.
	 */
	private function post_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		return isset( $_POST[ $key ] ) ? (int) wp_unslash( $_POST[ $key ] ) : 0;
	}

	/**
	 * AJAX: start a scan.
	 */
	public function ajax_scan(): void {
		$this->guard();
		Url_Rewrite_Repository::maybe_install();

		$this->respond( $this->service->start( $this->post_string( 'from' ), $this->post_string( 'to' ), $this->scope()->path_prefix() ) );
	}

	/**
	 * AJAX: continue the scan.
	 */
	public function ajax_scan_step(): void {
		$this->guard();

		$this->respond( $this->service->scan_step() );
	}

	/**
	 * AJAX: run one rewrite batch.
	 */
	public function ajax_run(): void {
		$this->guard();
		Url_Rewrite_Repository::maybe_install();

		$selection = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		if ( isset( $_POST['tasks'] ) && is_array( $_POST['tasks'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
			$selection = array_map( 'intval', (array) wp_unslash( $_POST['tasks'] ) );
		}

		$this->respond( $this->service->run_step( $selection, $this->post_int( 'batch' ) ) );
	}

	/**
	 * AJAX: sample rows for one task.
	 */
	public function ajax_preview(): void {
		$this->guard();

		$this->respond( $this->service->preview( $this->post_int( 'index' ) ) );
	}

	/**
	 * AJAX: roll back one ledger batch.
	 */
	public function ajax_revert(): void {
		$this->guard();
		Url_Rewrite_Repository::maybe_install();

		$this->respond( $this->service->revert_step( $this->post_string( 'runId' ), $this->post_int( 'after' ), $this->post_int( 'batch' ) ) );
	}

	/**
	 * AJAX: drop the scan state.
	 */
	public function ajax_reset(): void {
		$this->guard();
		Url_Rewrite_Repository::maybe_install();

		$this->respond( $this->service->reset() );
	}
}
