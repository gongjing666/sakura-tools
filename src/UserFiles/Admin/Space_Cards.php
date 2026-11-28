<?php
/**
 * Per-card data and the REST save path for the Zibll-only file space tab.
 *
 * The first paint and the save response share data(), so a saved card can never
 * disagree with the payload the same request loaded with. save() merges only the
 * fields the card owns into its option (Space_Settings::OPTION, or sufm_settings
 * for the two switches that live there) and lets the existing sanitisers clamp.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Frontend\Entry;
use Nboke\UserFiles\Frontend\Providers\Generic;
use Nboke\UserFiles\Services\Option_Lock;
use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use Nboke\UserFiles\Services\Zibll_Bridge;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Space_Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One owner for the ten card sections and the seven editable ones' write path.
 */
final class Space_Cards {

	/**
	 * Theme diagnostics card.
	 */
	public const DIAG = 'space_diag';

	/**
	 * Payment channels card.
	 */
	public const PAYMENT = 'space_payment';

	/**
	 * Front-end entry card.
	 */
	public const ENTRY = 'space_entry';

	/**
	 * Editor panel card.
	 */
	public const EDITOR = 'space_editor';

	/**
	 * Expansion gate card.
	 */
	public const GATE = 'space_gate';

	/**
	 * Level bonus card.
	 */
	public const LEVEL = 'space_level';

	/**
	 * VIP / SVIP bonus card.
	 */
	public const VIP = 'space_vip';

	/**
	 * Space packages card.
	 */
	public const PLANS = 'space_plans';

	/**
	 * Read-only grant ledger card.
	 */
	public const GRANTS = 'space_grants';

	/**
	 * Read-only reconciliation card.
	 */
	public const RECONCILE = 'space_reconcile';

	/**
	 * Cards the REST endpoint may write.
	 *
	 * @var array<int,string>
	 */
	private const EDITABLE = array(
		self::PAYMENT,
		self::ENTRY,
		self::EDITOR,
		self::GATE,
		self::LEVEL,
		self::VIP,
		self::PLANS,
	);

	/**
	 * Sections to return after a card is saved.
	 *
	 * A card that feeds the diagnostics breakdown must refresh it too, or the
	 * page keeps the numbers it loaded with until the next full reload.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const DEPENDENTS = array(
		self::PAYMENT => array( self::PAYMENT, self::DIAG ),
		self::ENTRY => array( self::ENTRY ),
		self::EDITOR => array( self::EDITOR ),
		self::GATE => array( self::GATE, self::DIAG ),
		self::LEVEL => array( self::LEVEL, self::DIAG ),
		self::VIP => array( self::VIP, self::DIAG ),
		self::PLANS => array( self::PLANS ),
	);

	/**
	 * Editable card ids.
	 *
	 * @return array<int,string>
	 */
	public static function editable(): array {
		return self::EDITABLE;
	}

