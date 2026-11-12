<?php
/**
 * Zibll-specific file space settings.
 *
 * All values are read from the active theme at runtime. This view never writes
 * theme options: the only forms post to the plugin's own option groups.
 *
 * Included from \Nboke\UserFiles\Admin\Space_Page::render(), which provides:
 *
 * @var array<string,mixed> $settings         Sanitised sufm_space_settings.
 * @var \Nboke\UserFiles\Services\Zibll_Bridge $bridge Theme gate.
 * @var array<string,mixed> $breakdown_for_me Derived quota breakdown of the current user.
 * @var array<string,mixed> $entry_status     Gate / switch snapshot.
 *
 * $files_enabled and $files_entry are covered by the phpstan.neon view rule.
 *
 * @package Sakura_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

use Nboke\UserFiles\Admin\Space_Page;
use Nboke\UserFiles\Space_Settings;
use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use Sakura\ImageOptimizer\Admin\UI;

$sufm_space_option = Space_Settings::OPTION;
$sufm_space_diag   = $bridge->diagnostics();
$sufm_space_vips   = Space_Rules::vip_labels();

$sufm_space_caps = array(
	'theme'       => __( '主题识别（zibll）', 'sakura-imgopt' ),
	'level'       => __( '等级读取 zib_get_user_level', 'sakura-imgopt' ),
	'vip'         => __( 'VIP 读取 zib_get_user_vip_level', 'sakura-imgopt' ),
	'points'      => __( '积分函数 zibpay_update_user_points', 'sakura-imgopt' ),
	'pay_methods' => __( '支付方式 zibpay_get_payment_methods', 'sakura-imgopt' ),
	'pay_initiate'=> __( '发起支付 zibpay_initiate_pay', 'sakura-imgopt' ),
	'orders'      => __( '订单类 ZibPay', 'sakura-imgopt' ),
	'user_center' => __( '用户中心 zib_get_user_center_url', 'sakura-imgopt' ),
);

$sufm_space_caps_state = isset( $sufm_space_diag['capabilities'] ) && is_array( $sufm_space_diag['capabilities'] )
	? $sufm_space_diag['capabilities']
	: array();

$sufm_space_ready = 0;
foreach ( array_keys( $sufm_space_caps ) as $sufm_space_cap_key ) {
	if ( ! empty( $sufm_space_caps_state[ $sufm_space_cap_key ] ) ) {
		$sufm_space_ready++;
	}
}
$sufm_space_cap_total = count( $sufm_space_caps );

$sufm_space_theme = isset( $sufm_space_diag['theme'] ) && is_array( $sufm_space_diag['theme'] )
	? $sufm_space_diag['theme']
	: array();
$sufm_space_theme = wp_parse_args(
	$sufm_space_theme,
	array(
		'name'       => '',
		'version'    => '',
		'stylesheet' => '',
		'template'   => '',
		'is_child'   => false,
	)
);

$sufm_space_theme_methods = isset( $sufm_space_diag['payment_methods'] ) && is_array( $sufm_space_diag['payment_methods'] )
	? $sufm_space_diag['payment_methods']
	: array();

$sufm_space_allowed       = Space_Settings::allowed_methods();
$sufm_space_points_on     = Space_Settings::points_enabled();
$sufm_space_methods_ready = array_intersect_key( $sufm_space_theme_methods, array_flip( $sufm_space_allowed ) );

$sufm_space_size = static function ( $bytes ): string {
	return (string) size_format( max( 0, (int) $bytes ) );
};

$sufm_space_format_mb = static function ( $mb ): string {
	$mb = (float) $mb;
	if ( $mb <= 0 ) {
		return '0 ' . __( 'MB', 'sakura-imgopt' );
	}
	if ( $mb >= 1024 ) {
		return (string) round( $mb / 1024, 1 ) . ' ' . __( 'GB', 'sakura-imgopt' );
	}
	return (string) round( $mb, 1 ) . ' ' . __( 'MB', 'sakura-imgopt' );
};

$sufm_space_mb_from_bytes = static function ( $bytes ): string {
	$mb = ( (float) $bytes ) / 1048576;
	if ( $mb <= 0 ) {
		return '0';
	}
	return rtrim( rtrim( number_format( $mb, 2, '.', '' ), '0' ), '.' );
};

$sufm_space_check = static function ( string $name, string $text, bool $checked, string $id = '' ): void {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
	echo '<label class="check">';
	echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1"';
	if ( '' !== $id ) {
		echo ' id="' . esc_attr( $id ) . '"';
	}
	echo ' ' . checked( $checked, true, false ) . ' />';
	echo '<span class="check__box" aria-hidden="true"></span>';
	echo '<span class="check__text">' . esc_html( $text ) . '</span>';
	echo '</label>';
};

/**
 * Save-time boolean option rendered with the shared 18px checkbox.
 *
 * The historical helper name is kept so the five call sites and their closure
 * use-lists stay stable; this is deliberately not a slider. Interactive
 * confirmations still bind through data-zibll-switch.
 */
$sufm_space_switch = static function ( string $name, string $text, bool $checked, string $kind = '', string $warn_on = '', string $warn_off = '' ): void {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
	echo '<label class="check">';
	echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1"';
	if ( '' !== $kind ) {
		echo ' data-zibll-switch="' . esc_attr( $kind ) . '"';
	}
	if ( '' !== $warn_on ) {
		echo ' data-zibll-warn-on="' . esc_attr( $warn_on ) . '"';
	}
	if ( '' !== $warn_off ) {
		echo ' data-zibll-warn-off="' . esc_attr( $warn_off ) . '"';
	}
	echo ' ' . checked( $checked, true, false ) . ' />';
	echo '<span class="check__box" aria-hidden="true"></span>';
	echo '<span class="check__text">' . esc_html( $text ) . '</span>';
	echo '</label>';
};

$sufm_space_bd = static function ( string $key, $default = 0 ) use ( $breakdown_for_me ) {
	return array_key_exists( $key, $breakdown_for_me ) ? $breakdown_for_me[ $key ] : $default;
};

$sufm_space_override = $sufm_space_bd( 'override', null );

$zibll_options = get_option( 'zibll_options' );
$zibll_options = is_array( $zibll_options ) ? $zibll_options : array();

$sufm_space_level_max = isset( $zibll_options['user_level_max'] ) ? (int) $zibll_options['user_level_max'] : 10;
$sufm_space_level_max = max( 1, min( 20, $sufm_space_level_max ) );
$sufm_space_level_opt = isset( $zibll_options['user_level_opt'] ) && is_array( $zibll_options['user_level_opt'] )
	? $zibll_options['user_level_opt']
	: array();

$sufm_space_level_map = isset( $settings['level_bonus'] ) && is_array( $settings['level_bonus'] ) ? $settings['level_bonus'] : array();
$sufm_space_vip_map   = isset( $settings['vip_bonus'] ) && is_array( $settings['vip_bonus'] ) ? $settings['vip_bonus'] : array();
$sufm_space_rule      = Space_Settings::level_rule();
$sufm_space_rule_on   = ! empty( $sufm_space_rule['enabled'] );

