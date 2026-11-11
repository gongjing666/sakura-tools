<?php
/**
 * Basic settings view: environment readout and the shared card-gap setting.
 *
 * Included from Basic_Settings_Module::render_page().
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $report Environment report.
 * @var array{gapX:int,gapY:int} $gaps Current site-wide card gaps.
 * @var string $gaps_option Option name used by the gap form.
 * @var int $gaps_max Maximum accepted gap in pixels.
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$php        = $report['php'];
$wordpress  = $report['wordpress'];
$engine     = $report['engine'];
$checks     = $report['checks'];
$summary    = $report['summary'];
$extensions = $report['extensions'];

$check_map = array();
foreach ( $checks as $check ) {
	$check_map[ (string) $check['key'] ] = $check;
}

$state_labels = array(
	'ok'   => __( '通过', 'sakura-imgopt' ),
	'warn' => __( '建议', 'sakura-imgopt' ),
	'fail' => __( '需要处理', 'sakura-imgopt' ),
);

$state_tone = static function ( string $state ): string {
	if ( 'ok' === $state ) {
		return 'ok';
	}
	if ( 'fail' === $state ) {
		return 'bad';
	}

	return 'warn';
};

$check_tone = static function ( string $key ) use ( $check_map, $state_tone ): string {
	$state = isset( $check_map[ $key ] ) ? (string) $check_map[ $key ]['state'] : 'warn';

	return $state_tone( $state );
};

$check_label = static function ( string $key ) use ( $check_map, $state_labels ): string {
	$state = isset( $check_map[ $key ] ) ? (string) $check_map[ $key ]['state'] : 'warn';

	return $state_labels[ $state ] ?? $state;
};

$total_checks = count( $checks );
$total_for_score = $total_checks > 0 ? $total_checks : 1;
$score        = (int) round( ( ( (int) $summary['pass'] + (int) $summary['warn'] * 0.5 ) / $total_for_score ) * 100 );
$page_tone    = (int) $summary['fail'] > 0 ? 'bad' : ( (int) $summary['warn'] > 0 ? 'warn' : 'ok' );
$status_text  = (int) $summary['fail'] > 0
	? __( '需要处理', 'sakura-imgopt' )
	: ( (int) $summary['warn'] > 0 ? __( '有建议', 'sakura-imgopt' ) : __( '环境可用', 'sakura-imgopt' ) );

$optimize_url = admin_url( 'admin.php?page=sakura-tools-optimize' );
$refresh_url  = admin_url( 'admin.php?page=sakura-tools' );

$memory_limit = (string) $php['memory_limit'];
$upload_limit = (string) $php['upload_limit'];
$post_limit   = (string) $php['post_limit'];
$time_limit   = (string) $php['max_execution_time'];
$memory_bytes = (int) $php['memory_limit_bytes'];
$upload_bytes = (int) ( $php['upload_limit_bytes'] ?? 0 );

$memory_warn = $memory_bytes >= 0 && $memory_bytes < 256 * 1024 * 1024;
$upload_warn = $upload_bytes >= 0 && $upload_bytes < 32 * 1024 * 1024;

$format_time = static function ( string $value ): string {
	if ( '' === $value ) {
		return __( '未知', 'sakura-imgopt' );
	}
	if ( '0' === $value ) {
		return __( '不限制', 'sakura-imgopt' );
	}

	return sprintf( /* translators: %s: seconds. */ __( '%s 秒', 'sakura-imgopt' ), $value );
};

$format_limit = static function ( string $value ): string {
	if ( '' === $value ) {
		return __( '未知', 'sakura-imgopt' );
	}
	if ( '-1' === $value ) {
		return __( '不限制', 'sakura-imgopt' );
	}

	return $value;
};

