<?php
/**
 * Thumbnail v2 settings page.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string,mixed>                $options Resolved options.
 * @var bool                               $is_zibll Theme detection.
 * @var \Sakura\ImageOptimizer\Thumbnail\Provider_Status $status Probe status.
 * @var string                             $mode Effective provider mode.
 * @var array<string,mixed>                $stats Stats summary.
 * @var string                             $host Media host.
 * @var array<string,mixed>                $cache_report Last cache flush report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$size_labels = \Sakura\ImageOptimizer\Thumbnail\Size_Catalog::configured_labels( $options['sizes'] );
$styles      = isset( $options['style_map'] ) && is_array( $options['style_map'] ) ? $options['style_map'] : array();
$probe_flag  = isset( $_GET['sakura_probe'] ) ? sanitize_key( wp_unslash( $_GET['sakura_probe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$mode_labels = array(
	'auto'   => __( '自动（按测试结果切换）', 'sakura-imgopt' ),
	'style'  => __( '命名样式（仅缤纷云）', 'sakura-imgopt' ),
	'params' => __( '宽高参数（?w=&h=）', 'sakura-imgopt' ),
	'native' => __( 'WordPress 自带尺寸', 'sakura-imgopt' ),
	'none'   => __( '不处理', 'sakura-imgopt' ),
);
$engine_labels = array(
	'url'  => __( '改写图片地址（推荐）', 'sakura-imgopt' ),
	'html' => __( '整页替换（旧版方式，较慢）', 'sakura-imgopt' ),
	'off'  => __( '关闭', 'sakura-imgopt' ),
);
$probe_class   = $status->is_ok() ? 'badge--ok' : ( 'blocked' === $status->state ? 'badge--bad' : 'badge--warn' );
$probe_label   = $status->is_ok() ? __( '测试通过', 'sakura-imgopt' ) : __( '待测试', 'sakura-imgopt' );
$mode_short    = array(
	'auto'   => __( '自动', 'sakura-imgopt' ),
	'style'  => __( '命名样式', 'sakura-imgopt' ),
	'params' => __( '宽高参数', 'sakura-imgopt' ),
	'native' => __( '自带尺寸', 'sakura-imgopt' ),
	'none'   => __( '不处理', 'sakura-imgopt' ),
);
$checked_text  = $status->checked_at > 0
	? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $status->checked_at ) : gmdate( 'Y-m-d H:i:s', $status->checked_at ) )
	: __( '尚未测试', 'sakura-imgopt' );
$cache_time    = ! empty( $cache_report['time'] )
	? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', (int) $cache_report['time'] ) : gmdate( 'Y-m-d H:i:s', (int) $cache_report['time'] ) )
	: __( '尚未清理', 'sakura-imgopt' );
$opcache_labels = array(
	'ok'          => __( '已重置', 'sakura-imgopt' ),
	'failed'      => __( '重置失败', 'sakura-imgopt' ),
	'unavailable' => __( '不可用', 'sakura-imgopt' ),
	'skipped'     => __( '已跳过（默认不重置）', 'sakura-imgopt' ),
	'skipped_cli' => __( '命令行下跳过', 'sakura-imgopt' ),
);
$opcache_label = $opcache_labels[ (string) ( $cache_report['opcache'] ?? 'unavailable' ) ] ?? __( '不可用', 'sakura-imgopt' );
$cache_backend = (string) ( $cache_report['backend_label'] ?? __( '对象缓存', 'sakura-imgopt' ) );
$cache_state   = (string) ( $cache_report['cache_state'] ?? 'unsupported' );
$cache_state_labels = array(
	'ok'          => __( '已清理', 'sakura-imgopt' ),
	'partial'     => __( '部分清理', 'sakura-imgopt' ),
	'failed'      => __( '清理失败', 'sakura-imgopt' ),
	'unsupported' => __( '不支持分组清理', 'sakura-imgopt' ),
	'none'        => __( '无需清理', 'sakura-imgopt' ),
);
$cache_state_label = $cache_state_labels[ $cache_state ] ?? __( '未知', 'sakura-imgopt' );
$cache_groups_text = ! empty( $cache_report['group_flush'] )
	/* translators: 1: cleaned cache groups, 2: requested cache groups */
	? sprintf( __( '已清理 %1$d / %2$d 组相关缓存', 'sakura-imgopt' ), (int) ( $cache_report['theme_groups'] ?? 0 ), (int) ( $cache_report['requested_groups'] ?? 0 ) )
	: __( '未清空', 'sakura-imgopt' );
