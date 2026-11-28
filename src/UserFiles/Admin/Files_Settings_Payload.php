<?php
/**
 * Bootstrap payload for the file-space settings island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\Payload_Contract;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the contract payload for the two editable cards.
 */
final class Files_Settings_Payload {

	/**
	 * Page id, also the Layout and Page_Assets key.
	 */
	public const PAGE = 'files-settings';

	/**
	 * Whole bootstrap document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$settings = Settings::get();
		$health   = Health::snapshot();
		$entry    = $this->entry_snapshot( $health );
		$cards    = $this->cards( $settings );
		$page     = $this->page_args( $health, $entry, $cards );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'files', 'settings' ),
			'cards'   => $cards,
			'strings' => $this->strings(),
			'rest'    => Payload_Contract::rest_for(
				rest_url( 'sufm/v1/admin/settings/cards' ),
				self::PAGE
			),
			'alerts'  => $this->alerts( $entry ),
		);
	}

	/**
	 * Page arguments shared by the PHP head and the React page head.
	 *
	 * @param array<string,mixed>            $health Health snapshot.
	 * @param array<string,mixed>            $entry  Entry snapshot.
	 * @param array<int,array<string,mixed>> $cards  Card descriptors.
	 * @return array<string,mixed>
	 */
	private function page_args( array $health, array $entry, array $cards ): array {
		$actions = array();

		if ( empty( $entry['enabled'] ) ) {
			$actions[] = array(
				'label'   => __( '前台入口设置', 'sakura-imgopt' ),
				'url'     => Registry::url( 'files', 'zibll' ),
				'variant' => 'primary',
			);
		}

		$actions[] = array(
			'label'   => __( '打开媒体库', 'sakura-imgopt' ),
			'url'     => admin_url( 'upload.php' ),
			'variant' => 'ghost',
		);

		$actions[] = array(
			'label'   => __( '返回插件首页', 'sakura-imgopt' ),
			'url'     => admin_url( 'admin.php?page=sakura-tools' ),
			'variant' => 'quiet',
		);

		return array(
			'id'       => self::PAGE,
			'module'   => 'files',
			'tab'      => 'settings',
			'eyebrow'  => 'USER FILE SPACE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
			'title'    => __( '文件管理', 'sakura-imgopt' ),
			'lede'     => __( '文件夹树的外观与上传配额策略；前台入口开关在「子比专属」页，媒体库功能始终可用。', 'sakura-imgopt' ),
			'status'   => array(
				'label' => __( '子比桥接', 'sakura-imgopt' ),
				'value' => (string) $health['value'],
				'unit'  => (string) $health['unit'],
				'sub'   => (string) $health['sub'],
				'tone'  => (string) $health['tone'],
			),
			'actions'  => $actions,
			'island'   => true,
			'skeleton' => array(
				'cards' => array_map(
					static fn( array $card ): string => (string) $card['id'],
					$cards
				),
			),
		);
	}

