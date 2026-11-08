<?php
/**
 * View partial. Included from a settings/media method; uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $options */
/** @var bool $is_zibll */

/** @var \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module $this */

$disabled_attr = $is_zibll ? '' : ' disabled="disabled"';
$section_class = $is_zibll ? '' : '';
$sizes         = array(
	'thumbnail' => __( '小图 thumbnail', 'sakura-imgopt' ),
	'medium'    => __( '中图 medium', 'sakura-imgopt' ),
	'large'     => __( '大图 large', 'sakura-imgopt' ),
);
$scope_rows    = array(
	'list'    => array(
		'label' => __( '文章列表模式', 'sakura-imgopt' ),
		'desc'  => __( '仅匹配主文章列表中 posts-item list 的缩略图区域。', 'sakura-imgopt' ),
	),
	'card'    => array(
		'label' => __( '文章卡片 / UI 模式', 'sakura-imgopt' ),
		'desc'  => __( '仅匹配主文章列表中 posts-item card 的缩略图区域。', 'sakura-imgopt' ),
	),
	'sidebar' => array(
		'label' => __( '侧边栏小工具', 'sakura-imgopt' ),
		'desc'  => __( '仅匹配真实 .sidebar 容器及确认挂载在侧边栏位置的 Ajax 小工具。', 'sakura-imgopt' ),
	),
);
settings_errors( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY );
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<header class="topbar">
			<div class="brand">
				<span class="brand-mark">S</span>
				<strong>樱花工具箱</strong>
				<span class="path"><span class="slash">/</span> <?php esc_html_e( '缩略图优化', 'sakura-imgopt' ); ?></span>
			</div>
			<div class="top-actions">
				<span class="conn-pill"><span class="dot"></span> <?php echo esc_html( ! empty( $options['enabled'] ) ? __( '规则已开启', 'sakura-imgopt' ) : __( '规则未开启', 'sakura-imgopt' ) ); ?></span>
				<a class="btn btn--small" href="<?php echo esc_url( admin_url( 'admin.php?page=sakura-tools-optimize' ) ); ?>"><?php esc_html_e( '图像优化', 'sakura-imgopt' ); ?></a>
			</div>
		</header>

		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">THUMBNAIL RULES / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php esc_html_e( '缩略图优化', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '只处理明确授权的列表、卡片与侧边栏缩略图区域；正文、封面、广告和页头页脚图片不会被接管。', 'sakura-imgopt' ); ?></p>
				<div class="hero-actions">
					<button class="btn btn--primary" type="submit" form="sakura-imgopt-oss-form"><?php esc_html_e( '保存设置', 'sakura-imgopt' ); ?></button>
				</div>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top"><span class="hero-status-label"><span class="dot"></span> <?php echo esc_html( ! empty( $options['enabled'] ) ? __( '已开启', 'sakura-imgopt' ) : __( '未开启', 'sakura-imgopt' ) ); ?></span></div>
				<div class="hero-status-main"><strong><?php echo esc_html( $options['oss_host'] ? $options['oss_host'] : __( '未填写域名', 'sakura-imgopt' ) ); ?></strong></div>
				<div class="hero-status-sub"><?php esc_html_e( '按 URL 主机名精确匹配', 'sakura-imgopt' ); ?></div>
			</aside>
		</section>

		<form id="sakura-imgopt-oss-form" method="post" action="options.php">
			<?php settings_fields( 'sakura_imgopt_oss_group' ); ?>
			<div class="compact-cards">
				<section class="card card--wide">
					<div class="card-head"><div><p class="section-kicker">Scope & Rules</p><h2><?php esc_html_e( '作用域与规则', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '只有位于授权容器内的图片才会被改写。', 'sakura-imgopt' ); ?></p></div></div>
					<div class="card-body">