$runtime_rows = array(
	array(
		'label'  => __( 'PHP 版本', 'sakura-imgopt' ),
		'value'  => (string) $php['version'],
		'badges' => array(
			array(
				version_compare( (string) $php['version'], '8.2', '>=' ) ? __( '满足 8.2+', 'sakura-imgopt' ) : __( '低于 8.2', 'sakura-imgopt' ),
				$check_tone( 'php' ),
			),
		),
	),
	array(
		'label'  => __( '运行方式', 'sakura-imgopt' ),
		'value'  => (string) $php['sapi_label'],
		'muted'  => (string) $php['sapi'],
	),
	array(
		'label' => __( 'Web 服务器', 'sakura-imgopt' ),
		'value' => '' !== (string) $php['server_software'] ? (string) $php['server_software'] : __( '未知', 'sakura-imgopt' ),
	),
	array(
		'label'  => __( '内存上限', 'sakura-imgopt' ),
		'value'  => $format_limit( $memory_limit ),
		'badges' => $memory_warn ? array( array( __( '建议 256M+', 'sakura-imgopt' ), 'warn' ) ) : array(),
	),
	array(
		'label' => __( '最大执行时间', 'sakura-imgopt' ),
		'value' => $format_time( $time_limit ),
	),
	array(
		'label'  => __( '上传文件上限', 'sakura-imgopt' ),
		'value'  => $format_limit( $upload_limit ),
		'badges' => $upload_warn ? array( array( __( '建议 32M+', 'sakura-imgopt' ), 'warn' ) ) : array(),
	),
	array(
		'label' => __( 'POST 上限', 'sakura-imgopt' ),
		'value' => $format_limit( $post_limit ),
	),
	array(
		'label'  => __( 'OPcache', 'sakura-imgopt' ),
		'value'  => '',
		'badges' => array( array( (string) $php['opcache']['label'], $check_tone( 'opcache' ) ) ),
	),
	array(
		'label' => __( 'WordPress', 'sakura-imgopt' ),
		'value' => (string) $wordpress['version'],
		'muted' => (string) $wordpress['environment'],
	),
	array(
		'label' => __( '数据库', 'sakura-imgopt' ),
		'value' => '' !== (string) $wordpress['db_version'] ? (string) $wordpress['db_version'] : __( '未知', 'sakura-imgopt' ),
		'muted' => __( 'MySQL / MariaDB', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '时区', 'sakura-imgopt' ),
		'value' => '' !== (string) $wordpress['timezone'] ? (string) $wordpress['timezone'] : __( '未知', 'sakura-imgopt' ),
	),
);

$format_badges = array();
foreach ( $engine['formats'] as $format => $supported ) {
	$format_badges[] = array( (string) $format, $supported ? 'ok' : 'warn' );
}

$engine_rows = array(
	array(
		'label'  => __( '首选编辑器', 'sakura-imgopt' ),
		'value'  => (string) $engine['primary'],
		'badges' => array( array( $check_label( 'engine' ), $check_tone( 'engine' ) ) ),
	),
	array(
		'label'  => 'Imagick',
		'value'  => '' !== (string) $engine['imagick'] ? (string) $engine['imagick'] : __( '未安装', 'sakura-imgopt' ),
		'badges' => array( array( '' !== (string) $engine['imagick'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ), '' !== (string) $engine['imagick'] ? 'ok' : 'warn' ) ),
	),
	array(
		'label'  => 'GD',
		'value'  => '' !== (string) $engine['gd'] ? (string) $engine['gd'] : __( '未安装', 'sakura-imgopt' ),
		'badges' => array( array( '' !== (string) $engine['gd'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ), '' !== (string) $engine['gd'] ? 'ok' : 'warn' ) ),
	),
	array(
		'label'  => 'WebP',
		'value'  => '',
		'badges' => array( array( $report['formats']['webp'] ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), $check_tone( 'webp' ) ) ),
	),
	array(
		'label'  => 'AVIF',
		'value'  => '',
		'badges' => array( array( $report['formats']['avif'] ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), $check_tone( 'avif' ) ) ),
	),
	array(
		'label'  => __( '支持格式', 'sakura-imgopt' ),
		'value'  => '',
		'badges' => $format_badges,
	),
);

$kpis = array(
	array(
		'label' => __( '通过', 'sakura-imgopt' ),
		'value' => (string) $summary['pass'],
		'unit'  => __( '项', 'sakura-imgopt' ),
		'sub'   => sprintf( /* translators: %d: number of checks. */ __( '共检测 %d 项', 'sakura-imgopt' ), $total_checks ),
	),
	array(
		'label' => __( '建议', 'sakura-imgopt' ),
		'value' => (string) $summary['warn'],
		'unit'  => __( '项', 'sakura-imgopt' ),
		'sub'   => __( '可优化但不阻断', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '需要处理', 'sakura-imgopt' ),
		'value' => (string) $summary['fail'],
		'unit'  => __( '项', 'sakura-imgopt' ),
		'sub'   => __( '影响核心能力', 'sakura-imgopt' ),
	),
);