	/**
	 * Static copy for one card, or null when the id is unknown.
	 *
	 * @param string $card Card id.
	 * @return array<string,mixed>|null
	 */
	public static function meta( string $card ): ?array {
		$map = array(
			self::DIAG => array(
				'kicker'      => 'DIAGNOSE',
				'title'       => __( '主题诊断', 'sakura-imgopt' ),
				'description' => __( '本页所有数值都在运行时读取主题，不写死任何主题侧的名称或价格。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			self::PAYMENT => array(
				'kicker'      => 'PAYMENT',
				'title'       => __( '支付方式', 'sakura-imgopt' ),
				'description' => __( '只有勾选并保存的通道才会出现在前台收银台。默认只启用余额；支付宝、微信等外部渠道需自行确认对账与退款流程。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存支付方式', 'sakura-imgopt' ),
			),
			self::ENTRY => array(
				'kicker'      => 'ENTRY',
				'title'       => __( '前台入口', 'sakura-imgopt' ),
				'description' => __( '只控制系统用户中心里是否出现「文件管理」入口，以及前台上传 / 删除接口。关闭不会删除任何文件。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存入口设置', 'sakura-imgopt' ),
			),
			self::EDITOR => array(
				'kicker'      => 'EDITOR',
				'title'       => __( '编辑器文件面板', 'sakura-imgopt' ),
				'description' => __( '在写文章 / 发帖页的编辑器工具栏加一个入口，点开后在正文左侧固定打开文件空间，写作时直接复用旧附件。面板只读：不能上传、不能删除、不占配额。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存编辑器面板设置', 'sakura-imgopt' ),
			),
			self::GATE => array(
				'kicker'      => 'GATE',
				'title'       => __( '门禁与开关', 'sakura-imgopt' ),
				'description' => __( '总开关默认关闭；「计算加成」是紧急止血阀：关闭后已购容量立即停止生效（等级 / VIP 加成仍按主题计算）。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存开关', 'sakura-imgopt' ),
			),
			self::LEVEL => array(
				'kicker'      => 'BONUS',
				'title'       => __( '等级加成', 'sakura-imgopt' ),
				'description' => '',
				'wide'        => true,
				'save'        => __( '保存等级加成', 'sakura-imgopt' ),
			),
			self::VIP => array(
				'kicker'      => 'BONUS',
				'title'       => __( 'VIP / SVIP 加成', 'sakura-imgopt' ),
				'description' => __( '名称在运行时解析：过滤器 → 主题选项 → 降级标签。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存 VIP 加成', 'sakura-imgopt' ),
			),
			self::PLANS => array(
				'kicker'      => 'PLANS',
				'title'       => __( '空间套餐', 'sakura-imgopt' ),
				'description' => __( '两种支付方式卖同一份空间：现金套餐（A 开头）走主题支付，积分套餐（P 开头）直接扣积分。下架或删除只影响新订单，已售出的授权按购买时的快照继续生效。', 'sakura-imgopt' ),
				'wide'        => true,
				'save'        => __( '保存套餐', 'sakura-imgopt' ),
			),
			self::GRANTS => array(
				'kicker'      => 'GRANTS',
				'title'       => __( '授权流水', 'sakura-imgopt' ),
				'description' => __( '最近 20 条授权记录，时间已按站点时区显示。到期时间留空表示永久。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			self::RECONCILE => array(
				'kicker'      => 'RECONCILE',
				'title'       => __( '对账与重试', 'sakura-imgopt' ),
				'description' => __( '「检查差异」只读，找出已付款但没有授权的子比订单；「修复差异」按订单快照补发，幂等（同一订单不会重复授予）。', 'sakura-imgopt' ),
				'wide'        => true,
			),
		);

		return $map[ $card ] ?? null;
	}

	/**
	 * Display data for one card, in the shape payload and save response share.
	 *
	 * @param string $card Card id.
	 * @return array<string,mixed>
	 */
	public static function data( string $card ): array {
		$bridge = Zibll_Bridge::instance();

		switch ( $card ) {
			case self::DIAG:
				$diag = $bridge->diagnostics();

				return self::diagnostics( $diag, self::theme( $diag ), self::caps( $diag ), ( new Entry() )->status() );

			case self::PAYMENT:
				return self::payment( $bridge->diagnostics() );

			case self::ENTRY:
				$diag = $bridge->diagnostics();

				return self::entry( ( new Entry() )->status(), self::theme( $diag ) );

			case self::EDITOR:
				return array( 'enabled' => Settings::editor_enabled() );

			case self::GATE:
				return array(
					'enabled'       => Space_Settings::enabled(),
					'apply_bonuses' => Space_Settings::apply_bonuses(),
					'mode'          => Space_Settings::mode(),
				);

			case self::LEVEL:
				return self::level();

			case self::VIP:
				return self::vip();

			case self::PLANS:
				return self::plans();
		}

		return array();
	}

	/**
	 * Save one card and return the sections the page replaces.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( string $card, array $fields ): array|WP_Error {
		if ( ! in_array( $card, self::EDITABLE, true ) ) {
			return new WP_Error(
				'sufm_space_card',
				__( '未知的设置卡片。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$fields = self::fields( $card, $fields );
		$saved  = ( self::ENTRY === $card || self::EDITOR === $card )
			? self::save_settings( $fields )
			: self::save_space( $fields );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$sections = array();

		foreach ( self::DEPENDENTS[ $card ] as $dependent ) {
			$sections[ $dependent ] = self::data( $dependent );
		}

		return array(
			'card'     => $card,
			'sections' => $sections,
			'notice'   => array(
				'tone'    => 'ok',
				'kicker'  => 'SAVED',
				'title'   => self::notice_title( $card ),
				'message' => self::notice_message( $card ),
			),
		);
	}

	/**
	 * Keep only the fields this card owns, in the shape its sanitiser expects.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted fields.
	 * @return array<string,mixed>
	 */
	private static function fields( string $card, array $fields ): array {
		$clean = array();

		switch ( $card ) {
			case self::PAYMENT:
				$methods = isset( $fields['allowed_methods'] ) && is_array( $fields['allowed_methods'] ) ? $fields['allowed_methods'] : array();

				$clean['allowed_methods']         = array_values( array_filter( array_map( 'strval', array_filter( $methods, 'is_scalar' ) ), static fn( string $method ): bool => '' !== $method ) );
				$clean['allowed_methods_present'] = 1;
				$clean['points']                  = array( 'enabled' => self::flag( $fields['points_enabled'] ?? 0 ) );
				break;

			case self::ENTRY:
				$clean['enabled'] = self::flag( $fields['enabled'] ?? 0 );
				break;

			case self::EDITOR:
				$clean['editor_enabled'] = self::flag( $fields['editor_enabled'] ?? 0 );
				break;

			case self::GATE:
				$clean['enabled']             = self::flag( $fields['enabled'] ?? 0 );
				$clean['apply_bonuses']       = self::flag( $fields['apply_bonuses'] ?? 0 );
				$clean['quota_override_mode'] = 'replace' === ( $fields['mode'] ?? '' ) ? 'replace' : 'cap';
				break;

			case self::LEVEL:
				$rule = isset( $fields['level_rule'] ) && is_array( $fields['level_rule'] ) ? $fields['level_rule'] : array();

				$rule_on = self::flag( $rule['enabled'] ?? 0 );

				$clean['level_rule'] = array(
					'enabled'           => $rule_on,
					'points_per_step'   => self::scalar_int( $rule['points_per_step'] ?? 0 ),
					'bytes_per_step_mb' => self::scalar_float( $rule['bytes_per_step_mb'] ?? 0 ),
				);

				// With the rule on the native form left the disabled inputs out, so
				// the stored manual table survived. Keep that behaviour here.
				if ( 0 === $rule_on && isset( $fields['level_bonus_mb'] ) && is_array( $fields['level_bonus_mb'] ) ) {
					$level_bonus = self::map_values( $fields['level_bonus_mb'] );

					if ( $level_bonus ) {
						$clean['level_bonus_mb'] = $level_bonus;
					}
				}
				break;

			case self::VIP:
				$vip_bonus = isset( $fields['vip_bonus_mb'] ) && is_array( $fields['vip_bonus_mb'] ) ? self::map_values( $fields['vip_bonus_mb'] ) : array();

				if ( $vip_bonus ) {
					$clean['vip_bonus_mb'] = $vip_bonus;
				}
				break;

			case self::PLANS:
				// The `??` inside `is_scalar` made the true branch read the key again,
				// so saving plans without an icon raised "Undefined array key".
				$clean['order_icon']      = sanitize_text_field( self::scalar_string( $fields['order_icon'] ?? '' ) );
				$clean['packages']        = self::cash_rows( isset( $fields['packages'] ) && is_array( $fields['packages'] ) ? $fields['packages'] : array() );
				$clean['points_packages'] = self::point_rows( isset( $fields['points_packages'] ) && is_array( $fields['points_packages'] ) ? $fields['points_packages'] : array() );

				$points = isset( $fields['points'] ) && is_array( $fields['points'] ) ? $fields['points'] : array();

				if ( $points ) {
					$clean['points'] = array(
						'points_per_gb'    => self::scalar_int( $points['points_per_gb'] ?? 0 ),
						'max_gb_per_order' => self::scalar_int( $points['max_gb_per_order'] ?? 0 ),
						'daily_cap_gb'     => self::scalar_int( $points['daily_cap_gb'] ?? 0 ),
					);
				}
				break;
		}

		return $clean;
	}

	/**
	 * Cash package rows using the native form's field names.
	 *
	 * @param array<int,mixed> $rows Submitted rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cash_rows( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'id'       => self::scalar_string( $row['id'] ?? '' ),
				'label'    => self::scalar_string( $row['label'] ?? '' ),
				'bytes_gb' => self::size_gb( $row ),
				'price'    => self::scalar_string( $row['price'] ?? '' ),
				'days'     => self::scalar_string( $row['days'] ?? '' ),
				'active'   => self::flag( $row['active'] ?? 0 ),
				'remove'   => self::flag( $row['remove'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Points package rows using the native form's field names.
	 *
	 * @param array<int,mixed> $rows Submitted rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function point_rows( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'id'     => self::scalar_string( $row['id'] ?? '' ),
				'label'  => self::scalar_string( $row['label'] ?? '' ),
				'gb'     => self::scalar_string( $row['gb'] ?? '' ),
				'points' => self::scalar_string( $row['points'] ?? '' ),
				'days'   => self::scalar_string( $row['days'] ?? '' ),
				'active' => self::flag( $row['active'] ?? 0 ),
				'remove' => self::flag( $row['remove'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Cast a [key => scalar] map to [int => string] without dropping zeros.
	 *
	 * @param array<mixed> $map Raw map.
	 * @return array<int,string>
	 */
	private static function map_values( array $map ): array {
		$out = array();

		foreach ( $map as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$out[ (int) $key ] = (string) $value;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Scalar value as a string; arrays and objects collapse to an empty string.
	 *
	 * Row fields come straight from the request. A bare `(string)` cast of an
	 * array raises "Array to string conversion" and stores the literal "Array";
	 * non-scalars are therefore treated as an absent field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Scalar value as a float; arrays and objects collapse to 0.0.
	 *
	 * A bare `(float)` cast turns any non-empty array into 1.0, which silently
	 * became a 1 MB level step or a 1 GB quota. Scalar input keeps its exact old
	 * cast semantics; only non-scalars are normalised to zero.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function scalar_float( $value ): float {
		return is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Scalar value as an integer; arrays and objects collapse to 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function scalar_int( $value ): int {
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Size field for one cash package row: `gb` first, then legacy `bytes_gb`.
	 *
	 * The old precedence is preserved for scalar input. A non-scalar `gb` is
	 * treated as absent so a legacy scalar `bytes_gb` can still be used.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return string
	 */
	private static function size_gb( array $row ): string {
		if ( isset( $row['gb'] ) && is_scalar( $row['gb'] ) ) {
			return (string) $row['gb'];
		}

		if ( isset( $row['bytes_gb'] ) && is_scalar( $row['bytes_gb'] ) ) {
			return (string) $row['bytes_gb'];
		}

		return '';
	}

	/**
	 * Checkbox value as the 0 / 1 the sanitisers understand.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function flag( $value ): int {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Merge the entry / editor switches into sufm_settings.
	 *
	 * @param array<string,mixed> $fields Clean fields.
	 * @return bool|WP_Error
	 */
	private static function save_settings( array $fields ): bool|WP_Error {
		if ( ! Option_Lock::acquire( Settings::OPTION ) ) {
			return self::lock_error();
		}

		try {
			$stored = get_option( Settings::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			update_option( Settings::OPTION, Settings::sanitize( array_merge( $stored, array( '_partial' => 1 ), $fields ) ), false );

			// Page::flush_entry_cache() only runs on admin screens; a REST save still
			// has to drop the cached shortcode page when the entry switch moves.
			if ( isset( $fields['enabled'] ) ) {
				Generic::flush_page_cache();
			}
		} finally {
			Option_Lock::release();
		}

		return true;
	}

	/**
	 * Merge the expansion cards into sufm_space_settings.
	 *
	 * @param array<string,mixed> $fields Clean fields.
	 * @return bool|WP_Error
	 */
	private static function save_space( array $fields ): bool|WP_Error {
		if ( ! Option_Lock::acquire( Space_Settings::OPTION ) ) {
			return self::lock_error();
		}

		try {
			$stored = get_option( Space_Settings::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			update_option( Space_Settings::OPTION, Space_Settings::sanitize( array_merge( $stored, array( '_partial' => 1 ), $fields ) ), false );

			// The option hooks that drop these memos only run on admin screens.
			Space_Settings::flush();
			Space_Rules::flush();
		} finally {
			Option_Lock::release();
		}

		return true;
	}

	/**
	 * Error returned when a competing request keeps the option lock.
	 *
	 * @return WP_Error
	 */
	private static function lock_error(): WP_Error {
		return new WP_Error(
			'sufm_space_locked',
			__( '设置正在被另一个请求保存，请稍后重试。', 'sakura-imgopt' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Post-save title for one card.
	 *
	 * @param string $card Card id.
	 * @return string
	 */
	private static function notice_title( string $card ): string {
		switch ( $card ) {
			case self::PAYMENT:
				return __( '支付方式已保存', 'sakura-imgopt' );
			case self::ENTRY:
				return __( '入口设置已保存', 'sakura-imgopt' );
			case self::EDITOR:
				return __( '编辑器面板设置已保存', 'sakura-imgopt' );
			case self::GATE:
				return __( '门禁开关已保存', 'sakura-imgopt' );
			case self::LEVEL:
				return __( '等级加成已保存', 'sakura-imgopt' );
			case self::VIP:
				return __( 'VIP 加成已保存', 'sakura-imgopt' );
			case self::PLANS:
				return __( '套餐已保存', 'sakura-imgopt' );
		}

		return __( '设置已保存', 'sakura-imgopt' );
	}

	/**
	 * Post-save message for one card.
	 *
	 * @param string $card Card id.
	 * @return string
	 */
	private static function notice_message( string $card ): string {
		switch ( $card ) {
			case self::PAYMENT:
				return __( '前台收银台会立即使用新的支付方式。', 'sakura-imgopt' );
			case self::ENTRY:
				return __( '前台入口与前台上传接口会立即按新设置生效。', 'sakura-imgopt' );
			case self::EDITOR:
				return __( '编辑器文件面板会立即按新设置生效。', 'sakura-imgopt' );
			case self::GATE:
				return __( '扩容开关会立即生效；已售出的授权按购买时的快照继续有效。', 'sakura-imgopt' );
			case self::LEVEL:
				return __( '等级加成会立即用于计算用户容量。', 'sakura-imgopt' );
			case self::VIP:
				return __( 'VIP / SVIP 加成会立即用于计算用户容量。', 'sakura-imgopt' );
			case self::PLANS:
				return __( '套餐与积分价格会立即用于新订单。', 'sakura-imgopt' );
		}

		return __( '新设置已生效。', 'sakura-imgopt' );
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
	private static function diagnostics( array $diag, array $theme, array $caps, array $entry ): array {
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
				'base'     => self::size( $breakdown['base'] ?? 0 ),
				'level'    => self::level_line( $breakdown ),
				'vip'      => self::vip_line( $breakdown ),
				'granted'  => self::size( $breakdown['granted_bytes'] ?? 0 ),
				'computed' => self::size( $breakdown['computed'] ?? 0 ),
				'override' => null === ( $breakdown['override'] ?? null ) || '' === ( $breakdown['override'] ?? '' ) ? null : self::size( $breakdown['override'] ),
				'final'    => self::size( $breakdown['final'] ?? 0 ),
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
	private static function payment( array $diag ): array {
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
	private static function entry( array $entry, array $theme ): array {
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
	private static function level(): array {
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
				'mb'        => self::mb( $bytes ),
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
			'total' => self::format_mb( $total ),
		);
	}

	/**
	 * VIP bonus data.
	 *
	 * @return array<string,mixed>
	 */
	private static function vip(): array {
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
				'mb'    => self::mb( $bytes ),
			);
		}

		return array(
			'rows'  => $rows,
			'total' => self::format_mb( $total ),
		);
	}

	/**
	 * Package, pricing and comparison data.
	 *
	 * @return array<string,mixed>
	 */
	private static function plans(): array {
		$packages  = Space_Settings::packages();
		$points    = Space_Settings::points();
		$ppkgs     = Space_Settings::points_packages();
		$icon      = Space_Settings::order_icon();
		$default   = Space_Store::product_image_url();
		$options   = get_option( 'zibll_options' );
		$options   = is_array( $options ) ? $options : array();
		$pay_rate  = isset( $options['pay_points_rate'] ) && is_numeric( $options['pay_points_rate'] ) ? (float) $options['pay_points_rate'] : 0.0;
		$per_gb    = isset( $points['points_per_gb'] ) && is_scalar( $points['points_per_gb'] ) ? max( 1, (int) $points['points_per_gb'] ) : 90;
		$cheapest  = null;

		$cash = array();
		foreach ( $packages as $pkg ) {
			// The writer sanitises rows, but this read path is also reachable
			// from a hand-edited option; never feed a nested array to (string).
			if ( ! is_array( $pkg ) ) {
				continue;
			}

			$id     = isset( $pkg['id'] ) && is_scalar( $pkg['id'] ) ? (string) $pkg['id'] : '';
			$bytes  = isset( $pkg['bytes'] ) && is_scalar( $pkg['bytes'] ) ? (float) $pkg['bytes'] : 0.0;
			$price  = isset( $pkg['price'] ) && is_scalar( $pkg['price'] ) ? (float) $pkg['price'] : 0.0;
			$cash[] = array(
				'key'    => $id,
				'id'     => strtoupper( $id ),
				'label'  => isset( $pkg['label'] ) && is_scalar( $pkg['label'] ) ? (string) $pkg['label'] : '',
				'gb'     => self::gb( $bytes ),
				'price'  => self::decimal( $price, 2 ),
				'days'   => (string) ( isset( $pkg['days'] ) && is_scalar( $pkg['days'] ) ? (int) $pkg['days'] : 365 ),
				'active' => isset( $pkg['active'] ) && is_scalar( $pkg['active'] ) && ! empty( $pkg['active'] ),
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
			if ( ! is_array( $pkg ) ) {
				continue;
			}

			$id     = isset( $pkg['id'] ) && is_scalar( $pkg['id'] ) ? (string) $pkg['id'] : '';
			$gb     = isset( $pkg['gb'] ) && is_scalar( $pkg['gb'] ) ? (float) $pkg['gb'] : 0.0;
			$days   = isset( $pkg['days'] ) && is_scalar( $pkg['days'] ) ? (int) $pkg['days'] : 365;
			$label  = isset( $pkg['label'] ) && is_scalar( $pkg['label'] ) && '' !== (string) $pkg['label'] ? (string) $pkg['label'] : ( $gb > 0 ? Space_Settings::points_label( $gb, $days ) : '' );
			$point_rows[] = array(
				'key'    => $id,
				'id'     => strtoupper( $id ),
				'label'  => $label,
				'gb'     => self::decimal( $gb, 2 ),
				'points' => (string) ( isset( $pkg['points'] ) && is_scalar( $pkg['points'] ) ? (int) $pkg['points'] : 0 ),
				'days'   => (string) $days,
				'active' => isset( $pkg['active'] ) && is_scalar( $pkg['active'] ) && ! empty( $pkg['active'] ),
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
				'pay_rate'  => $pay_rate > 0 ? self::decimal( $pay_rate, 2 ) : null,
			),
			'points_packages' => $point_rows,
			'icon'         => $icon,
			'default_icon' => $default,
			'compare'      => array(
				'cheapest' => null === $cheapest ? null : self::decimal( $cheapest, 2 ),
				'cash_gb'  => $pay_rate > 0 ? self::decimal( $cash_gb, 2 ) : null,
				'far'      => null !== $cheapest && $cheapest > 0 && $cash_gb > ( $cheapest * 2 ),
			),
			'today'        => array(
				'gb'    => self::decimal( (float) ( $today['gb'] ?? 0 ), 2 ),
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
	private static function theme( array $diag ): array {
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
	private static function caps( array $diag ): array {
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
	private static function level_line( array $breakdown ): string {
		return sprintf(
			/* translators: 1: theme level number, 2: capacity granted by that level. */
			__( 'LV%1$s · %2$s', 'sakura-imgopt' ),
			$breakdown['level'] ?? 0,
			self::size( $breakdown['level_bytes'] ?? 0 )
		);
	}

	/**
	 * Pre-formatted VIP breakdown line.
	 *
	 * @param array<string,mixed> $breakdown Breakdown.
	 * @return string
	 */
	private static function vip_line( array $breakdown ): string {
		return sprintf(
			/* translators: 1: zibll vip level number, 2: capacity granted by that VIP level. */
			__( 'vip_level=%1$s · %2$s', 'sakura-imgopt' ),
			$breakdown['vip'] ?? 0,
			self::size( $breakdown['vip_bytes'] ?? 0 )
		);
	}

	/**
	 * WordPress size string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private static function size( $bytes ): string {
		return (string) size_format( max( 0, (int) $bytes ) );
	}

	/**
	 * Number of MB as a compact string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private static function mb( $bytes ): string {
		$mb = ( (float) $bytes ) / 1048576;

		return $mb <= 0 ? '0' : rtrim( rtrim( number_format( $mb, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Number of GB as a compact string.
	 *
	 * @param mixed $bytes Bytes.
	 * @return string
	 */
	private static function gb( $bytes ): string {
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
	private static function decimal( $value, int $precision ): string {
		return rtrim( rtrim( number_format( (float) $value, $precision, '.', '' ), '0' ), '.' );
	}

	/**
	 * MB formatter used by the level/VIP totals.
	 *
	 * @param float $mb Megabytes.
	 * @return string
	 */
	private static function format_mb( float $mb ): string {
		if ( $mb <= 0 ) {
			return '0 ' . __( 'MB', 'sakura-imgopt' );
		}
		if ( $mb >= 1024 ) {
			return (string) round( $mb / 1024, 1 ) . ' ' . __( 'GB', 'sakura-imgopt' );
		}

		return (string) round( $mb, 1 ) . ' ' . __( 'MB', 'sakura-imgopt' );
	}
}
