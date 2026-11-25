<?php
/**
 * User files tab view.
 *
 * Rendered by \Nboke\UserFiles\Frontend\Tab::content(). The shell is static;
 * the browser fetches space and item data from the `sufm/v1` REST routes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * View data from Tab::content().
 *
 * @var array{strings: array<string, string>, notice: array{version: string, text: string}} $data
 */
$strings = isset( $data['strings'] ) && is_array( $data['strings'] ) ? $data['strings'] : array();
$notice  = isset( $data['notice'] ) && is_array( $data['notice'] ) ? $data['notice'] : array();

$sufm_str = static function ( string $key ) use ( $strings ): string {
	return isset( $strings[ $key ] ) ? (string) $strings[ $key ] : '';
};

$tree_style          = isset( $data['tree_style'] ) && 'mac' === $data['tree_style'] ? 'mac' : 'windows';
$folder_accent_class = isset( $data['folder_accent_class'] ) && is_string( $data['folder_accent_class'] ) ? $data['folder_accent_class'] : '';
$folder_accent_style = isset( $data['folder_accent_style'] ) && is_string( $data['folder_accent_style'] ) ? $data['folder_accent_style'] : '';
$app_classes         = 'sufm sufm-style-' . $tree_style . ( '' !== $folder_accent_class ? ' ' . $folder_accent_class : '' );
$app_style           = '' !== $folder_accent_style ? ' style="' . esc_attr( $folder_accent_style ) . '"' : '';

$notice_text    = isset( $notice['text'] ) && is_string( $notice['text'] ) ? $notice['text'] : '';
$notice_version = isset( $notice['version'] ) && is_string( $notice['version'] ) ? $notice['version'] : '';

