<?php
/**
 * Basic settings view. Included from Basic_Settings_Module::render_page().
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $report Environment report.
 * @var array{gapX:int,gapY:int} $gaps Current site-wide card gaps.
 * @var string $gaps_option Option name used by the gap form.
 * @var int $gaps_max Maximum accepted gap in pixels.
 */

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
	$check_map[ $check['key'] ] = $check;
}

$badge_classes = array(
	'ok'   => 'badge--ok',
	'warn' => 'badge--warn',
	'fail' => 'badge--bad',
);

$state_labels = array(
	'ok'   => __( '通过', 'sakura-imgopt' ),
	'warn' => __( '建议', 'sakura-imgopt' ),
	'fail' => __( '需要处理', 'sakura-imgopt' ),
);

$state_class = static function ( string $key ) use ( $check_map, $badge_classes ): string {
	$state = isset( $check_map[ $key ] ) ? (string) $check_map[ $key ]['state'] : 'warn';

	return $badge_classes[ $state ] ?? 'badge--warn';
};

$total       = count( $checks );
$total       = $total > 0 ? $total : 1;
$score       = (int) round( ( ( $summary['pass'] + $summary['warn'] * 0.5 ) / $total ) * 100 );
$sufm_status = $summary['fail'] > 0 ? 'is-bad' : ( $summary['warn'] > 0 ? 'is-warn' : '' );
$status_text = $summary['fail'] > 0
	? __( '需要处理', 'sakura-imgopt' )
	: ( $summary['warn'] > 0 ? __( '有建议', 'sakura-imgopt' ) : __( '环境可用', 'sakura-imgopt' ) );

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
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php settings_errors(); ?>
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'system',
			'overview',
			array( 'status' => sprintf( 'PHP %s · %s', $php['version'], $engine['primary'] ) )
		);
		?>

		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">SYSTEM READOUT / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php esc_html_e( '基础设置', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '服务器运行参数、PHP 扩展与图像引擎能力，一页看清；卡片间隔在这里统一设置。', 'sakura-imgopt' ); ?></p>
				<div class="hero-actions">
					<a class="btn btn--primary" href="<?php echo esc_url( $optimize_url ); ?>"><?php esc_html_e( '进入图像优化', 'sakura-imgopt' ); ?></a>
					<a class="btn" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( '重新检测', 'sakura-imgopt' ); ?></a>
					<span class="hero-note"><?php esc_html_e( '服务端渲染 · 加载时刷新', 'sakura-imgopt' ); ?></span>
				</div>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top">
					<span class="hero-status-label <?php echo esc_attr( $sufm_status ); ?>"><span class="dot"></span> <?php echo esc_html( $status_text ); ?></span>
					<small><?php echo esc_html( sprintf( /* translators: %d: number of checks. */ __( '检测 %d 项', 'sakura-imgopt' ), count( $checks ) ) ); ?></small>
				</div>
				<div class="hero-status-main"><strong><?php echo esc_html( (string) $summary['pass'] ); ?></strong><span>· <?php esc_html_e( '项通过', 'sakura-imgopt' ); ?></span></div>
				<div class="hero-status-sub"><?php /* translators: 1: number of recommendations, 2: number of items needing action */ echo esc_html( sprintf( __( '%1$d 项建议 · %2$d 项需要处理', 'sakura-imgopt' ), $summary['warn'], $summary['fail'] ) ); ?></div>
				<div class="hero-status-bar"><span style="width:<?php echo esc_attr( (string) $score ); ?>%"></span></div>
				<div class="hero-status-sub"><?php /* translators: 1: PHP version, 2: image engine name */ echo esc_html( sprintf( __( 'PHP %1$s · %2$s', 'sakura-imgopt' ), $php['version'], $engine['primary'] ) ); ?></div>
			</aside>
		</section>

		<div class="dashboard-grid">
			<div class="main-stack">
				<section class="card" data-sakura-card="runtime">
					<div class="card-head">
						<div>
							<p class="section-kicker">Runtime</p>
							<h2><?php esc_html_e( '服务器运行环境', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( 'PHP、Web 服务与 WordPress 的当前读数。', 'sakura-imgopt' ); ?></p>
						</div>
						<span class="badge badge--info">PHP <?php echo esc_html( $php['version'] ); ?></span>
					</div>
					<div class="card-body">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'PHP 版本', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( $php['version'] ); ?></span>
										<span class="badge <?php echo esc_attr( $state_class( 'php' ) ); ?>">
											<?php echo esc_html( version_compare( $php['version'], '8.2', '>=' ) ? __( '满足 8.2+', 'sakura-imgopt' ) : __( '低于 8.2', 'sakura-imgopt' ) ); ?>
										</span>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '运行方式', 'sakura-imgopt' ); ?></th>
									<td><?php echo esc_html( $php['sapi_label'] ); ?><span class="basic-muted"><?php echo esc_html( $php['sapi'] ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Web 服务器', 'sakura-imgopt' ); ?></th>
									<td><span class="basic-value"><?php echo esc_html( '' !== (string) $php['server_software'] ? $php['server_software'] : __( '未知', 'sakura-imgopt' ) ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '内存上限', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( $format_limit( $memory_limit ) ); ?></span>
										<?php if ( $memory_warn ) : ?>
											<span class="badge badge--warn"><?php esc_html_e( '建议 256M+', 'sakura-imgopt' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '最大执行时间', 'sakura-imgopt' ); ?></th>
									<td><span class="basic-value"><?php echo esc_html( $format_time( $time_limit ) ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '上传文件上限', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( $format_limit( $upload_limit ) ); ?></span>
										<?php if ( $upload_warn ) : ?>
											<span class="badge badge--warn"><?php esc_html_e( '建议 32M+', 'sakura-imgopt' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'POST 上限', 'sakura-imgopt' ); ?></th>
									<td><span class="basic-value"><?php echo esc_html( $format_limit( $post_limit ) ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'OPcache', 'sakura-imgopt' ); ?></th>
									<td><span class="badge <?php echo esc_attr( $state_class( 'opcache' ) ); ?>"><?php echo esc_html( $php['opcache']['label'] ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'WordPress', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( $wordpress['version'] ); ?></span>
										<?php if ( '' !== (string) $wordpress['environment'] ) : ?>
											<span class="basic-muted"><?php echo esc_html( $wordpress['environment'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '数据库', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( '' !== $wordpress['db_version'] ? $wordpress['db_version'] : __( '未知', 'sakura-imgopt' ) ); ?></span>
										<span class="basic-muted"><?php esc_html_e( 'MySQL / MariaDB', 'sakura-imgopt' ); ?></span>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '时区', 'sakura-imgopt' ); ?></th>
									<td><span class="basic-value"><?php echo esc_html( '' !== $wordpress['timezone'] ? $wordpress['timezone'] : __( '未知', 'sakura-imgopt' ) ); ?></span></td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section class="card" data-sakura-card="engine">
					<div class="card-head">
						<div>
							<p class="section-kicker">Image engine</p>
							<h2><?php esc_html_e( '图像引擎', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( 'WordPress 当前可用的图像处理能力；Imagick 优先，GD 作为回退。', 'sakura-imgopt' ); ?></p>
						</div>
						<span class="badge <?php echo esc_attr( '' !== $engine['imagick'] ? 'badge--ok' : 'badge--warn' ); ?>"><?php echo esc_html( $engine['primary'] ); ?></span>
					</div>
					<div class="card-body">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( '首选编辑器', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="basic-value"><?php echo esc_html( $engine['primary'] ); ?></span>
										<span class="badge <?php echo esc_attr( $state_class( 'engine' ) ); ?>"><?php echo esc_html( $state_labels[ $check_map['engine']['state'] ] ?? '' ); ?></span>
									</td>
								</tr>
								<tr>
									<th scope="row">Imagick</th>
									<td>
										<span class="basic-value"><?php echo esc_html( '' !== $engine['imagick'] ? $engine['imagick'] : __( '未安装', 'sakura-imgopt' ) ); ?></span>
										<span class="badge <?php echo esc_attr( '' !== $engine['imagick'] ? 'badge--ok' : 'badge--muted' ); ?>"><?php echo esc_html( '' !== $engine['imagick'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ) ); ?></span>
									</td>
								</tr>
								<tr>
									<th scope="row">GD</th>
									<td>
										<span class="basic-value"><?php echo esc_html( '' !== $engine['gd'] ? $engine['gd'] : __( '未安装', 'sakura-imgopt' ) ); ?></span>
										<span class="badge <?php echo esc_attr( '' !== $engine['gd'] ? 'badge--ok' : 'badge--muted' ); ?>"><?php echo esc_html( '' !== $engine['gd'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ) ); ?></span>
									</td>
								</tr>
								<tr>
									<th scope="row">WebP</th>
									<td><span class="badge <?php echo esc_attr( $state_class( 'webp' ) ); ?>"><?php echo esc_html( $report['formats']['webp'] ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ) ); ?></span></td>
								</tr>
								<tr>
									<th scope="row">AVIF</th>
									<td><span class="badge <?php echo esc_attr( $state_class( 'avif' ) ); ?>"><?php echo esc_html( $report['formats']['avif'] ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ) ); ?></span></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( '支持格式', 'sakura-imgopt' ); ?></th>
									<td>
										<span class="ext-grid">
											<?php foreach ( $engine['formats'] as $format => $supported ) : ?>
												<span class="badge <?php echo esc_attr( $supported ? 'badge--ok' : 'badge--muted' ); ?>"><?php echo esc_html( $format ); ?></span>
											<?php endforeach; ?>
										</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>
			</div>

			<div class="side-stack">
				<section class="card" data-sakura-card="checks">
					<div class="card-head">
						<div>
							<p class="section-kicker">Checks</p>
							<h2><?php esc_html_e( '环境检查', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '按插件的实际依赖逐项核对。', 'sakura-imgopt' ); ?></p>
						</div>
						<span class="badge badge--info"><?php echo esc_html( sprintf( '%d / %d', $summary['pass'], count( $checks ) ) ); ?></span>
					</div>
					<div class="card-body">
						<dl class="health-list">
							<?php foreach ( $checks as $check ) : ?>
								<div class="health-row">
									<dt><?php echo esc_html( $check['label'] ); ?></dt>
									<dd>
										<span class="badge <?php echo esc_attr( $badge_classes[ $check['state'] ] ?? 'badge--warn' ); ?>" title="<?php echo esc_attr( $check['note'] ); ?>"><?php echo esc_html( $check['value'] ); ?></span>
									</dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</div>
				</section>

				<section class="card" data-sakura-card="extensions">
					<div class="card-head">
						<div>
							<p class="section-kicker">Extensions</p>
							<h2><?php esc_html_e( 'PHP 扩展', 'sakura-imgopt' ); ?></h2>
						</div>
					</div>
					<div class="card-body">
						<div class="ext-grid">
							<?php foreach ( $extensions as $extension ) : ?>
								<span class="badge <?php echo esc_attr( $extension['loaded'] ? 'badge--ok' : 'badge--muted' ); ?>"><?php echo esc_html( $extension['name'] ); ?></span>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'S3 上传依赖 cURL 与 OpenSSL；图片处理依赖 Imagick 或 GD。', 'sakura-imgopt' ); ?></p>
					</div>
				</section>

				<section class="card" data-sakura-card="spacing">
					<div class="card-head">
						<div>
							<p class="section-kicker">Layout rhythm</p>
							<h2><?php esc_html_e( '卡片间隔', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '所有插件页面共用一组间隔；保存后刷新页面生效。', 'sakura-imgopt' ); ?></p>
						</div>
						<span class="badge badge--info"><?php esc_html_e( '全局', 'sakura-imgopt' ); ?></span>
					</div>
					<div class="card-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="basic-gap-form">
							<?php settings_fields( 'sakura_imgopt_basic' ); ?>

							<div class="basic-gap-field">
								<label for="sakura-gap-x"><?php esc_html_e( '左右间隔', 'sakura-imgopt' ); ?></label>
								<div class="basic-gap-control">
									<input id="sakura-gap-x" type="number" min="0" max="<?php echo esc_attr( (string) $gaps_max ); ?>" step="1" inputmode="numeric" name="<?php echo esc_attr( $gaps_option ); ?>[gapX]" value="<?php echo esc_attr( (string) $gaps['gapX'] ); ?>" />
									<span class="basic-gap-unit">px</span>
								</div>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum gap in pixels. */ __( '列与列之间 · 0–%d px', 'sakura-imgopt' ), (int) $gaps_max ) ); ?></p>
							</div>

							<div class="basic-gap-field">
								<label for="sakura-gap-y"><?php esc_html_e( '上下间隔', 'sakura-imgopt' ); ?></label>
								<div class="basic-gap-control">
									<input id="sakura-gap-y" type="number" min="0" max="<?php echo esc_attr( (string) $gaps_max ); ?>" step="1" inputmode="numeric" name="<?php echo esc_attr( $gaps_option ); ?>[gapY]" value="<?php echo esc_attr( (string) $gaps['gapY'] ); ?>" />
									<span class="basic-gap-unit">px</span>
								</div>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum gap in pixels. */ __( '行与行之间 · 0–%d px', 'sakura-imgopt' ), (int) $gaps_max ) ); ?></p>
							</div>

							<p class="basic-footnote"><?php esc_html_e( '只保存这两个数值，不会清理缓存，也不改动其它页面布局。', 'sakura-imgopt' ); ?></p>
							<div class="card-savebar">
								<button type="submit" class="btn btn--primary"><?php esc_html_e( '保存间隔', 'sakura-imgopt' ); ?></button>
							</div>
						</form>
					</div>
				</section>
			</div>
		</div>
	</div>
</div>