UI::page(
	array(
		'id'      => 'system-overview',
		'module'  => 'system',
		'tab'     => 'overview',
		'eyebrow' => 'SYSTEM READOUT / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '基础设置', 'sakura-imgopt' ),
		'lede'    => __( '服务器运行参数、PHP 扩展与图像引擎能力，一页看清；卡片间隔在这里统一设置。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '环境自检', 'sakura-imgopt' ),
			'value' => (string) $summary['pass'] . '/' . (string) $total_checks,
			'unit'  => __( '项通过', 'sakura-imgopt' ),
			'sub'   => sprintf(
				/* translators: 1: environment status, 2: PHP version */
				__( '%1$s · PHP %2$s', 'sakura-imgopt' ),
				$status_text,
				(string) $php['version']
			),
			'tone'  => $page_tone,
		),
		'actions' => array(
			array(
				'label'   => __( '进入图像优化', 'sakura-imgopt' ),
				'url'     => $optimize_url,
				'variant' => 'primary',
			),
			array(
				'label'   => __( '重新检测', 'sakura-imgopt' ),
				'url'     => $refresh_url,
				'variant' => 'ghost',
			),
		),
	),
	static function () use ( $score, $kpis, $runtime_rows, $engine_rows, $engine, $checks, $summary, $total_checks, $state_labels, $state_tone, $extensions, $gaps, $gaps_option, $gaps_max ) {
		settings_errors();
		?>
		<section class="kpis" aria-label="<?php echo esc_attr__( '环境评分', 'sakura-imgopt' ); ?>">
			<div class="kpi">
				<span class="kpi__label"><?php echo esc_html__( '环境评分', 'sakura-imgopt' ); ?></span>
				<strong class="kpi__value"><?php echo esc_html( (string) $score ); ?><small><?php echo esc_html__( '分', 'sakura-imgopt' ); ?></small></strong>
				<div class="kpi__progress">
					<progress class="progress" max="100" value="<?php echo esc_attr( (string) min( 100, max( 0, $score ) ) ); ?>" aria-label="<?php echo esc_attr__( '环境评分', 'sakura-imgopt' ); ?>"><?php echo esc_html( (string) min( 100, max( 0, $score ) ) ); ?>%</progress>
				</div>
				<span class="kpi__sub"><?php echo esc_html__( '通过项计满分，建议项计半分', 'sakura-imgopt' ); ?></span>
			</div>
			<?php foreach ( $kpis as $kpi ) : ?>
				<div class="kpi">
					<span class="kpi__label"><?php echo esc_html( (string) $kpi['label'] ); ?></span>
					<strong class="kpi__value"><?php echo esc_html( (string) $kpi['value'] ); ?><small><?php echo esc_html( (string) $kpi['unit'] ); ?></small></strong>
					<span class="kpi__sub"><?php echo esc_html( (string) $kpi['sub'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>

		<div class="card-grid">
			<?php
			UI::card(
				array(
					'id'          => 'runtime',
					'kicker'      => 'Runtime',
					'title'       => __( '服务器运行环境', 'sakura-imgopt' ),
					'description' => __( 'PHP、Web 服务与 WordPress 的当前读数。', 'sakura-imgopt' ),
				),
				static function () use ( $runtime_rows ) {
					?>
					<div class="table-scroll">
						<table class="table table--compact table--responsive">
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html__( '项目', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php echo esc_html__( '当前值', 'sakura-imgopt' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $runtime_rows as $row ) : ?>
									<tr>
										<td data-label="<?php echo esc_attr__( '项目', 'sakura-imgopt' ); ?>"><?php echo esc_html( (string) $row['label'] ); ?></td>
										<td data-label="<?php echo esc_attr__( '当前值', 'sakura-imgopt' ); ?>">
											<?php if ( '' !== (string) $row['value'] ) : ?>
												<span class="system-value"><?php echo esc_html( (string) $row['value'] ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $row['muted'] ) ) : ?>
												<span class="system-muted"><?php echo esc_html( (string) $row['muted'] ); ?></span>
											<?php endif; ?>
											<?php foreach ( (array) ( $row['badges'] ?? array() ) as $badge ) : ?>
												<?php UI::badge( (string) $badge[0], (string) $badge[1] ); ?>
											<?php endforeach; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'engine',
					'kicker'      => 'Image engine',
					'title'       => __( '图像引擎', 'sakura-imgopt' ),
					'description' => __( 'WordPress 当前可用的图像处理能力；Imagick 优先，GD 作为回退。', 'sakura-imgopt' ),
				),
				static function () use ( $engine_rows, $engine ) {
					?>
					<div class="table-scroll">
						<table class="table table--compact table--responsive">
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html__( '项目', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php echo esc_html__( '状态', 'sakura-imgopt' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $engine_rows as $row ) : ?>
									<tr>
										<td data-label="<?php echo esc_attr__( '项目', 'sakura-imgopt' ); ?>"><?php echo esc_html( (string) $row['label'] ); ?></td>
										<td data-label="<?php echo esc_attr__( '状态', 'sakura-imgopt' ); ?>">
											<?php if ( '' !== (string) $row['value'] ) : ?>
												<span class="system-value"><?php echo esc_html( (string) $row['value'] ); ?></span>
											<?php endif; ?>
											<?php foreach ( (array) ( $row['badges'] ?? array() ) as $badge ) : ?>
												<?php UI::badge( (string) $badge[0], (string) $badge[1] ); ?>
											<?php endforeach; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="system-note"><?php echo esc_html__( '支持格式来自当前图像编辑器；缺失 WebP / AVIF 时仍可处理常见格式，但无法输出对应格式。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'checks',
					'kicker'      => 'Checks',
					'title'       => __( '环境检查', 'sakura-imgopt' ),
					'description' => sprintf(
						/* translators: 1: passed checks, 2: total checks */
						__( '按插件的实际依赖逐项核对：%1$d / %2$d 项通过。', 'sakura-imgopt' ),
						(int) $summary['pass'],
						$total_checks
					),
					'wide'        => true,
				),
				static function () use ( $checks, $state_labels, $state_tone ) {
					?>
					<div class="table-scroll">
						<table class="table table--compact table--responsive">
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html__( '检查项', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php echo esc_html__( '状态', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php echo esc_html__( '当前值', 'sakura-imgopt' ); ?></th>
									<th scope="col"><?php echo esc_html__( '说明', 'sakura-imgopt' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $checks as $check ) : ?>
									<?php $check_state = (string) $check['state']; ?>
									<tr>
										<td data-label="<?php echo esc_attr__( '检查项', 'sakura-imgopt' ); ?>"><span class="system-value"><?php echo esc_html( (string) $check['label'] ); ?></span></td>
										<td data-label="<?php echo esc_attr__( '状态', 'sakura-imgopt' ); ?>"><?php UI::badge( $state_labels[ $check_state ] ?? $check_state, $state_tone( $check_state ) ); ?></td>
										<td data-label="<?php echo esc_attr__( '当前值', 'sakura-imgopt' ); ?>"><?php echo '' !== (string) $check['value'] ? esc_html( (string) $check['value'] ) : '—'; ?></td>
										<td data-label="<?php echo esc_attr__( '说明', 'sakura-imgopt' ); ?>" class="system-check-note"><?php echo '' !== (string) $check['note'] ? esc_html( (string) $check['note'] ) : '—'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'extensions',
					'kicker'      => 'Extensions',
					'title'       => __( 'PHP 扩展', 'sakura-imgopt' ),
					'description' => __( '按加载状态标记；S3 上传依赖 cURL 与 OpenSSL。', 'sakura-imgopt' ),
				),
				static function () use ( $extensions ) {
					?>
					<div class="system-ext-grid">
						<?php foreach ( $extensions as $extension ) : ?>
							<?php UI::badge( (string) $extension['name'], $extension['loaded'] ? 'ok' : 'warn' ); ?>
						<?php endforeach; ?>
					</div>
					<p class="system-note"><?php echo esc_html__( '图片处理依赖 Imagick 或 GD；缺失的扩展会在环境检查中单独标记。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'spacing',
					'kicker'      => 'Layout rhythm',
					'title'       => __( '卡片间隔', 'sakura-imgopt' ),
					'description' => __( '所有插件页面共用一组间隔；保存后刷新页面生效。', 'sakura-imgopt' ),
					'form'        => array(
						'option_group' => 'sakura_imgopt_basic',
						'markers'      => 'fields',
						'marker_card'  => 'spacing',
					),
					'save'        => __( '保存间隔', 'sakura-imgopt' ),
				),
				static function () use ( $gaps, $gaps_option, $gaps_max ) {
					?>
					<div class="field-list">
						<?php
						UI::field(
							array(
								'type'  => 'number',
								'id'    => 'sakura-gap-x',
								'name'  => $gaps_option . '[gapX]',
								'label' => __( '左右间隔', 'sakura-imgopt' ),
								'hint'  => sprintf(
									/* translators: %d: maximum gap in pixels. */
									__( '列与列之间 · 0–%d px', 'sakura-imgopt' ),
									(int) $gaps_max
								),
								'value' => (string) $gaps['gapX'],
								'min'   => 0,
								'max'   => (int) $gaps_max,
								'step'  => 1,
								'attrs' => array( 'inputmode' => 'numeric' ),
							)
						);

						UI::field(
							array(
								'type'  => 'number',
								'id'    => 'sakura-gap-y',
								'name'  => $gaps_option . '[gapY]',
								'label' => __( '上下间隔', 'sakura-imgopt' ),
								'hint'  => sprintf(
									/* translators: %d: maximum gap in pixels. */
									__( '行与行之间 · 0–%d px', 'sakura-imgopt' ),
									(int) $gaps_max
								),
								'value' => (string) $gaps['gapY'],
								'min'   => 0,
								'max'   => (int) $gaps_max,
								'step'  => 1,
								'attrs' => array( 'inputmode' => 'numeric' ),
							)
						);
						?>
					</div>
					<p class="system-note"><?php echo esc_html__( '只保存这两个数值，不会清理缓存，也不改动其它页面布局。', 'sakura-imgopt' ); ?></p>
					<?php
				}
			);
			?>
		</div>
		<?php
	}
);
