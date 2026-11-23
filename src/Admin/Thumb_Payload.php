<?php
/**
 * Boot payload for the thumbnail tab island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\Rest\Image_Controller;
use Sakura\ImageOptimizer\Admin\UI\Page;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail\Size_Catalog;
use Sakura\ImageOptimizer\Thumbnail\Stats;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the thumbnail tab once, for both the PHP shell and the island.
 *
 * Every card that owns part of `sakura_imgopt_oss_thumb` is described here as
 * data: the island renders it, the save endpoint writes the same field names.
 */
final class Thumb_Payload {

	/**
	 * Card ids in the order Layout::PAGES['thumb'] declares.
	 *
	 * @return array<int,string>
	 */
	public static function card_ids(): array {
		return array( 'switches', 'scopes', 'scenes', 'advanced', 'sizes', 'stats', 'rollback', 'cache' );
	}

	/**
	 * Build the payload.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$option = Settings::get();
		$host   = Settings::media_host();
		$mode   = Settings::effective_mode();

		$page = array(
			'id'      => 'image-thumb',
			'module'  => 'image',
			'tab'     => 'thumb',
			'eyebrow' => 'THUMBNAIL URL ENGINE / ' . SAKURA_TOOLS_VERSION,
			'title'   => __( '缩略图优化', 'sakura-imgopt' ),
			'lede'    => __( '把 WordPress 的图片尺寸换成云存储能识别的命名样式；只按显示位置决定是否启用，不依赖主题的样式名，也不再缓冲整个页面。', 'sakura-imgopt' ),
			'status'  => array(
				'label' => __( '缩略图引擎', 'sakura-imgopt' ),
				'value' => '' === $host ? __( '未找到图片域名', 'sakura-imgopt' ) : $host,
				'sub'   => $this->mode_label( $mode ),
				'tone'  => ! empty( $option['enabled'] ) ? 'ok' : 'neutral',
			),
			'island'  => true,
		);

		return array(
			'tab'      => 'thumb',
			'chrome'   => Shell::header_data( 'image', 'thumb' ),
			'strings'  => $this->strings(),
			'rest'     => array(
				'url'   => esc_url_raw( rest_url( Image_Controller::NS . '/image/thumb' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			'page'     => $page,
			'head'     => Page::head_data( $page ),
			'alerts'   => $this->alerts( $option, $mode ),
			'cards'    => $this->cards( $option, $host, $mode ),
			'sections' => $this->sections( $option ),
			'notice'   => $this->switch_notice( $host ),
		);
	}

	/**
	 * Cards, in layout order.
	 *
	 * @param array<string,mixed> $option Stored option.
	 * @param string              $host   Media host.
	 * @param string              $mode   Effective mode.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $option, string $host, string $mode ): array {
		$out = array();

		foreach ( self::card_ids() as $id ) {
			$card = $this->card( $id, $option, $host, $mode );

			if ( null !== $card ) {
				$out[] = $card;
			}
		}

		return $out;
	}

	/**
	 * One card definition.
	 *
	 * @param string              $id     Card id.
	 * @param array<string,mixed> $option Stored option.
	 * @param string              $host   Media host.
	 * @param string              $mode   Effective mode.
	 * @return array<string,mixed>|null
	 */
	private function card( string $id, array $option, string $host, string $mode ): ?array {
		$meta = array(
			'switches' => array( 'Engine & Provider', __( '总开关与处理方式', 'sakura-imgopt' ), __( '「改写图片地址」性能更好，是推荐方式；「整页替换」只用于临时回滚。', 'sakura-imgopt' ) ),
			'scopes'   => array( 'Display Scenes', __( '生效位置', 'sakura-imgopt' ), __( '这里只控制「在哪些位置生效」；具体尺寸在下面的「按位置指定尺寸」里设置。', 'sakura-imgopt' ) ),
			'scenes'   => array( 'Scene Sizes', __( '按位置指定尺寸', 'sakura-imgopt' ), __( '不同主题、不同区域请求的尺寸并不一样。这里让文章缩略图、侧边栏和正文各用一个尺寸；留空则跟随主题请求。', 'sakura-imgopt' ) ),
			'advanced' => array( 'Advanced', __( '高级', 'sakura-imgopt' ), __( '兼容开关默认关闭；开启后会改变前台输出，请保存后刷新页面核对。', 'sakura-imgopt' ) ),
			'sizes'    => array( 'Size Catalog', __( '尺寸规格', 'sakura-imgopt' ), __( '「命名样式」只使用最后一列；「宽高参数」只使用宽度、高度、模式、位置和质量。', 'sakura-imgopt' ) ),
			'stats'    => array( 'Last 14 Days', __( '近 14 天统计', 'sakura-imgopt' ), __( '只记录前台实际发生的改写与跳过。', 'sakura-imgopt' ) ),
			'rollback' => array( 'Rollback', __( '回滚', 'sakura-imgopt' ), '' ),
			'cache'    => array( 'Cache Flush', __( '缓存清理', 'sakura-imgopt' ), __( '每次保存设置自动执行，无需手动操作。', 'sakura-imgopt' ) ),
		);

		if ( ! isset( $meta[ $id ] ) ) {
			return null;
		}

		list( $kicker, $title, $description ) = $meta[ $id ];
		$placement                           = Layout::card( Layout::layout_for_page( 'image-thumb' ), $id );

		$card = array(
			'id'          => $id,
			'kicker'      => $kicker,
			'title'       => $title,
			'description' => $description,
			'wide'        => in_array( $id, array( 'sizes', 'stats' ), true ),
			'placement'   => null === $placement
				? null
				: array( $placement[0], $placement[1], $placement[2] ),
			'fields'      => array(),
			'values'      => array(),
		);

		if ( in_array( $id, Thumb_Settings::CARDS, true ) ) {
			$card['form'] = array(
				'id'    => 'sakura-imgopt-oss-' . $id,
				'save'  => __( '保存设置', 'sakura-imgopt' ),
				'label' => __( '保存设置', 'sakura-imgopt' ),
			);
		}

		if ( 'switches' === $id ) {
			return $this->switches_card( $card, $option );
		}

		if ( 'scopes' === $id ) {
			return $this->scopes_card( $card, $option );
		}

		if ( 'scenes' === $id ) {
			return $this->scenes_card( $card, $option );
		}

		if ( 'advanced' === $id ) {
			return $this->advanced_card( $card, $option );
		}

		if ( 'sizes' === $id ) {
			return $this->sizes_card( $card, $option );
		}

		if ( 'stats' === $id ) {
			return $this->stats_card( $card );
		}

		if ( 'rollback' === $id ) {
			return $this->rollback_card( $card );
		}

		// `card_ids()` is the whitelist checked above, so `cache` is the last one.
		return $this->cache_card( $card );
	}

