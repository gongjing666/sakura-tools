<?php
/**
 * Bootstrap payload for the file-space settings island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Services\Theme_Limits;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the whole files-settings document once, for PHP head and React body.
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
		$cards    = $this->cards( $entry );
		$page     = $this->page_args( $health );
		$config   = $this->config( $settings, $health, $entry, $cards );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'files', 'settings' ),
			'cards'   => $cards,
			'config'  => $config,
		);
	}

	/**
	 * Page arguments used by the PHP head and the React page head.
	 *
	 * @param array<string,mixed> $health Health snapshot.
	 * @return array<string,mixed>
	 */
	private function page_args( array $health ): array {
		return array(
			'id'      => self::PAGE,
			'module'  => 'files',
			'tab'     => 'settings',
			'eyebrow' => 'USER FILE SPACE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
			'title'   => __( '文件管理', 'sakura-imgopt' ),
			'lede'    => __( '文件夹树的外观与上传配额策略；前台入口开关在「子比专属」页，媒体库功能始终可用。', 'sakura-imgopt' ),
			'status'  => array(
				'label' => __( '子比桥接', 'sakura-imgopt' ),
				'value' => (string) $health['value'],
				'unit'  => (string) $health['unit'],
				'sub'   => (string) $health['sub'],
				'tone'  => (string) $health['tone'],
			),
			'actions' => array(
				array(
					'label'   => __( '打开媒体库', 'sakura-imgopt' ),
					'url'     => admin_url( 'upload.php' ),
					'variant' => 'ghost',
				),
				array(
					'label'   => __( '返回插件首页', 'sakura-imgopt' ),
					'url'     => admin_url( 'admin.php?page=sakura-tools' ),
					'variant' => 'quiet',
				),
			),
		);
	}

	/**
	 * Entry gate snapshot, shared by the optional card and the body copy.
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
			'hint'     => __( '在「子比专属 → 前台入口」勾选“启用前台入口”并保存后，用户中心的入口与前台上传接口才会开启；媒体库的多用户文件夹、整理与归属转移不受此开关影响。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Card metadata. The entry card, when present, pushes the other two down
	 * one row so the layout does not leave a hole at the top.
	 *
	 * @param array<string,mixed> $entry Entry snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $entry ): array {
		$appearance = Layout::card( self::PAGE, 'appearance' ) ?? array( 1, 1, 12 );
		$settings   = Layout::card( self::PAGE, 'settings' ) ?? array( 2, 1, 12 );
		$offset     = ! empty( $entry['enabled'] ) ? 0 : 1;
		$cards      = array();

		if ( empty( $entry['enabled'] ) ) {
			$cards[] = array(
				'id'          => 'entry-gate',
				'kicker'      => 'GET STARTED',
				'title'       => __( '启用前台入口', 'sakura-imgopt' ),
				'description' => __( '只有前台入口受开关控制：它决定用户中心里是否出现「文件管理」。媒体库的多用户文件夹、整理与归属转移是后台能力，始终可用。', 'sakura-imgopt' ),
				'wide'        => true,
				'placement'   => array( 1, 1, 12 ),
				'actions'     => array(
					array(
						'label' => __( '前往前台入口', 'sakura-imgopt' ),
						'url'   => Registry::url( 'files', 'zibll' ),
						'variant' => 'ghost',
					),
				),
			);
		}

		$cards[] = array(
			'id'          => 'appearance',
			'kicker'      => 'APPEARANCE',
			'title'       => __( '文件夹样式', 'sakura-imgopt' ),
			'description' => __( '决定媒体库左侧文件夹树的观感，保存后立即生效。', 'sakura-imgopt' ),
			'form'        => 'appearance',
			'placement'   => array( (int) $appearance[0] + $offset, (int) $appearance[1], (int) $appearance[2] ),
		);

		$cards[] = array(
			'id'          => 'settings',
			'kicker'      => 'POLICY',
			'title'       => __( '上传与配额策略', 'sakura-imgopt' ),
			'description' => __( '这里是唯一默认值来源；保存后立即对新请求生效。', 'sakura-imgopt' ),
			'form'        => 'settings',
			'placement'   => array( (int) $settings[0] + $offset, (int) $settings[1], (int) $settings[2] ),
		);

		return $cards;
	}

	/**
	 * Everything the React tree needs.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @param array<string,mixed> $health   Health snapshot.
	 * @param array<string,mixed> $entry    Entry snapshot.
	 * @param array<int,array<string,mixed>> $cards Card metadata.
	 * @return array<string,mixed>
	 */
	private function config( array $settings, array $health, array $entry, array $cards ): array {
		$accent = (string) ( $settings['folder_accent'] ?? '' );
		$custom = Settings::is_custom_accent( $accent );
		$style  = 'mac' === (string) ( $settings['tree_style'] ?? 'windows' ) ? 'mac' : 'windows';
		$labels = Settings::accent_choices();
		$palette = Settings::accent_palette();

		$accent_choices = array(
			array(
				'value' => '',
				'label' => (string) ( $labels[''] ?? __( '跟随样式', 'sakura-imgopt' ) ),
				'ink'   => '',
				'fill'  => '',
			),
		);

		foreach ( $labels as $key => $label ) {
			if ( '' === $key ) {
				continue;
			}
			$accent_choices[] = array(
				'value' => (string) $key,
				'label' => (string) $label,
				'ink'   => isset( $palette[ $key ]['ink'] ) ? (string) $palette[ $key ]['ink'] : '',
				'fill'  => isset( $palette[ $key ]['fill'] ) ? (string) $palette[ $key ]['fill'] : '',
			);
		}

		$theme_limits = Theme_Limits::detect();
		$limits       = null;

		if ( is_array( $theme_limits ) ) {
			$limits = array(
				'source'    => (string) ( $theme_limits['label'] ?? '' ),
				'syncedAt'  => (string) ( $settings['theme_upload_limit_synced_at'] ?? '' ),
				'fields'    => array(
					array(
						'key'   => 'theme_upload_limit_ten_minutes',
						'id'    => 'sufm-theme-limit-ten',
						'name'  => 'sufm_settings[theme_upload_limit_ten_minutes]',
						'label' => __( '每 10 分钟上传文件数', 'sakura-imgopt' ),
						'hint'  => __( '与主题「10 分钟」上限一致；0 表示不限制。', 'sakura-imgopt' ),
						'value' => (string) (int) ( $settings['theme_upload_limit_ten_minutes'] ?? 0 ),
					),
					array(
						'key'   => 'theme_upload_limit_day',
						'id'    => 'sufm-theme-limit-day',
						'name'  => 'sufm_settings[theme_upload_limit_day]',
						'label' => __( '每天上传文件数', 'sakura-imgopt' ),
						'hint'  => __( '与主题「每天」上限一致；0 表示不限制。', 'sakura-imgopt' ),
						'value' => (string) (int) ( $settings['theme_upload_limit_day'] ?? 0 ),
					),
				),
			);
		}

		$allowed_types = array();
		$current_types = is_array( $settings['allowed_types'] ?? null ) ? $settings['allowed_types'] : array();

		foreach ( Settings::type_labels() as $mime => $label ) {
			$allowed_types[] = array(
				'mime'    => (string) $mime,
				'label'   => (string) $label,
				'checked' => in_array( (string) $mime, $current_types, true ),
			);
		}

		return array(
			'optionsUrl'  => admin_url( 'options.php' ),
			'optionPage'  => 'sufm_settings_group',
			'nonce'       => wp_create_nonce( 'sufm_settings_group-options' ),
			'option'      => Settings::OPTION,
			'cardField'   => Card_Form::CARD_FIELD,
			// UserFiles\Settings::sanitize() merges the stored payload when it sees
			// this exact key; Card_Form::PARTIAL_FIELD is the generic marker used by
			// other option groups, not by this option's callback.
			'partialField' => '_partial',
			'current'     => array(
				'treeStyle'       => $style,
				'folderAccent'    => $accent,
				'isCustom'        => $custom,
				'customColor'     => $custom ? $accent : ( isset( $palette[ $accent ]['ink'] ) ? (string) $palette[ $accent ]['ink'] : '#c8932b' ),
				'styleLabel'      => 'mac' === $style ? __( 'macOS Finder', 'sakura-imgopt' ) : __( 'Windows 11', 'sakura-imgopt' ),
				'accentLabel'     => $custom ? __( '自定义颜色', 'sakura-imgopt' ) : (string) ( $labels[ $accent ] ?? $labels[''] ),
			),
			'styles'      => array(
				array(
					'value'       => 'windows',
					'label'       => __( 'Windows 11', 'sakura-imgopt' ),
					'description' => __( '圆角实心文件夹、深浅两级配色，接近资源管理器的观感。', 'sakura-imgopt' ),
				),
				array(
					'value'       => 'mac',
					'label'       => __( 'macOS Finder', 'sakura-imgopt' ),
					'description' => __( '描边文件夹、更轻的层次和字重，接近访达的观感。', 'sakura-imgopt' ),
				),
			),
			'accentChoices' => $accent_choices,
			'health'      => $health,
			'entry'       => $entry,
			'policy'      => array(
				'groups'      => $this->policy_groups( $settings ),
				'themeLimits' => $limits,
				'allowedTypes' => $allowed_types,
				'hidden'      => array(
					'reference_notice_version' => (string) ( $settings['reference_notice_version'] ?? Settings::NOTICE_VERSION ),
					'tree_style'               => (string) ( $settings['tree_style'] ?? 'windows' ),
					'folder_accent'            => $accent,
				),
			),
			'strings'     => $this->strings(),
			'cards'       => $cards,
		);
	}

	/**
	 * Policy groups, with values already converted to the form units.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function policy_groups( array $settings ): array {
		$groups = array(
			array(
				'title'  => __( '容量与数量', 'sakura-imgopt' ),
				'hint'   => __( '决定每个用户能存多少、能建多少。', 'sakura-imgopt' ),
				'fields' => array(
					array( 'key' => 'quota_default_bytes', 'label' => __( '默认单人容量（MB）', 'sakura-imgopt' ), 'mb' => true, 'min' => 1, 'hint' => __( '默认 512，用户可在下方单独调整。', 'sakura-imgopt' ) ),
					array( 'key' => 'max_file_bytes', 'label' => __( '单文件上限（MB）', 'sakura-imgopt' ), 'mb' => true, 'min' => 1, 'hint' => __( '默认 50。', 'sakura-imgopt' ) ),
					array( 'key' => 'max_user_files', 'label' => __( '每人文件数', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '超过后不能再上传。', 'sakura-imgopt' ) ),
					array( 'key' => 'max_user_folders', 'label' => __( '每人文件夹数', 'sakura-imgopt' ), 'min' => 0, 'hint' => '' ),
					array( 'key' => 'max_folder_depth', 'label' => __( '目录层级', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '根目录为第 1 层。', 'sakura-imgopt' ) ),
				),
			),
			array(
				'title'  => __( '上传频率与全站预算', 'sakura-imgopt' ),
				'hint'   => __( '防止集中占用带宽与服务端资源。', 'sakura-imgopt' ),
				'fields' => array(
					array( 'key' => 'daily_upload_files', 'label' => __( '每日上传文件数', 'sakura-imgopt' ), 'min' => 0, 'hint' => '' ),
					array( 'key' => 'daily_upload_bytes', 'label' => __( '每日上传量（MB）', 'sakura-imgopt' ), 'mb' => true, 'min' => 1, 'hint' => __( '按 UTC 自然日累计。', 'sakura-imgopt' ) ),
					array( 'key' => 'max_concurrent_uploads', 'label' => __( '并发上传数', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '前端同时上传的通道数。', 'sakura-imgopt' ) ),
					array( 'key' => 'upload_requests_per_minute', 'label' => __( '每分钟上传请求', 'sakura-imgopt' ), 'min' => 0, 'hint' => '' ),
					array( 'key' => 'site_storage_budget_bytes', 'label' => __( '全站容量预算（MB）', 'sakura-imgopt' ), 'mb' => true, 'min' => 0, 'hint' => __( '0 表示不启用全站预算。', 'sakura-imgopt' ) ),
					array( 'key' => 'site_storage_warning_percent', 'label' => __( '预算告警阈值', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '百分比，达到后提示管理员。', 'sakura-imgopt' ) ),
				),
			),
			array(
				'title'  => __( '删除申请', 'sakura-imgopt' ),
				'hint'   => __( '限制用户提交的审核单数量与体量。', 'sakura-imgopt' ),
				'fields' => array(
					array( 'key' => 'max_pending_delete_requests', 'label' => __( '待处理申请上限', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '每人最多同时存在的待审核申请。', 'sakura-imgopt' ) ),
					array( 'key' => 'max_delete_request_items', 'label' => __( '单个申请对象上限', 'sakura-imgopt' ), 'min' => 0, 'hint' => __( '一个申请最多包含的文件和文件夹数量。', 'sakura-imgopt' ) ),
				),
			),
		);

		foreach ( $groups as $group_index => $group ) {
			foreach ( $group['fields'] as $field_index => $field ) {
				$key  = (string) $field['key'];
				$is_mb = ! empty( $field['mb'] );
				$value = (int) ( $settings[ $key ] ?? 0 );
				$value = $is_mb ? round( $value / 1048576, 2 ) : $value;
				$name  = 'sufm_settings[' . ( $is_mb ? $key . '_mb' : $key ) . ']';

				$groups[ $group_index ]['fields'][ $field_index ]['id']    = 'sufm-' . str_replace( '_', '-', $key );
				$groups[ $group_index ]['fields'][ $field_index ]['name']  = $name;
				$groups[ $group_index ]['fields'][ $field_index ]['value'] = (string) $value;
			}
		}

		return $groups;
	}

	/**
	 * UI copy for the island.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saveAppearance' => __( '保存样式', 'sakura-imgopt' ),
			'savePolicy'     => __( '保存策略', 'sakura-imgopt' ),
			'styleLegend'    => __( '选择样式', 'sakura-imgopt' ),
			'styleHint'      => __( '同时作用于媒体库左侧文件夹树和前台用户中心的文件管理，两侧图形与配色保持一致。', 'sakura-imgopt' ),
			'accentLegend'   => __( '文件夹图标颜色', 'sakura-imgopt' ),
			'accentHint'     => __( '只给文件夹图标上色，标题文字保持中性灰，避免整棵树的花色压过内容。', 'sakura-imgopt' ),
			'accentAuto'     => __( '跟随样式', 'sakura-imgopt' ),
			'customColor'    => __( '自定义颜色', 'sakura-imgopt' ),
			'hexLabel'       => __( '十六进制', 'sakura-imgopt' ),
			'hexPlaceholder' => '#c8932b',
			'hexInvalid'     => __( '请输入 #RRGGBB 格式的颜色。', 'sakura-imgopt' ),
			'applyColor'     => __( '使用此颜色', 'sakura-imgopt' ),
			'currentSummary' => __( '当前样式：{style}；图标颜色：{accent}。', 'sakura-imgopt' ),
			'previewTitle'   => __( '实时预览', 'sakura-imgopt' ),
			'previewImages'  => __( '图片素材', 'sakura-imgopt' ),
			'previewArchive' => __( '项目归档', 'sakura-imgopt' ),
			'previewInbox'   => __( '待整理', 'sakura-imgopt' ),
			'entryTheme'     => __( '当前主题', 'sakura-imgopt' ),
			'entryDetected'  => __( '检测到的入口', 'sakura-imgopt' ),
			'entryPosition'  => __( '前台位置', 'sakura-imgopt' ),
			'entryOpen'      => __( '前往前台入口', 'sakura-imgopt' ),
			'entryHint'      => __( '在「子比专属 → 前台入口」勾选“启用前台入口”并保存后，用户中心的入口与前台上传接口才会开启；媒体库的多用户文件夹、整理与归属转移不受此开关影响。', 'sakura-imgopt' ),
			'themeLimits'    => __( '主题上传限制', 'sakura-imgopt' ),
			'themeSync'      => __( '同步写入主题', 'sakura-imgopt' ),
			/* translators: %s: theme setting source label. */
			'themeReadFrom'  => __( '读取自 %s。保存本页时会把下面两个值写回主题设置，避免主题先于插件拦截上传。', 'sakura-imgopt' ),
			/* translators: %s: last sync timestamp. */
			'themeSyncedAt'  => __( '最近同步：%s', 'sakura-imgopt' ),
			'allowedTypes'   => __( '允许的文件类型', 'sakura-imgopt' ),
			'allowedHint'    => __( 'SVG、HTML、脚本和可执行文件始终不会被接受，即使在这里勾选也不会生效。', 'sakura-imgopt' ),
			'noPreview'      => __( '暂无预览', 'sakura-imgopt' ),
			'unknown'        => __( '操作失败，请稍后重试。', 'sakura-imgopt' ),
		);
	}
}
