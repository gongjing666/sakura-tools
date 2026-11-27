<?php
/**
 * Bootstrap payload for the Zibll-only file space tab.
 *
 * The old view computed every value inline. This class keeps that math in PHP
 * (theme diagnostics, level/VIP bonuses, package normalisation, comparison
 * values) and only hands the React island display-ready data plus the native
 * options.php form markers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Frontend\Entry;
use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use Nboke\UserFiles\Services\Zibll_Bridge;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Space_Settings;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Data builder for files-zibll.
 */
final class Space_Payload {

	/**
	 * Stable card order, matching the old view and Layout placements.
	 *
	 * @var array<int,string>
	 */
	private const CARD_ORDER = array(
		'space_diag',
		'space_payment',
		'space_entry',
		'space_editor',
		'space_gate',
		'space_level',
		'space_vip',
		'space_plans',
		'space_grants',
		'space_reconcile',
	);

	/**
	 * Build the complete island document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$bridge = Zibll_Bridge::instance();
		$diag   = $bridge->diagnostics();
		$theme  = $this->theme( $diag );
		$caps   = $this->caps( $diag );
		$entry  = ( new Entry() )->status();
		$data   = array(
			'diag'    => $this->diagnostics( $diag, $theme, $caps, $entry ),
			'payment' => $this->payment( $diag ),
			'entry'   => $this->entry( $entry, $theme ),
			'editor'  => array( 'enabled' => Settings::editor_enabled() ),
			'gate'    => array(
				'enabled'       => Space_Settings::enabled(),
				'apply_bonuses' => Space_Settings::apply_bonuses(),
				'mode'          => Space_Settings::mode(),
			),
			'level'   => $this->level(),
			'vip'     => $this->vip(),
			'plans'   => $this->plans(),
		);

		$page = $this->page_args( $data['diag'] );
		$cards = $this->cards( $data );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'files', Space_Page::TAB ),
			'cards'   => $cards,
			'config'  => array(
				'restUrl'       => esc_url_raw( rest_url( 'sufm/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'spaceOption'   => Space_Settings::OPTION,
				'settingsOption' => 'sufm_settings',
				'strings'       => $this->strings(),
			),
		);
	}

	/**
	 * Page chrome.
	 *
	 * @param array<string,mixed> $diag Diagnostic data.
	 * @return array<string,mixed>
	 */
	private function page_args( array $diag ): array {
		return array(
			'id'      => 'files-zibll',
			'module'  => 'files',
			'tab'     => Space_Page::TAB,
			'eyebrow' => 'ZIBLL BRIDGE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
			'title'   => __( '子比专属', 'sakura-imgopt' ),
			'lede'    => __( '主题能力、支付通道、前台入口与等级加成集中在这里；所有数值都在运行时从 zibll 读取，不写死主题侧的名称或价格。', 'sakura-imgopt' ),
			'status'  => array(
				'label' => __( '子比桥接', 'sakura-imgopt' ),
				'value' => $diag['ready'] . ' / ' . $diag['total'],
				'unit'  => __( '项能力', 'sakura-imgopt' ),
				'sub'   => '' !== $diag['theme']['name'] ? $diag['theme']['name'] : __( '未识别主题', 'sakura-imgopt' ),
				'tone'  => $diag['ready'] === $diag['total'] ? 'ok' : ( $diag['ready'] >= 5 ? 'warn' : 'bad' ),
			),
		);
	}