	/**
	 * Switches card.
	 *
	 * @param array<string,mixed> $card   Base card.
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function switches_card( array $card, array $option ): array {
		$card['fields'] = array(
			array(
				'name'  => 'enabled',
				'type'  => 'switch',
				'label' => __( '启用', 'sakura-imgopt' ),
				'text'  => __( '启用缩略图 URL 改写', 'sakura-imgopt' ),
				'full'  => true,
			),
			array(
				'name'    => 'engine',
				'type'    => 'select',
				'id'      => 'sakura-thumb-engine',
				'label'   => __( '处理方式', 'sakura-imgopt' ),
				'hint'    => __( '「整页替换」会缓冲整个页面，只用于确认新版效果前的临时回滚。', 'sakura-imgopt' ),
				'options' => array(
					array( 'value' => 'url', 'label' => __( '改写图片地址（推荐）', 'sakura-imgopt' ) ),
					array( 'value' => 'html', 'label' => __( '整页替换（旧版方式，较慢）', 'sakura-imgopt' ) ),
					array( 'value' => 'off', 'label' => __( '关闭', 'sakura-imgopt' ) ),
				),
			),
			array(
				'name'    => 'provider_mode',
				'type'    => 'select',
				'id'      => 'sakura-thumb-mode',
				'label'   => __( '地址改写方式', 'sakura-imgopt' ),
				'hint'    => __( '自动模式按当前云存储支持的能力推断：厂商支持命名样式且填了样式名就用命名样式，否则用宽高参数，两者都不支持就落「不处理」。多吉云用宽高参数时，CDN 加速域名的缓存规则里必须关掉「忽略参数」，否则参数会被丢掉、返回原图。', 'sakura-imgopt' ),
				'options' => array(
					array( 'value' => 'auto', 'label' => __( '自动（按云存储能力推断）', 'sakura-imgopt' ) ),
					array( 'value' => 'style', 'label' => __( '命名样式（缤纷云 / 多吉云）', 'sakura-imgopt' ) ),
					array( 'value' => 'params', 'label' => __( '宽高参数（?w=&h=）', 'sakura-imgopt' ) ),
					array( 'value' => 'native', 'label' => __( 'WordPress 自带尺寸', 'sakura-imgopt' ) ),
					array( 'value' => 'none', 'label' => __( '不处理', 'sakura-imgopt' ) ),
				),
			),
			array(
				'name'  => 'dpr',
				'type'  => 'number',
				'id'    => 'sakura-thumb-dpr',
				'label' => __( '高分屏倍率上限', 'sakura-imgopt' ),
				'hint'  => __( '只用于 WordPress 没有登记的尺寸：输出宽度 = 请求宽度 × 倍率，且不超过原图宽度。', 'sakura-imgopt' ),
				'min'   => 1,
				'max'   => 3,
				'step'  => '0.1',
			),
		);

		$card['values'] = array(
			'enabled'       => (int) ( $option['enabled'] ?? 0 ),
			'engine'        => (string) ( $option['engine'] ?? 'url' ),
			'provider_mode' => (string) ( $option['provider_mode'] ?? 'auto' ),
			'dpr'           => (float) ( $option['dpr'] ?? 1.5 ),
		);

		return $card;
	}

	/**
	 * Scopes card.
	 *
	 * @param array<string,mixed> $card   Base card.
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function scopes_card( array $card, array $option ): array {
		$card['fields'] = array(
			array(
				'name'   => 'scopes',
				'type'   => 'checkgrid',
				'full'   => true,
				'items'  => array(
					array( 'key' => 'archive', 'label' => __( '归档 / 首页 / 搜索列表', 'sakura-imgopt' ) ),
					array( 'key' => 'sidebar', 'label' => __( '侧边栏小工具', 'sakura-imgopt' ) ),
					array( 'key' => 'singular', 'label' => __( '单篇 / 页面正文（含正文图）', 'sakura-imgopt' ) ),
					array( 'key' => 'other', 'label' => __( '其它位置', 'sakura-imgopt' ) ),
				),
				'hint'   => __( '正文图片和单篇文章共用这个开关；正文尺寸可以单独在「按位置指定尺寸」里指定。', 'sakura-imgopt' ),
			),
		);
		$card['values'] = array( 'scopes' => (array) ( $option['scopes'] ?? array() ) );

		return $card;
	}

	/**
	 * Scenes card.
	 *
	 * @param array<string,mixed> $card   Base card.
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function scenes_card( array $card, array $option ): array {
		$options = array( array( 'value' => '', 'label' => __( '跟随主题请求（不覆盖）', 'sakura-imgopt' ) ) );

		foreach ( $this->size_options( $option ) as $value => $label ) {
			$options[] = array( 'value' => $value, 'label' => $label );
		}

		$card['fields'] = array(
			array( 'name' => 'scenes.thumb', 'key' => 'thumb', 'type' => 'scene', 'id' => 'sakura-thumb-scene-thumb', 'label' => __( '文章缩略图', 'sakura-imgopt' ), 'hint' => __( '列表、卡片、相关文章等位置。子比主题这里请求的是 thumbnail。', 'sakura-imgopt' ), 'options' => $options ),
			array( 'name' => 'scenes.sidebar', 'key' => 'sidebar', 'type' => 'scene', 'id' => 'sakura-thumb-scene-sidebar', 'label' => __( '侧边栏', 'sakura-imgopt' ), 'hint' => __( '热榜、作者卡等小工具。子比主题这里请求的是 large，常被放大到 800px。', 'sakura-imgopt' ), 'options' => $options ),
			array( 'name' => 'scenes.content', 'key' => 'content', 'type' => 'scene', 'id' => 'sakura-thumb-scene-content', 'label' => __( '正文图片', 'sakura-imgopt' ), 'hint' => __( '文章正文里的大图。留空时大图用 large、小图用 medium。', 'sakura-imgopt' ), 'options' => $options ),
		);
		$card['values'] = array( 'scenes' => (array) ( $option['scenes'] ?? array() ) );
		$card['note']   = array( __( '只有「生效位置」里对应的开关打开时才会生效；命名样式模式下，所选尺寸要先在下方「尺寸规格」里填好命名样式。缩略图和侧边栏会重写主题的缩略图缓存，保存后刷新前台即可看到变化。', 'sakura-imgopt' ) );

		return $card;
	}

	/**
	 * Advanced card.
	 *
	 * @param array<string,mixed> $card   Base card.
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function advanced_card( array $card, array $option ): array {
		$switches = array(
			'native_fallback'  => __( '样式缺失时回退到 WordPress 自带尺寸', 'sakura-imgopt' ),
			'full_cap'         => __( '限制完整尺寸原图（默认关闭，风险较大）', 'sakura-imgopt' ),
			'content_images'   => __( '改写正文中的图片', 'sakura-imgopt' ),
			'theme_bridge'     => __( '子比主题备用图联动（读取主题设置，不用改主题文件）', 'sakura-imgopt' ),
			'rest'             => __( '改写接口返回的图片地址', 'sakura-imgopt' ),
			'animated'         => __( '允许处理 GIF（可能变成静态图）', 'sakura-imgopt' ),
			'unknown_fallback' => __( '未登记的尺寸按「请求宽 × 倍率」处理', 'sakura-imgopt' ),
			'stats'            => __( '记录改写与跳过的数量', 'sakura-imgopt' ),
		);

		$items = array();
		foreach ( $switches as $key => $label ) {
			$items[] = array( 'key' => $key, 'label' => $label );
		}

		$card['fields'] = array(
			array(
				'name'  => 'advanced',
				'type'  => 'checkgrid',
				'full'  => true,
				'items' => $items,
			),
			array(
				'name'        => 'template',
				'type'        => 'textarea',
				'id'          => 'sakura-thumb-template',
				'label'       => __( '自定义地址模板', 'sakura-imgopt' ),
				'rows'        => 3,
				'placeholder' => '?x-oss-process=image/resize,w_{w}',
				'input_class' => 'large-text code',
				'full'        => true,
				'hint'        => __( '使用其他服务商时填写。可用占位符：{url} 图片地址、{w} 宽、{h} 高、{mode} 模式、{pos} 位置、{q} 质量、{style} 命名样式。含 {url} 时按完整地址模板处理，否则作为后缀追加。', 'sakura-imgopt' ),
			),
		);
		$card['values'] = array( 'advanced' => (array) ( $option['advanced'] ?? array() ) );
		$card['note']   = array( __( '「子比主题备用图联动」会在读取主题设置时改写备用图地址，不修改主题文件，主题升级也不会丢。', 'sakura-imgopt' ) );

		return $card;
	}

	/**
	 * Sizes card.
	 *
	 * @param array<string,mixed> $card   Base card.
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function sizes_card( array $card, array $option ): array {
		$sizes  = isset( $option['sizes'] ) && is_array( $option['sizes'] ) ? $option['sizes'] : array();
		$styles = isset( $option['style_map'] ) && is_array( $option['style_map'] ) ? $option['style_map'] : array();
		$labels = Size_Catalog::configured_labels( $sizes );
		$rows   = array();

		foreach ( $sizes as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'name'      => (string) $name,
				'label'     => (string) ( $labels[ $name ] ?? $name ),
				'width'     => (int) ( $row['width'] ?? 0 ),
				'height'    => (int) ( $row['height'] ?? 0 ),
				'mode'      => (string) ( $row['mode'] ?? 'fit' ),
				'position'  => (string) ( $row['position'] ?? 'auto' ),
				'quality'   => (int) ( $row['quality'] ?? 82 ),
				'style'     => (string) ( $styles[ $name ] ?? '' ),
				'format'    => (string) ( $row['format'] ?? 'auto' ),
			);
		}

		$card['fields'] = array(
			array(
				'name'      => 'sizes',
				'type'      => 'size-grid',
				'full'      => true,
				'rows'      => $rows,
				'modes'     => array(
					array( 'value' => 'fit', 'label' => __( '等比缩放 fit', 'sakura-imgopt' ) ),
					array( 'value' => 'crop', 'label' => __( '裁剪 crop', 'sakura-imgopt' ) ),
					array( 'value' => 'pad', 'label' => __( '留白 pad', 'sakura-imgopt' ) ),
				),
				'positions' => array(
					array( 'value' => 'auto', 'label' => 'auto' ),
					array( 'value' => 'center', 'label' => 'center' ),
					array( 'value' => 'top', 'label' => 'top' ),
					array( 'value' => 'bottom', 'label' => 'bottom' ),
					array( 'value' => 'left', 'label' => 'left' ),
					array( 'value' => 'right', 'label' => 'right' ),
				),
				'columns'   => array(
					__( '尺寸', 'sakura-imgopt' ),
					__( '宽', 'sakura-imgopt' ),
					__( '高', 'sakura-imgopt' ),
					__( '模式', 'sakura-imgopt' ),
					__( '位置', 'sakura-imgopt' ),
					__( '质量', 'sakura-imgopt' ),
					__( '命名样式', 'sakura-imgopt' ),
				),
				'hint'      => __( '填控制台里那条图片处理规则的名称，留空表示这个尺寸不使用命名样式，命名样式模式下会被跳过。缤纷云填规则名本身（如 siomedium，地址里会变成 !style=siomedium）；多吉云的规则名带一个前导斜杠（如 /720wm，地址里会变成 a.jpg/720wm）。', 'sakura-imgopt' ),
			),
		);
		$card['values'] = array(
			'sizes'     => $sizes,
			'style_map' => $styles,
		);

		return $card;
	}

	/**
	 * Stats card (runtime snapshot).
	 *
	 * @param array<string,mixed> $card Base card.
	 * @return array<string,mixed>
	 */
	private function stats_card( array $card ): array {
		$summary = Stats::summary();
		$by_size = array();

		foreach ( (array) ( $summary['by_size'] ?? array() ) as $name => $count ) {
			$by_size[] = array( 'name' => (string) $name, 'count' => (int) $count );
		}

		$card['kpis'] = array(
			array( 'label' => __( '改写', 'sakura-imgopt' ), 'value' => (int) ( $summary['rewrite'] ?? 0 ) ),
			array( 'label' => __( '跳过', 'sakura-imgopt' ), 'value' => (int) ( $summary['skip'] ?? 0 ) ),
			array( 'label' => __( '有数据天数', 'sakura-imgopt' ), 'value' => (int) ( $summary['days'] ?? 0 ) ),
		);
		$card['list']    = $by_size;
		$card['empty']   = __( '前台还没有产生记录。', 'sakura-imgopt' );

		return $card;
	}