$sufm_space_levels = array();
$sufm_space_level_total_mb = 0.0;
for ( $sufm_space_level = 1; $sufm_space_level <= $sufm_space_level_max; $sufm_space_level++ ) {
	$sufm_space_threshold = null;
	$sufm_space_key       = 'upgrade_integral_' . $sufm_space_level;
	if ( isset( $sufm_space_level_opt[ $sufm_space_key ] ) && is_scalar( $sufm_space_level_opt[ $sufm_space_key ] ) ) {
		$sufm_space_threshold = (int) $sufm_space_level_opt[ $sufm_space_key ];
	}

	if ( $sufm_space_rule_on ) {
		$sufm_space_level_bytes = intdiv( max( 0, (int) $sufm_space_threshold ), max( 1, (int) $sufm_space_rule['points_per_step'] ) ) * (int) $sufm_space_rule['bytes_per_step'];
	} else {
		$sufm_space_level_bytes = isset( $sufm_space_level_map[ $sufm_space_level ] ) ? (int) $sufm_space_level_map[ $sufm_space_level ] : 0;
	}

	$sufm_space_levels[] = array(
		'level'       => $sufm_space_level,
		'threshold'   => $sufm_space_threshold,
		'bytes'       => max( 0, $sufm_space_level_bytes ),
		'mb'          => $sufm_space_mb_from_bytes( $sufm_space_level_bytes ),
	);
	$sufm_space_level_total_mb += max( 0, $sufm_space_level_bytes ) / 1048576;
}

$sufm_space_vip_rows = array();
$sufm_space_vip_total_mb = 0.0;
for ( $sufm_space_vip = 0; $sufm_space_vip <= 2; $sufm_space_vip++ ) {
	$sufm_space_vip_bytes = isset( $sufm_space_vip_map[ $sufm_space_vip ] ) ? (int) $sufm_space_vip_map[ $sufm_space_vip ] : 0;
	$sufm_space_vip_rows[] = array(
		'vip'   => $sufm_space_vip,
		'label' => isset( $sufm_space_vips[ $sufm_space_vip ] ) ? (string) $sufm_space_vips[ $sufm_space_vip ] : sprintf( 'VIP %d', $sufm_space_vip ),
		'mb'    => $sufm_space_mb_from_bytes( $sufm_space_vip_bytes ),
	);
	$sufm_space_vip_total_mb += max( 0, $sufm_space_vip_bytes ) / 1048576;
}

$sufm_space_packages = Space_Settings::packages();
$sufm_space_points   = Space_Settings::points();
$sufm_space_ppkgs    = Space_Settings::points_packages();
$sufm_space_icon     = Space_Settings::order_icon();
$sufm_space_default_icon = Space_Store::product_image_url();

$sufm_space_pay_rate = isset( $zibll_options['pay_points_rate'] ) && is_numeric( $zibll_options['pay_points_rate'] )
	? (float) $zibll_options['pay_points_rate']
	: 0.0;

$sufm_space_cheapest     = null;
$sufm_space_cheapest_gb  = 0.0;
foreach ( $sufm_space_packages as $sufm_space_pkg ) {
	$sufm_space_pkg_bytes = isset( $sufm_space_pkg['bytes'] ) ? (float) $sufm_space_pkg['bytes'] : 0.0;
	$sufm_space_pkg_price = isset( $sufm_space_pkg['price'] ) ? (float) $sufm_space_pkg['price'] : 0.0;
	if ( $sufm_space_pkg_bytes <= 0 || $sufm_space_pkg_price <= 0 ) {
		continue;
	}
	$sufm_space_per_gb = $sufm_space_pkg_price / ( $sufm_space_pkg_bytes / 1073741824 );
	if ( null === $sufm_space_cheapest || $sufm_space_per_gb < $sufm_space_cheapest ) {
		$sufm_space_cheapest    = $sufm_space_per_gb;
		$sufm_space_cheapest_gb = $sufm_space_pkg_bytes / 1073741824;
	}
}

$sufm_space_points_per_gb = isset( $sufm_space_points['points_per_gb'] ) ? max( 1, (int) $sufm_space_points['points_per_gb'] ) : 90;
$sufm_space_cash_gb       = $sufm_space_pay_rate > 0 ? ( $sufm_space_points_per_gb / $sufm_space_pay_rate ) : 0.0;
$sufm_space_far           = null !== $sufm_space_cheapest && $sufm_space_cheapest > 0 && $sufm_space_cash_gb > ( $sufm_space_cheapest * 2 );

$sufm_space_today = Space_Store::instance()->points_today_totals();
$sufm_space_today_gb    = isset( $sufm_space_today['gb'] ) ? (float) $sufm_space_today['gb'] : 0.0;
$sufm_space_today_count = isset( $sufm_space_today['count'] ) ? (int) $sufm_space_today['count'] : 0;

$sufm_space_entry_theme = isset( $files_entry['theme'] ) && '' !== $files_entry['theme'] ? $files_entry['theme'] : $sufm_space_theme['name'];
$sufm_space_entry_label = isset( $files_entry['label'] ) ? (string) $files_entry['label'] : '';
$sufm_space_entry_url   = isset( $files_entry['url'] ) ? (string) $files_entry['url'] : '';
$sufm_space_is_generic  = ! empty( $files_entry['generic'] );

$sufm_space_cash_row = static function ( array $pkg, string $key, bool $is_new ) use ( $sufm_space_option ): void {
	$bytes = isset( $pkg['bytes'] ) ? (float) $pkg['bytes'] : 0.0;
	$gb    = $bytes > 0 ? rtrim( rtrim( number_format( $bytes / 1073741824, 3, '.', '' ), '0' ), '.' ) : '';
	$price = isset( $pkg['price'] ) ? (float) $pkg['price'] : 0.0;
	$price = $price > 0 ? rtrim( rtrim( number_format( $price, 2, '.', '' ), '0' ), '.' ) : '';
	$days  = isset( $pkg['days'] ) ? (int) $pkg['days'] : 365;
	$label = isset( $pkg['label'] ) ? (string) $pkg['label'] : '';
	$id    = isset( $pkg['id'] ) ? strtoupper( (string) $pkg['id'] ) : '';
	$base  = $sufm_space_option . '[packages][' . $key . ']';
	?>
	<tr class="zibll-plan-row<?php echo $is_new ? ' is-new' : ''; ?>" data-zibll-plan-row<?php echo $is_new ? ' data-zibll-plan-new="1"' : ''; ?>>
		<td data-label="<?php esc_attr_e( '套餐 ID', 'sakura-imgopt' ); ?>">
			<input class="zibll-plan-id" type="text" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" readonly data-zibll-plan-id />
		</td>
		<td data-label="<?php esc_attr_e( '名称', 'sakura-imgopt' ); ?>">
			<input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( '如 10GB 扩容包', 'sakura-imgopt' ); ?>" />
		</td>
		<td data-label="<?php esc_attr_e( '容量（GB）', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[bytes_gb]' ); ?>" value="<?php echo esc_attr( $gb ); ?>" min="0.1" step="0.1" inputmode="decimal" />
		</td>
		<td data-label="<?php esc_attr_e( '价格（元）', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>" min="0" step="0.01" inputmode="decimal" />
		</td>
		<td data-label="<?php esc_attr_e( '时长（天）', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[days]' ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" min="0" step="1" inputmode="numeric" />
		</td>
		<td data-label="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>">
			<label class="check">
				<input type="checkbox" name="<?php echo esc_attr( $base . '[active]' ); ?>" value="1" <?php checked( ! empty( $pkg['active'] ), true ); ?> aria-label="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>" />
				<span class="check__box" aria-hidden="true"></span>
			</label>
		</td>
		<td data-label="<?php esc_attr_e( '删除', 'sakura-imgopt' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[remove]' ); ?>" value="0" data-zibll-plan-remove-value />
			<label class="check">
				<input type="checkbox" data-zibll-plan-remove aria-label="<?php esc_attr_e( '删除这个套餐', 'sakura-imgopt' ); ?>" />
				<span class="check__box" aria-hidden="true"></span>
			</label>
		</td>
	</tr>
	<?php
};