$cache_note = ! empty( $cache_report['group_flush'] )
	? __( '只清理本插件用到的缩略图地址缓存和主题的社区列表图缓存，不会清空整站缓存。', 'sakura-imgopt' )
	: ( 'none' === (string) ( $cache_report['backend'] ?? '' )
		? __( '当前没有启用对象缓存，无需清理。', 'sakura-imgopt' )
		: __( '当前的对象缓存不支持按组清理；插件不会清空整站缓存，请手动清理，或换用支持分组清理的缓存服务。', 'sakura-imgopt' ) );
$render_card_open = static function ( string $card ) {
	echo '<form id="sakura-imgopt-oss-' . esc_attr( $card ) . '" method="post" action="options.php">';
	settings_fields( 'sakura_imgopt_oss_group' );
	echo '<input type="hidden" name="sakura_imgopt_oss_thumb[schema]" value="2" />';
	\Sakura\ImageOptimizer\Admin\Card_Form::option_fields( 'sakura_imgopt_oss_thumb', $card );
};
$render_card_close = static function () {
	echo '</form>';
};
$render_card_save = static function () {
	echo '<div class="card-savebar"><button type="submit" class="btn btn--primary">' . esc_html__( '保存设置', 'sakura-imgopt' ) . '</button></div>';
};
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'image',
			'thumb',
			array(
				'status'      => ! empty( $options['enabled'] )
					? __( '规则已开启', 'sakura-imgopt' )
					: __( '规则未开启', 'sakura-imgopt' ),
				'status_tone' => ! empty( $options['enabled'] ) ? 'ok' : 'off',
			)
		);
		?>

		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">THUMBNAIL URL ENGINE / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php echo esc_html__( '缩略图优化', 'sakura-imgopt' ); ?></h1>
				<p class="lede">
					<?php echo esc_html__( '把 WordPress 的图片尺寸换成云存储能识别的命名样式；只按显示位置决定是否启用，不依赖主题的样式名，也不再缓冲整个页面。', 'sakura-imgopt' ); ?>
					<?php if ( ! $is_zibll ) : ?>
						<br /><strong><?php echo esc_html__( '当前主题不是子比（Zibll）主题；命名样式仍可使用，但请自己检查前台效果。', 'sakura-imgopt' ); ?></strong>
					<?php endif; ?>
				</p>
				<div class="hero-actions">
					<a class="btn" href="#sakura-thumb-probe"><?php echo esc_html__( '连接测试', 'sakura-imgopt' ); ?></a>
					<span class="hero-note"><?php echo esc_html__( '地址改写 · 不缓冲整页 · 保存后清理缓存', 'sakura-imgopt' ); ?></span>
				</div>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top">
					<span class="hero-status-label<?php echo $status->is_ok() ? '' : ' is-warn'; ?>"><span class="dot"></span> <?php echo esc_html( $status->is_ok() ? __( '连接正常', 'sakura-imgopt' ) : __( '等待测试', 'sakura-imgopt' ) ); ?></span>
					<span class="badge <?php echo esc_attr( $probe_class ); ?>"><?php echo esc_html( $probe_label ); ?></span>
				</div>
				<div class="hero-status-main">
					<strong><?php echo esc_html( '' === $host ? __( '未找到图片域名', 'sakura-imgopt' ) : $host ); ?></strong>
					<span><?php echo esc_html( $mode_short[ $mode ] ?? $mode ); ?></span>
				</div>
				<div class="hero-status-sub"><?php /* translators: %s: last probe time */ echo esc_html( sprintf( __( '最近探测：%s（站点时间）', 'sakura-imgopt' ), $checked_text ) ); ?></div>
				<div class="hero-status-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( $status->is_ok() ? '100' : '34' ); ?>%"></span></div>
				<div class="hero-status-sub"><?php echo esc_html__( '保存设置会清理主题缩略图地址缓存；PHP 缓存（OPcache）默认不重置。', 'sakura-imgopt' ); ?></div>
			</aside>
		</section>

		<?php settings_errors(); ?>
		<?php if ( 'ok' === $probe_flag ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( '连接测试成功，结果已保存。', 'sakura-imgopt' ); ?></p></div>
		<?php elseif ( 'fail' === $probe_flag ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( '连接测试未通过，地址改写会保持「不处理」。请查看下面的测试结果。', 'sakura-imgopt' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $options['enabled'] ) && 'url' === $options['engine'] && 'none' === $mode ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html__( '已启用地址改写，但当前实际没有生效：请先完成连接测试，或把方式改为「命名样式」/「宽高参数」。', 'sakura-imgopt' ); ?></p></div>
		<?php endif; ?>

		<div class="dashboard-grid" data-sakura-layout="thumb" data-sakura-group="main" data-sakura-collect="deep">
			<div class="sakura-thumb-main" data-sakura-source="1">

				<section class="card" data-sakura-card="switches">
					<div class="card-head">
						<div>
							<p class="section-kicker">Engine &amp; Provider</p>
							<h2><?php echo esc_html__( '总开关与处理方式', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '「改写图片地址」性能更好，是推荐方式；「整页替换」只用于临时回滚。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<?php $render_card_open( 'switches' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__( '启用', 'sakura-imgopt' ); ?></th>
								<td>
									<input type="hidden" name="sakura_imgopt_oss_thumb[enabled]" value="0" />
									<label><input type="checkbox" name="sakura_imgopt_oss_thumb[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> /> <?php echo esc_html__( '启用缩略图 URL 改写', 'sakura-imgopt' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sakura-thumb-engine"><?php echo esc_html__( '处理方式', 'sakura-imgopt' ); ?></label></th>
								<td>
									<select id="sakura-thumb-engine" name="sakura_imgopt_oss_thumb[engine]">
										<?php foreach ( $engine_labels as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['engine'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php echo esc_html__( '「整页替换」会缓冲整个页面，只用于确认新版效果前的临时回滚。', 'sakura-imgopt' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sakura-thumb-mode"><?php echo esc_html__( '地址改写方式', 'sakura-imgopt' ); ?></label></th>
								<td>
									<select id="sakura-thumb-mode" name="sakura_imgopt_oss_thumb[provider_mode]">
										<?php foreach ( $mode_labels as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['provider_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php echo esc_html__( '自动模式只会使用测试通过的方式，不会假装成功。', 'sakura-imgopt' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sakura-thumb-dpr"><?php echo esc_html__( '高分屏倍率上限', 'sakura-imgopt' ); ?></label></th>
								<td>
									<input id="sakura-thumb-dpr" type="number" step="0.1" min="1" max="3" name="sakura_imgopt_oss_thumb[dpr]" value="<?php echo esc_attr( (string) $options['dpr'] ); ?>" class="small-text" />
									<p class="description"><?php echo esc_html__( '只用于 WordPress 没有登记的尺寸：输出宽度 = 请求宽度 × 倍率，且不超过原图宽度。', 'sakura-imgopt' ); ?></p>
								</td>
							</tr>
						</table>
						<?php $render_card_save(); ?>
						<?php $render_card_close(); ?>
					</div>
				</section>

				<section class="card" data-sakura-card="scopes">
					<div class="card-head">
						<div>
							<p class="section-kicker">Display Scenes</p>
							<h2><?php echo esc_html__( '生效位置', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '这里只控制「在哪些位置生效」；具体尺寸在下面的「按位置指定尺寸」里设置。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<?php $render_card_open( 'scopes' ); ?>
						<div class="sakura-thumb-checkgrid">
							<?php
							$scope_labels = array(
								'archive'  => __( '归档 / 首页 / 搜索列表', 'sakura-imgopt' ),
								'sidebar'  => __( '侧边栏小工具', 'sakura-imgopt' ),
								'singular' => __( '单篇 / 页面正文（含正文图）', 'sakura-imgopt' ),
								'other'    => __( '其它位置', 'sakura-imgopt' ),
							);
							foreach ( $scope_labels as $scope => $label ) :
								?>
								<label class="sakura-thumb-check">
									<input type="hidden" name="sakura_imgopt_oss_thumb[scopes][<?php echo esc_attr( $scope ); ?>]" value="0" />
									<input type="checkbox" name="sakura_imgopt_oss_thumb[scopes][<?php echo esc_attr( $scope ); ?>]" value="1" <?php checked( ! empty( $options['scopes'][ $scope ] ) ); ?> />
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php echo esc_html__( '正文图片和单篇文章共用这个开关；正文尺寸可以单独在「按位置指定尺寸」里指定。', 'sakura-imgopt' ); ?></p>
						<?php $render_card_save(); ?>
						<?php $render_card_close(); ?>
					</div>
				</section>

				<section class="card" data-sakura-card="scenes">
					<div class="card-head">
						<div>
							<p class="section-kicker">Scene Sizes</p>
							<h2><?php echo esc_html__( '按位置指定尺寸', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '不同主题、不同区域请求的尺寸并不一样。这里让文章缩略图、侧边栏和正文各用一个尺寸；留空则跟随主题请求。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<?php $render_card_open( 'scenes' ); ?>
						<?php
						$scene_fields = array(
							'thumb'   => array(
								'label' => __( '文章缩略图', 'sakura-imgopt' ),
								'hint'  => __( '列表、卡片、相关文章等位置。子比主题这里请求的是 thumbnail。', 'sakura-imgopt' ),
							),
							'sidebar' => array(
								'label' => __( '侧边栏', 'sakura-imgopt' ),
								'hint'  => __( '热榜、作者卡等小工具。子比主题这里请求的是 large，常被放大到 800px。', 'sakura-imgopt' ),
							),
							'content' => array(
								'label' => __( '正文图片', 'sakura-imgopt' ),
								'hint'  => __( '文章正文里的大图。留空时大图用 large、小图用 medium。', 'sakura-imgopt' ),
							),
						);
						?>
						<table class="form-table" role="presentation">
							<tbody>
								<?php foreach ( $scene_fields as $scene => $field ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
										<td>
											<select name="sakura_imgopt_oss_thumb[scenes][<?php echo esc_attr( $scene ); ?>]">
												<option value=""><?php echo esc_html__( '跟随主题请求（不覆盖）', 'sakura-imgopt' ); ?></option>
												<?php
												foreach ( $options['sizes'] as $name => $row ) :
													$width  = (int) $row['width'];
													$height = (int) $row['height'];
													$desc   = $height > 0
														/* translators: 1: width, 2: height */
														? sprintf( __( '宽 %1$d × 高 %2$d', 'sakura-imgopt' ), $width, $height )
														/* translators: %d: width */
														: sprintf( __( '宽 %d', 'sakura-imgopt' ), $width );
													?>
													<option value="<?php echo esc_attr( $name ); ?>" <?php selected( (string) ( $options['scenes'][ $scene ] ?? '' ), $name ); ?>>
														<?php echo esc_html( ( $size_labels[ $name ] ?? $name ) . ' · ' . $desc . ' · ' . $row['mode'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php echo esc_html( $field['hint'] ); ?></p>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php echo esc_html__( '只有「生效位置」里对应的开关打开时才会生效；命名样式模式下，所选尺寸要先在下方「尺寸规格」里填好命名样式。缩略图和侧边栏会重写主题的缩略图缓存，保存后刷新前台即可看到变化。', 'sakura-imgopt' ); ?></p>
						<?php $render_card_save(); ?>
						<?php $render_card_close(); ?>
					</div>
				</section>

				<section class="card" data-sakura-card="sizes">
					<div class="card-head">
						<div>
							<p class="section-kicker">Size Catalog</p>
							<h2><?php echo esc_html__( '尺寸规格', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '「命名样式」只使用最后一列；「宽高参数」只使用宽度、高度、模式、位置和质量。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<?php $render_card_open( 'sizes' ); ?>
						<div class="table-wrap">
							<table class="sakura-thumb-size-table">
								<thead>
									<tr>
										<th><?php echo esc_html__( '尺寸', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '宽', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '高', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '模式', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '位置', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '质量', 'sakura-imgopt' ); ?></th>
										<th><?php echo esc_html__( '命名样式', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $options['sizes'] as $name => $row ) : ?>
										<tr>
											<td>
												<strong><?php echo esc_html( $size_labels[ $name ] ?? $name ); ?></strong>
												<code><?php echo esc_html( $name ); ?></code>
											</td>
											<td><input type="number" min="0" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][width]" value="<?php echo esc_attr( (string) $row['width'] ); ?>" class="small-text" /></td>
											<td><input type="number" min="0" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][height]" value="<?php echo esc_attr( (string) $row['height'] ); ?>" class="small-text" /></td>
											<td>
												<select name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][mode]">
													<?php foreach ( array( 'fit' => __( '等比缩放 fit', 'sakura-imgopt' ), 'crop' => __( '裁剪 crop', 'sakura-imgopt' ), 'pad' => __( '留白 pad', 'sakura-imgopt' ) ) as $value => $label ) : ?>
														<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td>
												<select name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][position]">
													<?php foreach ( array( 'auto', 'center', 'top', 'bottom', 'left', 'right' ) as $value ) : ?>
														<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['position'], $value ); ?>><?php echo esc_html( $value ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td><input type="number" min="0" max="100" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][quality]" value="<?php echo esc_attr( (string) $row['quality'] ); ?>" class="small-text" /></td>
											<td><input type="text" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][style]" value="<?php echo esc_attr( (string) ( $styles[ $name ] ?? '' ) ); ?>" class="regular-text" pattern="[A-Za-z0-9]{1,36}" placeholder="siomedium" /></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p class="description"><?php echo esc_html__( '只能填 1 到 36 个字母或数字；留空表示这个尺寸不使用命名样式，命名样式模式下会被跳过。填写的名称要和缤纷云后台的尺寸名称一致。', 'sakura-imgopt' ); ?></p>
						<?php $render_card_save(); ?>
						<?php $render_card_close(); ?>
					</div>
				</section>

				<section class="card" data-sakura-card="advanced">
					<div class="card-head">
						<div>
							<p class="section-kicker">Advanced</p>
							<h2><?php echo esc_html__( '高级', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '兼容开关默认关闭；开启后会改变前台输出，请保存后刷新页面核对。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<?php $render_card_open( 'advanced' ); ?>
						<div class="sakura-thumb-checkgrid sakura-thumb-checkgrid--wide">
							<?php
							$advanced_labels = array(
								'native_fallback'  => __( '样式缺失时回退到 WordPress 自带尺寸', 'sakura-imgopt' ),
								'full_cap'         => __( '限制完整尺寸原图（默认关闭，风险较大）', 'sakura-imgopt' ),
								'content_images'   => __( '改写正文中的图片', 'sakura-imgopt' ),
								'theme_bridge'     => __( '子比主题备用图联动（读取主题设置，不用改主题文件）', 'sakura-imgopt' ),
								'rest'             => __( '改写接口返回的图片地址', 'sakura-imgopt' ),
								'animated'         => __( '允许处理 GIF（可能变成静态图）', 'sakura-imgopt' ),
								'unknown_fallback' => __( '未登记的尺寸按「请求宽 × 倍率」处理', 'sakura-imgopt' ),
								'stats'            => __( '记录改写与跳过的数量', 'sakura-imgopt' ),
							);
							foreach ( $advanced_labels as $key => $label ) :
								?>
								<label class="sakura-thumb-check">
									<input type="hidden" name="sakura_imgopt_oss_thumb[advanced][<?php echo esc_attr( $key ); ?>]" value="0" />
									<input type="checkbox" name="sakura_imgopt_oss_thumb[advanced][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $options['advanced'][ $key ] ) ); ?> />
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>

						<div class="sakura-thumb-field">
							<label for="sakura-thumb-template"><?php echo esc_html__( '自定义地址模板', 'sakura-imgopt' ); ?></label>
							<textarea id="sakura-thumb-template" name="sakura_imgopt_oss_thumb[advanced][custom_provider_template]" rows="3" class="large-text code" placeholder="?x-oss-process=image/resize,w_{w}"><?php echo esc_textarea( (string) $options['advanced']['custom_provider_template'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( '使用其他服务商时填写。可用占位符：{url} 图片地址、{w} 宽、{h} 高、{mode} 模式、{pos} 位置、{q} 质量、{style} 命名样式。含 {url} 时按完整地址模板处理，否则作为后缀追加。', 'sakura-imgopt' ); ?></p>
							<p class="description"><?php echo esc_html__( '「子比主题备用图联动」会在读取主题设置时改写备用图地址，不修改主题文件，主题升级也不会丢。', 'sakura-imgopt' ); ?></p>
						</div>
						<?php $render_card_save(); ?>
						<?php $render_card_close(); ?>
					</div>
				</section>
			</div>

			<aside class="side-stack" data-sakura-source="1">
				<section class="card" data-sakura-card="probe" id="sakura-thumb-probe">
					<div class="card-head">
						<div>
							<p class="section-kicker">Capability Probe</p>
							<h2><?php echo esc_html__( '连接测试', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '用一张真实图片检查云存储是否接受当前的地址写法。', 'sakura-imgopt' ); ?></p>
						</div>
						<span class="badge <?php echo esc_attr( $probe_class ); ?>"><span class="dot"></span> <?php echo esc_html( $status->label() ); ?></span>
					</div>
					<div class="card-body">
						<div class="stat-strip">
							<div class="stat">
								<span><?php echo esc_html__( '图片域名', 'sakura-imgopt' ); ?></span>
								<strong class="sakura-thumb-stat-text"><?php echo esc_html( '' === $host ? __( '未解析', 'sakura-imgopt' ) : $host ); ?></strong>
							</div>
							<div class="stat">
								<span><?php echo esc_html__( '当前生效', 'sakura-imgopt' ); ?></span>
								<strong class="sakura-thumb-stat-text"><?php echo esc_html( $mode ); ?></strong>
							</div>
						</div>
						<p class="description sakura-thumb-evidence"><?php echo esc_html( '' !== $status->evidence ? $status->evidence : __( '尚未测试。点击下面的按钮，用真实图片检查能否访问、文件大小和宽度是否符合预期。', 'sakura-imgopt' ) ); ?></p>
						<p class="description"><?php /* translators: %s: last probe time */ echo esc_html( sprintf( __( '最近探测：%s（站点时间）', 'sakura-imgopt' ), $checked_text ) ); ?></p>
						<div class="card-savebar">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sakura-thumb-probe-form">
								<input type="hidden" name="action" value="sakura_thumb_probe" />
								<?php wp_nonce_field( 'sakura_thumb_probe' ); ?>
								<button class="btn btn--primary" type="submit"><?php echo esc_html__( '立即测试', 'sakura-imgopt' ); ?></button>
							</form>
						</div>
					</div>
				</section>

				<section class="card" data-sakura-card="stats">
					<div class="card-head">
						<div>
							<p class="section-kicker">Last 14 Days</p>
							<h2><?php echo esc_html__( '近 14 天统计', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '只记录前台实际发生的改写与跳过。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<div class="stat-strip">
							<div class="stat">
								<span><?php echo esc_html__( '改写', 'sakura-imgopt' ); ?></span>
								<strong><?php echo esc_html( (string) $stats['rewrite'] ); ?></strong>
							</div>
							<div class="stat">
								<span><?php echo esc_html__( '跳过', 'sakura-imgopt' ); ?></span>
								<strong><?php echo esc_html( (string) $stats['skip'] ); ?></strong>
							</div>
							<div class="stat">
								<span><?php echo esc_html__( '有数据天数', 'sakura-imgopt' ); ?></span>
								<strong><?php echo esc_html( (string) $stats['days'] ); ?></strong>
							</div>
						</div>
						<?php if ( ! empty( $stats['by_size'] ) ) : ?>
							<ul class="sakura-thumb-list">
								<?php foreach ( $stats['by_size'] as $size => $count ) : ?>
									<li><code><?php echo esc_html( (string) $size ); ?></code><span><?php echo esc_html( (string) $count ); ?></span></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="description"><?php echo esc_html__( '前台还没有产生记录。', 'sakura-imgopt' ); ?></p>
						<?php endif; ?>
					</div>
				</section>

				<section class="card" data-sakura-card="rollback">
					<div class="card-head">
						<div>
							<p class="section-kicker">Rollback</p>
							<h2><?php echo esc_html__( '回滚', 'sakura-imgopt' ); ?></h2>
						</div>
					</div>
					<div class="card-body">
						<p class="description"><?php echo esc_html__( '把处理方式切到「整页替换」就回到旧版做法；旧设置会保留，不会被新版覆盖。', 'sakura-imgopt' ); ?></p>
						<p class="description"><?php echo esc_html__( '保存设置会清理主题缩略图地址相关的缓存，不会清空整站缓存；PHP 缓存（OPcache）默认不重置。', 'sakura-imgopt' ); ?></p>
					</div>
				</section>

				<section class="card" data-sakura-card="cache">
					<div class="card-head">
						<div>
							<p class="section-kicker">Cache Flush</p>
							<h2><?php echo esc_html__( '缓存清理', 'sakura-imgopt' ); ?></h2>
							<p><?php echo esc_html__( '每次保存设置自动执行，无需手动操作。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<ul class="sakura-thumb-list">
							<li><span><?php echo esc_html( $cache_backend ); ?></span><strong><?php echo esc_html( $cache_state_label ); ?></strong></li>
							<li><span><?php echo esc_html__( '相关缓存', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( $cache_groups_text ); ?></strong></li>
							<li><span><?php echo esc_html__( 'PHP 缓存（OPcache）', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( $opcache_label ); ?></strong></li>
						</ul>
						<p class="description"><?php echo esc_html( $cache_note ); ?></p>
						<p class="description"><?php /* translators: %s: last cleanup time */ echo esc_html( sprintf( __( '最近清理：%s（站点时间）', 'sakura-imgopt' ), $cache_time ) ); ?></p>
						<p class="description"><?php echo esc_html__( '如果开启了重置又显示「重置失败」，通常是主机禁用了 opcache_reset；可让运维在 PHP 层面重载。', 'sakura-imgopt' ); ?></p>
					</div>
				</section>
			</aside>
		</div>
	</div>
</div>
