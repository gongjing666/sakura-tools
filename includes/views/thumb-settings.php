<?php
/**
 * Thumbnail v2 settings page.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string,mixed> $options Resolved options.
 * @var bool $is_zibll Theme detection.
 * @var string $mode Effective provider mode.
 * @var array<string,mixed> $stats Stats summary.
 * @var string $host Media host.
 * @var array<string,mixed> $cache_report Last cache flush report.
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$size_labels = \Sakura\ImageOptimizer\Thumbnail\Size_Catalog::configured_labels( $options['sizes'] );
$styles      = isset( $options['style_map'] ) && is_array( $options['style_map'] ) ? $options['style_map'] : array();

$mode_labels = array(
	'auto'   => __( '自动（按云存储能力推断）', 'sakura-imgopt' ),
	'style'  => __( '命名样式（缤纷云 / 多吉云）', 'sakura-imgopt' ),
	'params' => __( '宽高参数（?w=&h=）', 'sakura-imgopt' ),
	'native' => __( 'WordPress 自带尺寸', 'sakura-imgopt' ),
	'none'   => __( '不处理', 'sakura-imgopt' ),
);
$engine_labels = array(
	'url'  => __( '改写图片地址（推荐）', 'sakura-imgopt' ),
	'html' => __( '整页替换（旧版方式，较慢）', 'sakura-imgopt' ),
	'off'  => __( '关闭', 'sakura-imgopt' ),
);
$mode_short = array(
	'auto'   => __( '自动', 'sakura-imgopt' ),
	'style'  => __( '命名样式', 'sakura-imgopt' ),
	'params' => __( '宽高参数', 'sakura-imgopt' ),
	'native' => __( '自带尺寸', 'sakura-imgopt' ),
	'none'   => __( '不处理', 'sakura-imgopt' ),
);
$cache_time = ! empty( $cache_report['time'] )
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
$cache_state = (string) ( $cache_report['cache_state'] ?? 'unsupported' );
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

$checkbox_control = static function ( string $name, string $text, bool $checked ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
	echo '<label class="check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ' />';
	echo '<span class="check__box" aria-hidden="true"></span><span class="check__text">' . esc_html( $text ) . '</span></label>';
};

$form = static function ( string $card ) {
	return array(
		'id'           => 'sakura-imgopt-oss-' . $card,
		'option_group' => 'sakura_imgopt_oss_group',
		'markers'      => 'option_fields',
		'option'       => 'sakura_imgopt_oss_thumb',
		'hidden'       => array( 'sakura_imgopt_oss_thumb[schema]' => '2' ),
	);
};

UI::page(
	array(
		'id'      => 'image-thumb',
		'module'  => 'image',
		'tab'     => 'thumb',
		'eyebrow' => 'THUMBNAIL URL ENGINE / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '缩略图优化', 'sakura-imgopt' ),
		'lede'    => __( '把 WordPress 的图片尺寸换成云存储能识别的命名样式；只按显示位置决定是否启用，不依赖主题的样式名，也不再缓冲整个页面。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '缩略图引擎', 'sakura-imgopt' ),
			'value' => '' === $host ? __( '未找到图片域名', 'sakura-imgopt' ) : $host,
			'sub'   => ( $mode_short[ $mode ] ?? $mode ),
			'tone'  => ! empty( $options['enabled'] ) ? 'ok' : 'neutral',
		),
	),
	static function () use ( $options, $is_zibll, $mode, $stats, $mode_labels, $engine_labels, $cache_time, $opcache_label, $cache_backend, $cache_state_label, $cache_groups_text, $cache_note, $size_labels, $styles, $checkbox_control, $form ) {

		if ( ! $is_zibll ) {
			UI::alert( __( '当前主题不是子比（Zibll）主题；命名样式仍可使用，但请自己检查前台效果。', 'sakura-imgopt' ), 'info', __( '主题提示', 'sakura-imgopt' ) );
		}
		if ( ! empty( $options['enabled'] ) && 'url' === $options['engine'] && 'none' === $mode ) {
			UI::alert( __( '已启用地址改写，但当前实际没有生效：请把方式改为「命名样式」或「宽高参数」，或补上图片域名。', 'sakura-imgopt' ), 'warning' );
		}
		?>
		<div class="card-grid" data-sakura-layout="thumb" data-sakura-group="main">
			<?php
			UI::card(
				array(
					'id'          => 'switches',
					'kicker'      => 'Engine & Provider',
					'title'       => __( '总开关与处理方式', 'sakura-imgopt' ),
					'description' => __( '「改写图片地址」性能更好，是推荐方式；「整页替换」只用于临时回滚。', 'sakura-imgopt' ),
					'form'        => $form( 'switches' ),
					'save'        => __( '保存设置', 'sakura-imgopt' ),
				),
				static function () use ( $options, $engine_labels, $mode_labels, $checkbox_control ) {
					?>
					<div class="field-list">
						<?php
						UI::field(
							array(
								'type'    => 'custom',
								'label'   => __( '启用', 'sakura-imgopt' ),
								'full'    => true,
								'control' => static function () use ( $options, $checkbox_control ) {
									$checkbox_control( 'sakura_imgopt_oss_thumb[enabled]', __( '启用缩略图 URL 改写', 'sakura-imgopt' ), ! empty( $options['enabled'] ) );
								},
							)
						);
						UI::field(
							array(
								'type'    => 'select',
								'name'    => 'sakura_imgopt_oss_thumb[engine]',
								'id'      => 'sakura-thumb-engine',
								'label'   => __( '处理方式', 'sakura-imgopt' ),
								'value'   => $options['engine'],
								'options' => $engine_labels,
								'hint'    => __( '「整页替换」会缓冲整个页面，只用于确认新版效果前的临时回滚。', 'sakura-imgopt' ),
							)
						);
						UI::field(
							array(
								'type'    => 'select',
								'name'    => 'sakura_imgopt_oss_thumb[provider_mode]',
								'id'      => 'sakura-thumb-mode',
								'label'   => __( '地址改写方式', 'sakura-imgopt' ),
								'value'   => $options['provider_mode'],
								'options' => $mode_labels,
								'hint'    => __( '自动模式按当前云存储支持的能力推断：厂商支持命名样式且填了样式名就用命名样式，否则用宽高参数，两者都不支持就落「不处理」。多吉云用宽高参数时，CDN 加速域名的缓存规则里必须关掉「忽略参数」，否则参数会被丢掉、返回原图。', 'sakura-imgopt' ),
							)
						);
						UI::field(
							array(
								'type'        => 'number',
								'name'        => 'sakura_imgopt_oss_thumb[dpr]',
								'id'          => 'sakura-thumb-dpr',
								'label'       => __( '高分屏倍率上限', 'sakura-imgopt' ),
								'value'       => (string) $options['dpr'],
								'min'         => 1,
								'max'         => 3,
								'step'        => '0.1',
								'input_class' => 'small-text',
								'hint'        => __( '只用于 WordPress 没有登记的尺寸：输出宽度 = 请求宽度 × 倍率，且不超过原图宽度。', 'sakura-imgopt' ),
							)
						);
						?>
					</div>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'scopes',
					'kicker'      => 'Display Scenes',
					'title'       => __( '生效位置', 'sakura-imgopt' ),
					'description' => __( '这里只控制「在哪些位置生效」；具体尺寸在下面的「按位置指定尺寸」里设置。', 'sakura-imgopt' ),
					'form'        => $form( 'scopes' ),
					'save'        => __( '保存设置', 'sakura-imgopt' ),
				),
				static function () use ( $options, $checkbox_control ) {
					$scope_labels = array(
						'archive'  => __( '归档 / 首页 / 搜索列表', 'sakura-imgopt' ),
						'sidebar'  => __( '侧边栏小工具', 'sakura-imgopt' ),
						'singular' => __( '单篇 / 页面正文（含正文图）', 'sakura-imgopt' ),
						'other'    => __( '其它位置', 'sakura-imgopt' ),
					);
					echo '<div class="check-grid">';
					foreach ( $scope_labels as $scope => $label ) {
						$checkbox_control( 'sakura_imgopt_oss_thumb[scopes][' . $scope . ']', $label, ! empty( $options['scopes'][ $scope ] ) );
					}
					echo '</div>';
					echo '<p class="field__hint">' . esc_html__( '正文图片和单篇文章共用这个开关；正文尺寸可以单独在「按位置指定尺寸」里指定。', 'sakura-imgopt' ) . '</p>';
				}
			);

			UI::card(
				array(
					'id'          => 'scenes',
					'kicker'      => 'Scene Sizes',
					'title'       => __( '按位置指定尺寸', 'sakura-imgopt' ),
					'description' => __( '不同主题、不同区域请求的尺寸并不一样。这里让文章缩略图、侧边栏和正文各用一个尺寸；留空则跟随主题请求。', 'sakura-imgopt' ),
					'form'        => $form( 'scenes' ),
					'save'        => __( '保存设置', 'sakura-imgopt' ),
				),
				static function () use ( $options, $size_labels ) {
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
					$scene_options = array( '' => __( '跟随主题请求（不覆盖）', 'sakura-imgopt' ) );
					foreach ( $options['sizes'] as $name => $row ) {
						$width  = (int) $row['width'];
						$height = (int) $row['height'];
						$desc   = $height > 0
							/* translators: 1: width, 2: height */
							? sprintf( __( '宽 %1$d × 高 %2$d', 'sakura-imgopt' ), $width, $height )
							/* translators: %d: width */
							: sprintf( __( '宽 %d', 'sakura-imgopt' ), $width );
						$scene_options[ $name ] = ( $size_labels[ $name ] ?? $name ) . ' · ' . $desc . ' · ' . $row['mode'];
					}
					echo '<div class="field-list">';
					foreach ( $scene_fields as $scene => $field ) {
						UI::field(
							array(
								'type'    => 'select',
								'name'    => 'sakura_imgopt_oss_thumb[scenes][' . $scene . ']',
								'id'      => 'sakura-thumb-scene-' . $scene,
								'label'   => $field['label'],
								'value'   => (string) ( $options['scenes'][ $scene ] ?? '' ),
								'options' => $scene_options,
								'hint'    => $field['hint'],
							)
						);
					}
					echo '</div>';
					echo '<p class="field__hint">' . esc_html__( '只有「生效位置」里对应的开关打开时才会生效；命名样式模式下，所选尺寸要先在下方「尺寸规格」里填好命名样式。缩略图和侧边栏会重写主题的缩略图缓存，保存后刷新前台即可看到变化。', 'sakura-imgopt' ) . '</p>';
				}
			);

			UI::card(
				array(
					'id'          => 'sizes',
					'kicker'      => 'Size Catalog',
					'title'       => __( '尺寸规格', 'sakura-imgopt' ),
					'description' => __( '「命名样式」只使用最后一列；「宽高参数」只使用宽度、高度、模式、位置和质量。', 'sakura-imgopt' ),
					'wide'        => true,
					'form'        => $form( 'sizes' ),
					'save'        => __( '保存设置', 'sakura-imgopt' ),
				),
				static function () use ( $options, $size_labels, $styles ) {
					?>
					<div class="table-scroll">
						<table class="table table--responsive sakura-thumb-size-table">
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
										<td data-label=""><span class="sakura-thumb-size-name"><strong><?php echo esc_html( $size_labels[ $name ] ?? $name ); ?></strong><code><?php echo esc_html( $name ); ?></code></span></td>
										<td data-label="<?php echo esc_attr__( '宽', 'sakura-imgopt' ); ?>"><input type="number" min="0" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][width]" value="<?php echo esc_attr( (string) $row['width'] ); ?>" class="small-text" /></td>
										<td data-label="<?php echo esc_attr__( '高', 'sakura-imgopt' ); ?>"><input type="number" min="0" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][height]" value="<?php echo esc_attr( (string) $row['height'] ); ?>" class="small-text" /></td>
										<td data-label="<?php echo esc_attr__( '模式', 'sakura-imgopt' ); ?>">
											<select name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][mode]">
												<?php foreach ( array( 'fit' => __( '等比缩放 fit', 'sakura-imgopt' ), 'crop' => __( '裁剪 crop', 'sakura-imgopt' ), 'pad' => __( '留白 pad', 'sakura-imgopt' ) ) as $value => $label ) : ?>
													<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td data-label="<?php echo esc_attr__( '位置', 'sakura-imgopt' ); ?>">
											<select name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][position]">
												<?php foreach ( array( 'auto', 'center', 'top', 'bottom', 'left', 'right' ) as $value ) : ?>
													<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['position'], $value ); ?>><?php echo esc_html( $value ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td data-label="<?php echo esc_attr__( '质量', 'sakura-imgopt' ); ?>"><input type="number" min="0" max="100" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][quality]" value="<?php echo esc_attr( (string) $row['quality'] ); ?>" class="small-text" /></td>
										<td data-label="<?php echo esc_attr__( '命名样式', 'sakura-imgopt' ); ?>"><input type="text" name="sakura_imgopt_oss_thumb[sizes][<?php echo esc_attr( $name ); ?>][style]" value="<?php echo esc_attr( (string) ( $styles[ $name ] ?? '' ) ); ?>" class="regular-text" pattern="/?[A-Za-z0-9][A-Za-z0-9_\-]{0,35}" placeholder="siomedium" /></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="field__hint"><?php echo esc_html__( '填控制台里那条图片处理规则的名称，留空表示这个尺寸不使用命名样式，命名样式模式下会被跳过。缤纷云填规则名本身（如 siomedium，地址里会变成 !style=siomedium）；多吉云的规则名带一个前导斜杠（如 /720wm，地址里会变成 a.jpg/720wm）。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'advanced',
					'kicker'      => 'Advanced',
					'title'       => __( '高级', 'sakura-imgopt' ),
					'description' => __( '兼容开关默认关闭；开启后会改变前台输出，请保存后刷新页面核对。', 'sakura-imgopt' ),
					'form'        => $form( 'advanced' ),
					'save'        => __( '保存设置', 'sakura-imgopt' ),
				),
				static function () use ( $options, $checkbox_control ) {
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
					echo '<div class="check-grid">';
					foreach ( $advanced_labels as $key => $label ) {
						$checkbox_control( 'sakura_imgopt_oss_thumb[advanced][' . $key . ']', $label, ! empty( $options['advanced'][ $key ] ) );
					}
					echo '</div>';
					UI::field(
						array(
							'type'  => 'textarea',
							'name'  => 'sakura_imgopt_oss_thumb[advanced][custom_provider_template]',
							'id'    => 'sakura-thumb-template',
							'label' => __( '自定义地址模板', 'sakura-imgopt' ),
							'value' => (string) $options['advanced']['custom_provider_template'],
							'full'  => true,
							'attrs' => array(
								'rows'        => 3,
								'placeholder' => '?x-oss-process=image/resize,w_{w}',
								'class'       => 'large-text code',
							),
							'hint'  => __( '使用其他服务商时填写。可用占位符：{url} 图片地址、{w} 宽、{h} 高、{mode} 模式、{pos} 位置、{q} 质量、{style} 命名样式。含 {url} 时按完整地址模板处理，否则作为后缀追加。', 'sakura-imgopt' ),
						)
					);
					echo '<p class="field__hint">' . esc_html__( '「子比主题备用图联动」会在读取主题设置时改写备用图地址，不修改主题文件，主题升级也不会丢。', 'sakura-imgopt' ) . '</p>';
				}
			);


			UI::card(
				array(
					'id'          => 'stats',
					'kicker'      => 'Last 14 Days',
					'title'       => __( '近 14 天统计', 'sakura-imgopt' ),
					'description' => __( '只记录前台实际发生的改写与跳过。', 'sakura-imgopt' ),
					'wide'        => true,
				),
				static function () use ( $stats ) {
					echo '<div class="kpis">';
					foreach ( array( __( '改写', 'sakura-imgopt' ) => $stats['rewrite'], __( '跳过', 'sakura-imgopt' ) => $stats['skip'], __( '有数据天数', 'sakura-imgopt' ) => $stats['days'] ) as $label => $value ) {
						echo '<div class="kpi"><span class="kpi__label">' . esc_html( $label ) . '</span><strong class="kpi__value">' . esc_html( (string) $value ) . '</strong></div>';
					}
					echo '</div>';
					if ( ! empty( $stats['by_size'] ) ) {
						echo '<ul class="list">';
						foreach ( $stats['by_size'] as $size => $count ) {
							echo '<li><code>' . esc_html( (string) $size ) . '</code><strong>' . esc_html( (string) $count ) . '</strong></li>';
						}
						echo '</ul>';
					} else {
						echo '<p class="field__hint">' . esc_html__( '前台还没有产生记录。', 'sakura-imgopt' ) . '</p>';
					}
				}
			);

			UI::card(
				array(
					'id'     => 'rollback',
					'kicker' => 'Rollback',
					'title'  => __( '回滚', 'sakura-imgopt' ),
				),
				static function () {
					echo '<p class="sakura-thumb-readonly">' . esc_html__( '把处理方式切到「整页替换」就回到旧版做法；旧设置会保留，不会被新版覆盖。', 'sakura-imgopt' ) . '</p>';
					echo '<p class="sakura-thumb-readonly">' . esc_html__( '保存设置会清理主题缩略图地址相关的缓存，不会清空整站缓存；PHP 缓存（OPcache）默认不重置。', 'sakura-imgopt' ) . '</p>';
				}
			);

			UI::card(
				array(
					'id'          => 'cache',
					'kicker'      => 'Cache Flush',
					'title'       => __( '缓存清理', 'sakura-imgopt' ),
					'description' => __( '每次保存设置自动执行，无需手动操作。', 'sakura-imgopt' ),
				),
				static function () use ( $cache_backend, $cache_state_label, $cache_groups_text, $opcache_label, $cache_note, $cache_time ) {
					echo '<ul class="list">';
					echo '<li><span>' . esc_html( $cache_backend ) . '</span><strong>' . esc_html( $cache_state_label ) . '</strong></li>';
					echo '<li><span>' . esc_html__( '相关缓存', 'sakura-imgopt' ) . '</span><strong>' . esc_html( $cache_groups_text ) . '</strong></li>';
					echo '<li><span>' . esc_html__( 'PHP 缓存（OPcache）', 'sakura-imgopt' ) . '</span><strong>' . esc_html( $opcache_label ) . '</strong></li>';
					echo '</ul>';
					echo '<p class="field__hint">' . esc_html( $cache_note ) . '</p>';
					echo '<p class="field__hint">' . esc_html( sprintf( /* translators: %s: last cleanup time */ __( '最近清理：%s（站点时间）', 'sakura-imgopt' ), $cache_time ) ) . '</p>';
					echo '<p class="field__hint">' . esc_html__( '如果开启了重置又显示「重置失败」，通常是主机禁用了 opcache_reset；可让运维在 PHP 层面重载。', 'sakura-imgopt' ) . '</p>';
				}
			);
			?>
		</div>
		<?php
	}
);
