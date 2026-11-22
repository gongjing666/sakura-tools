<?php
/**
 * Admin page controller for the "media manager" tab of the file space module.
 *
 * The page replaces the media library's browsing surface for moderation work.
 * WordPress owns two different selection models — the list table's checkboxes
 * plus its bulk-action dropdown, and the grid's Backbone collection — and the
 * plugin has to keep bridging them by replacing their DOM. Every one of those
 * bridges is a promise to keep working across a core update.
 *
 * This screen instead paints its own two-pane browser (owners on the left, files
 * on the right) from REST payloads, so core owns none of its behaviour: there is
 * no `.tablenav` to lose its bindings, no `media-frame` to re-render, and no
 * `action2` mirror to keep in sync.
 *
 * What it does *not* do: it does not replace the media library. `upload.php`
 * keeps every existing capability, and the attachment modal used by the editor
 * is untouched. Both takedown entry points call the same service, so a file
 * removed from here and a file removed from there leave the same audit trail.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Services\Takedown;
use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Support\Hook_Loader;

/**
 * Registers the assets and the view of the media manager tab.
 */
final class Media_Manager {

	/**
	 * Tab id inside the files module.
	 */
	public const TAB = 'media';

	/**
	 * Hook registration.
	 *
	 * @param Hook_Loader $hooks Plugin hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// Registered immediately: Menu::render() resolves renderers on admin_menu,
		// so waiting for admin_init would be too late on some requests.
		Registry::register_renderer( 'files', self::TAB, array( $this, 'render' ) );
	}

	/**
	 * Enqueues the page assets, only on this tab.
	 *
	 * @param string $hook_suffix Current admin page hook (unused).
	 * @return void
	 */
	public function enqueue( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! class_exists( Registry::class ) || ! Registry::on( 'files', self::TAB ) ) {
			return;
		}

		Page_Assets::set_data( 'files-media', $this->page_data() );
		Assets::enqueue_page();
	}

	/**
	 * Controller data for assets/ui/js/pages/files-media.js.
	 *
	 * Every user-facing string lives here so the script never embeds untranslated
	 * text and the page keeps the same copy when the locale changes.
	 *
	 * @return array<string,mixed>
	 */
	private function page_data(): array {
		$reasons = array();

		foreach ( Takedown::reasons() as $value => $label ) {
			$reasons[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}

		return array(
			'restUrl'     => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'sufm/v1/' ) ) : '',
			'nonce'       => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			'canTakedown' => current_user_can( 'upload_files' ) && Capabilities::can_execute(),
			'reasons'     => $reasons,
			'strings'     => array(
				/* Only the copy the script writes itself lives here. Static labels
				 * are printed by the view: a string in both places would be two
				 * copies to keep in sync, and the POT would carry it twice. */
				'loading'            => __( '正在读取…', 'sakura-imgopt' ),
				'empty'              => __( '这里还没有文件。', 'sakura-imgopt' ),
				'emptyOwner'         => __( '这个账户还没有文件。', 'sakura-imgopt' ),
				'loadFailed'         => __( '加载失败，请稍后重试。', 'sakura-imgopt' ),
				'allOwners'          => __( '全部账户', 'sakura-imgopt' ),
				'folderRoot'         => __( '根目录', 'sakura-imgopt' ),
				'file'               => __( '文件', 'sakura-imgopt' ),
				'size'               => __( '大小', 'sakura-imgopt' ),
				'owner'              => __( '上传者', 'sakura-imgopt' ),
				'select'             => __( '选择', 'sakura-imgopt' ),
				'lockedTip'          => __( '该文件有待审的删除申请', 'sakura-imgopt' ),
				'noSelection'        => __( '先选择文件', 'sakura-imgopt' ),
				'selectedOne'        => __( '已选 1 个文件', 'sakura-imgopt' ),
				/* translators: %d: number of selected files. */
				'selectedMany'       => __( '已选 %d 个文件', 'sakura-imgopt' ),
				/* translators: %d: number of files about to be removed. */
				'takedownMessage'    => __( '即将下架选中的 %d 个文件：本地文件与云端对象都会被删除，且无法撤销。', 'sakura-imgopt' ),
				'takedownNeedReason' => __( '请选择一个下架理由。', 'sakura-imgopt' ),
				/* translators: 1: removed count, 2: partial count, 3: skipped count. */
				'takedownResult'     => __( '已删除 %1$d 个，云端待清理 %2$d 个，跳过 %3$d 个。', 'sakura-imgopt' ),
				/* translators: %d: number of uploaders that received a sanction. */
				'takedownSanctioned' => __( '已处罚 %d 名上传者。', 'sakura-imgopt' ),
				'takedownFailed'     => __( '下架失败，请稍后重试。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Renders the page.
	 *
	 * Menu::render() already blocks unavailable tabs with a 403; this guard only
	 * covers direct renderer invocation from other code paths.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::can_review() ) {
			wp_die( esc_html__( '你没有权限管理媒体文件。', 'sakura-imgopt' ) );
		}

		$view = defined( 'SAKURA_TOOLS_PATH' ) ? SAKURA_TOOLS_PATH . 'includes/views/userfiles-media.php' : '';

		if ( '' === $view || ! is_readable( $view ) ) {
			return;
		}

		$can_takedown = current_user_can( 'upload_files' ) && Capabilities::can_execute();

		include $view;
	}
}
