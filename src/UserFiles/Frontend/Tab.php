<?php
/**
 * User file space: front-end user center tab.
 *
 * Only registers the user-facing surface. All data is fetched by the browser
 * from the `sufm/v1` REST namespace; this class never queries the file tables.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Frontend;

use Nboke\UserFiles\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "文件管理" tab, sidebar entry, view and assets to the Zibll user center.
 */
final class Tab {

	/**
	 * Fixed reference-impact notice, mirrored in the request dialog.
	 *
	 * @return string
	 */
	private static function reference_notice(): string {
		return __( '删除后文章、商品、头像等位置的链接可能失效，请自行确认后再提交', 'sakura-imgopt' );
	}

	/**
	 * Fallback notice version when the option is empty or invalid.
	 */
	private const NOTICE_VERSION_FALLBACK = '2026-10-31';

	/**
	 * Register the four front-end filters plus the asset action.
	 *
	 * @param \Sakura\ImageOptimizer\Support\Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( \Sakura\ImageOptimizer\Support\Hook_Loader $hooks ): void {
		$hooks->add_filter( 'user_ctnter_main_tabs_array', array( $this, 'tabs' ) );
		$hooks->add_filter( 'zib_user_center_page_sidebar_button_2_args', array( $this, 'sidebar' ), 10, 2 );
		$hooks->add_filter( 'main_user_tab_content_files', array( $this, 'content' ), 10, 2 );
		$hooks->add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the files tab to the user center navigation.
	 *
	 * @param array<string, array<string, string>> $tabs Existing tabs.
	 * @return array<string, array<string, string>>
	 */
	public function tabs( array $tabs ): array {
		if ( isset( $tabs['files'] ) ) {
			return $tabs;
		}

		$title = __( '文件管理', 'sakura-imgopt' );

		$tabs['files'] = array(
			'title'    => $title,
			'nav_attr' => 'drawer-title="' . esc_attr( $title ) . '"',
			'loader'   => '<div class="zib-widget"><div class="box-body"><div class="placeholder k1 mb10"></div><div class="placeholder k1 mb10"></div><div class="placeholder s1 mb10"></div><div class="placeholder k1 mb30"></div><div class="placeholder t1 mb30" style="height:120px;"></div><div class="placeholder k1"></div></div></div>',
		);

		return $tabs;
	}

	/**
	 * Add the sidebar entry. Appending it puts it on the second icon row.
	 *
	 * The theme applies this filter with one argument, so `$tab` is optional.
	 *
	 * @param array<int, array<string, string>> $args Existing buttons.
	 * @param string                            $tab  Current tab, when supplied.
	 * @return array<int, array<string, string>>
	 */
	public function sidebar( array $args, string $tab = '' ): array {
		unset( $tab );

		foreach ( $args as $button ) {
			if ( isset( $button['tab'] ) && 'files' === $button['tab'] ) {
				return $args;
			}
		}

		$args[] = array(
			'html' => '',
			'icon' => $this->sidebar_icon(),
			'name' => __( '文件管理', 'sakura-imgopt' ),
			'tab'  => 'files',
		);

		return $args;
	}

	/**
	 * Render the tab content.
	 *
	 * @param string $html Existing content from earlier filters.
	 * @param mixed  $opt  Tab options supplied by the theme.
	 * @return string
	 */
	public function content( string $html, $opt ): string {
		unset( $opt );

		$markup = $this->render();

		if ( '' === $markup ) {
			return $html;
		}

		/*
		 * Zibll replaces a tab body with the `.ajax-item` block of the fetched
		 * page, and reports "没有更多内容了" when it finds none. Every built-in
		 * tab is wrapped by zib_get_ajax_ajaxpager_one_centent(); do the same so
		 * the first AJAX switch keeps the markup instead of an empty pager.
		 */
		if ( function_exists( 'zib_get_ajax_ajaxpager_one_centent' ) ) {
			$markup = zib_get_ajax_ajaxpager_one_centent( $markup );
		}

		/*
		 * Zibll loads a tab body through an AJAX request after the first click.
		 * That request never runs wp_enqueue_scripts for the tab, so print the
		 * already-registered handles once, and only when they are not done.
		 */
		$assets = '';

		// A normal page render queues the assets in the footer; printing them here
		// would execute the script before #sufm-app exists in the DOM.
		if ( wp_doing_ajax() ) {
			$this->register_assets();
		}

		if ( wp_doing_ajax() && ! wp_style_is( 'sufm-front', 'done' ) ) {
			ob_start();
			wp_print_styles( 'sufm-front' );
			$assets .= (string) ob_get_clean();
		}

		if ( wp_doing_ajax() && ! wp_script_is( 'sufm-front', 'done' ) ) {
			ob_start();
			wp_print_scripts( 'sufm-front' );
			$assets .= (string) ob_get_clean();
		}

		return $assets . $markup;
	}