$sufm_space_points_row = static function ( array $pkg, string $key, bool $is_new ) use ( $sufm_space_option ): void {
	$gb     = isset( $pkg['gb'] ) ? (float) $pkg['gb'] : 0.0;
	$gb_val = $gb > 0 ? rtrim( rtrim( number_format( $gb, 2, '.', '' ), '0' ), '.' ) : '';
	$points = isset( $pkg['points'] ) ? (int) $pkg['points'] : 0;
	$days   = isset( $pkg['days'] ) ? (int) $pkg['days'] : 365;
	$label  = isset( $pkg['label'] ) && '' !== (string) $pkg['label'] ? (string) $pkg['label'] : ( $gb > 0 ? Space_Settings::points_label( $gb, $days ) : '' );
	$id     = isset( $pkg['id'] ) ? strtoupper( (string) $pkg['id'] ) : '';
	$base   = $sufm_space_option . '[points_packages][' . $key . ']';
	?>
	<tr class="zibll-plan-row<?php echo $is_new ? ' is-new' : ''; ?>" data-zibll-plan-row<?php echo $is_new ? ' data-zibll-plan-new="1"' : ''; ?>>
		<td data-label="<?php esc_attr_e( '套餐 ID', 'sakura-imgopt' ); ?>">
			<input class="zibll-plan-id" type="text" name="<?php echo esc_attr( $base . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" readonly data-zibll-plan-id />
		</td>
		<td data-label="<?php esc_attr_e( '名称', 'sakura-imgopt' ); ?>">
			<input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( '留空自动生成', 'sakura-imgopt' ); ?>" data-zibll-plan-field="label" />
		</td>
		<td data-label="<?php esc_attr_e( '容量（GB）', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[gb]' ); ?>" value="<?php echo esc_attr( $gb_val ); ?>" min="0.5" step="0.5" inputmode="decimal" data-zibll-plan-field="gb" />
		</td>
		<td data-label="<?php esc_attr_e( '所需积分', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[points]' ); ?>" value="<?php echo esc_attr( (string) $points ); ?>" min="1" step="1" inputmode="numeric" />
		</td>
		<td data-label="<?php esc_attr_e( '时长（天）', 'sakura-imgopt' ); ?>">
			<input type="number" name="<?php echo esc_attr( $base . '[days]' ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" min="0" step="1" inputmode="numeric" data-zibll-plan-field="days" />
		</td>
		<td data-label="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>">
			<label class="check">
				<input type="checkbox" name="<?php echo esc_attr( $base . '[active]' ); ?>" value="1" <?php checked( ! empty( $pkg['active'] ), true ); ?> aria-label="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>" />
				<span class="check__box" aria-hidden="true"></span>
			</label>
		</td>
		<td data-label="<?php esc_attr_e( '删除', 'sakura-imgopt' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[remove]' ); ?>" value="0" data-zibll-plan-remove-value />
			<label class="check">
				<input type="checkbox" data-zibll-plan-remove aria-label="<?php esc_attr_e( '删除这个套餐', 'sakura-imgopt' ); ?>" />
				<span class="check__box" aria-hidden="true"></span>
			</label>
		</td>
	</tr>
	<?php
};