	/**
	 * Card metadata and per-card data.
	 *
	 * @param array<string,array<string,mixed>> $data Computed data.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $data ): array {
		$cards = array();

		foreach ( self::CARD_ORDER as $id ) {
			$card = $this->card_meta( $id );
			if ( ! $card ) {
				continue;
			}

			$key                 = substr( $id, 6 );
			$card['placement']   = Layout::card( 'files-space', $id ) ?? array( 0, 1, 12 );
			$card['data']        = $data[ $key ] ?? array();
			$card['form']        = $this->form( $id );
			$cards[]             = $card;
		}

		return $cards;
	}

	/**
	 * Static card copy.
	 *
	 * @param string $id Card id.
	 * @return array<string,mixed>|null
	 */
	private function card_meta( string $id ): ?array {
		$map = array(
			'space_diag'     => array(
				'kicker'      => 'DIAGNOSE',
				'title'       => __( '主题诊断', 'sakura-imgopt' ),
				'description' => __( '本页所有数值都在运行时读取主题，不写死任何主题侧的名称或价格。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			'space_payment'  => array(
				'kicker'      => 'PAYMENT',
				'title'       => __( '支付方式', 'sakura-imgopt' ),
				'description' => __( '只有勾选并保存的通道才会出现在前台收银台。默认只启用余额；支付宝、微信等外部渠道需自行确认对账与退款流程。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存支付方式', 'sakura-imgopt' ),
			),
			'space_entry'    => array(
				'kicker'      => 'ENTRY',
				'title'       => __( '前台入口', 'sakura-imgopt' ),
				'description' => __( '只控制系统用户中心里是否出现「文件管理」入口，以及前台上传 / 删除接口。关闭不会删除任何文件。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存入口设置', 'sakura-imgopt' ),
			),
			'space_editor'   => array(
				'kicker'      => 'EDITOR',
				'title'       => __( '编辑器文件面板', 'sakura-imgopt' ),
				'description' => __( '在写文章 / 发帖页的编辑器工具栏加一个入口，点开后在正文左侧固定打开文件空间，写作时直接复用旧附件。面板只读：不能上传、不能删除、不占配额。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存编辑器面板设置', 'sakura-imgopt' ),
			),
			'space_gate'     => array(
				'kicker'      => 'GATE',
				'title'       => __( '门禁与开关', 'sakura-imgopt' ),
				'description' => __( '总开关默认关闭；「计算加成」是紧急止血阀：关闭后已购容量立即停止生效（等级 / VIP 加成仍按主题计算）。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存开关', 'sakura-imgopt' ),
			),
			'space_level'    => array(
				'kicker'      => 'BONUS',
				'title'       => __( '等级加成', 'sakura-imgopt' ),
				'description' => '',
				'wide'        => true,
				'save'        => __( '保存等级加成', 'sakura-imgopt' ),
			),
			'space_vip'      => array(
				'kicker'      => 'BONUS',
				'title'       => __( 'VIP / SVIP 加成', 'sakura-imgopt' ),
				'description' => __( '名称在运行时解析：过滤器 → 主题选项 → 降级标签。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存 VIP 加成', 'sakura-imgopt' ),
			),
			'space_plans'    => array(
				'kicker'      => 'PLANS',
				'title'       => __( '空间套餐', 'sakura-imgopt' ),
				'description' => __( '两种支付方式卖同一份空间：现金套餐（A 开头）走主题支付，积分套餐（P 开头）直接扣积分。下架或删除只影响新订单，已售出的授权按购买时的快照继续生效。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存套餐', 'sakura-imgopt' ),
			),
			'space_grants'   => array(
				'kicker'      => 'GRANTS',
				'title'       => __( '授权流水', 'sakura-imgopt' ),
				'description' => __( '最近 20 条授权记录，时间已按站点时区显示。到期时间留空表示永久。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			'space_reconcile' => array(
				'kicker'      => 'RECONCILE',
				'title'       => __( '对账与重试', 'sakura-imgopt' ),
				'description' => __( '「检查差异」只读，找出已付款但没有授权的子比订单；「修复差异」按订单快照补发，幂等（同一订单不会重复授予）。', 'sakura-imgopt' ),
				'wide'        => true,
			),
		);

		return isset( $map[ $id ] ) ? array_merge( array( 'id' => $id ), $map[ $id ] ) : null;
	}

	/**
	 * Native options.php form markers for one card.
	 *
	 * @param string $id Card id.
	 * @return array<string,mixed>
	 */
	private function form( string $id ): array {
		$space = array(
			'action'     => admin_url( 'options.php' ),
			'group'      => Space_Page::GROUP,
			'nonce'      => wp_create_nonce( Space_Page::GROUP . '-options' ),
			'markers'    => 'option_fields',
			'option'     => Space_Settings::OPTION,
			'markerCard' => $id,
			'hidden'     => array( Space_Settings::OPTION . '[_partial]' => '1' ),
		);

		if ( 'space_payment' === $id ) {
			$space['hidden'][ Space_Settings::OPTION . '[allowed_methods_present]' ] = '1';
		}

		if ( in_array( $id, array( 'space_entry', 'space_editor' ), true ) ) {
			return array(
				'action'     => admin_url( 'options.php' ),
				'group'      => 'sufm_settings_group',
				'nonce'      => wp_create_nonce( 'sufm_settings_group-options' ),
				'markers'    => 'option_fields',
				'option'     => 'sufm_settings',
				'markerCard' => $id,
				'hidden'     => array( 'sufm_settings[_partial]' => '1' ),
			);
		}

		return $space;
	}

	/**
	 * Theme diagnostics and capabilities.
	 *
	 * @param array<string,mixed> $diag  Raw diagnostics.
	 * @param array<string,mixed> $theme Normalised theme.
	 * @param array<int,array<string,mixed>> $caps Capabilities.
	 * @param array<string,mixed> $entry Entry status.
	 * @return array<string,mixed>
	 */
	private function diagnostics( array $diag, array $theme, array $caps, array $entry ): array {
		$ready = 0;
		foreach ( $caps as $cap ) {
			if ( $cap['ok'] ) {
				++$ready;
			}
		}

		$methods = array();
		$allowed = Space_Settings::allowed_methods();
		$raw     = isset( $diag['payment_methods'] ) && is_array( $diag['payment_methods'] ) ? $diag['payment_methods'] : array();
		foreach ( $raw as $id => $method ) {
			if ( ! is_array( $method ) || ! in_array( (string) $id, $allowed, true ) ) {
				continue;
			}
			$methods[] = array(
				'id'   => (string) $id,
				'name' => isset( $method['name'] ) ? (string) $method['name'] : (string) $id,
			);
		}

		$breakdown = Space_Rules::breakdown( (int) get_current_user_id() );
		$mode      = isset( $breakdown['mode'] ) ? (string) $breakdown['mode'] : 'cap';

		return array(
			'ready'     => $ready,
			'total'     => count( $caps ),
			'theme'     => $theme,
			'caps'      => $caps,
			'methods'   => $methods,
			'points_on' => Space_Settings::points_enabled(),
			'entry'     => $entry,
			'breakdown' => array(
				'base'     => $this->size( $breakdown['base'] ?? 0 ),
				'level'    => $this->level_line( $breakdown ),
				'vip'      => $this->vip_line( $breakdown ),
				'granted'  => $this->size( $breakdown['granted_bytes'] ?? 0 ),
				'computed' => $this->size( $breakdown['computed'] ?? 0 ),
				'override' => null === ( $breakdown['override'] ?? null ) || '' === ( $breakdown['override'] ?? '' ) ? null : $this->size( $breakdown['override'] ),
				'final'    => $this->size( $breakdown['final'] ?? 0 ),
				'mode'     => 'replace' === $mode ? 'replace' : 'cap',
			),
		);
	}

	/**
	 * Payment card data.
	 *
	 * @param array<string,mixed> $diag Diagnostics.
	 * @return array<string,mixed>
	 */
	private function payment( array $diag ): array {
		$raw     = isset( $diag['payment_methods'] ) && is_array( $diag['payment_methods'] ) ? $diag['payment_methods'] : array();
		$allowed = Space_Settings::allowed_methods();
		$methods = array();

		foreach ( $raw as $id => $method ) {
			if ( ! is_array( $method ) ) {
				continue;
			}
			$id = (string) $id;
			$methods[] = array(
				'id'      => $id,
				'name'    => isset( $method['name'] ) ? (string) $method['name'] : $id,
				'note'    => 'balance' === $id ? 'balance' : 'external',
				'checked' => in_array( $id, $allowed, true ),
			);
		}

		return array(
			'methods'   => $methods,
			'points_on' => Space_Settings::points_enabled(),
		);
	}

	/**
	 * Entry card data.
	 *
	 * @param array<string,mixed> $entry Entry status.
	 * @param array<string,mixed> $theme Theme diagnostic data.
	 * @return array<string,mixed>
	 */
	private function entry( array $entry, array $theme ): array {
		$name = isset( $entry['theme'] ) ? (string) $entry['theme'] : '';

		return array(
			'enabled' => Settings::entry_enabled(),
			'status'  => $entry,
			'theme'   => '' !== $name ? $name : (string) ( $theme['name'] ?? '' ),
			'label'   => isset( $entry['label'] ) ? (string) $entry['label'] : '',
			'url'     => isset( $entry['url'] ) ? (string) $entry['url'] : '',
			'generic' => ! empty( $entry['generic'] ),
		);
	}

	/**
	 * Level bonus data.
	 *
	 * @return array<string,mixed>
	 */
	private function level(): array {
		$options   = get_option( 'zibll_options' );
		$options   = is_array( $options ) ? $options : array();
		$level_max = isset( $options['user_level_max'] ) ? (int) $options['user_level_max'] : 10;
		$level_max = max( 1, min( 20, $level_max ) );
		$level_opt = isset( $options['user_level_opt'] ) && is_array( $options['user_level_opt'] ) ? $options['user_level_opt'] : array();
		$bonus     = Space_Settings::get()['level_bonus'] ?? array();
		$rule      = Space_Settings::level_rule();
		$rule_on   = ! empty( $rule['enabled'] );
		$rows      = array();
		$total     = 0.0;

		for ( $level = 1; $level <= $level_max; $level++ ) {
			$key       = 'upgrade_integral_' . $level;
			$threshold = isset( $level_opt[ $key ] ) && is_scalar( $level_opt[ $key ] ) ? (int) $level_opt[ $key ] : null;
			$bytes     = $rule_on
				? intdiv( max( 0, (int) $threshold ), max( 1, (int) $rule['points_per_step'] ) ) * (int) $rule['bytes_per_step']
				: ( isset( $bonus[ $level ] ) ? (int) $bonus[ $level ] : 0 );
			$bytes     = max( 0, $bytes );
			$total    += $bytes / 1048576;
			$rows[]    = array(
				'level'     => $level,
				'threshold' => null === $threshold ? null : (string) $threshold,
				'mb'        => $this->mb( $bytes ),
			);
		}

		return array(
			'rule'  => array(
				'enabled'     => $rule_on,
				'points'      => (string) $rule['points_per_step'],
				'bytes_mb'    => (string) round( (int) $rule['bytes_per_step'] / 1048576, 2 ),
			),
			'max'   => $level_max,
			'rows'  => $rows,
			'total' => $this->format_mb( $total ),
		);
	}

	/**
	 * VIP bonus data.
	 *
	 * @return array<string,mixed>
	 */
	private function vip(): array {
		$labels = Space_Rules::vip_labels();
		$map    = Space_Settings::get()['vip_bonus'] ?? array();
		$rows   = array();
		$total  = 0.0;

		for ( $vip = 0; $vip <= 2; $vip++ ) {
			$bytes = isset( $map[ $vip ] ) ? (int) $map[ $vip ] : 0;
			$total += max( 0, $bytes ) / 1048576;
			$rows[] = array(
				'vip'   => $vip,
				'label' => isset( $labels[ $vip ] ) ? (string) $labels[ $vip ] : 'VIP ' . $vip,
				'mb'    => $this->mb( $bytes ),
			);
		}

		return array(
			'rows'  => $rows,
			'total' => $this->format_mb( $total ),
		);
	}

	/**
	 * Package, pricing and comparison data.
	 *
	 * @return array<string,mixed>
	 */
	private function plans(): array {
		$packages  = Space_Settings::packages();
		$points    = Space_Settings::points();
		$ppkgs     = Space_Settings::points_packages();
		$icon      = Space_Settings::order_icon();
		$default   = Space_Store::product_image_url();
		$options   = get_option( 'zibll_options' );
		$options   = is_array( $options ) ? $options : array();
		$pay_rate  = isset( $options['pay_points_rate'] ) && is_numeric( $options['pay_points_rate'] ) ? (float) $options['pay_points_rate'] : 0.0;
		$per_gb    = isset( $points['points_per_gb'] ) ? max( 1, (int) $points['points_per_gb'] ) : 90;
		$cheapest  = null;

		$cash = array();
		foreach ( $packages as $pkg ) {
			$bytes  = isset( $pkg['bytes'] ) ? (float) $pkg['bytes'] : 0.0;
			$price  = isset( $pkg['price'] ) ? (float) $pkg['price'] : 0.0;
			$cash[] = array(
				'key'    => (string) ( $pkg['id'] ?? '' ),
				'id'     => strtoupper( (string) ( $pkg['id'] ?? '' ) ),
				'label'  => isset( $pkg['label'] ) ? (string) $pkg['label'] : '',
				'gb'     => $this->gb( $bytes ),
				'price'  => $this->decimal( $price, 2 ),
				'days'   => (string) ( isset( $pkg['days'] ) ? (int) $pkg['days'] : 365 ),
				'active' => ! empty( $pkg['active'] ),
			);

			if ( $bytes > 0 && $price > 0 ) {
				$per = $price / ( $bytes / 1073741824 );
				if ( null === $cheapest || $per < $cheapest ) {
					$cheapest = $per;
				}
			}
		}

		$point_rows = array();
		foreach ( $ppkgs as $pkg ) {
			$gb     = isset( $pkg['gb'] ) ? (float) $pkg['gb'] : 0.0;
			$label  = isset( $pkg['label'] ) && '' !== (string) $pkg['label'] ? (string) $pkg['label'] : ( $gb > 0 ? Space_Settings::points_label( $gb, (int) ( $pkg['days'] ?? 365 ) ) : '' );
			$point_rows[] = array(
				'key'    => (string) ( $pkg['id'] ?? '' ),
				'id'     => strtoupper( (string) ( $pkg['id'] ?? '' ) ),
				'label'  => $label,
				'gb'     => $this->decimal( $gb, 2 ),
				'points' => (string) (int) ( $pkg['points'] ?? 0 ),
				'days'   => (string) (int) ( $pkg['days'] ?? 365 ),
				'active' => ! empty( $pkg['active'] ),
			);
		}

		$today = Space_Store::instance()->points_today_totals();
		$cash_gb = $pay_rate > 0 ? ( $per_gb / $pay_rate ) : 0.0;

		return array(
			'packages'     => $cash,
			'points'       => array(
				'enabled'   => Space_Settings::points_enabled(),
				'per_gb'    => (string) $per_gb,
				'max_gb'    => (string) max( 1, (int) ( $points['max_gb_per_order'] ?? 1 ) ),
				'daily_cap' => (string) max( 1, (int) ( $points['daily_cap_gb'] ?? 1 ) ),
				'pay_rate'  => $pay_rate > 0 ? $this->decimal( $pay_rate, 2 ) : null,
			),
			'points_packages' => $point_rows,
			'icon'         => $icon,
			'default_icon' => $default,
			'compare'      => array(
				'cheapest' => null === $cheapest ? null : $this->decimal( $cheapest, 2 ),
				'cash_gb'  => $pay_rate > 0 ? $this->decimal( $cash_gb, 2 ) : null,
				'far'      => null !== $cheapest && $cheapest > 0 && $cash_gb > ( $cheapest * 2 ),
			),
			'today'        => array(
				'gb'    => $this->decimal( (float) ( $today['gb'] ?? 0 ), 2 ),
				'count' => (int) ( $today['count'] ?? 0 ),
			),
		);
	}

	/**
	 * Theme normalisation.
	 *
	 * @param array<string,mixed> $diag Diagnostics.
	 * @return array<string,mixed>
	 */
	private function theme( array $diag ): array {
		$theme = isset( $diag['theme'] ) && is_array( $diag['theme'] ) ? $diag['theme'] : array();

		return wp_parse_args(
			$theme,
			array(
				'name'       => '',
				'version'    => '',
				'stylesheet' => '',
				'template'   => '',
				'is_child'   => false,
			)
		);
	}

	/**
	 * Capability list.
	 *
	 * @param array<string,mixed> $diag Diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private function caps( array $diag ): array {
		$labels = array(
			'theme'       => __( '主题识别（zibll）', 'sakura-imgopt' ),
			'level'       => __( '等级读取 zib_get_user_level', 'sakura-imgopt' ),
			'vip'         => __( 'VIP 读取 zib_get_user_vip_level', 'sakura-imgopt' ),
			'points'      => __( '积分函数 zibpay_update_user_points', 'sakura-imgopt' ),
			'pay_methods' => __( '支付方式 zibpay_get_payment_methods', 'sakura-imgopt' ),
			'pay_initiate'=> __( '发起支付 zibpay_initiate_pay', 'sakura-imgopt' ),
			'orders'      => __( '订单类 ZibPay', 'sakura-imgopt' ),
			'user_center' => __( '用户中心 zib_get_user_center_url', 'sakura-imgopt' ),
		);
		$state = isset( $diag['capabilities'] ) && is_array( $diag['capabilities'] ) ? $diag['capabilities'] : array();
		$out   = array();

		foreach ( $labels as $key => $label ) {
			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'ok'    => ! empty( $state[ $key ] ),
			);
		}

		return $out;
	}

	/**
	 * Pre-formatted level breakdown line.
	 *
	 * @param array<string,mixed> $breakdown Breakdown.
	 * @return string
	 */
	private function level_line( array $breakdown ): string {
		return sprintf(
			/* translators: 1: theme level number, 2: capacity granted by that level. */
			__( 'LV%1$s · %2$s', 'sakura-imgopt' ),
			$breakdown['level'] ?? 0,
			$this->size( $breakdown['level_bytes'] ?? 0 )
		);
	}

	/**
	 * Pre-formatted VIP breakdown line.
	 *
	 * @param array<string,mixed> $breakdown Breakdown.
	 * @return string
	 */
	private function vip_line( array $breakdown ): string {
		return sprintf(
			/* translators: 1: zibll vip level number, 2: capacity granted by that VIP level. */
			__( 'vip_level=%1$s · %2$s', 'sakura-imgopt' ),
			$breakdown['vip'] ?? 0,
			$this->size( $breakdown['vip_bytes'] ?? 0 )
		);
	}

	/**
	 * WordPress size string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private function size( $bytes ): string {
		return (string) size_format( max( 0, (int) $bytes ) );
	}

	/**
	 * Number of MB as a compact string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private function mb( $bytes ): string {
		$mb = ( (float) $bytes ) / 1048576;

		return $mb <= 0 ? '0' : rtrim( rtrim( number_format( $mb, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Number of GB as a compact string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private function gb( $bytes ): string {
		$gb = ( (float) $bytes ) / 1073741824;

		return $gb > 0 ? rtrim( rtrim( number_format( $gb, 3, '.', '' ), '0' ), '.' ) : '';
	}

	/**
	 * Decimal string without trailing zeros.
	 *
	 * @param mixed $value Value.
	 * @param int   $precision Precision.
	 * @return string
	 */
	private function decimal( $value, int $precision ): string {
		return rtrim( rtrim( number_format( (float) $value, $precision, '.', '' ), '0' ), '.' );
	}

	/**
	 * MB formatter used by the level/VIP totals.
	 *
	 * @param float $mb Megabytes.
	 * @return string
	 */
	private function format_mb( float $mb ): string {
		if ( $mb <= 0 ) {
			return '0 ' . __( 'MB', 'sakura-imgopt' );
		}
		if ( $mb >= 1024 ) {
			return (string) round( $mb / 1024, 1 ) . ' ' . __( 'GB', 'sakura-imgopt' );
		}

		return (string) round( $mb, 1 ) . ' ' . __( 'MB', 'sakura-imgopt' );
	}

	/**
	 * All user-facing strings used by the island.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'unitMb' => __( 'MB', 'sakura-imgopt' ),
			'unitGb' => __( 'GB', 'sakura-imgopt' ),
			'confirmTitle' => __( '请确认', 'sakura-imgopt' ),
			'confirm' => __( '确认', 'sakura-imgopt' ),
			'cancel' => __( '取消', 'sakura-imgopt' ),
			'loading' => __( '正在读取…', 'sakura-imgopt' ),
			'empty' => __( '暂无数据', 'sakura-imgopt' ),
			'yes' => __( '是', 'sakura-imgopt' ),
			'no' => __( '否', 'sakura-imgopt' ),
			'unknown' => __( '未取到', 'sakura-imgopt' ),
			'themeTitle' => __( '当前主题', 'sakura-imgopt' ),
			'name' => __( '名称', 'sakura-imgopt' ),
			'version' => __( '版本', 'sakura-imgopt' ),
			'stylesheet' => __( '样式目录', 'sakura-imgopt' ),
			'template' => __( '模板目录', 'sakura-imgopt' ),
			'child' => __( '子主题', 'sakura-imgopt' ),
			'capsTitle' => __( '能力矩阵', 'sakura-imgopt' ),
			'capOk' => __( '可用', 'sakura-imgopt' ),
			'capMissing' => __( '缺失', 'sakura-imgopt' ),
			'capClosed' => __( '该通道已自动关闭', 'sakura-imgopt' ),
			'methodsTitle' => __( '已启用购买方式', 'sakura-imgopt' ),
			'noMethods' => __( '当前没有启用任何购买方式。可在下方「支付方式」卡片勾选并保存。', 'sakura-imgopt' ),
			'points' => __( '积分兑换', 'sakura-imgopt' ),
			'methodsNote' => __( '现金通道只显示已勾选且主题当前提供的项目；积分兑换由本插件直接扣减，不经过主题支付。', 'sakura-imgopt' ),
			'breakdownTitle' => __( '我的派生容量', 'sakura-imgopt' ),
			'base' => __( '基础', 'sakura-imgopt' ),
			'levelBonus' => __( '等级加成', 'sakura-imgopt' ),
			'vipBonus' => __( 'VIP 加成', 'sakura-imgopt' ),
			'granted' => __( '已购', 'sakura-imgopt' ),
			'computed' => __( '计算值', 'sakura-imgopt' ),
			'override' => __( '单独设定', 'sakura-imgopt' ),
			'final' => __( '最终生效', 'sakura-imgopt' ),
			'unset' => __( '未设定', 'sakura-imgopt' ),
			'mode' => __( '封顶语义', 'sakura-imgopt' ),
			'modeCap' => __( '封顶（cap）· 实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ),
			'modeReplace' => __( '替换（replace）· 单独设定直接覆盖计算值', 'sakura-imgopt' ),
			'themeMethods' => __( '主题支付通道', 'sakura-imgopt' ),
			'themeMethodsHint' => __( '余额由主题同步确认；二维码或跳转渠道必须确认能处理退款与超时订单后再勾选。', 'sakura-imgopt' ),
			'noThemeMethods' => __( '未读取到主题支付通道。请确认已登录后台，且主题已开启对应的支付方式。', 'sakura-imgopt' ),
			'balanceNote' => __( '同步到账，推荐启用。', 'sakura-imgopt' ),
			'externalNote' => __( '外部支付渠道，可能出现未支付订单，需要人工对账。', 'sakura-imgopt' ),
			'pointsEnabled' => __( '启用积分兑换', 'sakura-imgopt' ),
			'pointsHint' => __( '积分由本插件直接扣减，不经过主题支付；与现金通道互不影响。', 'sakura-imgopt' ),
			'pointsNote' => __( '关闭后前台隐藏积分入口，接口拒绝兑换；已售出的积分授权不受影响。', 'sakura-imgopt' ),
			'entryEnabled' => __( '启用前台入口', 'sakura-imgopt' ),
			'entryOn' => __( '当前：已启用', 'sakura-imgopt' ),
			'entryOff' => __( '当前：未启用（前台零入口）', 'sakura-imgopt' ),
			'currentTheme' => __( '当前主题', 'sakura-imgopt' ),
			'detectedEntry' => __( '检测到的入口', 'sakura-imgopt' ),
			'frontPosition' => __( '前台位置', 'sakura-imgopt' ),
			'frontUrl' => __( '前台地址', 'sakura-imgopt' ),
			'generic' => __( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' ),
			'userCenter' => __( '用户中心 → 文件管理', 'sakura-imgopt' ),
			'unpublished' => __( '尚未发布', 'sakura-imgopt' ),
			'entryNote' => __( '关闭只停用入口与前台上传接口，不删除文件，也不重置容量。', 'sakura-imgopt' ),
			'editorEnabled' => __( '启用编辑器文件面板', 'sakura-imgopt' ),
			'editorPages' => __( '生效页面', 'sakura-imgopt' ),
			'editorPagesValue' => __( '写文章 /newposts、发帖与编辑 /posts-edit', 'sakura-imgopt' ),
			'editorCondition' => __( '开启条件', 'sakura-imgopt' ),
			'editorConditionValue' => __( '桌面端，且浏览器窗口宽度 ≥ 1840px；不足时按钮不出现', 'sakura-imgopt' ),
			'editorPermission' => __( '权限', 'sakura-imgopt' ),
			'editorPermissionValue' => __( '已登录且拥有「管理自己的文件空间」权限；只看得到自己的文件', 'sakura-imgopt' ),
			'editorIndependent' => __( '与前台入口互不影响。', 'sakura-imgopt' ),
			'gateEnabled' => __( '启用扩容售卖', 'sakura-imgopt' ),
			'gateOn' => __( '当前：已启用', 'sakura-imgopt' ),
			'gateOff' => __( '当前：未启用（前台零入口）', 'sakura-imgopt' ),
			'gateEnableWarn' => __( '启用后将开启扩容售卖入口，用户可付费购买容量。确认继续？', 'sakura-imgopt' ),
			'gateDisableWarn' => __( '关闭后前台扩容入口会立即消失，已售出的授权仍然有效。确认继续？', 'sakura-imgopt' ),
			'applyBonuses' => __( '计算加成（已购授权）', 'sakura-imgopt' ),
			'applyBonusesLabel' => __( '计算已购授权', 'sakura-imgopt' ),
			'applyBonusesNote' => __( '关闭后，所有已购容量立即停止生效（不删任何文件）。', 'sakura-imgopt' ),
			'applyBonusesWarn' => __( '关闭「计算加成」会让所有已购容量立即停止生效（不删任何文件）。确认继续？', 'sakura-imgopt' ),
			'overrideMode' => __( '单独设定的封顶语义', 'sakura-imgopt' ),
			'modeCapShort' => __( '封顶（cap）：实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ),
			'modeReplaceShort' => __( '替换（replace）：单独设定直接覆盖计算值', 'sakura-imgopt' ),
			'levelRule' => __( '启用规则（关闭后回退到下方手填表）', 'sakura-imgopt' ),
			'pointsPerStep' => __( '每满积分', 'sakura-imgopt' ),
			'pointsPerStepHint' => __( '达到该积分后开始计算一级加成。', 'sakura-imgopt' ),
			'bytesPerStep' => __( '增加（MB）', 'sakura-imgopt' ),
			'bytesPerStepHint' => __( '每满一级增加的容量，单位 MB。', 'sakura-imgopt' ),
			'level' => __( '等级', 'sakura-imgopt' ),
			'threshold' => __( '门槛积分', 'sakura-imgopt' ),
			'bonusMb' => __( '加成（MB）', 'sakura-imgopt' ),
			'total' => __( '加成合计：', 'sakura-imgopt' ),
			'vipLevel' => __( 'VIP 等级', 'sakura-imgopt' ),
			/* translators: %d: number of theme levels. */
			'levelDescription' => __( '按主题等级自动折算：当前主题共 %d 级，等级名与门槛积分都读自主题。每满一定积分增加一定容量，等级越高、门槛越高，加成越大。', 'sakura-imgopt' ),
			'cashPackages' => __( '现金套餐', 'sakura-imgopt' ),
			'cashHint' => __( '用户在弹窗里看到的价格档位，按下单顺序展示；价格取主题支付通道。', 'sakura-imgopt' ),
			'pointsPackages' => __( '积分套餐', 'sakura-imgopt' ),
			'pointsPackagesHint' => __( '积分兑换按「每 GB 所需积分」折算；等价现金用主题积分汇率（pay_points_rate）反算。', 'sakura-imgopt' ),
			'perGb' => __( '每 GB 积分', 'sakura-imgopt' ),
			/* translators: 1: price in currency per GB, 2: points per currency unit. */
			'perGbHint' => __( '约 %1$s 元/GB（主题汇率 %2$s 积分 = 1 元）。', 'sakura-imgopt' ),
			'noPayRate' => __( '未取到主题积分汇率（pay_points_rate）。', 'sakura-imgopt' ),
			'maxGb' => __( '单笔上限（GB）', 'sakura-imgopt' ),
			'maxGbHint' => __( '保存时会被强制压到「每日上限」以内。', 'sakura-imgopt' ),
			'dailyCap' => __( '每日上限（GB）', 'sakura-imgopt' ),
			'dailyCapHint' => __( '同一用户一天内可兑换的总量上限。', 'sakura-imgopt' ),
			'planId' => __( '套餐 ID', 'sakura-imgopt' ),
			'planName' => __( '名称', 'sakura-imgopt' ),
			'capacityGb' => __( '容量（GB）', 'sakura-imgopt' ),
			'price' => __( '价格（元）', 'sakura-imgopt' ),
			'amount' => __( '金额', 'sakura-imgopt' ),
			'pointsRequired' => __( '所需积分', 'sakura-imgopt' ),
			'days' => __( '时长（天）', 'sakura-imgopt' ),
			'active' => __( '上架', 'sakura-imgopt' ),
			'remove' => __( '删除', 'sakura-imgopt' ),
			'addCash' => __( '＋ 添加现金套餐', 'sakura-imgopt' ),
			'addPoints' => __( '＋ 添加积分套餐', 'sakura-imgopt' ),
			'labelPlaceholder' => __( '如 10GB 扩容包', 'sakura-imgopt' ),
			'pointsLabelPlaceholder' => __( '留空自动生成', 'sakura-imgopt' ),
			'chooseIcon' => __( '选择图标', 'sakura-imgopt' ),
			'removeIcon' => __( '移除', 'sakura-imgopt' ),
			'iconPlaceholder' => __( '选择或粘贴图标地址', 'sakura-imgopt' ),
			'iconHint' => __( '对所有套餐生效（现金套餐与积分套餐共用）。建议 96×96（或 128×128）正方形 PNG/SVG，≤200 KB；留空使用默认的存储图标。图标会出现在后台订单列表、前台「我的订单」和订单详情弹窗。', 'sakura-imgopt' ),
			'noCash' => __( '暂无可用现金套餐，无法比较。', 'sakura-imgopt' ),
			/* translators: %s: lowest cash price per GB. */
			'cheapest' => __( '最便宜现金套餐：%s 元/GB。', 'sakura-imgopt' ),
			'far' => __( '积分定价明显偏离现金价（超过 2 倍）。', 'sakura-imgopt' ),
			/* translators: 1: GB already redeemed today, 2: daily GB cap, 3: number of redemptions. */
			'today' => __( '今日已兑换 %1$s GB / 上限 %2$s GB（%3$d 笔）。', 'sakura-imgopt' ),
			'planNote' => __( '套餐 ID 自动分配（A 为现金、P 为积分），删除后不复用；容量填 0 或价格/积分空着的行在保存时会被丢弃。', 'sakura-imgopt' ),
			'grantsEmpty' => __( '暂无授权记录', 'sakura-imgopt' ),
			'grantsEmptyMessage' => __( '授权会在用户完成现金订单或积分兑换后出现。', 'sakura-imgopt' ),
			'gateDisabledBanner' => __( '扩容尚未启用。前台不会出现任何入口；已存在的等级 / VIP 加成也不会影响容量。', 'sakura-imgopt' ),
			'grantsLoading' => __( '正在读取授权记录…', 'sakura-imgopt' ),
			'grantsError' => __( '授权记录加载失败，请稍后重试。', 'sakura-imgopt' ),
			'grantTimeUser' => __( '时间 / 用户', 'sakura-imgopt' ),
			'grantPackageSize' => __( '套餐 / 容量', 'sakura-imgopt' ),
			'grantExpiry' => __( '到期', 'sakura-imgopt' ),
			'grantState' => __( '状态', 'sakura-imgopt' ),
			'grantActions' => __( '操作', 'sakura-imgopt' ),
			'permanent' => __( '永久', 'sakura-imgopt' ),
			'grantExtendTitle' => __( '延长授权', 'sakura-imgopt' ),
			'grantExtendPrompt' => __( '请输入延长天数（1–3650）', 'sakura-imgopt' ),
			'grantExtend' => __( '延长', 'sakura-imgopt' ),
			'grantExtendDone' => __( '已延长授权。', 'sakura-imgopt' ),
			'grantRevokeTitle' => __( '撤销授权', 'sakura-imgopt' ),
			'grantRevokePrompt' => __( '输入「撤销」两个字确认本次操作', 'sakura-imgopt' ),
			'grantRevoke' => __( '撤销', 'sakura-imgopt' ),
			'grantRevokeDone' => __( '已撤销授权。', 'sakura-imgopt' ),
			'grantActionFailed' => __( '操作失败，请重试。', 'sakura-imgopt' ),
			'revokeWord' => __( '撤销', 'sakura-imgopt' ),
			'invalidDays' => __( '请输入 1–3650 之间的天数。', 'sakura-imgopt' ),
			'invalidWord' => __( '输入不匹配，操作已取消。', 'sakura-imgopt' ),
			'mediaUnavailable' => __( '媒体库未加载，请刷新页面后重试。', 'sakura-imgopt' ),
			'refreshGrants' => __( '刷新授权', 'sakura-imgopt' ),
			'reconcileCheck' => __( '检查差异', 'sakura-imgopt' ),
			'reconcileApply' => __( '修复差异', 'sakura-imgopt' ),
			'reconcileNotChecked' => __( '尚未检查。点击「检查差异」比对已付款订单与授权记录。', 'sakura-imgopt' ),
			'reconcileChecking' => __( '正在比对订单与授权…', 'sakura-imgopt' ),
			/* translators: %s: number of paid orders without a grant. */
			'reconcileMissing' => __( '发现 %s 笔「已付款但无授权」的订单。', 'sakura-imgopt' ),
			'reconcileClean' => __( '未发现差异。', 'sakura-imgopt' ),
			'reconcileHealing' => __( '正在补发授权…', 'sakura-imgopt' ),
			/* translators: %s: number of reissued grants. */
			'reconcileHealed' => __( '已补发 %s 笔授权。', 'sakura-imgopt' ),
			'reconcileFailed' => __( '对账查询失败。', 'sakura-imgopt' ),
			/* translators: %s: number of grants about to be reissued. */
			'reconcileConfirm' => __( '将按订单快照补发 %s 笔授权，确认？', 'sakura-imgopt' ),
			'reconcileNoItems' => __( '没有需要补发的订单。', 'sakura-imgopt' ),
			'orderNum' => __( '订单号', 'sakura-imgopt' ),
			'user' => __( '用户', 'sakura-imgopt' ),
			'payTime' => __( '支付时间', 'sakura-imgopt' ),
		);
	}
}