	/**
	 * Rollback card (read-only text).
	 *
	 * @param array<string,mixed> $card Base card.
	 * @return array<string,mixed>
	 */
	private function rollback_card( array $card ): array {
		$card['lines'] = array(
			__( '把处理方式切到「整页替换」就回到旧版做法；旧设置会保留，不会被新版覆盖。', 'sakura-imgopt' ),
			__( '保存设置会清理主题缩略图地址相关的缓存，不会清空整站缓存；PHP 缓存（OPcache）默认不重置。', 'sakura-imgopt' ),
		);

		return $card;
	}

	/**
	 * Cache card (last flush snapshot).
	 *
	 * @param array<string,mixed> $card Base card.
	 * @return array<string,mixed>
	 */
	private function cache_card( array $card ): array {
		$report = Settings::cache_flush_report();

		$opcache = array(
			'ok'           => __( '已重置', 'sakura-imgopt' ),
			'failed'       => __( '重置失败', 'sakura-imgopt' ),
			'unavailable'  => __( '不可用', 'sakura-imgopt' ),
			'skipped'      => __( '已跳过（默认不重置）', 'sakura-imgopt' ),
			'skipped_cli'  => __( '命令行下跳过', 'sakura-imgopt' ),
		);
		$state   = array(
			'ok'          => __( '已清理', 'sakura-imgopt' ),
			'partial'     => __( '部分清理', 'sakura-imgopt' ),
			'failed'      => __( '清理失败', 'sakura-imgopt' ),
			'unsupported' => __( '不支持分组清理', 'sakura-imgopt' ),
			'none'        => __( '无需清理', 'sakura-imgopt' ),
		);

		$time  = (int) ( $report['time'] ?? 0 );
		$state_key = (string) ( $report['cache_state'] ?? 'unsupported' );

		$card['rows'] = array(
			array( 'label' => (string) ( $report['backend_label'] ?? __( '对象缓存', 'sakura-imgopt' ) ), 'value' => (string) ( $state[ $state_key ] ?? __( '未知', 'sakura-imgopt' ) ) ),
			array(
				'label' => __( '相关缓存', 'sakura-imgopt' ),
				'value' => ! empty( $report['group_flush'] )
					/* translators: 1: cleaned groups, 2: requested groups. */
					? sprintf( __( '已清理 %1$d / %2$d 组相关缓存', 'sakura-imgopt' ), (int) ( $report['theme_groups'] ?? 0 ), (int) ( $report['requested_groups'] ?? 0 ) )
					: __( '未清空', 'sakura-imgopt' ),
			),
			array( 'label' => __( 'PHP 缓存（OPcache）', 'sakura-imgopt' ), 'value' => (string) ( $opcache[ (string) ( $report['opcache'] ?? 'unavailable' ) ] ?? __( '不可用', 'sakura-imgopt' ) ) ),
		);

		if ( ! empty( $report['group_flush'] ) ) {
			$note = __( '只清理本插件用到的缩略图地址缓存和主题的社区列表图缓存，不会清空整站缓存。', 'sakura-imgopt' );
		} elseif ( 'none' === (string) ( $report['backend'] ?? '' ) ) {
			$note = __( '当前没有启用对象缓存，无需清理。', 'sakura-imgopt' );
		} else {
			$note = __( '当前的对象缓存不支持按组清理；插件不会清空整站缓存，请手动清理，或换用支持分组清理的缓存服务。', 'sakura-imgopt' );
		}

		$card['notes'] = array(
			$note,
			/* translators: %s: local time of the last flush. */
			0 === $time ? __( '尚未清理', 'sakura-imgopt' ) : sprintf( __( '最近清理：%s（站点时间）', 'sakura-imgopt' ), (string) wp_date( 'Y-m-d H:i:s', $time ) ),
			__( '如果开启了重置又显示「重置失败」，通常是主机禁用了 opcache_reset；可让运维在 PHP 层面重载。', 'sakura-imgopt' ),
		);

		return $card;
	}