	/**
	 * Render the tab markup for any entry point (theme tab or shortcode).
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$template = SAKURA_TOOLS_PATH . 'includes/views/userfiles-tab.php';

		if ( ! is_readable( $template ) ) {
			return '';
		}

		$data = array(
			'strings'      => $this->strings(),
			'notice'       => $this->notice(),
			// The folder look is one setting for both surfaces: the media library
			// tree reads it from the body class, this tab from its own root class.
			'tree_style'   => Settings::tree_style(),
			'folder_accent' => Settings::folder_accent(),
		);

		ob_start();
		include $template;
		$rendered = ob_get_clean();

		return is_string( $rendered ) ? $rendered : '';
	}

	/**
	 * Enqueue assets only on the user center files tab.
	 */
	public function enqueue(): void {
		if ( ! $this->is_files_screen() && ! $this->is_user_center_page() ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Whether this request renders the theme user center, where the files tab
	 * may be opened later over AJAX.
	 */
	public function is_user_center_page(): bool {
		if ( 'files' === $this->request_value( 'user_center' ) ) {
			return true;
		}

		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only routing.

		return (bool) preg_match( '#/user(/|$)#', $path );
	}

	/**
	 * Load the front-end assets. Entry points decide when this is appropriate.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->register_assets() ) {
			return;
		}

		wp_enqueue_style( 'sufm-front' );
		wp_enqueue_script( 'sufm-front' );
	}

	/**
	 * Register the assets without deciding whether the current request needs them.
	 *
	 * @return bool True when the handles were registered.
	 */
	public function register_assets(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( wp_style_is( 'sufm-front', 'registered' ) && wp_script_is( 'sufm-front', 'registered' ) ) {
			return true;
		}

		$css_version = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles.css' );
		$js_version  = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles.js' );

		wp_enqueue_style(
			'sufm-front',
			SAKURA_TOOLS_URL . 'assets/userfiles.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'sufm-front',
			SAKURA_TOOLS_URL . 'assets/userfiles.js',
			array(),
			$js_version,
			true
		);

		wp_localize_script( 'sufm-front', 'sufmData', $this->script_data() );

		return true;
	}

	/**
	 * Whether the current request is the user center files tab.
	 */
	public function is_files_screen(): bool {
		$tab = $this->request_value( 'tab' );

		if ( 'files' === $tab ) {
			return true;
		}

		return 'files' === $this->request_value( 'user_center' );
	}

	/**
	 * Read a request key from the query var or the query string.
	 *
	 * @param string $key Query key.
	 */
	private function request_value( string $key ): string {
		$value = get_query_var( $key );

		if ( '' === $value && isset( $_GET[ $key ] ) ) {
			$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		}

		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * Data exposed as `window.sufmData`.
	 *
	 * @return array<string, mixed>
	 */
	public function script_data(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'sufm/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'userId'  => get_current_user_id(),
			'strings' => $this->strings(),
			'notice'  => $this->notice(),
		);
	}

	/**
	 * Reference-impact notice read from the module settings.
	 *
	 * @return array{version: string, text: string}
	 */
	private function notice(): array {
		$settings = get_option( 'sufm_settings', array() );
		$version  = is_array( $settings ) && isset( $settings['reference_notice_version'] )
			? (string) $settings['reference_notice_version']
			: self::NOTICE_VERSION_FALLBACK;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) ) {
			$version = self::NOTICE_VERSION_FALLBACK;
		}

		return array(
			'version' => $version,
			'text'    => self::reference_notice(),
		);
	}

	/**
	 * Colored folder mark used by the theme's sidebar icon slot.
	 */
	private function sidebar_icon(): string {
		return '<svg class="icon" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. '<rect x="1" y="1" width="22" height="22" rx="6" fill="#fff0f6"></rect>'
			. '<path d="M4.7 8.2h4.1l1.5 1.6h9a1.2 1.2 0 0 1 1.2 1.2v6.9a2.2 2.2 0 0 1-2.2 2.2H6.9a2.2 2.2 0 0 1-2.2-2.2V8.2z" fill="#f7a23b"></path>'
			. '<path d="M4.7 8.2V7.1a1.2 1.2 0 0 1 1.2-1.2h3.3l1.5 1.6" fill="#ffc36a"></path>'
			. '</svg>';
	}