	/**
	 * Entry gate snapshot, used by the page alert and the head action.
	 *
	 * @param array<string,mixed> $health Health snapshot.
	 * @return array<string,mixed>
	 */
	private function entry_snapshot( array $health ): array {
		return array(
			'enabled'  => ! empty( $health['entryEnabled'] ),
			'label'    => (string) $health['entryLabel'],
			'theme'    => (string) $health['theme'],
			'generic'  => ! empty( $health['entryGeneric'] ),
			'url'      => (string) $health['entryUrl'],
			'position' => ! empty( $health['entryGeneric'] )
				? __( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' )
				: __( '用户中心 → 文件管理', 'sakura-imgopt' ),
		);
	}

	/**
	 * Card descriptors in Layout order, each carrying its own data.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $settings ): array {
		$cards = array(
			array(
				'id'          => Settings_Cards::APPEARANCE,
				'kicker'      => 'APPEARANCE',
				'title'       => __( '文件夹样式', 'sakura-imgopt' ),
				'description' => __( '决定媒体库左侧文件夹树的观感，保存后立即生效。', 'sakura-imgopt' ),
				'placement'   => Layout::card( self::PAGE, Settings_Cards::APPEARANCE ),
			),
			array(
				'id'          => Settings_Cards::SETTINGS,
				'kicker'      => 'POLICY',
				'title'       => __( '上传与配额策略', 'sakura-imgopt' ),
				'description' => __( '这里是唯一默认值来源；保存后立即对新请求生效。', 'sakura-imgopt' ),
				'placement'   => Layout::card( self::PAGE, Settings_Cards::SETTINGS ),
			),
		);

		foreach ( $cards as $index => $card ) {
			$cards[ $index ] = array_merge( $card, Settings_Cards::data( (string) $card['id'], $settings ) );
		}

		return $cards;
	}

	/**
	 * Persistent state, not an event: the front-end entry is still off.
	 *
	 * @param array<string,mixed> $entry Entry snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	private function alerts( array $entry ): array {
		if ( ! empty( $entry['enabled'] ) ) {
			return array();
		}

		return array(
			array(
				'tone'    => 'warn',
				'kicker'  => 'GET STARTED',
				'title'   => __( '前台入口尚未启用', 'sakura-imgopt' ),
				'message' => sprintf(
					/* translators: 1: active theme name, 2: where the entry would appear. */
					__( '当前主题：%1$s；入口位置：%2$s。勾选“启用前台入口”并保存后，用户中心入口与前台上传接口才会开启；媒体库的多用户文件夹与归属转移不受影响。', 'sakura-imgopt' ),
					(string) $entry['theme'],
					(string) $entry['position']
				),
				'action'  => array(
					'label' => __( '前往前台入口', 'sakura-imgopt' ),
					'url'   => Registry::url( 'files', 'zibll' ),
				),
			),
		);
	}

	/**
	 * UI copy for the island.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saveAppearance'   => __( '保存样式', 'sakura-imgopt' ),
			'savingAppearance' => __( '正在保存…', 'sakura-imgopt' ),
			'savePolicy'       => __( '保存策略', 'sakura-imgopt' ),
			'savingPolicy'     => __( '正在保存…', 'sakura-imgopt' ),
			'styleLegend'      => __( '选择样式', 'sakura-imgopt' ),
			'styleHint'        => __( '同时作用于媒体库左侧文件夹树和前台用户中心的文件管理，两侧图形与配色保持一致。', 'sakura-imgopt' ),
			'accentLegend'     => __( '文件夹图标颜色', 'sakura-imgopt' ),
			'accentHint'       => __( '只给文件夹图标上色，标题文字保持中性灰，避免整棵树的花色压过内容。', 'sakura-imgopt' ),
			'customColor'      => __( '自定义颜色', 'sakura-imgopt' ),
			'hexLabel'         => __( '十六进制', 'sakura-imgopt' ),
			'hexPlaceholder'   => '#c8932b',
			'hexInvalid'       => __( '请输入 #RRGGBB 格式的颜色。', 'sakura-imgopt' ),
			'applyColor'       => __( '使用此颜色', 'sakura-imgopt' ),
			'currentSummary'   => __( '当前样式：{style}；图标颜色：{accent}。', 'sakura-imgopt' ),
			'previewImages'    => __( '图片素材', 'sakura-imgopt' ),
			'previewArchive'   => __( '项目归档', 'sakura-imgopt' ),
			'previewInbox'     => __( '待整理', 'sakura-imgopt' ),
			'themeLimits'      => __( '主题上传限制', 'sakura-imgopt' ),
			/* translators: %s: theme setting source label. */
			'themeReadFrom'    => __( '读取自 %s。保存本页时会把下面两个值写回主题设置，避免主题先于插件拦截上传。', 'sakura-imgopt' ),
			/* translators: %s: last sync timestamp. */
			'themeSyncedAt'    => __( '最近同步：%s', 'sakura-imgopt' ),
			'allowedTypes'     => __( '允许的文件类型', 'sakura-imgopt' ),
			'allowedHint'      => __( 'SVG、HTML、脚本和可执行文件始终不会被接受，即使在这里勾选也不会生效。', 'sakura-imgopt' ),
			'failed'           => __( '保存失败，请稍后重试。', 'sakura-imgopt' ),
			'network'          => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
			'close'            => __( '关闭', 'sakura-imgopt' ),
			'dismiss'          => __( '知道了', 'sakura-imgopt' ),
			'closeIcon'        => UI\Modal::close_icon(),
			'mascot'           => UI\Modal::mascot(),
		);
	}
}