	/**
	 * Section data the island needs next to the cards.
	 *
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,mixed>
	 */
	private function sections( array $option ): array {
		return array(
			'zibll' => (bool) Settings::effective_mode(),
			'modes' => array(
				'auto'   => __( '自动', 'sakura-imgopt' ),
				'style'  => __( '命名样式', 'sakura-imgopt' ),
				'params' => __( '宽高参数', 'sakura-imgopt' ),
				'native' => __( '自带尺寸', 'sakura-imgopt' ),
				'none'   => __( '不处理', 'sakura-imgopt' ),
			),
			'sizes' => $this->size_options( $option ),
		);
	}

	/**
	 * Size name => label options, as the scenes dropdowns render them.
	 *
	 * @param array<string,mixed> $option Stored option.
	 * @return array<string,string>
	 */
	private function size_options( array $option ): array {
		$sizes  = isset( $option['sizes'] ) && is_array( $option['sizes'] ) ? $option['sizes'] : array();
		$labels = Size_Catalog::configured_labels( $sizes );
		$out    = array();

		foreach ( $sizes as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$width  = (int) ( $row['width'] ?? 0 );
			$height = (int) ( $row['height'] ?? 0 );
			$mode   = (string) ( $row['mode'] ?? 'fit' );

			$dimension = 0 === $height
				/* translators: %d: width in pixels. */
				? sprintf( __( '宽 %d', 'sakura-imgopt' ), $width )
				/* translators: 1: width, 2: height. */
				: sprintf( __( '宽 %1$d × 高 %2$d', 'sakura-imgopt' ), $width, $height );

			$out[ (string) $name ] = sprintf(
				'%1$s · %2$s · %3$s',
				(string) ( $labels[ $name ] ?? $name ),
				$dimension,
				$mode
			);
		}

		return $out;
	}

