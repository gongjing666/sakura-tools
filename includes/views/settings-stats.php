<?php
/**
 * View partial. Included from a settings/media method; uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \Sakura\ImageOptimizer\Image_Optimizer_Module $this */
/** @var \Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface $stats */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$stats_repo = $stats;
$stats      = $stats_repo->get_stats();
$logs       = $stats_repo->get_recent_logs( 100 );
$total_logs = count( $logs );
$logs       = array_slice( $logs, 0, 25 );
$saved      = max( 0, (int) $stats['total_source_size'] - (int) $stats['total_target_size'] );
$avg_ratio  = ( $stats['total_source_size'] > 0 ) ? round( ( 1 - $stats['total_target_size'] / $stats['total_source_size'] ) * 100, 1 ) : 0;
$reset_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sakura_imgopt_reset_stats' ), 'sakura_imgopt_reset_stats' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
$reset = isset( $_GET['reset'] ) ? sanitize_key( wp_unslash( $_GET['reset'] ) ) : '';
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php /* translators: %s: total bytes saved */ \Sakura\ImageOptimizer\Admin\Shell::header( 'image', 'stats', array( 'status' => sprintf( __( '累计节省 %s', 'sakura-imgopt' ), size_format( $saved, 2 ) ) ) ); ?>

		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">OPTIMIZATION LEDGER / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php esc_html_e( '优化统计', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '汇总图片处理结果、空间节省与最近执行记录，便于持续观察媒体优化效果。', 'sakura-imgopt' ); ?></p>
				<div class="hero-actions">
					<a class="btn btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sakura-tools-optimize' ) ); ?>"><?php esc_html_e( '返回图像优化', 'sakura-imgopt' ); ?></a>
					<a class="btn btn--danger" href="<?php echo esc_url( $reset_url ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定重置统计吗？此操作不可撤销。', 'sakura-imgopt' ) ); ?>');"><?php esc_html_e( '重置统计', 'sakura-imgopt' ); ?></a>
				</div>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top">
					<span class="hero-status-label"><span class="dot"></span> <?php esc_html_e( '已统计', 'sakura-imgopt' ); ?></span>
				</div>
				<div class="hero-status-main"><strong><?php echo esc_html( number_format_i18n( $stats['total_processed'] ) ); ?></strong><span>· <?php esc_html_e( '总处理次数', 'sakura-imgopt' ); ?></span></div>
				<div class="hero-status-sub"><?php /* translators: %s: average compression ratio */ echo esc_html( sprintf( __( '平均压缩率 %s%%', 'sakura-imgopt' ), number_format_i18n( $avg_ratio, 1 ) ) ); ?></div>
			</aside>
		</section>

		<?php if ( '1' === $reset ) : ?>
			<div class="alert-strip" role="status"><span><strong><?php esc_html_e( '统计已重置。', 'sakura-imgopt' ); ?></strong></span></div>
		<?php endif; ?>

		<section class="kpis kpis--stats" aria-label="<?php esc_attr_e( '统计摘要', 'sakura-imgopt' ); ?>">
			<article class="kpi"><div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '总处理次数', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $stats['total_processed'] ) ); ?></strong><small><?php esc_html_e( '次', 'sakura-imgopt' ); ?></small></div><div class="kpi-sub"><?php esc_html_e( '含上传与批量处理', 'sakura-imgopt' ); ?></div></article>
			<article class="kpi kpi--ok"><div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '成功', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $stats['total_success'] ) ); ?></strong><small><?php esc_html_e( '次', 'sakura-imgopt' ); ?></small></div><div class="kpi-sub"><?php esc_html_e( '已完成优化', 'sakura-imgopt' ); ?></div></article>
			<article class="kpi kpi--bad"><div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '失败', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $stats['total_failed'] ) ); ?></strong><small><?php esc_html_e( '次', 'sakura-imgopt' ); ?></small></div><div class="kpi-sub"><?php esc_html_e( '保留原图未处理', 'sakura-imgopt' ); ?></div></article>
			<article class="kpi kpi--warn"><div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '跳过', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $stats['total_skipped'] ) ); ?></strong><small><?php esc_html_e( '次', 'sakura-imgopt' ); ?></small></div><div class="kpi-sub"><?php esc_html_e( '体积未减小，保留原图', 'sakura-imgopt' ); ?></div></article>
			<article class="kpi"><div class="kpi-top"><span class="kpi-label"><?php /* translators: 1: original size, 2: optimized size */ esc_html_e( '累计节省空间', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( size_format( $saved, 2 ) ); ?></strong></div><div class="kpi-sub"><?php echo esc_html( sprintf( __( '原始 %1$s → 优化后 %2$s', 'sakura-imgopt' ), size_format( $stats['total_source_size'], 2 ), size_format( $stats['total_target_size'], 2 ) ) ); ?></div></article>
			<article class="kpi kpi--ok"><div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '平均压缩率', 'sakura-imgopt' ); ?></span></div><div class="kpi-value"><strong><?php echo esc_html( (string) $avg_ratio ); ?></strong><small>%</small></div><div class="kpi-progress"><span style="width:<?php echo esc_attr( (string) min( 100, max( 0, $avg_ratio ) ) ); ?>%"></span></div></article>
		</section>

		<section class="card">
			<div class="card-head">
				<div><p class="section-kicker">History</p><h2><?php /* translators: %s: timestamp of the last reset */ esc_html_e( '最近处理记录', 'sakura-imgopt' ); ?></h2><p><?php echo esc_html( ! empty( $stats['last_reset'] ) ? sprintf( __( '上次重置：%s', 'sakura-imgopt' ), $stats['last_reset'] ) : __( '可在下方切换每页 25 / 50 / 100 条', 'sakura-imgopt' ) ); ?></p></div>
				<div class="card-head-actions">
					<a class="btn btn--small" href="<?php echo esc_url( $reset_url ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定要重置汇总统计吗？（每张图片的处理记录会保留）', 'sakura-imgopt' ) ); ?>');"><?php esc_html_e( '重置统计', 'sakura-imgopt' ); ?></a>
				</div>
			</div>
			<div class="table-wrap">
				<table id="sakura-stats-history" data-total="<?php echo esc_attr( (string) $total_logs ); ?>" data-per-page="25">
					<thead><tr>
						<th><?php esc_html_e( '时间', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '图片', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '格式', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '引擎', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '来源', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '大小变化', 'sakura-imgopt' ); ?></th>
						<th><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr class="stats-empty"><td colspan="7"><?php esc_html_e( '暂无记录。', 'sakura-imgopt' ); ?></td></tr>
					<?php else : ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_stats_log_rows() escapes every cell.
						echo $this->render_stats_log_rows( $logs );
						?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
			<div class="stats-history-foot" id="stats-history-foot">
				<div class="stats-page-size" role="group" aria-label="<?php esc_attr_e( '每页显示条数', 'sakura-imgopt' ); ?>">
					<span><?php esc_html_e( '每页', 'sakura-imgopt' ); ?></span>
					<button type="button" class="stats-size-btn is-active" data-size="25">25</button>
					<button type="button" class="stats-size-btn" data-size="50">50</button>
					<button type="button" class="stats-size-btn" data-size="100">100</button>
				</div>
				<span class="stats-history-summary" id="stats-history-summary"></span>
				<div class="stats-history-pager">
					<button type="button" class="stats-page-btn" id="stats-page-prev" aria-label="<?php esc_attr_e( '上一页', 'sakura-imgopt' ); ?>">‹</button>
					<span id="stats-page-now">1 / 1</span>
					<button type="button" class="stats-page-btn" id="stats-page-next" aria-label="<?php esc_attr_e( '下一页', 'sakura-imgopt' ); ?>">›</button>
				</div>
			</div>
		</section>
	</div>
</div>
