<?php
/**
 * Front-end assets for the editor panel.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Editor;

use Nboke\UserFiles\Frontend\Tab;
use Nboke\UserFiles\Support\Assets as Versioned;

defined( 'ABSPATH' ) || exit;

/**
 * One stylesheet, one script, one config object.
 */
final class Assets {

	/**
	 * Enqueue on the two writing screens only.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		// The panel owns its own copies of the stylesheet and the manager script
		// (assets/editor-picker.css, assets/editor-picker-manager.js). They were
		// forked from the user-centre pair so panel tweaks never disturb
		// /user/files. Only the config object is still built by Tab.
		wp_enqueue_style(
			'sufm-picker',
			SAKURA_TOOLS_URL . 'assets/editor-picker.css',
			array(),
			Versioned::version( 'assets/editor-picker.css' )
		);

		wp_enqueue_script(
			'sufm-picker-manager',
			SAKURA_TOOLS_URL . 'assets/editor-picker-manager.js',
			array(),
			Versioned::version( 'assets/editor-picker-manager.js' ),
			true
		);

		wp_localize_script( 'sufm-picker-manager', 'sufmData', ( new Tab() )->script_data() );

		wp_enqueue_script(
			'sufm-picker',
			SAKURA_TOOLS_URL . 'assets/editor-picker.js',
			array( 'sufm-picker-manager' ),
			Versioned::version( 'assets/editor-picker.js' ),
			true
		);

		wp_localize_script(
			'sufm-picker',
			'SUFM_EDITOR',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'sufm/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'editorId'   => Context::EDITOR_ID,
				'minWidth'   => 1360,
				'widths'     => array( 400, 360, 320, 300 ),
				'strings'    => array(
					'loading'    => __( '正在读取…', 'sakura-imgopt' ),
					'empty'      => __( '这里还没有文件', 'sakura-imgopt' ),
					'noResults'  => __( '没有找到匹配的文件', 'sakura-imgopt' ),
					'failed'     => __( '读取失败，请重试', 'sakura-imgopt' ),
					'retry'      => __( '重试', 'sakura-imgopt' ),
					'insert'     => __( '插入', 'sakura-imgopt' ),
					'inserted'   => __( '已插入', 'sakura-imgopt' ),
					/* translators: %d: number of selected items. */
					'picked'     => __( '已选 %d 项', 'sakura-imgopt' ),
					/* translators: %d: number of items to insert. */
					'insertMany' => __( '插入 %d 个', 'sakura-imgopt' ),
					'orderHint'  => __( '图片优先，按勾选顺序插入', 'sakura-imgopt' ),
					'pickHint'   => __( '单击选中，双击直接插入', 'sakura-imgopt' ),
					'folders'    => __( '子文件夹', 'sakura-imgopt' ),
					'files'      => __( '文件', 'sakura-imgopt' ),
					'allFiles'   => __( '全部文件', 'sakura-imgopt' ),
					'unassigned' => __( '未整理', 'sakura-imgopt' ),
					'back'       => __( '返回上级', 'sakura-imgopt' ),
					/* translators: %d: number of files. */
					'total'      => __( '%d 个文件', 'sakura-imgopt' ),
					'narrow'     => __( '窗口宽度不足 1360px，文件面板已收起', 'sakura-imgopt' ),
					'fold'       => __( '收起文件空间', 'sakura-imgopt' ),
					'download'   => __( '下载', 'sakura-imgopt' ),
					'currentFolder' => __( '当前位置', 'sakura-imgopt' ),
					'moveSearch' => __( '搜索文件夹', 'sakura-imgopt' ),
					'noMatch'    => __( '没有匹配的文件夹。', 'sakura-imgopt' ),
					'expand'     => __( '展开', 'sakura-imgopt' ),
					'collapse'   => __( '收起', 'sakura-imgopt' ),
					'viewGrid'   => __( '网格视图', 'sakura-imgopt' ),
					'viewList'   => __( '列表视图', 'sakura-imgopt' ),
					/* translators: %d: total number of items. */
					'items'      => __( '共 %d 项', 'sakura-imgopt' ),
					'loadMore'   => __( '加载更多', 'sakura-imgopt' ),
					'emptyHint'  => __( '这里还没有上传过文件', 'sakura-imgopt' ),
					'searchHint' => __( '换一个关键词试试', 'sakura-imgopt' ),
				),
			)
		);
	}
}