	/**
	 * Front-end strings. Kept in one place so the JS carries no source copy.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			'upload'                 => __( '上传文件', 'sakura-imgopt' ),
			'newFolder'              => __( '新建文件夹', 'sakura-imgopt' ),
			'searchPlaceholder'      => __( '搜索我的文件', 'sakura-imgopt' ),
			'listView'               => __( '列表视图', 'sakura-imgopt' ),
			'gridView'               => __( '网格视图', 'sakura-imgopt' ),
			'allFiles'               => __( '全部文件', 'sakura-imgopt' ),
			'currentFolder' => __( '当前位置', 'sakura-imgopt' ),
			'moveSearch' => __( '搜索文件夹', 'sakura-imgopt' ),
			'noMatch'    => __( '没有匹配的文件夹。', 'sakura-imgopt' ),
			'images'                 => __( '图片', 'sakura-imgopt' ),
			'documents'              => __( '文档', 'sakura-imgopt' ),
			'archives'               => __( '压缩包', 'sakura-imgopt' ),
			'videos'                 => __( '视频', 'sakura-imgopt' ),
			'audio'                  => __( '音频', 'sakura-imgopt' ),
			'requests'               => __( '删除申请', 'sakura-imgopt' ),
			'fileUnit'               => __( '个文件', 'sakura-imgopt' ),
			'dragInsert'             => __( '拖到正文插入', 'sakura-imgopt' ),
			'folderUnit'             => __( '个文件夹', 'sakura-imgopt' ),
			'itemUnit'               => __( '项', 'sakura-imgopt' ),
			/* translators: %s: file count */
			'filesCount'             => __( '%s 个文件', 'sakura-imgopt' ),
			/* translators: %s: folder count */
			'foldersCount'           => __( '%s 个文件夹', 'sakura-imgopt' ),
			'foldersToggle'          => __( '子文件夹', 'sakura-imgopt' ),
			'searchScope'            => __( '搜索', 'sakura-imgopt' ),
			'foldersShow'            => __( '展开子文件夹', 'sakura-imgopt' ),
			'foldersHide'            => __( '收起子文件夹', 'sakura-imgopt' ),
			/* translators: %s: item count */
			'itemsCount'             => __( '共 %s 项', 'sakura-imgopt' ),
			'pendingSpace'           => __( '待处理申请仍占用空间', 'sakura-imgopt' ),
			'storageLoading'         => __( '正在加载空间…', 'sakura-imgopt' ),
			'storageUnavailable'     => __( '空间信息暂不可用', 'sakura-imgopt' ),
			'remaining'              => __( '剩余', 'sakura-imgopt' ),
			'usedTrack'              => __( '空间已使用', 'sakura-imgopt' ),
			'overQuota'              => __( '已超额，请申请管理员清理', 'sakura-imgopt' ),
			'quotaExceeded'          => __( '已使用容量超过上限，请联系网站管理员扩容或删除多余文件！', 'sakura-imgopt' ),
			'spaceFull'              => __( '空间已满，请申请管理员清理', 'sakura-imgopt' ),
			'folderLocked'           => __( '有待处理申请 · 修改已锁定', 'sakura-imgopt' ),
			/* translators: 1: file count, 2: total size */
			'folderMeta'             => __( '%s 个文件 · %s', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
			'folderEmpty'            => __( '空文件夹', 'sakura-imgopt' ),
			/* translators: %s: folder name */
			'openFolderLabel'        => __( '打开文件夹 %s', 'sakura-imgopt' ),
			/* translators: %s: folder name */
			'folderActions'          => __( '%s的操作', 'sakura-imgopt' ),
			/* translators: %s: file name */
			'fileActions'            => __( '%s的操作', 'sakura-imgopt' ),
			'selectAll'              => __( '全选当前文件', 'sakura-imgopt' ),
			'selectAllShort'         => __( '全选', 'sakura-imgopt' ),
			'selectNoneShort'        => __( '取消全选', 'sakura-imgopt' ),
			'selectHint'             => __( 'Shift 连选 · Ctrl/⌘ 点选 · Ctrl/⌘A 全选', 'sakura-imgopt' ),
			/* translators: %s: item name */
			'selectFile'             => __( '选择 %s', 'sakura-imgopt' ),
			'sortName'               => __( '文件名', 'sakura-imgopt' ),
			'sortSize'               => __( '文件大小', 'sakura-imgopt' ),
			'sortDate'               => __( '上传时间', 'sakura-imgopt' ),
			'actions'                => __( '操作', 'sakura-imgopt' ),
			/* translators: %s: selected item count */
			'selected'               => __( '已选择 %s 项', 'sakura-imgopt' ),
			'batchMove'              => __( '移动到', 'sakura-imgopt' ),
			'batchDelete'            => __( '申请删除', 'sakura-imgopt' ),
			'batchCancel'            => __( '取消选择', 'sakura-imgopt' ),
			'emptyTitle'             => __( '这里还没有文件', 'sakura-imgopt' ),
			'emptyFolderTitle'       => __( '此文件夹还是空的', 'sakura-imgopt' ),
			'emptySearchTitle'       => __( '没有找到相关文件', 'sakura-imgopt' ),
			'emptyHint'              => __( '上传文件或新建文件夹，开始整理你的空间', 'sakura-imgopt' ),
			'emptyFolderHint'        => __( '上传文件或新建子文件夹，开始整理这个文件夹', 'sakura-imgopt' ),
			'emptySearchHint'        => __( '试试其他关键词或切换分类', 'sakura-imgopt' ),
			'emptyCta'               => __( '上传第一个文件', 'sakura-imgopt' ),
			'emptyTypeTitle'         => __( '这个分类下还没有文件', 'sakura-imgopt' ),
			'emptyTypeHint'          => __( '上传文件后会自动出现在对应分类中', 'sakura-imgopt' ),
			'requestsEmptyTitle'     => __( '还没有删除申请', 'sakura-imgopt' ),
			'requestsEmptyHint'      => __( '在文件或文件夹菜单中选择“申请删除”，填写原因后提交管理员。', 'sakura-imgopt' ),
			'requestHelp'            => __( '删除须经管理员审核并在后台统一执行。申请、批准均不返还空间；文件仍可通过原链接访问。', 'sakura-imgopt' ),
			/* translators: %s: request number */
			'requestCardTitle'       => __( '删除申请 #%s', 'sakura-imgopt' ),
			/* translators: 1: item count, 2: total size */
			'requestCounts'          => __( '%s 项 · %s', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
			/* translators: %s: request reason */
			'requestReason'          => __( '申请原因：%s', 'sakura-imgopt' ),
			/* translators: %s: request creation time */
			'requestCreated'         => __( '申请时间：%s', 'sakura-imgopt' ),
			/* translators: %s: review time */
			'requestReviewed'        => __( '处理时间：%s', 'sakura-imgopt' ),
			'requestDetail'          => __( '查看内容', 'sakura-imgopt' ),
			'withdraw'               => __( '撤回申请', 'sakura-imgopt' ),
			'withdrawTitle'          => __( '撤回删除申请', 'sakura-imgopt' ),
			'withdrawHelp'           => __( '撤回后恢复相关文件的修改权限，不影响文件内容和空间。', 'sakura-imgopt' ),
			'withdrawDone'           => __( '删除申请已撤回', 'sakura-imgopt' ),
			'withdrawing'            => __( '撤回中…', 'sakura-imgopt' ),
			/* translators: %s: request number */
			'requestDetailTitle'     => __( '删除申请 #%s', 'sakura-imgopt' ),
			'requestItems'           => __( '申请内容', 'sakura-imgopt' ),
			'requestItemsEmpty'      => __( '暂无内容', 'sakura-imgopt' ),
			/* translators: 1: object type, 2: size or count */
			'requestItemMeta'        => __( '%s · %s', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
			/* translators: %s: reviewer note */
			'requestNote'            => __( '处理说明：%s', 'sakura-imgopt' ),
			'typeFile'               => __( '文件', 'sakura-imgopt' ),
			'typeFolder'             => __( '文件夹', 'sakura-imgopt' ),
			'preview'                => __( '预览文件', 'sakura-imgopt' ),
			/* translators: %s: file name */
			'previewFileLabel'       => __( '预览 %s', 'sakura-imgopt' ),
			'download'               => __( '下载文件', 'sakura-imgopt' ),
			'rename'                 => __( '重命名', 'sakura-imgopt' ),
			'move'                   => __( '移动到', 'sakura-imgopt' ),
			'requestDelete'          => __( '申请删除', 'sakura-imgopt' ),
			'viewRequests'           => __( '查看删除申请', 'sakura-imgopt' ),
			'close'                  => __( '关闭', 'sakura-imgopt' ),
			'cancel'                 => __( '取消', 'sakura-imgopt' ),
			'confirm'                => __( '确定', 'sakura-imgopt' ),
			'processing'             => __( '处理中…', 'sakura-imgopt' ),
			'newFolderTitle'         => __( '新建文件夹', 'sakura-imgopt' ),
			'folderName'             => __( '文件夹名称', 'sakura-imgopt' ),
			'create'                 => __( '创建', 'sakura-imgopt' ),
			'renameFileTitle'        => __( '重命名文件', 'sakura-imgopt' ),
			'renameFolderTitle'      => __( '重命名文件夹', 'sakura-imgopt' ),
			'newName'                => __( '新名称', 'sakura-imgopt' ),
			'save'                   => __( '保存', 'sakura-imgopt' ),
			'moveTitle'              => __( '移动到', 'sakura-imgopt' ),
			'moveHelp'               => __( '移动不改变文件链接；审批中的相关目录暂不可选。', 'sakura-imgopt' ),
			'moveHere'               => __( '移动到这里', 'sakura-imgopt' ),
			'moveRoot'               => __( '全部文件', 'sakura-imgopt' ),
			'moveEnter'              => __( '进入', 'sakura-imgopt' ),
			'moveEmpty'              => __( '这里没有子文件夹', 'sakura-imgopt' ),
			'nameRequired'           => __( '请输入 1–80 个字符的名称', 'sakura-imgopt' ),
			'nameInvalid'            => __( '名称不能含有路径分隔符或控制字符', 'sakura-imgopt' ),
			'renameExt'              => __( '请保留原文件扩展名', 'sakura-imgopt' ),
			'folderCreated'          => __( '文件夹已创建', 'sakura-imgopt' ),
			'renamed'                => __( '名称已更新', 'sakura-imgopt' ),
			'moved'                  => __( '已移动', 'sakura-imgopt' ),
			'deleteRequestTitle'     => __( '申请删除', 'sakura-imgopt' ),
			/* translators: %s: file name */
			'deleteIntroFile'        => __( '“%s”将提交管理员审核。', 'sakura-imgopt' ),
			/* translators: %s: number of selected files */
			'deleteIntroFiles'       => __( '选中的 %s 个文件将提交管理员审核。', 'sakura-imgopt' ),
			/* translators: %s: folder name */
			'deleteIntroFolder'      => __( '文件夹“%s”及当前全部子内容将提交管理员审核。', 'sakura-imgopt' ),
			'deleteFreeze'           => __( '此时不会删除文件或释放空间；待审核时可撤回。审批中的对象暂时锁定修改。', 'sakura-imgopt' ),
			'deleteReasonLabel'      => __( '删除原因', 'sakura-imgopt' ),
			'deleteReasonPlaceholder' => __( '说明不再需要这些文件的原因', 'sakura-imgopt' ),
			'deleteReasonHelp'       => __( '2–300 字，提交后管理员可见。', 'sakura-imgopt' ),
			'referenceConfirm'       => __( '我已自行确认引用影响，申请删除所选内容', 'sakura-imgopt' ),
			'referenceRequired'      => __( '请先勾选确认项', 'sakura-imgopt' ),
			'deleteReasonError'      => __( '请填写 2–300 字的删除原因', 'sakura-imgopt' ),
			'submitRequest'          => __( '提交申请', 'sakura-imgopt' ),
			'submitting'             => __( '提交中…', 'sakura-imgopt' ),
			'requestSubmitted'       => __( '删除申请已提交，等待管理员审核', 'sakura-imgopt' ),
			'fileInfo'               => __( '文件信息', 'sakura-imgopt' ),
			'size'                   => __( '文件大小', 'sakura-imgopt' ),
			'uploadedAt'             => __( '上传时间', 'sakura-imgopt' ),
			'status'                 => __( '管理状态', 'sakura-imgopt' ),
			'access'                 => __( '访问方式', 'sakura-imgopt' ),
			'linkAccess'             => __( '媒体链接访问', 'sakura-imgopt' ),
			'stillOccupied'          => __( '仍占用空间', 'sakura-imgopt' ),
			'downloadUnavailable'    => __( '文件链接暂不可用，请稍后重试', 'sakura-imgopt' ),
			'statusPending'          => __( '待审核', 'sakura-imgopt' ),
			'statusWithdrawn'        => __( '已撤回', 'sakura-imgopt' ),
			'statusRejected'         => __( '已驳回', 'sakura-imgopt' ),
			'statusApproved'         => __( '已批准 · 待执行', 'sakura-imgopt' ),
			'statusNeedsReview'      => __( '需要重新审核', 'sakura-imgopt' ),
			'statusRevoked'          => __( '已撤销批准', 'sakura-imgopt' ),
			'statusQueued'           => __( '排队中', 'sakura-imgopt' ),
			'statusExecuting'        => __( '执行中', 'sakura-imgopt' ),
			'statusCompleted'        => __( '已完成', 'sakura-imgopt' ),
			'statusPartialFailed'    => __( '部分失败', 'sakura-imgopt' ),
			'statusFailed'           => __( '失败', 'sakura-imgopt' ),
			'statusStopped'          => __( '已停止', 'sakura-imgopt' ),
			'statusUnknown'          => __( '处理中', 'sakura-imgopt' ),
			'groupImage'             => __( '图片', 'sakura-imgopt' ),
			'groupDocument'          => __( '文档', 'sakura-imgopt' ),
			'groupArchive'           => __( '压缩包', 'sakura-imgopt' ),
			'groupVideo'             => __( '视频', 'sakura-imgopt' ),
			'groupAudio'             => __( '音频', 'sakura-imgopt' ),
			'groupFile'              => __( '文件', 'sakura-imgopt' ),
			'uploadQueue'            => __( '上传文件', 'sakura-imgopt' ),
			'uploading'              => __( '上传中', 'sakura-imgopt' ),
			'uploadWaiting'          => __( '等待上传', 'sakura-imgopt' ),
			'uploadDone'             => __( '上传完成', 'sakura-imgopt' ),
			'uploadFailed'           => __( '上传失败', 'sakura-imgopt' ),
			'uploadRetry'            => __( '重试', 'sakura-imgopt' ),
			'uploadRemove'           => __( '移除', 'sakura-imgopt' ),
			'uploadClear'            => __( '清除已完成', 'sakura-imgopt' ),
			'uploadPrecheckType'     => __( '文件类型不支持', 'sakura-imgopt' ),
			'uploadPrecheckSize'     => __( '超过单文件大小限制', 'sakura-imgopt' ),
			'uploadPrecheckEmpty'    => __( '不能上传空文件', 'sakura-imgopt' ),
			'uploadPrecheckQuota'    => __( '剩余空间不足', 'sakura-imgopt' ),
			/* translators: %s: uploaded file count */
			'uploadAllDone'          => __( '%s 个文件上传完成', 'sakura-imgopt' ),
			/* translators: 1: uploaded count, 2: failed count */
			'uploadPartial'          => __( '%s 个文件上传完成，%s 个未上传', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
			/* translators: %s: error message */
			'uploadFailedMsg'        => __( '上传失败：%s', 'sakura-imgopt' ),
			'uploadWait'             => __( '请等待当前上传完成', 'sakura-imgopt' ),
			'uploadSelectFolder'     => __( '请先选择可上传的目录', 'sakura-imgopt' ),
			'loadFailed'             => __( '加载失败', 'sakura-imgopt' ),
			'retry'                  => __( '重试', 'sakura-imgopt' ),
			'loadMore'               => __( '加载更多', 'sakura-imgopt' ),
			'loading'                => __( '加载中…', 'sakura-imgopt' ),
			'allLoaded'              => __( '已全部加载', 'sakura-imgopt' ),
			'networkError'           => __( '网络异常，请检查网络后重试', 'sakura-imgopt' ),
			'sessionExpired'         => __( '登录状态已失效，请刷新页面后重试', 'sakura-imgopt' ),
			'actionFailed'           => __( '操作失败，请重试', 'sakura-imgopt' ),
			/* translators: %s: search term */
			'searchResults'          => __( '搜索“%s”的结果', 'sakura-imgopt' ),
			'footerNote'             => __( '媒体文件可通过链接访问，请勿上传敏感资料', 'sakura-imgopt' ),
			/* translators: %s: file count */
			'footerCount'            => __( '%s 个文件', 'sakura-imgopt' ),
			'adminNote'              => __( '管理员执行后更新空间', 'sakura-imgopt' ),
		);
	}
}
