<?php
/**
 * Admin view: file space / Zibll only tab (P0 skeleton).
 *
 * Local variables provided by \Nboke\UserFiles\Admin\Space_Page::render():
 *
 * @var array<string,mixed> $settings         Sanitised sufm_space_settings.
 * @var \Nboke\UserFiles\Services\Zibll_Bridge $bridge Theme gate.
 * @var array<string,mixed> $breakdown_for_me Derived quota breakdown of the current user.
 * @var array<string,mixed> $entry_status     Gate / switch snapshot (available, enabled, apply_bonuses, mode).
 *
 * @package Sakura\ImageOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sufm_space_option = \Nboke\UserFiles\Space_Settings::OPTION;
$sufm_space_diag   = $bridge->diagnostics();
$sufm_space_vips   = \Nboke\UserFiles\Services\Space_Rules::vip_labels();

$sufm_space_caps = array(
	'theme'        => __( '主题识别（zibll）', 'sakura-imgopt' ),
	'level'        => __( '等级读取 zib_get_user_level', 'sakura-imgopt' ),
	'vip'          => __( 'VIP 读取 zib_get_user_vip_level', 'sakura-imgopt' ),
	'points'       => __( '积分函数 zibpay_update_user_points', 'sakura-imgopt' ),
	'pay_methods'  => __( '支付方式 zibpay_get_payment_methods', 'sakura-imgopt' ),
	'pay_initiate' => __( '发起支付 zibpay_initiate_pay', 'sakura-imgopt' ),
	'orders'       => __( '订单类 ZibPay', 'sakura-imgopt' ),
	'user_center'  => __( '用户中心 zib_get_user_center_url', 'sakura-imgopt' ),
);

$sufm_space_theme   = $sufm_space_diag['theme'];
$sufm_space_caps_ok = $sufm_space_diag['capabilities'];

$sufm_space_theme_methods  = is_array( $sufm_space_diag['payment_methods'] ) ? $sufm_space_diag['payment_methods'] : array();
$sufm_space_allowed        = \Nboke\UserFiles\Space_Settings::allowed_methods();
$sufm_space_points_enabled = \Nboke\UserFiles\Space_Settings::points_enabled();
$sufm_space_methods        = array_intersect_key( $sufm_space_theme_methods, array_flip( $sufm_space_allowed ) );

$sufm_space_size = static function ( $bytes ): string {
	$bytes = (int) $bytes;

	return function_exists( 'size_format' ) ? size_format( $bytes ) : $bytes . ' B';
};

// Level thresholds are read from the theme when available; never invented.
$sufm_space_theme_options = function_exists( 'get_option' ) ? get_option( 'zibll_options' ) : array();
$sufm_space_level_max     = 10;

if ( is_array( $sufm_space_theme_options ) && isset( $sufm_space_theme_options['user_level_max'] ) ) {
	$sufm_space_level_max = (int) $sufm_space_theme_options['user_level_max'];
}

if ( 1 > $sufm_space_level_max ) {
	$sufm_space_level_max = 1;
}

if ( 20 < $sufm_space_level_max ) {
	$sufm_space_level_max = 20;
}

$sufm_space_level_rows = array();
$sufm_space_threshold  = array();
$sufm_space_level_opt  = array();

if ( is_array( $sufm_space_theme_options ) && isset( $sufm_space_theme_options['user_level_opt'] ) && is_array( $sufm_space_theme_options['user_level_opt'] ) ) {
	$sufm_space_level_opt = $sufm_space_theme_options['user_level_opt'];
}

for ( $sufm_space_level = 1; $sufm_space_level <= $sufm_space_level_max; $sufm_space_level++ ) {
	$sufm_space_level_rows[] = $sufm_space_level;

	$sufm_space_threshold[ $sufm_space_level ] = '';
	$sufm_space_key                            = 'upgrade_integral_' . $sufm_space_level;

	if ( isset( $sufm_space_level_opt[ $sufm_space_key ] ) && is_scalar( $sufm_space_level_opt[ $sufm_space_key ] ) ) {
		$sufm_space_threshold[ $sufm_space_level ] = (string) $sufm_space_level_opt[ $sufm_space_key ];
	}
}

$sufm_space_level_map = isset( $settings['level_bonus'] ) && is_array( $settings['level_bonus'] ) ? $settings['level_bonus'] : array();
$sufm_space_vip_map   = isset( $settings['vip_bonus'] ) && is_array( $settings['vip_bonus'] ) ? $settings['vip_bonus'] : array();
$sufm_space_rule      = \Nboke\UserFiles\Space_Settings::level_rule();
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php \Sakura\ImageOptimizer\Admin\Shell::header( 'files', 'zibll' ); ?>

		<?php if ( empty( $entry_status['enabled'] ) ) : ?>
			<section class="card sakura-space-notice" role="status">
				<div class="card-body">
					<strong><?php esc_html_e( '扩容未启用', 'sakura-imgopt' ); ?></strong>
					<span><?php esc_html_e( '前台不会出现任何入口；已存在的等级 / VIP 加成也不会影响容量。', 'sakura-imgopt' ); ?></span>
				</div>
			</section>
		<?php endif; ?>

		<div class="compact-cards" data-sakura-layout="files-space" data-sakura-group="main">

			<section class="card" data-sakura-card="space_diag" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'DIAGNOSE', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '主题诊断', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '本页所有数值都在运行时读取主题，不写死任何主题侧的名称或价格。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<div class="sufm-space-diag">
						<div class="sufm-space-diag__block">
							<p class="sufm-space-subtitle"><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></p>
							<dl class="sufm-space-kv">
								<dt><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( '' !== $sufm_space_theme['name'] ? $sufm_space_theme['name'] : __( '未取到', 'sakura-imgopt' ) ); ?></dd>
								<dt><?php esc_html_e( '版本', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( '' !== $sufm_space_theme['version'] ? $sufm_space_theme['version'] : __( '未取到', 'sakura-imgopt' ) ); ?></dd>
								<dt><?php esc_html_e( '样式目录', 'sakura-imgopt' ); ?></dt>
								<dd><code><?php echo esc_html( '' !== $sufm_space_theme['stylesheet'] ? $sufm_space_theme['stylesheet'] : '-' ); ?></code></dd>
								<dt><?php esc_html_e( '模板目录', 'sakura-imgopt' ); ?></dt>
								<dd><code><?php echo esc_html( '' !== $sufm_space_theme['template'] ? $sufm_space_theme['template'] : '-' ); ?></code></dd>
								<dt><?php esc_html_e( '子主题', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( empty( $sufm_space_theme['is_child'] ) ? __( '否', 'sakura-imgopt' ) : __( '是', 'sakura-imgopt' ) ); ?></dd>
							</dl>
						</div>

						<div class="sufm-space-diag__block">
							<p class="sufm-space-subtitle"><?php esc_html_e( '能力矩阵', 'sakura-imgopt' ); ?></p>
							<ul class="sufm-space-caps">
								<?php foreach ( $sufm_space_caps as $sufm_space_cap_key => $sufm_space_cap_label ) : ?>
									<?php $sufm_space_cap_ok = ! empty( $sufm_space_caps_ok[ $sufm_space_cap_key ] ); ?>
									<li class="sufm-space-caps__row<?php echo esc_attr( $sufm_space_cap_ok ? ' is-ok' : ' is-missing' ); ?>">
										<span class="sufm-space-caps__mark" aria-hidden="true"><?php echo esc_html( $sufm_space_cap_ok ? '✓' : '✗' ); ?></span>
										<span class="sufm-space-caps__label"><?php echo esc_html( $sufm_space_cap_label ); ?></span>
										<span class="screen-reader-text">
											<?php echo $sufm_space_cap_ok ? esc_html__( '可用', 'sakura-imgopt' ) : esc_html__( '缺失，该通道已自动关闭', 'sakura-imgopt' ); ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>

						<div class="sufm-space-diag__block">
							<p class="sufm-space-subtitle"><?php esc_html_e( '已启用购买方式', 'sakura-imgopt' ); ?></p>
							<?php if ( empty( $sufm_space_methods ) && ! $sufm_space_points_enabled ) : ?>
								<p class="sufm-space-muted"><?php esc_html_e( '当前没有启用任何购买方式。可在下方「支付方式」卡片勾选并保存。', 'sakura-imgopt' ); ?></p>
							<?php else : ?>
								<ul class="sufm-space-methods">
									<?php foreach ( $sufm_space_methods as $sufm_space_method_id => $sufm_space_method ) : ?>
										<li>
											<code><?php echo esc_html( (string) $sufm_space_method_id ); ?></code>
											<?php if ( '' !== $sufm_space_method['name'] ) : ?>
												<span><?php echo esc_html( $sufm_space_method['name'] ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
									<?php if ( $sufm_space_points_enabled ) : ?>
										<li class="is-points">
											<code>points</code>
											<span><?php esc_html_e( '积分兑换', 'sakura-imgopt' ); ?></span>
										</li>
									<?php endif; ?>
								</ul>
								<p class="sufm-space-muted"><?php esc_html_e( '现金通道只显示已勾选且主题当前提供的项目；积分兑换由本插件直接扣减，不经过主题支付。', 'sakura-imgopt' ); ?></p>
							<?php endif; ?>
						</div>

						<div class="sufm-space-diag__block">
							<p class="sufm-space-subtitle"><?php esc_html_e( '我的派生容量', 'sakura-imgopt' ); ?></p>
							<dl class="sufm-space-kv sufm-space-kv--num">
								<dt><?php esc_html_e( '基础', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( $sufm_space_size( $breakdown_for_me['base'] ) ); ?></dd>
								<dt><?php esc_html_e( '等级加成', 'sakura-imgopt' ); ?></dt>
								<dd>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: level number, 2: formatted size. */
											__( 'LV%1$s · %2$s', 'sakura-imgopt' ),
											(string) $breakdown_for_me['level'],
											$sufm_space_size( $breakdown_for_me['level_bytes'] )
										)
									);
									?>
								</dd>
								<dt><?php esc_html_e( 'VIP 加成', 'sakura-imgopt' ); ?></dt>
								<dd>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: vip level number, 2: formatted size. */
											__( 'vip_level=%1$s · %2$s', 'sakura-imgopt' ),
											(string) $breakdown_for_me['vip'],
											$sufm_space_size( $breakdown_for_me['vip_bytes'] )
										)
									);
									?>
								</dd>
								<dt><?php esc_html_e( '已购', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( $sufm_space_size( $breakdown_for_me['granted_bytes'] ) ); ?></dd>
								<dt><?php esc_html_e( '计算值', 'sakura-imgopt' ); ?></dt>
								<dd><?php echo esc_html( $sufm_space_size( $breakdown_for_me['computed'] ) ); ?></dd>
								<dt><?php esc_html_e( '单独设定', 'sakura-imgopt' ); ?></dt>
								<dd>
									<?php
									echo null === $breakdown_for_me['override']
										? esc_html__( '未设定', 'sakura-imgopt' )
										: esc_html( $sufm_space_size( $breakdown_for_me['override'] ) );
									?>
								</dd>
								<dt><?php esc_html_e( '最终生效', 'sakura-imgopt' ); ?></dt>
								<dd><strong><?php echo esc_html( $sufm_space_size( $breakdown_for_me['final'] ) ); ?></strong></dd>
							</dl>
							<p class="sufm-space-muted">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: mode name, either cap or replace. */
										__( '封顶语义：%s', 'sakura-imgopt' ),
										'cap' === $breakdown_for_me['mode'] ? __( '封顶（cap）', 'sakura-imgopt' ) : __( '替换（replace）', 'sakura-imgopt' )
									)
								);
								?>
							</p>
						</div>
					</div>
				</div>
			</section>

			<section class="card" data-sakura-card="space_payment" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'PAYMENT', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '支付方式', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '只有勾选并保存的通道才会出现在前台收银台。默认只启用余额；支付宝、微信等外部渠道需自行确认对账与退款流程。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" data-sufm-space-payment>
						<?php settings_fields( \Nboke\UserFiles\Admin\Space_Page::GROUP ); ?>
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[_partial]" value="1" />
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[allowed_methods_present]" value="1" />

						<div class="sufm-space-paygrid">
							<div class="sufm-space-paygroup">
								<p class="sufm-space-subtitle"><?php esc_html_e( '主题支付通道', 'sakura-imgopt' ); ?></p>
								<?php if ( empty( $sufm_space_theme_methods ) ) : ?>
									<p class="sufm-space-muted"><?php esc_html_e( '未读取到主题支付通道。请确认已登录后台，且主题已开启对应的支付方式。', 'sakura-imgopt' ); ?></p>
								<?php else : ?>
									<ul class="sufm-space-paylist">
										<?php foreach ( $sufm_space_theme_methods as $sufm_space_method_id => $sufm_space_method ) : ?>
											<?php
											$sufm_space_method_id    = (string) $sufm_space_method_id;
											$sufm_space_method_name  = isset( $sufm_space_method['name'] ) && '' !== (string) $sufm_space_method['name'] ? (string) $sufm_space_method['name'] : $sufm_space_method_id;
											$sufm_space_method_check = in_array( $sufm_space_method_id, $sufm_space_allowed, true );
											?>
											<li>
												<label class="sufm-space-pay-row">
													<input type="checkbox" name="<?php echo esc_attr( $sufm_space_option . '[allowed_methods][]' ); ?>" value="<?php echo esc_attr( $sufm_space_method_id ); ?>" <?php checked( $sufm_space_method_check ); ?> />
													<span class="sufm-space-pay-text">
														<span class="sufm-space-pay-name">
															<code><?php echo esc_html( $sufm_space_method_id ); ?></code>
															<strong><?php echo esc_html( $sufm_space_method_name ); ?></strong>
														</span>
														<em>
															<?php echo 'balance' === $sufm_space_method_id ? esc_html__( '同步到账，推荐启用。', 'sakura-imgopt' ) : esc_html__( '外部支付渠道，可能出现未支付订单，需要人工对账。', 'sakura-imgopt' ); ?>
														</em>
													</span>
												</label>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<p class="sufm-space-hint"><?php esc_html_e( '余额由主题同步确认；二维码或跳转渠道必须确认能处理退款与超时订单后再勾选。', 'sakura-imgopt' ); ?></p>
							</div>

							<div class="sufm-space-paygroup">
								<p class="sufm-space-subtitle"><?php esc_html_e( '积分兑换', 'sakura-imgopt' ); ?></p>
								<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[points][enabled]" value="0" />
								<label class="sufm-space-pay-row">
									<input type="checkbox" name="<?php echo esc_attr( $sufm_space_option ); ?>[points][enabled]" value="1" <?php checked( $sufm_space_points_enabled ); ?> />
									<span class="sufm-space-pay-text">
										<span class="sufm-space-pay-name">
											<code>points</code>
											<strong><?php esc_html_e( '积分兑换', 'sakura-imgopt' ); ?></strong>
										</span>
										<em><?php esc_html_e( '关闭后前台隐藏积分入口，接口拒绝兑换；已售出的积分授权不受影响。', 'sakura-imgopt' ); ?></em>
									</span>
								</label>
								<p class="sufm-space-hint"><?php esc_html_e( '积分由本插件直接扣减，不经过主题支付；与现金通道互不影响。', 'sakura-imgopt' ); ?></p>
							</div>
						</div>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存支付方式', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<section class="card" data-sakura-card="space_entry" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'ENTRY', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '前台入口', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '只控制系统用户中心里是否出现「文件管理」入口，以及前台上传 / 删除接口。后台媒体库的多用户文件夹、整理与归属转移属于基础能力，始终可用；关闭不会删除任何文件。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( 'sufm_settings_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[_partial]' ); ?>" value="1" />

						<div class="sufm-space-switch-row">
							<label class="sufm-switch">
								<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[enabled]' ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[enabled]' ); ?>" value="1" <?php checked( $files_enabled ); ?> />
								<span><?php esc_html_e( '启用前台入口', 'sakura-imgopt' ); ?></span>
							</label>
							<em class="sufm-space-muted">
								<?php echo $files_enabled ? esc_html__( '当前：已启用', 'sakura-imgopt' ) : esc_html__( '当前：未启用（前台零入口）', 'sakura-imgopt' ); ?>
							</em>
						</div>

						<dl class="sufm-space-kv">
							<dt><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></dt>
							<dd><?php echo esc_html( '' !== $files_entry['theme'] ? $files_entry['theme'] : $files_entry['stylesheet'] ); ?></dd>
							<dt><?php esc_html_e( '检测到的入口', 'sakura-imgopt' ); ?></dt>
							<dd><?php echo esc_html( $files_entry['label'] ); ?></dd>
							<dt><?php esc_html_e( '前台位置', 'sakura-imgopt' ); ?></dt>
							<dd><?php echo esc_html( $files_entry['generic'] ? __( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' ) : __( '用户中心 → 文件管理', 'sakura-imgopt' ) ); ?></dd>
							<?php if ( '' !== $files_entry['url'] ) : ?>
								<dt><?php esc_html_e( '前台地址', 'sakura-imgopt' ); ?></dt>
								<dd><a href="<?php echo esc_url( $files_entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $files_entry['url'] ); ?></a></dd>
							<?php endif; ?>
						</dl>

						<p class="sufm-space-muted"><?php esc_html_e( '关闭只会停用前台入口与前台上传接口，不会删除文件，也不会重置用户容量；媒体库功能与重新启用后的数据都保持原样。', 'sakura-imgopt' ); ?></p>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存入口设置', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<?php $sufm_editor_enabled = \Nboke\UserFiles\Settings::editor_enabled(); ?>
			<section class="card" data-sakura-card="space_editor" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'EDITOR', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '编辑器文件面板', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '在写文章 / 发帖页的编辑器工具栏加一个入口，点开后在正文左侧固定打开文件空间，写作时直接复用旧附件。面板只读：不能上传、不能删除、不占配额。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( 'sufm_settings_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[_partial]' ); ?>" value="1" />

						<div class="sufm-space-switch-row">
							<label class="sufm-switch">
								<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[editor_enabled]' ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[editor_enabled]' ); ?>" value="1" <?php checked( $sufm_editor_enabled ); ?> />
								<span><?php esc_html_e( '启用编辑器文件面板', 'sakura-imgopt' ); ?></span>
							</label>
							<em class="sufm-space-muted">
								<?php echo $sufm_editor_enabled ? esc_html__( '当前：已启用', 'sakura-imgopt' ) : esc_html__( '当前：未启用', 'sakura-imgopt' ); ?>
							</em>
						</div>

						<dl class="sufm-space-kv">
							<dt><?php esc_html_e( '生效页面', 'sakura-imgopt' ); ?></dt>
							<dd><?php esc_html_e( '写文章 /newposts、发帖与编辑 /posts-edit', 'sakura-imgopt' ); ?></dd>
							<dt><?php esc_html_e( '开启条件', 'sakura-imgopt' ); ?></dt>
							<dd><?php esc_html_e( '桌面端，且浏览器窗口宽度 ≥ 1840px；不足时按钮不出现', 'sakura-imgopt' ); ?></dd>
							<dt><?php esc_html_e( '权限', 'sakura-imgopt' ); ?></dt>
							<dd><?php esc_html_e( '已登录且拥有「管理自己的文件空间」权限；只看得到自己的文件', 'sakura-imgopt' ); ?></dd>
						</dl>

						<p class="sufm-space-muted"><?php esc_html_e( '与上方「前台入口」互不影响：入口关掉时用户中心不出现文件管理，但写文章时仍可翻自己的附件插入。关闭本开关不会删除任何文件。', 'sakura-imgopt' ); ?></p>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存编辑器面板设置', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<section class="card" data-sakura-card="space_gate" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'GATE', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '门禁与开关', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '总开关默认关闭；「计算加成」是紧急止血阀：关闭后已购容量立即停止生效（等级 / VIP 加成仍按主题计算）。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( \Nboke\UserFiles\Admin\Space_Page::GROUP ); ?>
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[_partial]" value="1" />


						<div class="sufm-space-switch-row">
							<label class="sufm-switch">
								<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[enabled]" value="0" />
								<input type="checkbox"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[enabled]"
									value="1"
									data-sufm-space-confirm="enabled"
									<?php checked( ! empty( $entry_status['enabled'] ) ); ?> />
								<span><?php esc_html_e( '启用扩容售卖', 'sakura-imgopt' ); ?></span>
							</label>
							<em class="sufm-space-muted">
								<?php echo empty( $entry_status['enabled'] ) ? esc_html__( '当前：未启用（前台零入口）', 'sakura-imgopt' ) : esc_html__( '当前：已启用', 'sakura-imgopt' ); ?>
							</em>
						</div>

						<div class="sufm-space-switch-row">
							<label class="sufm-switch">
								<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[apply_bonuses]" value="0" />
								<input type="checkbox"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[apply_bonuses]"
									value="1"
									data-sufm-space-confirm="apply_bonuses"
									<?php checked( ! empty( $entry_status['apply_bonuses'] ) ); ?> />
								<span><?php esc_html_e( '计算加成（已购授权）', 'sakura-imgopt' ); ?></span>
							</label>
							<em class="sufm-space-muted"><?php esc_html_e( '关闭后，所有已购容量立即停止生效（不删任何文件）。', 'sakura-imgopt' ); ?></em>
						</div>

						<fieldset class="sufm-space-mode">
							<legend><?php esc_html_e( '单独设定的封顶语义', 'sakura-imgopt' ); ?></legend>
							<label>
								<input type="radio"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[quota_override_mode]"
									value="cap"
									<?php checked( 'cap', $entry_status['mode'] ); ?> />
								<span><?php esc_html_e( '封顶（cap）：实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ); ?></span>
							</label>
							<label>
								<input type="radio"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[quota_override_mode]"
									value="replace"
									<?php checked( 'replace', $entry_status['mode'] ); ?> />
								<span><?php esc_html_e( '替换（replace）：单独设定直接覆盖计算值', 'sakura-imgopt' ); ?></span>
							</label>
						</fieldset>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存开关', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<section class="card" data-sakura-card="space_level" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'BONUS', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '等级加成', 'sakura-imgopt' ); ?></h2>
						<p>
							<?php
							printf(
								/* translators: %d: number of levels the theme exposes. */
								esc_html__( '按主题等级自动折算：当前主题共 %d 级，等级名与门槛积分都读自主题。每满一定积分增加一定容量，等级越高、门槛越高，加成越大。', 'sakura-imgopt' ),
								(int) $sufm_space_level_max
							);
							?>
						</p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( \Nboke\UserFiles\Admin\Space_Page::GROUP ); ?>
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[_partial]" value="1" />

						<div class="sufm-space-rulebar">
							<label class="sufm-space-rulebar__item">
								<input type="hidden"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[level_rule][enabled]"
									value="0" />
								<input type="checkbox"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[level_rule][enabled]"
									value="1"
									<?php checked( $sufm_space_rule['enabled'] ); ?> />
								<span><?php esc_html_e( '启用规则（关闭后回退到下方手填表）', 'sakura-imgopt' ); ?></span>
							</label>
							<span class="sufm-space-rulebar__sentence">
							<label class="sufm-space-rulebar__item">
								<span><?php esc_html_e( '每满', 'sakura-imgopt' ); ?></span>
								<input type="number"
									class="sufm-space-input"
									min="1"
									step="100"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[level_rule][points_per_step]"
									value="<?php echo esc_attr( (string) $sufm_space_rule['points_per_step'] ); ?>" />
								<span><?php esc_html_e( '积分，', 'sakura-imgopt' ); ?></span>
							</label>
							<label class="sufm-space-rulebar__item">
								<span><?php esc_html_e( '增加', 'sakura-imgopt' ); ?></span>
								<input type="number"
									class="sufm-space-input"
									min="0"
									step="1"
									name="<?php echo esc_attr( $sufm_space_option ); ?>[level_rule][bytes_per_step_mb]"
									value="<?php echo esc_attr( (string) round( $sufm_space_rule['bytes_per_step'] / 1048576, 2 ) ); ?>" />
								<span><?php esc_html_e( 'MB', 'sakura-imgopt' ); ?></span>
							</label>
							</span>
						</div>

						<?php
						$sufm_space_level_halves = array_chunk(
							$sufm_space_level_rows,
							(int) ceil( count( $sufm_space_level_rows ) / 2 )
						);
						?>
						<div class="sufm-space-ladder">
							<?php foreach ( $sufm_space_level_halves as $sufm_space_half ) : ?>
								<div class="sufm-space-rows">
									<div class="sufm-space-row is-head" aria-hidden="true">
										<span><?php esc_html_e( '等级', 'sakura-imgopt' ); ?></span>
										<span><?php esc_html_e( '门槛积分', 'sakura-imgopt' ); ?></span>
										<span><?php esc_html_e( '加成（MB）', 'sakura-imgopt' ); ?></span>
									</div>
									<?php foreach ( $sufm_space_half as $sufm_space_level ) : ?>
										<?php
										$sufm_space_threshold_value = isset( $sufm_space_threshold[ $sufm_space_level ] ) ? (int) $sufm_space_threshold[ $sufm_space_level ] : 0;

										if ( $sufm_space_rule['enabled'] ) {
											$sufm_space_steps = intdiv( max( 0, $sufm_space_threshold_value ), max( 1, $sufm_space_rule['points_per_step'] ) );
											$sufm_space_bytes = max( 0, $sufm_space_steps * $sufm_space_rule['bytes_per_step'] );
										} else {
											$sufm_space_bytes = isset( $sufm_space_level_map[ $sufm_space_level ] ) ? (int) $sufm_space_level_map[ $sufm_space_level ] : 0;
										}

										$sufm_space_mb    = round( $sufm_space_bytes / 1048576, 1 );
										$sufm_space_mb    = ( $sufm_space_mb === floor( $sufm_space_mb ) ) ? (int) $sufm_space_mb : $sufm_space_mb;
										$sufm_space_label = sprintf(
											/* translators: %s: level number. */
											__( 'LV%s 加成（MB）', 'sakura-imgopt' ),
											(string) $sufm_space_level
										);
										?>
										<div class="sufm-space-row">
											<span class="sufm-space-row-key"><?php echo esc_html( 'LV' . $sufm_space_level ); ?></span>
											<span class="sufm-space-row-meta"><?php echo '' !== $sufm_space_threshold[ $sufm_space_level ] ? esc_html( $sufm_space_threshold[ $sufm_space_level ] ) : esc_html( '—' ); ?></span>
											<input type="number"
												id="sufm-space-level-<?php echo esc_attr( (string) $sufm_space_level ); ?>"
												class="sufm-space-input"
												data-sufm-space-mb="level"
												min="0"
												step="1"
												aria-label="<?php echo esc_attr( $sufm_space_label ); ?>"
												name="<?php echo esc_attr( $sufm_space_option ); ?>[level_bonus_mb][<?php echo esc_attr( (string) $sufm_space_level ); ?>]"
												value="<?php echo esc_attr( (string) $sufm_space_mb ); ?>"
												<?php disabled( $sufm_space_rule['enabled'] ); ?> />
										</div>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
						</div>

						<p class="sufm-space-total" data-sufm-space-total>
							<?php esc_html_e( '加成合计：', 'sakura-imgopt' ); ?><strong><?php esc_html_e( '0 MB', 'sakura-imgopt' ); ?></strong>
						</p>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存等级加成', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<section class="card" data-sakura-card="space_vip" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'BONUS', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( 'VIP / SVIP 加成', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '名称在运行时解析：过滤器 → 主题选项 → 降级标签。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
						<?php settings_fields( \Nboke\UserFiles\Admin\Space_Page::GROUP ); ?>
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[_partial]" value="1" />

						<div class="sufm-space-tiles">
							<?php foreach ( array( 0, 1, 2 ) as $sufm_space_vip ) : ?>
								<?php
								$sufm_space_bytes = isset( $sufm_space_vip_map[ $sufm_space_vip ] ) ? (int) $sufm_space_vip_map[ $sufm_space_vip ] : 0;
								$sufm_space_mb    = round( $sufm_space_bytes / 1048576, 1 );
								$sufm_space_mb    = ( $sufm_space_mb === floor( $sufm_space_mb ) ) ? (int) $sufm_space_mb : $sufm_space_mb;
								$sufm_space_label = sprintf(
									/* translators: %s: vip level number. */
									__( 'vip_level=%s 加成（MB）', 'sakura-imgopt' ),
									(string) $sufm_space_vip
								);
								?>
								<label class="sufm-space-tile">
									<span class="sufm-space-tile__key"><code>vip_level=<?php echo esc_html( (string) $sufm_space_vip ); ?></code></span>
									<span class="sufm-space-tile__name"><?php echo esc_html( isset( $sufm_space_vips[ $sufm_space_vip ] ) ? $sufm_space_vips[ $sufm_space_vip ] : __( '未取到', 'sakura-imgopt' ) ); ?></span>
									<span class="sufm-space-tile__field">
										<input type="number"
											id="sufm-space-vip-<?php echo esc_attr( (string) $sufm_space_vip ); ?>"
											class="sufm-space-input"
											data-sufm-space-mb="vip"
											min="0"
											step="1"
											aria-label="<?php echo esc_attr( $sufm_space_label ); ?>"
											name="<?php echo esc_attr( $sufm_space_option ); ?>[vip_bonus_mb][<?php echo esc_attr( (string) $sufm_space_vip ); ?>]"
											value="<?php echo esc_attr( (string) $sufm_space_mb ); ?>" />
										<span class="sufm-space-tile__unit">MB</span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>

						<p class="sufm-space-total" data-sufm-space-total>
							<?php esc_html_e( '加成合计：', 'sakura-imgopt' ); ?><strong><?php esc_html_e( '0 MB', 'sakura-imgopt' ); ?></strong>
						</p>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存 VIP 加成', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>
				</div>
			</section>

			<section class="card" data-sakura-card="space_plans" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'PLANS', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '空间套餐', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '两种支付方式卖同一份空间：现金套餐（A 开头）走主题支付，积分套餐（P 开头）直接扣积分。下架或删除只影响新订单，已售出的授权按购买时的快照继续生效。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<?php
					$sufm_space_packages = ( isset( $settings['packages'] ) && is_array( $settings['packages'] ) ) ? $settings['packages'] : array();
					$sufm_space_ppkgs    = \Nboke\UserFiles\Space_Settings::points_packages();
					$sufm_space_points   = ( isset( $settings['points'] ) && is_array( $settings['points'] ) ) ? $settings['points'] : array();

					$sufm_space_default_icon = \Nboke\UserFiles\Services\Space_Store::product_image_url();
					$sufm_space_order_icon   = \Nboke\UserFiles\Space_Settings::order_icon();

					$sufm_space_cash_row = static function ( array $pkg, string $key, bool $is_new ) use ( $sufm_space_option ): string {
						$id    = isset( $pkg['id'] ) && '' !== (string) $pkg['id'] ? strtoupper( (string) $pkg['id'] ) : '';
						$field = $sufm_space_option . '[packages][' . $key . ']';
						$label = isset( $pkg['label'] ) ? (string) $pkg['label'] : '';
						$gb    = isset( $pkg['bytes'] ) && 0 < (int) $pkg['bytes']
							? rtrim( rtrim( number_format( (int) $pkg['bytes'] / 1073741824, 3, '.', '' ), '0' ), '.' )
							: '';
						$price = isset( $pkg['price'] ) && 0 < (float) $pkg['price'] ? (string) (float) $pkg['price'] : '';
						$days  = isset( $pkg['days'] ) ? (int) $pkg['days'] : 365;
						$on    = ! empty( $pkg['active'] );

						ob_start();
						?>
						<div class="sufm-space-row<?php echo $is_new ? ' is-new' : ''; ?>" data-sufm-pkg-row>
							<span class="sufm-space-row-key">
								<input type="text" class="sufm-space-input sufm-space-input--id" data-sufm-pkg-id readonly="readonly"
									name="<?php echo esc_attr( $field . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" />
							</span>
							<span>
								<input type="text" class="sufm-space-input" data-sufm-pkg-label
									placeholder="<?php esc_attr_e( '如 10GB 扩容包', 'sakura-imgopt' ); ?>"
									name="<?php echo esc_attr( $field . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" />
							</span>
							<span>
								<input type="number" min="0.1" step="0.1" class="sufm-space-input"
									name="<?php echo esc_attr( $field . '[bytes_gb]' ); ?>" value="<?php echo esc_attr( (string) $gb ); ?>" />
							</span>
							<span>
								<input type="number" min="0" step="0.01" class="sufm-space-input"
									name="<?php echo esc_attr( $field . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>" />
							</span>
							<span>
								<input type="number" min="0" step="1" class="sufm-space-input"
									name="<?php echo esc_attr( $field . '[days]' ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" />
							</span>
							<span class="sufm-space-pkg-flags">
								<input type="hidden" data-sufm-pkg-remove-value name="<?php echo esc_attr( $field . '[remove]' ); ?>" value="0" />
								<label title="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $field . '[active]' ); ?>" value="1" <?php checked( $on ); ?> />
									<?php esc_html_e( '上架', 'sakura-imgopt' ); ?>
								</label>
								<label class="sufm-space-pkg-remove" title="<?php esc_attr_e( '删除该套餐', 'sakura-imgopt' ); ?>">
									<input type="checkbox" data-sufm-pkg-remove value="1" />
									<?php esc_html_e( '删除', 'sakura-imgopt' ); ?>
								</label>
							</span>
						</div>
						<?php
						return (string) ob_get_clean();
					};

					$sufm_space_points_row = static function ( array $pkg, string $key, bool $is_new ) use ( $sufm_space_option ): string {
						$id     = isset( $pkg['id'] ) && '' !== (string) $pkg['id'] ? strtoupper( (string) $pkg['id'] ) : '';
						$field  = $sufm_space_option . '[points_packages][' . $key . ']';
						$gb_num = isset( $pkg['gb'] ) ? (float) $pkg['gb'] : 0.0;
						$gb     = 0.0 < $gb_num ? (string) $gb_num : '';
						$points = isset( $pkg['points'] ) && 0 < (int) $pkg['points'] ? (string) (int) $pkg['points'] : '';
						$days   = isset( $pkg['days'] ) ? (int) $pkg['days'] : 365;
						$on     = ! empty( $pkg['active'] );

						$label = isset( $pkg['label'] ) ? (string) $pkg['label'] : '';

						if ( '' === $label && 0 < $gb_num ) {
							$label = \Nboke\UserFiles\Space_Settings::points_label( $gb_num, $days );
						}

						ob_start();
						?>
						<div class="sufm-space-row<?php echo $is_new ? ' is-new' : ''; ?>" data-sufm-pkg-row>
							<span class="sufm-space-row-key">
								<input type="text" class="sufm-space-input sufm-space-input--id" data-sufm-pkg-id readonly="readonly"
									name="<?php echo esc_attr( $field . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" />
							</span>
							<span>
								<input type="text" class="sufm-space-input" data-sufm-pkg-label data-sufm-plan-field="label"
									placeholder="<?php esc_attr_e( '如 10GB 扩容包', 'sakura-imgopt' ); ?>"
									name="<?php echo esc_attr( $field . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" />
							</span>
							<span>
								<input type="number" min="0.5" step="0.5" class="sufm-space-input" data-sufm-plan-field="gb"
									name="<?php echo esc_attr( $field . '[gb]' ); ?>" value="<?php echo esc_attr( $gb ); ?>" />
							</span>
							<span>
								<input type="number" min="1" step="1" class="sufm-space-input"
									name="<?php echo esc_attr( $field . '[points]' ); ?>" value="<?php echo esc_attr( $points ); ?>" />
							</span>
							<span>
								<input type="number" min="0" step="1" class="sufm-space-input" data-sufm-plan-field="days"
									name="<?php echo esc_attr( $field . '[days]' ); ?>" value="<?php echo esc_attr( (string) $days ); ?>" />
							</span>
							<span class="sufm-space-pkg-flags">
								<input type="hidden" data-sufm-pkg-remove-value name="<?php echo esc_attr( $field . '[remove]' ); ?>" value="0" />
								<label title="<?php esc_attr_e( '上架', 'sakura-imgopt' ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $field . '[active]' ); ?>" value="1" <?php checked( $on ); ?> />
									<?php esc_html_e( '上架', 'sakura-imgopt' ); ?>
								</label>
								<label class="sufm-space-pkg-remove" title="<?php esc_attr_e( '删除该套餐', 'sakura-imgopt' ); ?>">
									<input type="checkbox" data-sufm-pkg-remove value="1" />
									<?php esc_html_e( '删除', 'sakura-imgopt' ); ?>
								</label>
							</span>
						</div>
						<?php
						return (string) ob_get_clean();
					};

					$sufm_space_pp_gb  = isset( $sufm_space_points['points_per_gb'] ) ? (int) $sufm_space_points['points_per_gb'] : 90;
					$sufm_space_pp_max = isset( $sufm_space_points['max_gb_per_order'] ) ? (int) $sufm_space_points['max_gb_per_order'] : 10;
					$sufm_space_pp_day = isset( $sufm_space_points['daily_cap_gb'] ) ? (int) $sufm_space_points['daily_cap_gb'] : 20;

					$sufm_space_theme_opt = function_exists( 'get_option' ) ? get_option( 'zibll_options' ) : array();
					$sufm_space_rate      = ( is_array( $sufm_space_theme_opt ) && isset( $sufm_space_theme_opt['pay_points_rate'] ) ) ? (int) $sufm_space_theme_opt['pay_points_rate'] : 0;
					$sufm_space_cash_gb   = ( 0 < $sufm_space_rate ) ? ( $sufm_space_pp_gb / $sufm_space_rate ) : null;

					$sufm_space_cheapest = null;

					foreach ( $sufm_space_packages as $sufm_space_cmp ) {
						$sufm_space_cmp      = is_array( $sufm_space_cmp ) ? $sufm_space_cmp : array();
						$sufm_space_cmp_bytes = isset( $sufm_space_cmp['bytes'] ) ? (int) $sufm_space_cmp['bytes'] : 0;
						$sufm_space_cmp_price = isset( $sufm_space_cmp['price'] ) ? (float) $sufm_space_cmp['price'] : 0.0;

						if ( 0 >= $sufm_space_cmp_bytes || 0 >= $sufm_space_cmp_price ) {
							continue;
						}

						$sufm_space_per_gb = $sufm_space_cmp_price / ( $sufm_space_cmp_bytes / 1073741824 );

						if ( null === $sufm_space_cheapest || $sufm_space_per_gb < $sufm_space_cheapest ) {
							$sufm_space_cheapest = $sufm_space_per_gb;
						}
					}

					$sufm_space_far = ( null !== $sufm_space_cash_gb && null !== $sufm_space_cheapest && $sufm_space_cash_gb > ( $sufm_space_cheapest * 2 ) );
					$sufm_space_today = \Nboke\UserFiles\Services\Space_Store::instance()->points_today_totals();
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" data-sufm-space-plans>
						<?php settings_fields( \Nboke\UserFiles\Admin\Space_Page::GROUP ); ?>
						<input type="hidden" name="<?php echo esc_attr( $sufm_space_option ); ?>[_partial]" value="1" />

											<div class="sufm-space-icon-global">
						<span class="sufm-space-icon-global-label"><?php esc_html_e( '订单图标', 'sakura-imgopt' ); ?></span>
						<span class="sufm-space-icon-cell">
							<button type="button" class="sufm-space-icon-pick" data-sufm-icon-pick
								title="<?php esc_attr_e( '从媒体库选择订单图标（建议 96×96 或 128×128）', 'sakura-imgopt' ); ?>">
								<img data-sufm-icon-preview data-default="<?php echo esc_url( $sufm_space_default_icon ); ?>"
									src="<?php echo esc_url( '' !== $sufm_space_order_icon ? $sufm_space_order_icon : $sufm_space_default_icon ); ?>" alt="" width="20" height="20" />
								<span><?php esc_html_e( '选择', 'sakura-imgopt' ); ?></span>
							</button>
							<input type="url" class="sufm-space-input sufm-space-input--icon" data-sufm-icon-input
								placeholder="<?php esc_attr_e( '选择或粘贴图标地址', 'sakura-imgopt' ); ?>"
								name="<?php echo esc_attr( $sufm_space_option . '[order_icon]' ); ?>" value="<?php echo esc_attr( $sufm_space_order_icon ); ?>" />
							<button type="button" class="sufm-space-icon-clear" data-sufm-icon-clear
								title="<?php esc_attr_e( '改回默认图标', 'sakura-imgopt' ); ?>" <?php echo '' === $sufm_space_order_icon ? 'hidden' : ''; ?>>&times;</button>
						</span>
						<p class="sufm-space-hint"><?php esc_html_e( '对所有套餐生效（现金套餐与积分套餐共用）。建议 96×96（或 128×128）正方形 PNG/SVG，≤200 KB；留空使用默认的存储图标。图标会出现在后台订单列表、前台「我的订单」和订单详情弹窗。', 'sakura-imgopt' ); ?></p>
					</div>
						<div class="sufm-space-block">
							<div class="sufm-space-block-head">
								<h3><?php esc_html_e( '现金套餐', 'sakura-imgopt' ); ?></h3>
								<p><?php esc_html_e( '用户在弹窗里看到的价格档位，按下单顺序展示；价格取主题支付通道。', 'sakura-imgopt' ); ?></p>
							</div>

							<div class="sufm-space-rows sufm-space-packages">
								<div class="sufm-space-row is-head" aria-hidden="true">
									<span><?php esc_html_e( '套餐 ID', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '容量(GB)', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '价格(元)', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '时长(天)', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '上架 / 删除', 'sakura-imgopt' ); ?></span>
								</div>

								<input type="hidden" name="<?php echo esc_attr( $sufm_space_option . '[packages][_present]' ); ?>" value="1" />
								<?php foreach ( $sufm_space_packages as $sufm_space_i => $sufm_space_pkg ) : ?>
									<?php echo $sufm_space_cash_row( is_array( $sufm_space_pkg ) ? $sufm_space_pkg : array(), (string) $sufm_space_i, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endforeach; ?>
							</div>

							<button type="button" class="btn btn--small sufm-space-add" data-sufm-plan-add="cash"><?php esc_html_e( '＋ 添加现金套餐', 'sakura-imgopt' ); ?></button>

						</div>

						<div class="sufm-space-block">
							<div class="sufm-space-block-head">
								<h3><?php esc_html_e( '积分套餐', 'sakura-imgopt' ); ?></h3>
								<p><?php esc_html_e( '积分兑换按「每 GB 所需积分」折算；等价现金用主题积分汇率（pay_points_rate）反算。', 'sakura-imgopt' ); ?></p>
							</div>

							<div class="sufm-space-rules">
								<label class="sufm-space-rule">
									<span><?php esc_html_e( '每 GB 积分', 'sakura-imgopt' ); ?></span>
									<input type="number" min="1" step="1" class="sufm-space-input"
										name="<?php echo esc_attr( $sufm_space_option . '[points][points_per_gb]' ); ?>"
										value="<?php echo esc_attr( (string) $sufm_space_pp_gb ); ?>" />
									<em>
										<?php
										echo null === $sufm_space_cash_gb
											? esc_html__( '未取到主题积分汇率（pay_points_rate）', 'sakura-imgopt' )
											/* translators: 1: price per GB, 2: points per currency unit */
											: esc_html( sprintf( __( '约 %1$s 元/GB（主题汇率 %2$s 积分 = 1 元）', 'sakura-imgopt' ), number_format( (float) $sufm_space_cash_gb, 2 ), (string) $sufm_space_rate ) );
										?>
									</em>
								</label>
								<label class="sufm-space-rule">
									<span><?php esc_html_e( '单笔上限(GB)', 'sakura-imgopt' ); ?></span>
									<input type="number" min="1" step="1" class="sufm-space-input"
										name="<?php echo esc_attr( $sufm_space_option . '[points][max_gb_per_order]' ); ?>"
										value="<?php echo esc_attr( (string) $sufm_space_pp_max ); ?>" />
									<em><?php esc_html_e( '保存时会被强制压到「每日上限」以内。', 'sakura-imgopt' ); ?></em>
								</label>
								<label class="sufm-space-rule">
									<span><?php esc_html_e( '每日上限(GB)', 'sakura-imgopt' ); ?></span>
									<input type="number" min="1" step="1" class="sufm-space-input"
										name="<?php echo esc_attr( $sufm_space_option . '[points][daily_cap_gb]' ); ?>"
										value="<?php echo esc_attr( (string) $sufm_space_pp_day ); ?>" />
									<em><?php esc_html_e( '同一用户一天内可兑换的总量上限。', 'sakura-imgopt' ); ?></em>
								</label>
							</div>

							<div class="sufm-space-rows sufm-space-ppkgs">
								<div class="sufm-space-row is-head" aria-hidden="true">
									<span><?php esc_html_e( '套餐 ID', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '名称', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '容量(GB)', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '所需积分', 'sakura-imgopt' ); ?></span>
									<span><?php esc_html_e( '时长(天)', 'sakura-imgopt' ); ?></span>

									<span><?php esc_html_e( '上架 / 删除', 'sakura-imgopt' ); ?></span>
								</div>

								<input type="hidden" name="<?php echo esc_attr( $sufm_space_option . '[points_packages][_present]' ); ?>" value="1" />
								<?php foreach ( $sufm_space_ppkgs as $sufm_space_pp_i => $sufm_space_pp ) : ?>
									<?php echo $sufm_space_points_row( is_array( $sufm_space_pp ) ? $sufm_space_pp : array(), (string) $sufm_space_pp_i, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endforeach; ?>
							</div>

							<button type="button" class="btn btn--small sufm-space-add" data-sufm-plan-add="points"><?php esc_html_e( '＋ 添加积分套餐', 'sakura-imgopt' ); ?></button>
						</div>

						<p class="sufm-space-muted">
							<?php
							echo null === $sufm_space_cheapest
								? esc_html__( '暂无可用现金套餐，无法比较。', 'sakura-imgopt' )
								/* translators: %s: lowest price per GB */
								: esc_html( sprintf( __( '最便宜现金套餐：%s 元/GB', 'sakura-imgopt' ), number_format( (float) $sufm_space_cheapest, 2 ) ) );
							?>
							<?php if ( $sufm_space_far ) : ?>
								<strong><?php esc_html_e( '积分定价明显偏离现金价（超过 2 倍）。', 'sakura-imgopt' ); ?></strong>
							<?php endif; ?>
						</p>

						<p class="sufm-space-muted" data-sufm-space-points-today>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: redeemed GB today, 2: daily cap GB, 3: number of grants. */
									__( '今日已兑换 %1$s GB / 上限 %2$s GB（%3$d 笔）', 'sakura-imgopt' ),
									number_format( (float) $sufm_space_today['gb'], 2 ),
									number_format( (float) $sufm_space_pp_day, 2 ),
									(int) $sufm_space_today['count']
								)
							);
							?>
						</p>

						<p class="sufm-space-muted"><?php esc_html_e( '套餐 ID 自动分配（A 为现金、P 为积分），删除后不复用；容量填 0 或价格/积分空着的行在保存时会被丢弃。', 'sakura-imgopt' ); ?></p>
						<p class="sufm-space-error" data-sufm-space-pkg-error role="alert" aria-live="polite"></p>

						<div class="card-savebar">
							<?php
							submit_button(
								__( '保存套餐', 'sakura-imgopt' ),
								'btn btn--primary',
								'submit',
								false
							);
							?>
						</div>
					</form>

					<template data-sufm-plan-template="cash"><?php echo $sufm_space_cash_row( array(), '__KEY__', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
					<template data-sufm-plan-template="points"><?php echo $sufm_space_points_row( array(), '__KEY__', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
				</div>
			</section>

			<section class="card" data-sakura-card="space_grants" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'GRANTS', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '授权流水', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '最近 20 条授权记录，时间已按站点时区显示。到期时间留空表示永久。', 'sakura-imgopt' ); ?></p>
					</div>
					<div class="card-head-actions">
						<button type="button" class="btn" id="sufm-space-grants-refresh"><?php esc_html_e( '刷新', 'sakura-imgopt' ); ?></button>
					</div>
				</div>
				<div class="card-body">
					<div class="sufm-space-rows sufm-space-grants">
						<div class="sufm-space-row is-head" aria-hidden="true">
							<span><?php esc_html_e( '时间', 'sakura-imgopt' ); ?></span>
							<span><?php esc_html_e( '用户 / 套餐', 'sakura-imgopt' ); ?></span>
							<span><?php esc_html_e( '容量', 'sakura-imgopt' ); ?></span>
							<span><?php esc_html_e( '到期', 'sakura-imgopt' ); ?></span>
							<span><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></span>
							<span><?php esc_html_e( '操作', 'sakura-imgopt' ); ?></span>
						</div>
					</div>
					<div id="sufm-space-grants" data-sufm-space-grants>
						<p class="sufm-space-muted" data-sufm-space-grants-empty><?php esc_html_e( '暂无授权记录', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
			</section>

			<section class="card" data-sakura-card="space_reconcile" data-sakura-span="12">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'RECONCILE', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '对账与重试', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '「检查差异」只读，找出已付款但没有授权的子比订单；「修复差异」按订单快照补发，幂等（同一订单不会重复授予）。', 'sakura-imgopt' ); ?></p>
					</div>
					<div class="card-head-actions">
						<button type="button" class="btn" id="sufm-space-reconcile-check"><?php esc_html_e( '检查差异', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn" id="sufm-space-reconcile-apply" disabled="disabled"><?php esc_html_e( '修复差异', 'sakura-imgopt' ); ?></button>
					</div>
				</div>
				<div class="card-body">
					<p class="sufm-space-reconcile-summary" data-sufm-space-reconcile-summary aria-live="polite"><?php esc_html_e( '尚未检查。点击「检查差异」比对已付款订单与授权记录。', 'sakura-imgopt' ); ?></p>
					<div id="sufm-space-reconcile-list" data-sufm-space-reconcile-list></div>
				</div>
			</section>

		</div>
	</div>
</div>