	/**
	 * Page-level alerts shown above the cards.
	 *
	 * @param array<string,mixed> $option Stored option.
	 * @param string              $mode   Effective mode.
	 * @return array<int,array<string,string>>
	 */
	private function alerts( array $option, string $mode ): array {
		$alerts = array();

		if ( ! $this->is_zibll() ) {
			$alerts[] = array(
				'tone'  => 'info',
				'title' => __( '主题提示', 'sakura-imgopt' ),
				'text'  => __( '当前主题不是子比（Zibll）主题；命名样式仍可使用，但请自己检查前台效果。', 'sakura-imgopt' ),
			);
		}

		if ( ! empty( $option['enabled'] ) && 'url' === (string) ( $option['engine'] ?? '' ) && 'none' === $mode ) {
			$alerts[] = array(
				'tone' => 'warning',
				'text' => __( '已启用地址改写，但当前实际没有生效：请把方式改为「命名样式」或「宽高参数」，或补上图片域名。', 'sakura-imgopt' ),
			);
		}

		return $alerts;
	}

	/**
	 * Whether the active theme is Zibll.
	 *
	 * @return bool
	 */
	private function is_zibll(): bool {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$name  = $theme && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Template' ) : '';
		$own   = $theme && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : '';

		return false !== stripos( $name, 'zibll' ) || false !== stripos( $own, '子比' ) || false !== stripos( $own, 'zibll' );
	}