UI::page(
	array(
		'id'      => 'files-zibll',
		'module'  => 'files',
		'tab'     => 'zibll',
		'eyebrow' => 'ZIBLL BRIDGE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
		'title'   => __( '子比专属', 'sakura-imgopt' ),
		'lede'    => __( '主题能力、支付通道、前台入口与等级加成集中在这里；所有数值都在运行时从 zibll 读取，不写死主题侧的名称或价格。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '子比桥接', 'sakura-imgopt' ),
			'value' => $sufm_space_ready . ' / ' . $sufm_space_cap_total,
			'unit'  => __( '项能力', 'sakura-imgopt' ),
			'sub'   => '' !== $sufm_space_theme['name'] ? $sufm_space_theme['name'] : __( '未识别主题', 'sakura-imgopt' ),
			'tone'  => $sufm_space_ready === $sufm_space_cap_total ? 'ok' : ( $sufm_space_ready >= 5 ? 'warn' : 'bad' ),
		),
	),
	static function () use ( $sufm_space_option, $sufm_space_caps, $sufm_space_caps_state, $sufm_space_theme, $sufm_space_theme_methods, $sufm_space_allowed, $sufm_space_points_on, $sufm_space_methods_ready, $sufm_space_size, $sufm_space_format_mb, $sufm_space_check, $sufm_space_switch, $sufm_space_bd, $sufm_space_override, $sufm_space_levels, $sufm_space_level_max, $sufm_space_level_total_mb, $sufm_space_vip_rows, $sufm_space_vip_total_mb, $sufm_space_rule, $sufm_space_rule_on, $sufm_space_packages, $sufm_space_points, $sufm_space_ppkgs, $sufm_space_icon, $sufm_space_default_icon, $sufm_space_pay_rate, $sufm_space_cheapest, $sufm_space_points_per_gb, $sufm_space_far, $sufm_space_today_gb, $sufm_space_today_count, $sufm_space_entry_theme, $sufm_space_entry_label, $sufm_space_entry_url, $sufm_space_is_generic, $sufm_space_cash_row, $sufm_space_points_row, $entry_status, $files_enabled ) {
		settings_errors();
		?>
		<div class="card-grid" data-sakura-layout="files-space" data-sakura-group="main">
			<?php if ( empty( $entry_status['enabled'] ) ) : ?>
				<div class="zibll-span-all">
					<?php UI::alert( __( '扩容尚未启用。前台不会出现任何入口；已存在的等级 / VIP 加成也不会影响容量。', 'sakura-imgopt' ), 'warn', __( '扩容未启用', 'sakura-imgopt' ) ); ?>
				</div>
			<?php endif; ?>

			<?php
			UI::card(
				array(
					'id'          => 'space_diag',
					'kicker'      => 'DIAGNOSE',
					'title'       => __( '主题诊断', 'sakura-imgopt' ),
					'description' => __( '本页所有数值都在运行时读取主题，不写死任何主题侧的名称或价格。', 'sakura-imgopt' ),
					'wide'        => true,
				),
				static function () use ( $sufm_space_caps, $sufm_space_caps_state, $sufm_space_theme, $sufm_space_points_on, $sufm_space_methods_ready, $sufm_space_size, $sufm_space_bd, $sufm_space_override ) {
					?>
					<div class="zibll-diag-grid">
						<section class="zibll-block" aria-labelledby="zibll-theme-title">
							<h3 class="zibll-block__title" id="zibll-theme-title"><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></h3>
							<dl class="zibll-meta">
								<div><dt><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( '' !== $sufm_space_theme['name'] ? $sufm_space_theme['name'] : __( '未取到', 'sakura-imgopt' ) ); ?></dd></div>
								<div><dt><?php esc_html_e( '版本', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( '' !== $sufm_space_theme['version'] ? $sufm_space_theme['version'] : __( '未取到', 'sakura-imgopt' ) ); ?></dd></div>
								<div><dt><?php esc_html_e( '样式目录', 'sakura-imgopt' ); ?></dt><dd><code class="zibll-code"><?php echo esc_html( '' !== $sufm_space_theme['stylesheet'] ? $sufm_space_theme['stylesheet'] : '—' ); ?></code></dd></div>
								<div><dt><?php esc_html_e( '模板目录', 'sakura-imgopt' ); ?></dt><dd><code class="zibll-code"><?php echo esc_html( '' !== $sufm_space_theme['template'] ? $sufm_space_theme['template'] : '—' ); ?></code></dd></div>
								<div><dt><?php esc_html_e( '子主题', 'sakura-imgopt' ); ?></dt><dd><?php echo $sufm_space_theme['is_child'] ? esc_html__( '是', 'sakura-imgopt' ) : esc_html__( '否', 'sakura-imgopt' ); ?></dd></div>
							</dl>
						</section>

						<section class="zibll-block" aria-labelledby="zibll-caps-title">
							<h3 class="zibll-block__title" id="zibll-caps-title"><?php esc_html_e( '能力矩阵', 'sakura-imgopt' ); ?></h3>
							<ul class="zibll-cap-list">
								<?php foreach ( $sufm_space_caps as $sufm_space_cap_key => $sufm_space_cap_label ) : ?>
									<?php $sufm_space_cap_ok = ! empty( $sufm_space_caps_state[ $sufm_space_cap_key ] ); ?>
									<li class="zibll-cap">
										<?php UI::badge( $sufm_space_cap_ok ? __( '可用', 'sakura-imgopt' ) : __( '缺失', 'sakura-imgopt' ), $sufm_space_cap_ok ? 'ok' : 'warn' ); ?>
										<span class="zibll-cap__label"><?php echo esc_html( $sufm_space_cap_label ); ?></span>
										<?php if ( ! $sufm_space_cap_ok ) : ?>
											<span class="zibll-cap__note"><?php esc_html_e( '该通道已自动关闭', 'sakura-imgopt' ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					</div>

					<section class="zibll-block" aria-labelledby="zibll-methods-title">
						<h3 class="zibll-block__title" id="zibll-methods-title"><?php esc_html_e( '已启用购买方式', 'sakura-imgopt' ); ?></h3>
						<?php if ( empty( $sufm_space_methods_ready ) && ! $sufm_space_points_on ) : ?>
							<p class="zibll-muted"><?php esc_html_e( '当前没有启用任何购买方式。可在下方「支付方式」卡片勾选并保存。', 'sakura-imgopt' ); ?></p>
						<?php else : ?>
							<ul class="zibll-method-list">
								<?php foreach ( $sufm_space_methods_ready as $sufm_space_method_id => $sufm_space_method ) : ?>
									<li>
										<code class="zibll-code"><?php echo esc_html( (string) $sufm_space_method_id ); ?></code>
										<span><?php echo esc_html( isset( $sufm_space_method['name'] ) ? (string) $sufm_space_method['name'] : (string) $sufm_space_method_id ); ?></span>
									</li>
								<?php endforeach; ?>
								<?php if ( $sufm_space_points_on ) : ?>
									<li><code class="zibll-code">points</code><span><?php esc_html_e( '积分兑换', 'sakura-imgopt' ); ?></span></li>
								<?php endif; ?>
							</ul>
							<p class="zibll-muted"><?php esc_html_e( '现金通道只显示已勾选且主题当前提供的项目；积分兑换由本插件直接扣减，不经过主题支付。', 'sakura-imgopt' ); ?></p>
						<?php endif; ?>
					</section>

					<section class="zibll-block" aria-labelledby="zibll-breakdown-title">
						<h3 class="zibll-block__title" id="zibll-breakdown-title"><?php esc_html_e( '我的派生容量', 'sakura-imgopt' ); ?></h3>
						<dl class="zibll-meta zibll-meta--wide">
							<div><dt><?php esc_html_e( '基础', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $sufm_space_size( $sufm_space_bd( 'base' ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( '等级加成', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: theme level number, 2: capacity granted by that level. */ __( 'LV%1$s · %2$s', 'sakura-imgopt' ), $sufm_space_bd( 'level' ), $sufm_space_size( $sufm_space_bd( 'level_bytes' ) ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'VIP 加成', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: zibll vip level number, 2: capacity granted by that VIP level. */ __( 'vip_level=%1$s · %2$s', 'sakura-imgopt' ), $sufm_space_bd( 'vip' ), $sufm_space_size( $sufm_space_bd( 'vip_bytes' ) ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( '已购', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $sufm_space_size( $sufm_space_bd( 'granted_bytes' ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( '计算值', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $sufm_space_size( $sufm_space_bd( 'computed' ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( '单独设定', 'sakura-imgopt' ); ?></dt><dd><?php echo null === $sufm_space_override || '' === $sufm_space_override ? esc_html__( '未设定', 'sakura-imgopt' ) : esc_html( $sufm_space_size( $sufm_space_override ) ); ?></dd></div>
							<div><dt><?php esc_html_e( '最终生效', 'sakura-imgopt' ); ?></dt><dd><strong><?php echo esc_html( $sufm_space_size( $sufm_space_bd( 'final' ) ) ); ?></strong></dd></div>
						</dl>
						<p class="zibll-muted"><?php echo esc_html( sprintf( /* translators: %s: how the final quota is derived from the override and the computed value. */ __( '封顶语义：%s', 'sakura-imgopt' ), 'replace' === (string) $sufm_space_bd( 'mode', 'cap' ) ? __( '替换（replace）· 单独设定直接覆盖计算值', 'sakura-imgopt' ) : __( '封顶（cap）· 实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ) ) ); ?></p>
					</section>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_payment',
					'kicker'      => 'PAYMENT',
					'title'       => __( '支付方式', 'sakura-imgopt' ),
					'description' => __( '只有勾选并保存的通道才会出现在前台收银台。默认只启用余额；支付宝、微信等外部渠道需自行确认对账与退款流程。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => Space_Page::GROUP,
						'markers'      => 'option_fields',
						'option'       => $sufm_space_option,
						'marker_card'  => 'space_payment',
						'hidden'       => array(
							$sufm_space_option . '[_partial]'              => '1',
							$sufm_space_option . '[allowed_methods_present]' => '1',
						),
					),
					'save'        => __( '保存支付方式', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_option, $sufm_space_theme_methods, $sufm_space_allowed, $sufm_space_points_on, $sufm_space_switch ) {
					?>
					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '主题支付通道', 'sakura-imgopt' ); ?></span>
							<p class="field__hint"><?php esc_html_e( '余额由主题同步确认；二维码或跳转渠道必须确认能处理退款与超时订单后再勾选。', 'sakura-imgopt' ); ?></p>
						</div>
						<div class="field__control">
							<?php if ( empty( $sufm_space_theme_methods ) ) : ?>
								<p class="zibll-muted"><?php esc_html_e( '未读取到主题支付通道。请确认已登录后台，且主题已开启对应的支付方式。', 'sakura-imgopt' ); ?></p>
							<?php else : ?>
								<div class="zibll-method-list">
									<?php foreach ( $sufm_space_theme_methods as $sufm_space_method_id => $sufm_space_method ) : ?>
										<?php
										$sufm_space_method_id   = (string) $sufm_space_method_id;
										$sufm_space_method_name = isset( $sufm_space_method['name'] ) ? (string) $sufm_space_method['name'] : $sufm_space_method_id;
										$sufm_space_method_note = 'balance' === $sufm_space_method_id
											? __( '同步到账，推荐启用。', 'sakura-imgopt' )
											: __( '外部支付渠道，可能出现未支付订单，需要人工对账。', 'sakura-imgopt' );
										?>
										<label class="check zibll-method">
											<input type="checkbox" name="<?php echo esc_attr( $sufm_space_option . '[allowed_methods][]' ); ?>" value="<?php echo esc_attr( $sufm_space_method_id ); ?>" <?php checked( in_array( $sufm_space_method_id, $sufm_space_allowed, true ), true ); ?> />
											<span class="check__box" aria-hidden="true"></span>
											<span class="check__text">
												<code class="zibll-code"><?php echo esc_html( $sufm_space_method_id ); ?></code>
												<strong><?php echo esc_html( $sufm_space_method_name ); ?></strong>
												<em><?php echo esc_html( $sufm_space_method_note ); ?></em>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '积分兑换', 'sakura-imgopt' ); ?></span>
							<p class="field__hint"><?php esc_html_e( '积分由本插件直接扣减，不经过主题支付；与现金通道互不影响。', 'sakura-imgopt' ); ?></p>
						</div>
						<div class="field__control">
							<?php $sufm_space_switch( $sufm_space_option . '[points][enabled]', __( '启用积分兑换', 'sakura-imgopt' ), $sufm_space_points_on ); ?>
							<p class="zibll-muted"><?php esc_html_e( '关闭后前台隐藏积分入口，接口拒绝兑换；已售出的积分授权不受影响。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_entry',
					'kicker'      => 'ENTRY',
					'title'       => __( '前台入口', 'sakura-imgopt' ),
					'description' => __( '只控制系统用户中心里是否出现「文件管理」入口，以及前台上传 / 删除接口。关闭不会删除任何文件。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => 'sufm_settings_group',
						'markers'      => 'option_fields',
						'option'       => 'sufm_settings',
						'marker_card'  => 'space_entry',
						'hidden'       => array( 'sufm_settings[_partial]' => '1' ),
					),
					'save'        => __( '保存入口设置', 'sakura-imgopt' ),
				),
				static function () use ( $files_enabled, $sufm_space_entry_theme, $sufm_space_entry_label, $sufm_space_entry_url, $sufm_space_is_generic, $sufm_space_switch ) {
					?>
					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '启用前台入口', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="field__control">
							<?php $sufm_space_switch( 'sufm_settings[enabled]', __( '启用前台入口', 'sakura-imgopt' ), (bool) $files_enabled ); ?>
							<p class="zibll-muted"><?php echo $files_enabled ? esc_html__( '当前：已启用', 'sakura-imgopt' ) : esc_html__( '当前：未启用（前台零入口）', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<dl class="zibll-meta zibll-meta--wide">
						<div><dt><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( '' !== $sufm_space_entry_theme ? $sufm_space_entry_theme : __( '未取到', 'sakura-imgopt' ) ); ?></dd></div>
						<div><dt><?php esc_html_e( '检测到的入口', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( '' !== $sufm_space_entry_label ? $sufm_space_entry_label : __( '未取到', 'sakura-imgopt' ) ); ?></dd></div>
						<div><dt><?php esc_html_e( '前台位置', 'sakura-imgopt' ); ?></dt><dd><?php echo $sufm_space_is_generic ? esc_html__( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' ) : esc_html__( '用户中心 → 文件管理', 'sakura-imgopt' ); ?></dd></div>
						<div><dt><?php esc_html_e( '前台地址', 'sakura-imgopt' ); ?></dt><dd>
							<?php if ( '' !== $sufm_space_entry_url ) : ?>
								<a href="<?php echo esc_url( $sufm_space_entry_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $sufm_space_entry_url ); ?></a>
							<?php else : ?>
								<?php esc_html_e( '尚未发布', 'sakura-imgopt' ); ?>
							<?php endif; ?>
						</dd></div>
					</dl>
					<p class="zibll-muted"><?php esc_html_e( '关闭只停用入口与前台上传接口，不删除文件，也不重置容量。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_editor',
					'kicker'      => 'EDITOR',
					'title'       => __( '编辑器文件面板', 'sakura-imgopt' ),
					'description' => __( '在写文章 / 发帖页的编辑器工具栏加一个入口，点开后在正文左侧固定打开文件空间，写作时直接复用旧附件。面板只读：不能上传、不能删除、不占配额。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => 'sufm_settings_group',
						'markers'      => 'option_fields',
						'option'       => 'sufm_settings',
						'marker_card'  => 'space_editor',
						'hidden'       => array( 'sufm_settings[_partial]' => '1' ),
					),
					'save'        => __( '保存编辑器面板设置', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_switch ) {
					$sufm_space_editor_on = \Nboke\UserFiles\Settings::editor_enabled();
					?>
					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '启用编辑器文件面板', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="field__control">
							<?php $sufm_space_switch( 'sufm_settings[editor_enabled]', __( '启用编辑器文件面板', 'sakura-imgopt' ), (bool) $sufm_space_editor_on ); ?>
						</div>
					</div>
					<dl class="zibll-meta zibll-meta--wide">
						<div><dt><?php esc_html_e( '生效页面', 'sakura-imgopt' ); ?></dt><dd><?php esc_html_e( '写文章 /newposts、发帖与编辑 /posts-edit', 'sakura-imgopt' ); ?></dd></div>
						<div><dt><?php esc_html_e( '开启条件', 'sakura-imgopt' ); ?></dt><dd><?php esc_html_e( '桌面端，且浏览器窗口宽度 ≥ 1840px；不足时按钮不出现', 'sakura-imgopt' ); ?></dd></div>
						<div><dt><?php esc_html_e( '权限', 'sakura-imgopt' ); ?></dt><dd><?php esc_html_e( '已登录且拥有「管理自己的文件空间」权限；只看得到自己的文件', 'sakura-imgopt' ); ?></dd></div>
					</dl>
					<p class="zibll-muted"><?php esc_html_e( '与前台入口互不影响。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_gate',
					'kicker'      => 'GATE',
					'title'       => __( '门禁与开关', 'sakura-imgopt' ),
					'description' => __( '总开关默认关闭；「计算加成」是紧急止血阀：关闭后已购容量立即停止生效（等级 / VIP 加成仍按主题计算）。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => Space_Page::GROUP,
						'markers'      => 'option_fields',
						'option'       => $sufm_space_option,
						'marker_card'  => 'space_gate',
						'hidden'       => array( $sufm_space_option . '[_partial]' => '1' ),
					),
					'save'        => __( '保存开关', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_option, $entry_status, $sufm_space_switch ) {
					?>
					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '启用扩容售卖', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="field__control">
							<?php $sufm_space_switch( $sufm_space_option . '[enabled]', __( '启用扩容售卖', 'sakura-imgopt' ), ! empty( $entry_status['enabled'] ), 'enabled', 'enableWarn', 'disableWarn' ); ?>
							<p class="zibll-muted"><?php echo ! empty( $entry_status['enabled'] ) ? esc_html__( '当前：已启用', 'sakura-imgopt' ) : esc_html__( '当前：未启用（前台零入口）', 'sakura-imgopt' ); ?></p>
						</div>
					</div>

					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '计算加成（已购授权）', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="field__control">
							<?php $sufm_space_switch( $sufm_space_option . '[apply_bonuses]', __( '计算已购授权', 'sakura-imgopt' ), ! empty( $entry_status['apply_bonuses'] ), 'apply_bonuses', '', 'flashWarn' ); ?>
							<p class="zibll-muted"><?php esc_html_e( '关闭后，所有已购容量立即停止生效（不删任何文件）。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>

					<div class="field field--full">
						<div class="field__main">
							<span class="field__label"><?php esc_html_e( '单独设定的封顶语义', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="field__control">
							<div class="check-group">
								<label class="check">
									<input type="radio" name="<?php echo esc_attr( $sufm_space_option . '[quota_override_mode]' ); ?>" value="cap" <?php checked( 'replace' !== (string) $entry_status['mode'], true ); ?> />
									<span class="check__box" aria-hidden="true"></span>
									<span class="check__text"><?php esc_html_e( '封顶（cap）：实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ); ?></span>
								</label>
								<label class="check">
									<input type="radio" name="<?php echo esc_attr( $sufm_space_option . '[quota_override_mode]' ); ?>" value="replace" <?php checked( 'replace' === (string) $entry_status['mode'], true ); ?> />
									<span class="check__box" aria-hidden="true"></span>
									<span class="check__text"><?php esc_html_e( '替换（replace）：单独设定直接覆盖计算值', 'sakura-imgopt' ); ?></span>
								</label>
							</div>
						</div>
					</div>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_level',
					'kicker'      => 'BONUS',
					'title'       => __( '等级加成', 'sakura-imgopt' ),
					'description' => sprintf(
						/* translators: %d: number of theme levels. */
						__( '按主题等级自动折算：当前主题共 %d 级，等级名与门槛积分都读自主题。每满一定积分增加一定容量，等级越高、门槛越高，加成越大。', 'sakura-imgopt' ),
						(int) $sufm_space_level_max
					),
					'wide'        => true,
					'form'        => array(
						'option_group' => Space_Page::GROUP,
						'markers'      => 'option_fields',
						'option'       => $sufm_space_option,
						'marker_card'  => 'space_level',
						'hidden'       => array( $sufm_space_option . '[_partial]' => '1' ),
					),
					'save'        => __( '保存等级加成', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_option, $sufm_space_rule, $sufm_space_rule_on, $sufm_space_levels, $sufm_space_level_total_mb, $sufm_space_format_mb, $sufm_space_check ) {
					?>
					<div class="field-list">
						<div class="field field--full">
							<div class="field__control">
								<?php $sufm_space_check( $sufm_space_option . '[level_rule][enabled]', __( '启用规则（关闭后回退到下方手填表）', 'sakura-imgopt' ), (bool) $sufm_space_rule['enabled'] ); ?>
							</div>
						</div>
						<?php
						UI::field(
							array(
								'type'  => 'number',
								'id'    => 'space-level-points-step',
								'name'  => $sufm_space_option . '[level_rule][points_per_step]',
								'label' => __( '每满积分', 'sakura-imgopt' ),
								'value' => (string) $sufm_space_rule['points_per_step'],
								'min'   => 1,
								'step'  => 100,
								'hint'  => __( '达到该积分后开始计算一级加成。', 'sakura-imgopt' ),
							)
						);
						UI::field(
							array(
								'type'  => 'number',
								'id'    => 'space-level-bytes-step',
								'name'  => $sufm_space_option . '[level_rule][bytes_per_step_mb]',
								'label' => __( '增加（MB）', 'sakura-imgopt' ),
								'value' => (string) round( $sufm_space_rule['bytes_per_step'] / 1048576, 2 ),
								'min'   => 0,
								'step'  => 1,
								'hint'  => __( '每满一级增加的容量，单位 MB。', 'sakura-imgopt' ),
							)
						);
						?>
					</div>

					<div class="table-scroll zibll-level-table">
						<table class="table table--compact table--responsive">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( '等级', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php esc_html_e( '门槛积分', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php esc_html_e( '加成（MB）', 'sakura-imgopt' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sufm_space_levels as $sufm_space_row ) : ?>
									<tr>
										<td data-label="<?php esc_attr_e( '等级', 'sakura-imgopt' ); ?>"><?php echo esc_html( 'LV' . $sufm_space_row['level'] ); ?></td>
										<td data-label="<?php esc_attr_e( '门槛积分', 'sakura-imgopt' ); ?>"><?php echo null === $sufm_space_row['threshold'] ? '—' : esc_html( (string) $sufm_space_row['threshold'] ); ?></td>
										<td data-label="<?php esc_attr_e( '加成（MB）', 'sakura-imgopt' ); ?>">
											<?php
											UI::field(
												array(
													'type'     => 'number',
													'name'     => $sufm_space_option . '[level_bonus_mb][' . $sufm_space_row['level'] . ']',
													'value'    => $sufm_space_row['mb'],
													'min'      => 0,
													'step'     => 1,
													'disabled' => $sufm_space_rule_on,
													'class'    => 'zibll-cell-field',
													'attrs'    => array(
														'data-zibll-mb' => 'level',
														/* translators: %d: theme level number. */
														'aria-label'    => sprintf( __( 'LV%d 加成（MB）', 'sakura-imgopt' ), $sufm_space_row['level'] ),
													),
												)
											);
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<p class="zibll-total" data-zibll-total="level"><?php esc_html_e( '加成合计：', 'sakura-imgopt' ); ?><strong><?php echo esc_html( $sufm_space_format_mb( $sufm_space_level_total_mb ) ); ?></strong></p>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_vip',
					'kicker'      => 'BONUS',
					'title'       => __( 'VIP / SVIP 加成', 'sakura-imgopt' ),
					'description' => __( '名称在运行时解析：过滤器 → 主题选项 → 降级标签。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => Space_Page::GROUP,
						'markers'      => 'option_fields',
						'option'       => $sufm_space_option,
						'marker_card'  => 'space_vip',
						'hidden'       => array( $sufm_space_option . '[_partial]' => '1' ),
					),
					'save'        => __( '保存 VIP 加成', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_option, $sufm_space_vip_rows, $sufm_space_vip_total_mb, $sufm_space_format_mb ) {
					?>
					<div class="zibll-vip-grid">
						<?php foreach ( $sufm_space_vip_rows as $sufm_space_vip_row ) : ?>
							<div class="zibll-vip-card">
								<div class="zibll-vip-card__head">
									<code class="zibll-code">vip_level=<?php echo esc_html( (string) $sufm_space_vip_row['vip'] ); ?></code>
									<strong><?php echo esc_html( $sufm_space_vip_row['label'] ); ?></strong>
								</div>
								<?php
								UI::field(
									array(
										'type'  => 'number',
										'id'    => 'space-vip-' . $sufm_space_vip_row['vip'],
										'name'  => $sufm_space_option . '[vip_bonus_mb][' . $sufm_space_vip_row['vip'] . ']',
										'label' => __( '加成（MB）', 'sakura-imgopt' ),
										'value' => $sufm_space_vip_row['mb'],
										'min'   => 0,
										'step'  => 1,
										'attrs' => array( 'data-zibll-mb' => 'vip' ),
									)
								);
								?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="zibll-total" data-zibll-total="vip"><?php esc_html_e( '加成合计：', 'sakura-imgopt' ); ?><strong><?php echo esc_html( $sufm_space_format_mb( $sufm_space_vip_total_mb ) ); ?></strong></p>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_plans',
					'kicker'      => 'PLANS',
					'title'       => __( '空间套餐', 'sakura-imgopt' ),
					'description' => __( '两种支付方式卖同一份空间：现金套餐（A 开头）走主题支付，积分套餐（P 开头）直接扣积分。下架或删除只影响新订单，已售出的授权按购买时的快照继续生效。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => array(
						'option_group' => Space_Page::GROUP,
						'markers'      => 'option_fields',
						'option'       => $sufm_space_option,
						'marker_card'  => 'space_plans',
						'hidden'       => array( $sufm_space_option . '[_partial]' => '1' ),
					),
					'save'        => __( '保存套餐', 'sakura-imgopt' ),
				),
				static function () use ( $sufm_space_option, $sufm_space_packages, $sufm_space_points, $sufm_space_ppkgs, $sufm_space_icon, $sufm_space_default_icon, $sufm_space_pay_rate, $sufm_space_cheapest, $sufm_space_points_per_gb, $sufm_space_far, $sufm_space_today_gb, $sufm_space_today_count, $sufm_space_cash_row, $sufm_space_points_row ) {
					?>
					<div class="zibll-plans" data-zibll-plans>
						<div class="zibll-icon-field">
							<button type="button" class="zibll-icon-pick" data-zibll-icon-pick>
								<img src="<?php echo esc_url( '' !== $sufm_space_icon ? $sufm_space_icon : $sufm_space_default_icon ); ?>" alt="" width="96" height="96" data-zibll-icon-preview data-default="<?php echo esc_url( $sufm_space_default_icon ); ?>" />
								<span><?php esc_html_e( '选择图标', 'sakura-imgopt' ); ?></span>
							</button>
							<div class="zibll-icon-field__input">
								<input type="url" name="<?php echo esc_attr( $sufm_space_option . '[order_icon]' ); ?>" value="<?php echo esc_attr( $sufm_space_icon ); ?>" placeholder="<?php esc_attr_e( '选择或粘贴图标地址', 'sakura-imgopt' ); ?>" data-zibll-icon-input />
								<button type="button" class="btn btn--quiet btn--small" data-zibll-icon-clear <?php echo '' === $sufm_space_icon ? 'hidden' : ''; ?>><?php esc_html_e( '移除', 'sakura-imgopt' ); ?></button>
							</div>
							<p class="field__hint"><?php esc_html_e( '对所有套餐生效（现金套餐与积分套餐共用）。建议 96×96（或 128×128）正方形 PNG/SVG，≤200 KB；留空使用默认的存储图标。图标会出现在后台订单列表、前台「我的订单」和订单详情弹窗。', 'sakura-imgopt' ); ?></p>
						</div>

						<section class="zibll-subsection" aria-labelledby="zibll-cash-title">
							<header class="zibll-subhead">
								<h3 id="zibll-cash-title"><?php esc_html_e( '现金套餐', 'sakura-imgopt' ); ?></h3>
								<p><?php esc_html_e( '用户在弹窗里看到的价格档位，按下单顺序展示；价格取主题支付通道。', 'sakura-imgopt' ); ?></p>
							</header>
							<input type="hidden" name="<?php echo esc_attr( $sufm_space_option . '[packages][_present]' ); ?>" value="1" />
							<div class="table-scroll">
								<table class="table table--compact table--responsive zibll-plan-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( '套餐 ID', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '容量（GB）', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '价格（元）', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '时长（天）', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '上架', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '删除', 'sakura-imgopt' ); ?></th>
										</tr>
									</thead>
									<tbody data-zibll-cash-rows>
										<?php foreach ( $sufm_space_packages as $sufm_space_pkg ) : ?>
											<?php $sufm_space_cash_row( $sufm_space_pkg, (string) $sufm_space_pkg['id'], false ); ?>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<p class="zibll-row-actions">
								<button type="button" class="btn btn--ghost btn--small" data-zibll-plan-add="cash">＋ <?php esc_html_e( '添加现金套餐', 'sakura-imgopt' ); ?></button>
							</p>
						</section>

						<section class="zibll-subsection" aria-labelledby="zibll-points-title">
							<header class="zibll-subhead">
								<h3 id="zibll-points-title"><?php esc_html_e( '积分套餐', 'sakura-imgopt' ); ?></h3>
								<p><?php esc_html_e( '积分兑换按「每 GB 所需积分」折算；等价现金用主题积分汇率（pay_points_rate）反算。', 'sakura-imgopt' ); ?></p>
							</header>
							<div class="field-list">
								<?php
								UI::field(
									array(
										'type'  => 'number',
										'id'    => 'space-points-per-gb',
										'name'  => $sufm_space_option . '[points][points_per_gb]',
										'label' => __( '每 GB 积分', 'sakura-imgopt' ),
										'value' => (string) $sufm_space_points_per_gb,
										'min'   => 1,
										'step'  => 1,
										'hint'  => $sufm_space_pay_rate > 0
											/* translators: 1: price in currency per GB, 2: points per currency unit from the theme. */
											? sprintf( __( '约 %1$s 元/GB（主题汇率 %2$s 积分 = 1 元）。', 'sakura-imgopt' ), number_format_i18n( $sufm_space_pay_rate > 0 ? $sufm_space_points_per_gb / $sufm_space_pay_rate : 0, 2 ), number_format_i18n( $sufm_space_pay_rate, 2 ) )
											: __( '未取到主题积分汇率（pay_points_rate）。', 'sakura-imgopt' ),
									)
								);
								UI::field(
									array(
										'type'  => 'number',
										'id'    => 'space-points-max-gb',
										'name'  => $sufm_space_option . '[points][max_gb_per_order]',
										'label' => __( '单笔上限（GB）', 'sakura-imgopt' ),
										'value' => (string) max( 1, (int) $sufm_space_points['max_gb_per_order'] ),
										'min'   => 1,
										'step'  => 1,
										'hint'  => __( '保存时会被强制压到「每日上限」以内。', 'sakura-imgopt' ),
									)
								);
								UI::field(
									array(
										'type'  => 'number',
										'id'    => 'space-points-daily-cap',
										'name'  => $sufm_space_option . '[points][daily_cap_gb]',
										'label' => __( '每日上限（GB）', 'sakura-imgopt' ),
										'value' => (string) max( 1, (int) $sufm_space_points['daily_cap_gb'] ),
										'min'   => 1,
										'step'  => 1,
										'hint'  => __( '同一用户一天内可兑换的总量上限。', 'sakura-imgopt' ),
									)
								);
								?>
							</div>
							<input type="hidden" name="<?php echo esc_attr( $sufm_space_option . '[points_packages][_present]' ); ?>" value="1" />
							<div class="table-scroll">
								<table class="table table--compact table--responsive zibll-plan-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( '套餐 ID', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '容量（GB）', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '所需积分', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '时长（天）', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '上架', 'sakura-imgopt' ); ?></th>
											<th scope="col"><?php esc_html_e( '删除', 'sakura-imgopt' ); ?></th>
										</tr>
									</thead>
									<tbody data-zibll-points-rows>
										<?php foreach ( $sufm_space_ppkgs as $sufm_space_pkg ) : ?>
											<?php $sufm_space_points_row( $sufm_space_pkg, (string) $sufm_space_pkg['id'], false ); ?>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<p class="zibll-row-actions">
								<button type="button" class="btn btn--ghost btn--small" data-zibll-plan-add="points">＋ <?php esc_html_e( '添加积分套餐', 'sakura-imgopt' ); ?></button>
							</p>
						</section>

						<div class="zibll-compare">
							<?php if ( null === $sufm_space_cheapest || $sufm_space_cheapest <= 0 ) : ?>
								<p class="zibll-muted"><?php esc_html_e( '暂无可用现金套餐，无法比较。', 'sakura-imgopt' ); ?></p>
							<?php else : ?>
								<p><?php echo esc_html( sprintf( /* translators: %s: lowest cash price per GB. */ __( '最便宜现金套餐：%s 元/GB。', 'sakura-imgopt' ), number_format_i18n( $sufm_space_cheapest, 2 ) ) ); ?></p>
								<?php if ( $sufm_space_far ) : ?>
									<p><strong><?php esc_html_e( '积分定价明显偏离现金价（超过 2 倍）。', 'sakura-imgopt' ); ?></strong></p>
								<?php endif; ?>
							<?php endif; ?>
							<p class="zibll-muted"><?php echo esc_html( sprintf( /* translators: 1: GB already redeemed today, 2: daily GB cap, 3: number of redemptions today. */ __( '今日已兑换 %1$s GB / 上限 %2$s GB（%3$d 笔）。', 'sakura-imgopt' ), number_format_i18n( $sufm_space_today_gb, 2 ), number_format_i18n( max( 1, (int) $sufm_space_points['daily_cap_gb'] ) ), $sufm_space_today_count ) ); ?></p>
						</div>

						<p class="zibll-muted"><?php esc_html_e( '套餐 ID 自动分配（A 为现金、P 为积分），删除后不复用；容量填 0 或价格/积分空着的行在保存时会被丢弃。', 'sakura-imgopt' ); ?></p>
						<p class="zibll-error" role="alert" aria-live="polite" data-zibll-plan-error></p>

						<template data-zibll-plan-template="cash">
							<table><tbody><?php $sufm_space_cash_row( array( 'id' => '', 'label' => '', 'bytes' => 0, 'price' => 0, 'days' => 365, 'active' => true ), '__KEY__', true ); ?></tbody></table>
						</template>
						<template data-zibll-plan-template="points">
							<table><tbody><?php $sufm_space_points_row( array( 'id' => '', 'label' => '', 'gb' => 0, 'points' => 0, 'days' => 365, 'active' => true ), '__KEY__', true ); ?></tbody></table>
						</template>
					</div>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_grants',
					'kicker'      => 'GRANTS',
					'title'       => __( '授权流水', 'sakura-imgopt' ),
					'description' => __( '最近 20 条授权记录，时间已按站点时区显示。到期时间留空表示永久。', 'sakura-imgopt' ),
					'wide'        => true,
					'actions'     => array(
						array(
							'label'   => __( '刷新授权', 'sakura-imgopt' ),
							'url'     => '#',
							'variant' => 'ghost',
							'id'      => 'zibll-space-grants-refresh',
							'attrs'   => array( 'data-zibll-grants-refresh' => '1' ),
						),
					),
				),
				static function () {
					?>
					<div data-zibll-grants>
						<div data-zibll-grants-state>
							<?php UI::empty_state( __( '暂无授权记录', 'sakura-imgopt' ), __( '授权会在用户完成现金订单或积分兑换后出现。', 'sakura-imgopt' ) ); ?>
						</div>
						<div class="table-scroll" data-zibll-grants-wrap hidden>
							<table class="table table--compact table--responsive">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( '时间 / 用户', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '套餐 / 容量', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '到期', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '操作', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody data-zibll-grants-rows></tbody>
							</table>
						</div>
					</div>
					<?php
				}
			);
			?>

			<?php
			UI::card(
				array(
					'id'          => 'space_reconcile',
					'kicker'      => 'RECONCILE',
					'title'       => __( '对账与重试', 'sakura-imgopt' ),
					'description' => __( '「检查差异」只读，找出已付款但没有授权的子比订单；「修复差异」按订单快照补发，幂等（同一订单不会重复授予）。', 'sakura-imgopt' ),
					'wide'        => true,
				),
				static function () {
					?>
					<div class="button-row">
						<button type="button" class="btn btn--primary" id="zibll-space-reconcile-check" data-zibll-reconcile-check><?php esc_html_e( '检查差异', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--danger" id="zibll-space-reconcile-apply" data-zibll-reconcile-apply disabled><?php esc_html_e( '修复差异', 'sakura-imgopt' ); ?></button>
					</div>
					<p class="zibll-reconcile-status" data-zibll-reconcile-summary aria-live="polite"><?php esc_html_e( '尚未检查。点击「检查差异」比对已付款订单与授权记录。', 'sakura-imgopt' ); ?></p>
					<div class="zibll-reconcile-list" data-zibll-reconcile-list></div>
					<?php
				}
			);
			?>
		</div>
		<?php
	}
);