<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( '启用', 'sakura-imgopt' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $options['enabled'], 1 ); ?>>
								<?php echo esc_html__( '开启缩略图参数拼接', 'sakura-imgopt' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( '侧边栏小工具兜底', 'sakura-imgopt' ); ?><br><small><?php echo esc_html__( '子比主题专用', 'sakura-imgopt' ); ?></small></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[sidebar_enabled]" value="1" <?php checked( $is_zibll && ! empty( $options['sidebar_enabled'] ) ); ?><?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> >
								<?php echo esc_html__( '允许处理真实侧边栏容器中的小工具图片', 'sakura-imgopt' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( '仅识别侧边栏位置，不处理顶部/底部全宽小工具、主内容区小工具或顶部多功能组件。', 'sakura-imgopt' ); ?></p>
							<?php if ( ! $is_zibll ) : ?>
								<p class="description" style="color:#b32d2e;"><strong><?php echo esc_html__( '当前不是子比主题：侧边栏 Ajax 兜底停用；主内容区仍可按下方作用域 class 使用。', 'sakura-imgopt' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sakura-imgopt-oss-host"><?php echo esc_html__( 'OSS 域名', 'sakura-imgopt' ); ?></label></th>
						<td>
							<input id="sakura-imgopt-oss-host" type="text" class="regular-text" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[oss_host]" value="<?php echo esc_attr( $options['oss_host'] ); ?>" placeholder="your-oss.example.com">
							<p class="description"><?php echo esc_html__( '必填。按 URL 的精确主机名匹配，不再使用模糊字符串匹配。', 'sakura-imgopt' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sakura-imgopt-oss-pos"><?php echo esc_html__( '裁剪保留位置 (pos)', 'sakura-imgopt' ); ?></label></th>
						<td>
							<select id="sakura-imgopt-oss-pos" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[pos]">
								<?php
								$positions = array(
									'auto'   => __( '智能(auto)', 'sakura-imgopt' ),
									'center' => __( '居中', 'sakura-imgopt' ),
									'top'    => __( '上', 'sakura-imgopt' ),
									'bottom' => __( '下', 'sakura-imgopt' ),
									'left'   => __( '左', 'sakura-imgopt' ),
									'right'  => __( '右', 'sakura-imgopt' ),
								);
								foreach ( $positions as $value => $label ) {
									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $options['pos'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<p class="description"><?php echo esc_html__( '仅在宽和高都存在且启用裁剪时生效。', 'sakura-imgopt' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( '作用域 class（高级）', 'sakura-imgopt' ); ?></th>
						<td>
							<?php
							$scope_class_fields = array(
								'row_classes'     => array( __( '列表行容器', 'sakura-imgopt' ), 'posts-row' ),
								'post_classes'    => array( __( '单条内容', 'sakura-imgopt' ), 'posts-item,ajax-item' ),
								'thumb_classes'   => array( __( '缩略图区域', 'sakura-imgopt' ), 'item-thumbnail,thumb-items' ),
								'sidebar_classes' => array( __( '侧边栏容器', 'sakura-imgopt' ), 'sidebar' ),
							);
							foreach ( $scope_class_fields as $field => $meta ) :
								?>
								<label style="display:inline-block; margin:0 14px 8px 0;">
									<span class="sakura-imgopt-inline-label"><?php echo esc_html( $meta[0] ); ?></span>
									<input type="text" class="regular-text" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $options[ $field ] ); ?>" placeholder="<?php echo esc_attr( $meta[1] ); ?>" style="width:180px;">
								</label>
							<?php endforeach; ?>
							<p class="description"><?php echo esc_html__( '逗号分隔。默认适配子比主题；其它主题请填写包裹图片的列表/条目/缩略图容器 class。图片必须位于这些容器内才会被改写，避免误改头像、Logo 等站点图片。', 'sakura-imgopt' ); ?></p>
						</td>
					</tr>
				</table>					</div>
				</section>
				<section class="card">
					<div class="card-head"><div><p class="section-kicker">Display Scenes</p><h2><?php esc_html_e( '按显示场景设置参数', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '每个场景可选基础预设或自定义参数，互不联动。', 'sakura-imgopt' ); ?></p></div></div>
					<div class="card-body">
<table class="form-table" role="presentation">
					<?php foreach ( $scope_rows as $scope => $config ) : ?>
						<?php
						$size_field   = 'fallback_' . $scope . '_size';
						$custom_field = 'fallback_' . $scope . '_custom';
						?>
						<tr class="<?php echo 'sidebar' === $scope ? esc_attr( $section_class ) : ''; ?>">
							<th scope="row"><?php echo esc_html( $config['label'] ); ?></th>
							<td>
								<label>
									<span><?php echo esc_html__( '基础预设', 'sakura-imgopt' ); ?></span>
									<select name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $size_field ); ?>]"<?php echo 'sidebar' === $scope ? $disabled_attr : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
										<?php foreach ( $sizes as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options[ $size_field ], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<br><br>
								<label>
									<span><?php echo esc_html__( '场景自定义', 'sakura-imgopt' ); ?></span>
									<input type="text" class="regular-text sakura-imgopt-custom-input" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $custom_field ); ?>]" value="<?php echo esc_attr( $options[ $custom_field ] ); ?>" placeholder="<?php echo esc_attr__( '例如 ?fmt=webp&amp;w=500&amp;mode=clip', 'sakura-imgopt' ); ?>"<?php echo 'sidebar' === $scope ? $disabled_attr : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								</label>
								<p class="description"><?php echo esc_html( $config['desc'] ); ?></p>
								<p class="description"><?php echo esc_html__( '支持 ?query=params 或 !style=name；留空时使用所选基础预设。', 'sakura-imgopt' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>					</div>
				</section>
				<section class="card">
					<div class="card-head"><div><p class="section-kicker">Presets</p><h2><?php esc_html_e( '基础尺寸预设', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '宽/高为 0 表示不限制；自定义参数覆盖宽高裁剪。', 'sakura-imgopt' ); ?></p></div></div>
					<div class="card-body">
<table class="form-table" role="presentation">
					<?php
					$size_rows = array(
						'thumbnail' => array(
							'label'  => __( '小图 thumbnail', 'sakura-imgopt' ),
							'prefix' => 'thumb',
						),
						'medium'    => array(
							'label'  => __( '中图 medium', 'sakura-imgopt' ),
							'prefix' => 'medium',
						),
						'large'     => array(
							'label'  => __( '大图 large', 'sakura-imgopt' ),
							'prefix' => 'large',
						),
					);
					foreach ( $size_rows as $row ) :
						$prefix = $row['prefix'];
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
							<td>
								<label>
									<?php echo esc_html__( '宽', 'sakura-imgopt' ); ?>
									<input type="number" min="0" class="sakura-imgopt-dimension-input" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $prefix ); ?>_w]" value="<?php echo esc_attr( $options[ $prefix . '_w' ] ); ?>">
								</label>
								<label>
									<?php echo esc_html__( '高', 'sakura-imgopt' ); ?>
									<input type="number" min="0" class="sakura-imgopt-dimension-input" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $prefix ); ?>_h]" value="<?php echo esc_attr( $options[ $prefix . '_h' ] ); ?>">
								</label>
								<label class="sakura-imgopt-check-label">
									<input type="checkbox" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $prefix ); ?>_crop]" value="1" <?php checked( $options[ $prefix . '_crop' ], 1 ); ?>>
									<?php echo esc_html__( '裁剪', 'sakura-imgopt' ); ?>
								</label>
								<br><br>
								<label>
									<?php echo esc_html__( '预设自定义', 'sakura-imgopt' ); ?>
									<input type="text" class="regular-text sakura-imgopt-preset-custom" name="<?php echo esc_attr( \Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::OPTION_KEY ); ?>[<?php echo esc_attr( $prefix ); ?>_custom]" value="<?php echo esc_attr( $options[ $prefix . '_custom' ] ); ?>" placeholder="<?php echo esc_attr__( '填写后忽略该预设的宽高裁剪', 'sakura-imgopt' ); ?>">
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>					</div>
				</section>
			</div>
			<div class="card-actions">
				<?php submit_button( __( '保存设置', 'sakura-imgopt' ), 'primary', 'submit', false, array( 'class' => 'btn btn--primary' ) ); ?>
			</div>
		</form>
	</div>
</div>