	/**
	 * One-shot notice shown after the storage bucket changed.
	 *
	 * @param string $host Media host.
	 * @return array<string,mixed>|null
	 */
	private function switch_notice( string $host ): ?array {
		$flag = get_transient( 'sakura_imgopt_thumb_switch_notice' );

		if ( false === $flag ) {
			return null;
		}

		delete_transient( 'sakura_imgopt_thumb_switch_notice' );

		return array(
			'kicker'  => 'THUMBNAIL URL ENGINE / SWITCHED',
			'title'   => __( '存储桶换了，缩略图地址改写已关闭', 'sakura-imgopt' ),
			'message' => __( '图片域名变了，之前缓存的缩略图地址已经作废。确认下面的域名是新的之后，再重新打开「改写图片地址」。', 'sakura-imgopt' ),
			'host'    => '' === $host ? __( '未找到图片域名', 'sakura-imgopt' ) : $host,
			'label'   => __( '当前图片域名', 'sakura-imgopt' ),
			'button'  => __( '我知道了', 'sakura-imgopt' ),
		);
	}

	/**
	 * Translate an effective mode.
	 *
	 * @param string $mode Mode key.
	 * @return string
	 */
	private function mode_label( string $mode ): string {
		$labels = array(
			'auto'   => __( '自动', 'sakura-imgopt' ),
			'style'  => __( '命名样式', 'sakura-imgopt' ),
			'params' => __( '宽高参数', 'sakura-imgopt' ),
			'native' => __( '自带尺寸', 'sakura-imgopt' ),
			'none'   => __( '不处理', 'sakura-imgopt' ),
			''       => __( '不处理', 'sakura-imgopt' ),
		);

		return $labels[ $mode ] ?? $mode;
	}

	/**
	 * Shared UI strings.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saving'   => __( '保存中…', 'sakura-imgopt' ),
			'saved'    => __( '已保存', 'sakura-imgopt' ),
			'dismiss'  => __( '知道了', 'sakura-imgopt' ),
			'close'    => __( '关闭', 'sakura-imgopt' ),
			'failed'   => __( '设置未保存，请稍后重试。', 'sakura-imgopt' ),
			'network'  => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
		);
	}
}
