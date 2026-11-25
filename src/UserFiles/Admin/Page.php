<?php
/**
 * Admin screen for review, execution and quotas.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Frontend\Entry;
use Nboke\UserFiles\Frontend\Providers\Generic;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\Route;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; each tab has its own page controller under
 * assets/ui/js/pages and talks to sufm/v1 through SakuraAdmin.api.
 */
final class Page {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'sakura-tools-files';

	/**
	 * Legacy layout page key.
	 */
	public const PAGE = 'files';

	/**
	 * React island bundle for the settings tab.
	 */
	public const BUNDLE = 'assets/ui/dist/files.js';

	/**
	 * Tabs migrated to the new UI stack.
	 *
	 * @var array<int, string>
	 */
	private const UI_PAGES = array(
		'files-settings',
		'files-users',
		'files-queue',
		'files-log',
	);

	/**
	 * The screen is stateless: everything it needs comes from Settings and the
	 * REST endpoints, so no service has to be injected here.
	 */
	public function __construct() {}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		$hooks->add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush_entry_cache' ) );
		$hooks->add_action( 'admin_post_sakura_sufm_download', array( $this, 'download_request_file' ) );

		Capability_Matrix::register( $hooks );

		/*
		 * Registering the renderers touches Registry::tabs(), which builds the
		 * translated module labels. Doing that here would run __() during
		 * plugins_loaded - before init - and WordPress 6.7+ flags it with
		 * _load_textdomain_just_in_time on every admin page. The textdomain
		 * loads on init, so wait for it; Menu only reads the renderers later,
		 * on admin_menu.
		 */
		$hooks->add_action( 'init', array( $this, 'register_renderers' ), 20 );
	}

	/**
	 * Claim the renderer for every tab of the file space screen.
	 *
	 * The menu lives in Sakura\ImageOptimizer\Admin\Menu; this screen only
	 * claims its routes. Every tab of the file space renders this same page.
	 */
	public function register_renderers(): void {
		foreach ( Registry::tabs( 'files' ) as $sufm_tab_id ) {
			/* One tab owns its screen instead of sharing this one: Zibll has its
			 * own controller (Space_Page). */
			if ( in_array( (string) $sufm_tab_id, array( 'zibll' ), true ) ) {
				continue;
			}

			Registry::register_renderer( 'files', (string) $sufm_tab_id, array( $this, 'render' ) );
		}
	}

	/**
	 * The embed page is cached by the shortcode provider; drop it when the
	 * settings change so a re-published page is found immediately.
	 */
	public function flush_entry_cache(): void {
		if ( class_exists( Generic::class ) ) {
			Generic::flush_page_cache();
		}
	}

	/**
	 * Stream one attachment from a deletion request as a download.
	 *
	 * Uploads live on the object-storage CDN, whose response is inline and whose
	 * download attribute is ignored across origins. Reviewers need a real
	 * attachment response before they approve destruction of the file.
	 *
	 * @return void
	 */
	public function download_request_file(): void {
		if ( ! Capabilities::can_review() ) {
			wp_die( esc_html__( '你没有权限下载这个文件。', 'sakura-imgopt' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'sakura_sufm_download' );

		$attachment = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;
		if ( $attachment < 1 ) {
			wp_die( esc_html__( '文件不存在。', 'sakura-imgopt' ), '', array( 'response' => 404 ) );
		}

		$path = get_attached_file( $attachment );

		if ( ! $path || ! is_readable( $path ) ) {
			$url = wp_get_attachment_url( $attachment );
			if ( $url ) {
				wp_safe_redirect( $url );
				exit;
			}

			wp_die( esc_html__( '文件不存在或无法读取。', 'sakura-imgopt' ), '', array( 'response' => 404 ) );
		}

		$name = wp_basename( $path );
		$mime = (string) get_post_mime_type( $attachment );
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}

		$ascii = preg_replace( '/[^A-Za-z0-9._ -]/', '_', $name );
		$ascii = trim( (string) $ascii );
		if ( '' === $ascii ) {
			$ascii = 'download';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path );
		exit;
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'sufm_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Load the UI stack for the four core tabs.
	 *
	 * Page data must be attached before Assets::enqueue_page() runs because the
	 * localized config is printed from Page_Assets::enqueue().
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		unset( $hook );

		if ( ! Registry::on( 'files' ) ) {
			return;
		}

		$page = Route::current_page();

		if ( ! in_array( $page, self::UI_PAGES, true ) ) {
			// files-zibll is owned by Space_Page and loads its own data.
			return;
		}

		Page_Assets::set_data( $page, $this->page_data( $page ) );
		Assets::enqueue_page();

		$bundle = Page_Assets::island_bundle( $page );

		if ( '' !== $bundle && is_readable( SAKURA_TOOLS_PATH . $bundle ) ) {
			$handle = 'files-settings' === $page ? 'sakura-admin-files' : 'sakura-admin-' . $page;

			wp_enqueue_script(
				$handle,
				SAKURA_TOOLS_URL . $bundle,
				array( 'sakura-admin-core' ),
				Page_Assets::asset_version( $bundle ),
				true
			);
		}
	}

	/**
	 * Controller strings for one page. All copy goes through __() here so the
	 * JavaScript never embeds untranslated UI text.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>
	 */
	public function page_data( string $page ): array {
		$data = array(
			'restPath' => 'sufm/v1/',
			'strings'  => array(
				'failed'       => __( '操作失败，请重试。', 'sakura-imgopt' ),
				'cancel'       => __( '取消', 'sakura-imgopt' ),
				'confirm'      => __( '确认', 'sakura-imgopt' ),
				'pagerSummary' => __( '显示 {from}–{to} 条 · 共 {total} 条', 'sakura-imgopt' ),
				'pagerNow'     => __( '{page} / {pages}', 'sakura-imgopt' ),
				'pagerEmpty'   => __( '暂无记录', 'sakura-imgopt' ),
			),
		);

		if ( 'files-queue' === $page ) {
			$data['strings'] = array_merge(
				$data['strings'],
				array(
					'loading'             => __( '正在读取…', 'sakura-imgopt' ),
					'loadFailed'          => __( '读取删除申请失败。', 'sakura-imgopt' ),
					'approvedLoadFailed'  => __( '读取已批准申请失败。', 'sakura-imgopt' ),
					'emptyTitle'          => __( '暂无删除申请', 'sakura-imgopt' ),
					'emptyMessage'        => __( '当前筛选条件下没有申请。', 'sakura-imgopt' ),
					'approvedEmpty'       => __( '暂无已批准、待执行的申请。', 'sakura-imgopt' ),
					'approve'             => __( '批准', 'sakura-imgopt' ),
					'reject'              => __( '驳回', 'sakura-imgopt' ),
					'revoke'              => __( '撤销批准', 'sakura-imgopt' ),
					'stopBatch'           => __( '停止批次', 'sakura-imgopt' ),
					'pickNone'            => __( '未选择', 'sakura-imgopt' ),
					'pickCount'           => __( '已选 {count} 项', 'sakura-imgopt' ),
					'previewButton'       => __( '预览删除范围', 'sakura-imgopt' ),
					'previewNeed'         => __( '请先勾选至少一条已批准的申请。', 'sakura-imgopt' ),
					'previewHint'         => __( '先在上一步勾选已批准的申请，再核对范围。', 'sakura-imgopt' ),
					'previewTitle'        => __( '{count} 项', 'sakura-imgopt' ),
					'previewTotal'        => __( '共 {size}', 'sakura-imgopt' ),
					'previewRequest'      => __( '申请 #{id}：{count} 项，{size}', 'sakura-imgopt' ),
					'previewHash'         => __( '范围哈希 {hash}…，确认时会再次比对。', 'sakura-imgopt' ),
					'previewFailed'       => __( '预览失败，请重试。', 'sakura-imgopt' ),
					'executeButton'       => __( '建立删除批次', 'sakura-imgopt' ),
					'phraseWrong'         => __( '请输入“删除”两个字。', 'sakura-imgopt' ),
					'phraseValue'         => __( '删除', 'sakura-imgopt' ),
					'executeTitle'        => __( '确认执行删除', 'sakura-imgopt' ),
					'executeMessage'      => __( '将建立包含 {count} 个对象的删除批次；任务开始后无法撤回已经处理的对象。', 'sakura-imgopt' ),
					'executeConfirm'      => __( '确认执行', 'sakura-imgopt' ),
					'batchCreated'        => __( '批次 #{id} 已建立，等待后台执行。', 'sakura-imgopt' ),
					'batchState'          => __( '批次 #{id} · {status}', 'sakura-imgopt' ),
					'batchProgress'       => __( '完成 {done} / {total}，失败 {failed}', 'sakura-imgopt' ),
					'batchFinished'       => __( '批次 #{id} 已结束。', 'sakura-imgopt' ),
					'updated'             => __( '已更新 #{id}。', 'sakura-imgopt' ),
					'reviewDone'          => __( '已更新 #{id} 的审核状态。', 'sakura-imgopt' ),
					'revokeDone'          => __( '已撤销 #{id} 的批准。', 'sakura-imgopt' ),
					'revokeTitle'         => __( '撤销批准 #{id}', 'sakura-imgopt' ),
					'revokeMessage'       => __( '撤销后申请回到待审核状态，对象锁会立即解除。', 'sakura-imgopt' ),
					'approveTitle'        => __( '批准申请 #{id}', 'sakura-imgopt' ),
					'approveMessage'      => __( '批准只代表可以进入执行，不会立即删除；还需要在下方预览范围并确认。', 'sakura-imgopt' ),
					'rejectTitle'         => __( '驳回申请 #{id}', 'sakura-imgopt' ),
					'rejectMessage'       => __( '驳回会立即解除对象锁，用户需要重新提交申请。', 'sakura-imgopt' ),
					'stopTitle'           => __( '停止批次 #{id}', 'sakura-imgopt' ),
					'stopMessage'         => __( '已经处理的对象无法撤回，剩余对象会停止处理。', 'sakura-imgopt' ),
					'stopDone'            => __( '已请求停止批次 #{id}。', 'sakura-imgopt' ),
					'countSub'            => __( '已批准待执行 {approved} · 执行中 {queued}', 'sakura-imgopt' ),
					'ownerId'             => __( 'ID {id}', 'sakura-imgopt' ),
					'items'               => __( '{count} 项', 'sakura-imgopt' ),
					'viewFiles'           => __( '查看文件', 'sakura-imgopt' ),
					'viewFilesLabel'      => __( '查看申请 #{id} 的 {count} 个文件', 'sakura-imgopt' ),
					'filesLoadFailed'     => __( '读取申请文件失败，请重试。', 'sakura-imgopt' ),
					'filesEmpty'          => __( '这条申请没有可查看的文件。', 'sakura-imgopt' ),
					'galleryPrev'         => __( '上一个文件', 'sakura-imgopt' ),
					'galleryNext'         => __( '下一个文件', 'sakura-imgopt' ),
					'galleryCounter'      => __( '{current} / {total}', 'sakura-imgopt' ),
					'galleryLabel'        => __( '第 {current} 个，共 {total} 个文件', 'sakura-imgopt' ),
					'noActions'           => __( '—', 'sakura-imgopt' ),
					'idLabel'             => __( '编号', 'sakura-imgopt' ),
					'userLabel'           => __( '用户', 'sakura-imgopt' ),
					'objectsLabel'        => __( '对象', 'sakura-imgopt' ),
					'statusLabel'         => __( '状态', 'sakura-imgopt' ),
					'timeLabel'           => __( '提交时间', 'sakura-imgopt' ),
					'actionsLabel'        => __( '操作', 'sakura-imgopt' ),
					'statusLabels'        => array(
						'pending_review' => __( '待审核', 'sakura-imgopt' ),
						'approved'       => __( '已批准', 'sakura-imgopt' ),
						'rejected'       => __( '已驳回', 'sakura-imgopt' ),
						'revoked'        => __( '已撤销', 'sakura-imgopt' ),
						'withdrawn'      => __( '已撤回', 'sakura-imgopt' ),
						'needs_review'   => __( '需核实', 'sakura-imgopt' ),
						'queued'         => __( '待执行', 'sakura-imgopt' ),
						'executing'      => __( '执行中', 'sakura-imgopt' ),
						'completed'      => __( '已完成', 'sakura-imgopt' ),
						'partial_failed' => __( '部分失败', 'sakura-imgopt' ),
						'failed'         => __( '失败', 'sakura-imgopt' ),
						'stopped'        => __( '已停止', 'sakura-imgopt' ),
					),
				)
			);
		}

		if ( 'files-users' === $page ) {
			$default_quota = (int) ( Settings::defaults()['quota_default_bytes'] ?? 0 );

			$data['defaultQuotaMb'] = (int) round( $default_quota / 1048576 );
			$data['strings']        = array_merge(
				$data['strings'],
				array(
					'loading'          => __( '正在读取…', 'sakura-imgopt' ),
					'loadFailed'       => __( '读取用户容量失败。', 'sakura-imgopt' ),
					'emptyTitle'       => __( '没有匹配的用户', 'sakura-imgopt' ),
					'emptyMessage'     => __( '换一个关键词，或清空搜索后重试。', 'sakura-imgopt' ),
					'save'             => __( '保存', 'sakura-imgopt' ),
					'saved'            => __( '已更新 {name}。', 'sakura-imgopt' ),
					'saveFailed'       => __( '保存容量失败。', 'sakura-imgopt' ),
					'quotaPlaceholder' => __( '默认', 'sakura-imgopt' ),
					'quotaTitle'       => __( '当前生效容量：{size}', 'sakura-imgopt' ),
					'quotaInvalid'     => __( '请输入不小于 0 的整数，或留空恢复默认容量。', 'sakura-imgopt' ),
					'quotaClear'       => __( '留空恢复默认容量。', 'sakura-imgopt' ),
					'filesUnit'        => __( '个文件', 'sakura-imgopt' ),
					'foldersUnit'      => __( '个文件夹', 'sakura-imgopt' ),
					'sync'             => __( '同步容量', 'sakura-imgopt' ),
					'syncing'          => __( '同步中…', 'sakura-imgopt' ),
					'syncStartFailed'  => __( '启动同步失败。', 'sakura-imgopt' ),
					'syncFailed'       => __( '同步状态读取失败。', 'sakura-imgopt' ),
					'syncProgress'     => __( '同步中 {scanned} / {total}（{percent}%），更新 {updated} 个文件', 'sakura-imgopt' ),
					'syncDone'         => __( '同步完成：{message}', 'sakura-imgopt' ),
					'reset'            => __( '重置容量', 'sakura-imgopt' ),
					'resetTitle'       => __( '重置所有用户的单独容量', 'sakura-imgopt' ),
					'resetMessage'     => __( '重置会清除【所有用户】单独设置的容量，全部回到默认容量。确定继续？', 'sakura-imgopt' ),
					'resetConfirm'     => __( '确定重置', 'sakura-imgopt' ),
					'resetDone'        => __( '已重置 {count} 个用户的单独容量。', 'sakura-imgopt' ),
					'resetFailed'      => __( '重置失败，请重试。', 'sakura-imgopt' ),
					'sanction'         => __( '处罚', 'sakura-imgopt' ),
					'sanctionTitle'    => __( '处罚用户 {name}', 'sakura-imgopt' ),
					'sanctionActive'   => __( '当前处罚：{label}', 'sakura-imgopt' ),
					'sanctionNone'     => __( '该用户当前没有生效中的处罚。', 'sakura-imgopt' ),
					'sanctionSuggest'  => __( '建议：{reason}', 'sakura-imgopt' ),
					'sanctionType'     => __( '处罚方式', 'sakura-imgopt' ),
					'sanctionDuration' => __( '处罚期限', 'sakura-imgopt' ),
					'sanctionNote'     => __( '备注（可选，最多 120 字）', 'sakura-imgopt' ),
					'sanctionReason'   => __( '理由', 'sakura-imgopt' ),
					'sanctionApply'    => __( '确认处罚', 'sakura-imgopt' ),
					'sanctionApplied'  => __( '已处罚 {name}。', 'sakura-imgopt' ),
					'sanctionApplyFailed' => __( '处罚失败，请重试。', 'sakura-imgopt' ),
					'sanctionRevoke'   => __( '撤销处罚', 'sakura-imgopt' ),
					'sanctionRevokeTitle' => __( '撤销对 {name} 的处罚', 'sakura-imgopt' ),
					'sanctionRevokeMessage' => __( '撤销后该用户可以立即恢复上传，但本功能不会恢复已删除的文件。', 'sakura-imgopt' ),
					'sanctionRevokeNote' => __( '文件已删除，撤销处罚不会恢复内容。', 'sakura-imgopt' ),
					'sanctionRevoked'  => __( '已撤销 {name} 的处罚。', 'sakura-imgopt' ),
					'sanctionRevokeFailed' => __( '撤销失败，请重试。', 'sakura-imgopt' ),
					'sanctionForever'  => __( '永久处罚需要输入该用户的登录名确认。', 'sakura-imgopt' ),
					'sanctionForeverLabel' => __( '输入登录名 {login} 确认', 'sakura-imgopt' ),
					'sanctionLoadFailed' => __( '读取处罚信息失败。', 'sakura-imgopt' ),
					'sanctionSelf'     => __( '不能处罚自己。', 'sakura-imgopt' ),
					'sanctionAdmin'    => __( '不能处罚拥有管理后台权限的账号。', 'sakura-imgopt' ),
				)
			);
		}

		if ( 'files-log' === $page ) {
			$data['strings'] = array_merge(
				$data['strings'],
				array(
					'loading'           => __( '正在读取…', 'sakura-imgopt' ),
					'loadFailed'        => __( '读取操作记录失败。', 'sakura-imgopt' ),
					'emptyTitle'        => __( '暂无操作记录', 'sakura-imgopt' ),
					'emptyMessage'      => __( '审核、执行和容量变更会记录在这里。', 'sakura-imgopt' ),
					'allResults'        => __( '全部结果', 'sakura-imgopt' ),
					'filterPlaceholder' => __( '筛选当前页（动作、结果或说明）', 'sakura-imgopt' ),
					'filterSummary'     => __( '本页筛选：{shown} / {total} 条', 'sakura-imgopt' ),
					'filterEmpty'       => __( '没有匹配的记录。', 'sakura-imgopt' ),
					'actionLabel'       => __( '动作', 'sakura-imgopt' ),
					'resultLabel'       => __( '结果', 'sakura-imgopt' ),
					'detailLabel'       => __( '说明', 'sakura-imgopt' ),
					'timeLabel'         => __( '时间', 'sakura-imgopt' ),
				)
			);
		}

		if ( 'files-settings' === $page ) {
			$data['strings'] = array_merge(
				$data['strings'],
				array(
					'currentSelection' => __( '当前样式：{style}；图标颜色：{accent}。', 'sakura-imgopt' ),
					'styleFallback'    => __( '跟随样式', 'sakura-imgopt' ),
					'accentFallback'   => __( '跟随样式', 'sakura-imgopt' ),
				)
			);
		}

		return $data;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::can_review() ) {
			wp_die( esc_html__( '你没有权限管理文件空间。', 'sakura-imgopt' ) );
		}

		$page = Route::current_page();

		$bundle = Page_Assets::island_bundle( $page );

		if ( '' !== $bundle && is_readable( SAKURA_TOOLS_PATH . $bundle ) ) {
			if ( 'files-settings' === $page ) {
				$data = ( new Files_Settings_Payload() )->build();

				require SAKURA_TOOLS_PATH . 'includes/views/files-settings-island.php';

				return;
			}

			if ( in_array( $page, array( 'files-users', 'files-queue', 'files-log' ), true ) ) {
				$data = ( new Files_Admin_Payload() )->build( $page );

				require SAKURA_TOOLS_PATH . 'includes/views/files-admin-island.php';

				return;
			}
		}

		$view = SAKURA_TOOLS_PATH . 'includes/views/userfiles-admin.php';

		if ( ! is_readable( $view ) ) {
			return;
		}

		$enabled      = Settings::entry_enabled();
		$settings     = Settings::get();
		$entry        = new Entry();
		$entry_status = $entry->status();

		include $view;
	}
}