if ( '' === $notice_text ) {
	$notice_text = __( '删除后文章、商品、头像等位置的链接可能失效，请自行确认后再提交', 'sakura-imgopt' );
}
?>
<div class="<?php echo esc_attr( $app_classes ); ?>" id="sufm-app"<?php echo $app_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?> data-sufm data-notice-version="<?php echo esc_attr( $notice_version ); ?>">
	<svg class="sufm-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
		<symbol id="sufm-i-folder" viewBox="0 0 24 24"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2"/></symbol>
		<symbol id="sufm-i-folder-plus" viewBox="0 0 24 24"><path d="M12 19H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4l3 3h7a2 2 0 0 1 2 2v3.5"/><path d="M16 19h6"/><path d="M19 16v6"/></symbol>
		<symbol id="sufm-i-upload" viewBox="0 0 24 24"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><path d="M7 9l5-5 5 5"/><path d="M12 4v12"/></symbol>
		<symbol id="sufm-i-search" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="M21 21l-6-6"/></symbol>
		<symbol id="sufm-i-list" viewBox="0 0 24 24"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M5 6h.01"/><path d="M5 12h.01"/><path d="M5 18h.01"/></symbol>
		<symbol id="sufm-i-grid" viewBox="0 0 24 24"><path d="M5 5h.01"/><path d="M12 5h.01"/><path d="M19 5h.01"/><path d="M5 12h.01"/><path d="M12 12h.01"/><path d="M19 12h.01"/><path d="M5 19h.01"/><path d="M12 19h.01"/><path d="M19 19h.01"/></symbol>
		<symbol id="sufm-i-dots" viewBox="0 0 24 24"><path d="M5 12h.01"/><path d="M12 12h.01"/><path d="M19 12h.01"/></symbol>
		<symbol id="sufm-i-chevron-right" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></symbol>
		<symbol id="sufm-i-chevron-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
		<symbol id="sufm-i-file-text" viewBox="0 0 24 24"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z"/><path d="M9 9h1"/><path d="M9 13h6"/><path d="M9 17h6"/></symbol>
		<symbol id="sufm-i-x" viewBox="0 0 24 24"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></symbol>
		<symbol id="sufm-i-trash" viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></symbol>
		<symbol id="sufm-i-download" viewBox="0 0 24 24"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><path d="M7 11l5 5 5-5"/><path d="M12 4v12"/></symbol>
		<symbol id="sufm-i-external" viewBox="0 0 24 24"><path d="M12 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/><path d="M11 13l9-9"/><path d="M15 4h5v5"/></symbol>
		<symbol id="sufm-i-move" viewBox="0 0 24 24"><path d="M18 9l3 3-3 3"/><path d="M15 12h6"/><path d="M6 9l-3 3 3 3"/><path d="M3 12h6"/><path d="M9 18l3 3 3-3"/><path d="M12 15v6"/><path d="M15 6l-3-3-3 3"/><path d="M12 3v6"/></symbol>
		<symbol id="sufm-i-check" viewBox="0 0 24 24"><path d="M5 12l5 5 10-10"/></symbol>
		<symbol id="sufm-i-cloud" viewBox="0 0 24 24"><path d="M6.657 18C4.085 18 2 15.993 2 13.517c0-2.475 2.085-4.482 4.657-4.482.393-1.762 1.794-3.2 3.675-3.773 1.88-.572 3.956-.193 5.444 1 1.488 1.19 2.162 3.007 1.77 4.769h.99c1.913 0 3.464 1.56 3.464 3.486C22 16.44 20.449 18 18.535 18H6.657"/></symbol>
		<symbol id="sufm-i-shield" viewBox="0 0 24 24"><path d="M12 3a12 12 0 0 0 8.5 3 12 12 0 0 1-8.5 15 12 12 0 0 1-8.5-15 12 12 0 0 0 8.5-3"/><path d="M12 11h.01"/><path d="M12 12v2.5"/></symbol>
		<symbol id="sufm-i-refresh" viewBox="0 0 24 24"><path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 5v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 19v-4h-4"/></symbol>
		<symbol id="sufm-i-arrow-left" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M5 12l6 6"/><path d="M5 12l6-6"/></symbol>
		<symbol id="sufm-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></symbol>
		<symbol id="sufm-i-pie" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M12 3v9h9"/></symbol>
		<symbol id="sufm-i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3 10h18"/></symbol>
	</svg>

	<section class="sufm-card sufm-storage" aria-labelledby="sufm-title">
		<div class="sufm-storage-head">
			<div class="sufm-storage-title">
				<span class="sufm-storage-icon" aria-hidden="true"><svg class="sufm-icon"><use href="#sufm-i-folder"></use></svg></span>
				<div>
					<h2 id="sufm-title"><?php esc_html_e( '文件管理', 'sakura-imgopt' ); ?></h2>
					<p><?php esc_html_e( '整理你的文件，让每一份灵感井井有条', 'sakura-imgopt' ); ?></p>
				</div>
			</div>
			<span class="sufm-badge"><?php esc_html_e( '我的空间', 'sakura-imgopt' ); ?></span>
		</div>

		<div class="sufm-space-info">
			<div class="sufm-space-used"><strong id="sufm-used">—</strong><span class="sufm-muted"> / <span id="sufm-quota">—</span></span></div>
			<div class="sufm-space-free"><?php esc_html_e( '剩余', 'sakura-imgopt' ); ?> <b id="sufm-free">—</b></div>
		</div>

		<div class="sufm-track" id="sufm-track" role="progressbar" aria-label="<?php echo esc_attr__( '空间已使用', 'sakura-imgopt' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<i id="sufm-progress"></i>
		</div>

		<div class="sufm-space-foot">
			<span id="sufm-counts"><?php esc_html_e( '正在加载空间…', 'sakura-imgopt' ); ?></span>
			<span><?php esc_html_e( '待处理申请仍占用空间', 'sakura-imgopt' ); ?></span>
		</div>
	</section>

	<div class="sufm-quota-warn" id="sufm-quota-warn" role="status" hidden>
		<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-shield"></use></svg>
		<span id="sufm-quota-warn-text"></span>
	</div>

	<section class="sufm-card sufm-manager" aria-label="<?php echo esc_attr__( '文件管理', 'sakura-imgopt' ); ?>">
		<div class="sufm-head">
		<span class="sufm-brand" aria-hidden="true">
			<span class="sufm-brand-icon"><svg class="sufm-icon"><use href="#sufm-i-folder"></use></svg></span>
			<span class="sufm-brand-title"><?php esc_html_e( '文件管理', 'sakura-imgopt' ); ?></span>
		</span>
		<div class="sufm-tabs" role="tablist" aria-label="<?php echo esc_attr__( '文件类型', 'sakura-imgopt' ); ?>">
			<button type="button" class="sufm-tab is-active" role="tab" aria-selected="true" data-sufm-filter="all"><?php esc_html_e( '全部文件', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab" role="tab" aria-selected="false" data-sufm-filter="image"><?php esc_html_e( '图片', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab" role="tab" aria-selected="false" data-sufm-filter="document"><?php esc_html_e( '文档', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab" role="tab" aria-selected="false" data-sufm-filter="archive"><?php esc_html_e( '压缩包', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab" role="tab" aria-selected="false" data-sufm-filter="video"><?php esc_html_e( '视频', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab" role="tab" aria-selected="false" data-sufm-filter="audio"><?php esc_html_e( '音频', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-tab sufm-trash-tab" role="tab" aria-selected="false" data-sufm-filter="requests">
				<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-file-text"></use></svg>
				<span><?php esc_html_e( '删除申请', 'sakura-imgopt' ); ?></span>
				<span class="sufm-tab-count" id="sufm-request-count" hidden></span>
			</button>
		</div>

		<div class="sufm-toolbar">
			<div class="sufm-toolbar-main">
				<span class="sufm-meter">
					<span class="sufm-meter-label"><b id="sufm-used-mini">—</b><i> / </i><span id="sufm-quota-mini">—</span></span>
					<span class="sufm-meter-track" id="sufm-track-mini" role="progressbar" aria-label="<?php echo esc_attr__( '空间已使用', 'sakura-imgopt' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i id="sufm-progress-mini"></i></span>
				</span>
				<?php if ( \Nboke\UserFiles\Space_Panel::has_panel() ) : ?>
					<button type="button" class="sufm-btn" id="sufm-space-open"><?php esc_html_e( '扩容', 'sakura-imgopt' ); ?></button>
				<?php endif; ?>
				<button type="button" class="sufm-btn sufm-btn-capacity" id="sufm-capacity-open" aria-haspopup="dialog" aria-controls="sufm-capacity-dialog" aria-label="<?php echo esc_attr__( '容量详情', 'sakura-imgopt' ); ?>">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-pie"></use></svg>
					<span class="sufm-capacity-label"><?php esc_html_e( '容量详情', 'sakura-imgopt' ); ?></span>
				</button>
				<button type="button" class="sufm-btn sufm-btn-primary" id="sufm-upload">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-upload"></use></svg>
					<?php esc_html_e( '上传文件', 'sakura-imgopt' ); ?>
				</button>
				<button type="button" class="sufm-btn" id="sufm-new-folder">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-folder-plus"></use></svg>
					<?php esc_html_e( '新建文件夹', 'sakura-imgopt' ); ?>
				</button>
				<input type="file" id="sufm-file-input" class="sufm-sr-only" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.zip,.mp4,.mp3">
			</div>

			<div class="sufm-toolbar-view">
				<label class="sufm-search">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-search"></use></svg>
					<input type="search" id="sufm-search" placeholder="<?php echo esc_attr( $sufm_str( 'searchPlaceholder' ) ); ?>" aria-label="<?php echo esc_attr( $sufm_str( 'searchPlaceholder' ) ); ?>" autocomplete="off">
					<button type="button" class="sufm-search-clear" data-sufm-search-clear hidden aria-label="<?php esc_attr_e( '清空搜索', 'sakura-imgopt' ); ?>" title="<?php esc_attr_e( '清空搜索', 'sakura-imgopt' ); ?>">
						<svg class="sufm-icon" aria-hidden="true" focusable="false"><use href="#sufm-i-x"></use></svg>
					</button>
				</label>
				<div class="sufm-view" role="group" aria-label="<?php echo esc_attr__( '视图切换', 'sakura-imgopt' ); ?>">
					<button type="button" class="is-active" data-sufm-view="list" aria-label="<?php echo esc_attr( $sufm_str( 'listView' ) ); ?>" aria-pressed="true">
						<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-list"></use></svg>
					</button>
					<button type="button" data-sufm-view="grid" aria-label="<?php echo esc_attr( $sufm_str( 'gridView' ) ); ?>" aria-pressed="false">
						<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-grid"></use></svg>
					</button>
				</div>
			</div>
		</div>

		<div class="sufm-path" title="<?php echo esc_attr( $sufm_str( 'selectHint' ) ); ?>">
			<nav class="sufm-crumbs" id="sufm-crumbs" aria-label="<?php echo esc_attr__( '当前位置', 'sakura-imgopt' ); ?>">
				<button type="button" class="sufm-crumb" data-sufm-folder="0"><?php esc_html_e( '全部文件', 'sakura-imgopt' ); ?></button>
			</nav>
			<span class="sufm-path-tools">
				<span class="sufm-select-tip"><?php echo esc_html( $sufm_str( 'selectHint' ) ); ?></span>
				<button type="button" class="sufm-btn sufm-btn-sm sufm-select-all" data-sufm-select-all hidden aria-pressed="false"><?php echo esc_html( $sufm_str( 'selectAllShort' ) ); ?></button>
				<span class="sufm-path-count" id="sufm-path-count"></span>
			</span>
		</div>

		<div class="sufm-alert" id="sufm-alert" role="alert" hidden>
			<span id="sufm-alert-text"></span>
			<button type="button" class="sufm-btn sufm-btn-sm" id="sufm-alert-retry"><?php esc_html_e( '重试', 'sakura-imgopt' ); ?></button>
		</div>

		<div class="sufm-batch" id="sufm-batch" hidden>
			<span id="sufm-batch-label"></span>
			<div class="sufm-batch-actions">
				<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-batch="up" hidden><?php esc_html_e( '移到上一级', 'sakura-imgopt' ); ?></button>
				<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-batch="move"><?php esc_html_e( '移动到', 'sakura-imgopt' ); ?></button>
				<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-batch="delete"><?php esc_html_e( '申请删除', 'sakura-imgopt' ); ?></button>
				<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-batch="cancel"><?php esc_html_e( '取消选择', 'sakura-imgopt' ); ?></button>
			</div>
		</div>

		<div class="sufm-folders" id="sufm-folders" hidden></div>
		</div><!-- /.sufm-head -->

		<div class="sufm-files" id="sufm-files" aria-busy="true">
			<div class="sufm-skeleton" aria-hidden="true">
				<div class="sufm-skel-row"><span class="sufm-sk sufm-sk-check"></span><span class="sufm-sk sufm-sk-tile"></span><span class="sufm-sk sufm-sk-name"></span><span class="sufm-sk sufm-sk-size"></span><span class="sufm-sk sufm-sk-date"></span><span class="sufm-sk sufm-sk-more"></span></div>
				<div class="sufm-skel-row"><span class="sufm-sk sufm-sk-check"></span><span class="sufm-sk sufm-sk-tile"></span><span class="sufm-sk sufm-sk-name"></span><span class="sufm-sk sufm-sk-size"></span><span class="sufm-sk sufm-sk-date"></span><span class="sufm-sk sufm-sk-more"></span></div>
				<div class="sufm-skel-row"><span class="sufm-sk sufm-sk-check"></span><span class="sufm-sk sufm-sk-tile"></span><span class="sufm-sk sufm-sk-name"></span><span class="sufm-sk sufm-sk-size"></span><span class="sufm-sk sufm-sk-date"></span><span class="sufm-sk sufm-sk-more"></span></div>
				<div class="sufm-skel-row"><span class="sufm-sk sufm-sk-check"></span><span class="sufm-sk sufm-sk-tile"></span><span class="sufm-sk sufm-sk-name"></span><span class="sufm-sk sufm-sk-size"></span><span class="sufm-sk sufm-sk-date"></span><span class="sufm-sk sufm-sk-more"></span></div>
				<div class="sufm-skel-row"><span class="sufm-sk sufm-sk-check"></span><span class="sufm-sk sufm-sk-tile"></span><span class="sufm-sk sufm-sk-name"></span><span class="sufm-sk sufm-sk-size"></span><span class="sufm-sk sufm-sk-date"></span><span class="sufm-sk sufm-sk-more"></span></div>
			</div>
		</div>

		<div class="sufm-drop" id="sufm-drop">
			<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-upload"></use></svg>
			<p><?php esc_html_e( '拖拽文件到这里，或', 'sakura-imgopt' ); ?> <button type="button" id="sufm-drop-select"><?php esc_html_e( '选择文件', 'sakura-imgopt' ); ?></button></p>
			<small><?php esc_html_e( '单个文件最大', 'sakura-imgopt' ); ?> <span id="sufm-max-file">50 MB</span><?php esc_html_e( '，支持图片、文档、压缩包及音视频', 'sakura-imgopt' ); ?></small>
		</div>

		<div class="sufm-filefoot">
			<span class="sufm-filefoot-hint">
				<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-cloud"></use></svg>
				<?php esc_html_e( '媒体文件可通过链接访问，请勿上传敏感资料', 'sakura-imgopt' ); ?>
			</span>
			<span class="sufm-filefoot-side">
				<span id="sufm-footer-count"></span>
				<span class="sufm-filefoot-owner">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-shield"></use></svg>
					<?php esc_html_e( '文件按账号独立管理，管理员可在网站媒体库中维护。', 'sakura-imgopt' ); ?>
				</span>
			</span>
		</div>
	</section>

	<div class="sufm-toast" id="sufm-toast" role="status" aria-live="polite" hidden></div>
	<aside class="sufm-queue" id="sufm-queue" aria-label="<?php echo esc_attr__( '上传队列', 'sakura-imgopt' ); ?>" hidden></aside>

	<?php echo \Nboke\UserFiles\Space_Panel::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted markup built by the plugin. ?>

	<dialog class="sufm-dialog" id="sufm-modal" aria-labelledby="sufm-modal-title">
		<form method="dialog" class="sufm-dialog-form" id="sufm-modal-form" novalidate>
			<header class="sufm-dialog-head">
				<h2 id="sufm-modal-title"></h2>
				<button type="button" class="sufm-icon-btn" data-sufm-modal-close aria-label="<?php echo esc_attr__( '关闭', 'sakura-imgopt' ); ?>">
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-x"></use></svg>
				</button>
			</header>
			<div class="sufm-dialog-body" id="sufm-modal-body"></div>
			<footer class="sufm-dialog-actions">
				<button type="button" class="sufm-btn" data-sufm-modal-close><?php esc_html_e( '取消', 'sakura-imgopt' ); ?></button>
				<button type="submit" class="sufm-btn sufm-btn-primary" id="sufm-modal-confirm"><?php esc_html_e( '确定', 'sakura-imgopt' ); ?></button>
			</footer>
		</form>
	</dialog>

	<dialog class="sufm-dialog sufm-capacity" id="sufm-capacity-dialog" aria-labelledby="sufm-capacity-title">
		<header class="sufm-capacity-head">
			<span class="sufm-capacity-mark" aria-hidden="true"><svg class="sufm-icon"><use href="#sufm-i-pie"></use></svg></span>
			<div class="sufm-capacity-heading">
				<h2 id="sufm-capacity-title"><?php esc_html_e( '容量详情', 'sakura-imgopt' ); ?></h2>
				<p><?php esc_html_e( '查看容量构成与到期时间', 'sakura-imgopt' ); ?></p>
			</div>
			<button type="button" class="sufm-icon-btn" data-sufm-capacity-close aria-label="<?php echo esc_attr__( '关闭', 'sakura-imgopt' ); ?>">
				<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-x"></use></svg>
			</button>
		</header>

		<div class="sufm-capacity-body" data-sufm-capacity-body aria-busy="true">
			<div class="sufm-capacity-loading" data-sufm-capacity-loading>
				<span class="sufm-capacity-spin" aria-hidden="true"></span>
				<p><?php esc_html_e( '正在读取容量明细…', 'sakura-imgopt' ); ?></p>
			</div>

			<div class="sufm-capacity-error" data-sufm-capacity-error role="alert" hidden>
				<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-shield"></use></svg>
				<p><?php esc_html_e( '容量明细读取失败，请稍后重试。', 'sakura-imgopt' ); ?></p>
				<button type="button" class="sufm-btn sufm-btn-sm" data-sufm-capacity-retry><?php esc_html_e( '重试', 'sakura-imgopt' ); ?></button>
			</div>

			<div class="sufm-capacity-content" data-sufm-capacity-content hidden>
				<div class="sufm-capacity-hero">
					<div class="sufm-capacity-total">
						<span><?php esc_html_e( '总容量', 'sakura-imgopt' ); ?></span>
						<strong><span data-sufm-capacity-value>—</span><small data-sufm-capacity-unit></small></strong>
					</div>
					<dl class="sufm-capacity-stats">
						<div><dt><?php esc_html_e( '已用', 'sakura-imgopt' ); ?></dt><dd data-sufm-capacity-used>—</dd></div>
						<div><dt><?php esc_html_e( '可用', 'sakura-imgopt' ); ?></dt><dd data-sufm-capacity-free>—</dd></div>
					</dl>
				</div>

				<div class="sufm-capacity-track" data-sufm-capacity-track role="progressbar" aria-label="<?php echo esc_attr__( '容量使用率', 'sakura-imgopt' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<i data-sufm-capacity-fill></i>
				</div>
				<div class="sufm-capacity-track-foot">
					<span data-sufm-capacity-percent>0%</span>
					<span data-sufm-capacity-files></span>
				</div>

				<div class="sufm-capacity-section">
					<div class="sufm-capacity-section-head">
						<h3><?php esc_html_e( '容量来源', 'sakura-imgopt' ); ?></h3>
						<span data-sufm-capacity-count></span>
					</div>
					<ul class="sufm-capacity-list" data-sufm-capacity-list></ul>
				</div>

				<div class="sufm-capacity-note" data-sufm-capacity-note hidden>
					<svg class="sufm-icon" aria-hidden="true"><use href="#sufm-i-clock"></use></svg>
					<p data-sufm-capacity-note-text></p>
				</div>
			</div>
		</div>

		<footer class="sufm-capacity-foot">
			<button type="button" class="sufm-btn" data-sufm-capacity-close><?php esc_html_e( '关闭', 'sakura-imgopt' ); ?></button>
			<button type="button" class="sufm-btn sufm-btn-primary" data-sufm-capacity-expand hidden><?php esc_html_e( '扩容容量', 'sakura-imgopt' ); ?></button>
		</footer>
	</dialog>

	<div class="sufm-sr-only" id="sufm-live" aria-live="polite"></div>
</div>
