<?php
/**
 * Optimization statistics page.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var \Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface $stats Stats repository.
 */

use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$stats_repo = $stats;
$stats      = $stats_repo->get_stats();
$logs       = $stats_repo->get_recent_logs( 100 );
$total_logs = count( $logs );
$logs       = array_slice( $logs, 0, 25 );
$rows_html  = $this->render_stats_log_rows( $logs );
$saved      = max( 0, (int) $stats['total_source_size'] - (int) $stats['total_target_size'] );
$avg_ratio  = ( $stats['total_source_size'] > 0 ) ? round( ( 1 - $stats['total_target_size'] / $stats['total_source_size'] ) * 100, 1 ) : 0;
$reset_url  = wp_nonce_url( admin_url( 'admin-post.php?action=sakura_imgopt_reset_stats' ), 'sakura_imgopt_reset_stats' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
$reset = isset( $_GET['reset'] ) ? sanitize_key( wp_unslash( $_GET['reset'] ) ) : '';

$kpis = array(
	array(
		'label' => __( '总处理次数', 'sakura-imgopt' ),
		'value' => number_format_i18n( $stats['total_processed'] ),
		'unit'  => __( '次', 'sakura-imgopt' ),
		'sub'   => __( '含上传与批量处理', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '成功', 'sakura-imgopt' ),
		'value' => number_format_i18n( $stats['total_success'] ),
		'unit'  => __( '次', 'sakura-imgopt' ),
		'sub'   => __( '已完成优化', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '失败', 'sakura-imgopt' ),
		'value' => number_format_i18n( $stats['total_failed'] ),
		'unit'  => __( '次', 'sakura-imgopt' ),
		'sub'   => __( '保留原图未处理', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '跳过', 'sakura-imgopt' ),
		'value' => number_format_i18n( $stats['total_skipped'] ),
		'unit'  => __( '次', 'sakura-imgopt' ),
		'sub'   => __( '体积未减小，保留原图', 'sakura-imgopt' ),
	),
	array(
		'label' => __( '累计节省空间', 'sakura-imgopt' ),
		'value' => size_format( $saved, 2 ),
		'sub'   => sprintf(
			/* translators: 1: original size, 2: optimized size */
			__( '原始 %1$s → 优化后 %2$s', 'sakura-imgopt' ),
			size_format( $stats['total_source_size'], 2 ),
			size_format( $stats['total_target_size'], 2 )
		),
	),
);

UI::page(
	array(
		'id'      => 'image-stats',
		'module'  => 'image',
		'tab'     => 'stats',
		'eyebrow' => 'OPTIMIZATION LEDGER / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '优化统计', 'sakura-imgopt' ),
		'lede'    => __( '汇总图片处理结果、空间节省与最近执行记录，便于持续观察媒体优化效果。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '已统计', 'sakura-imgopt' ),
			'value' => number_format_i18n( $stats['total_processed'] ),
			'unit'  => __( '总处理次数', 'sakura-imgopt' ),
			'sub'   => sprintf(
				/* translators: %s: average compression ratio */
				__( '平均压缩率 %s%%', 'sakura-imgopt' ),
				number_format_i18n( $avg_ratio, 1 )
			),
			'tone'  => 'neutral',
		),
		'actions' => array(
			array(
				'label'   => __( '返回图像优化', 'sakura-imgopt' ),
				'url'     => Registry::url( 'image', 'rules' ),
				'variant' => 'ghost',
			),
			array(
				'label'   => __( '重置统计', 'sakura-imgopt' ),
				'url'     => $reset_url,
				'variant' => 'danger',
				'attrs'   => array(
					'data-sakura-confirm' => __( '确定要重置汇总统计吗？（每张图片的处理记录会保留）', 'sakura-imgopt' ),
				),
			),
		),
	),
	static function () use ( $stats, $logs, $rows_html, $total_logs, $avg_ratio, $reset, $kpis ) {
		if ( '1' === $reset ) {
			UI::alert( __( '统计已重置。', 'sakura-imgopt' ), 'success' );
		}
		?>
		<section class="kpis" aria-label="<?php echo esc_attr__( '统计摘要', 'sakura-imgopt' ); ?>">
			<?php foreach ( $kpis as $kpi ) : ?>
				<div class="kpi">
					<span class="kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
					<strong class="kpi__value"><?php echo esc_html( $kpi['value'] ); ?><?php if ( ! empty( $kpi['unit'] ) ) : ?><small><?php echo esc_html( $kpi['unit'] ); ?></small><?php endif; ?></strong>
					<?php if ( ! empty( $kpi['sub'] ) ) : ?>
						<span class="kpi__sub"><?php echo esc_html( $kpi['sub'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<div class="kpi">
				<span class="kpi__label"><?php echo esc_html__( '平均压缩率', 'sakura-imgopt' ); ?></span>
				<strong class="kpi__value"><?php echo esc_html( (string) $avg_ratio ); ?><small>%</small></strong>
				<div class="kpi__progress">
					<div class="progress"><span class="progress__bar" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $avg_ratio ) ) ); ?>%"></span></div>
				</div>
			</div>
		</section>

		<?php
		UI::card(
			array(
				'id'          => 'history',
				'kicker'      => 'History',
				'title'       => __( '最近处理记录', 'sakura-imgopt' ),
				'description' => ! empty( $stats['last_reset'] )
					/* translators: %s: timestamp of the last reset */
					? sprintf( __( '上次重置：%s', 'sakura-imgopt' ), $stats['last_reset'] )
					: __( '可在下方切换每页 25 / 50 / 100 条', 'sakura-imgopt' ),
				'wide'        => true,
			),
			static function () use ( $logs, $total_logs ) {
				?>
				<div class="table-scroll">
					<table class="table table--responsive" id="sakura-stats-history" data-total="<?php echo esc_attr( (string) $total_logs ); ?>" data-per-page="25">
						<thead>
							<tr>
								<th><?php echo esc_html__( '时间', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '图片', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '格式', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '引擎', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '来源', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '大小变化', 'sakura-imgopt' ); ?></th>
								<th><?php echo esc_html__( '状态', 'sakura-imgopt' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr class="stats-empty"><td colspan="7"><?php echo esc_html__( '暂无记录。', 'sakura-imgopt' ); ?></td></tr>
							<?php else : ?>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_stats_log_rows() escapes every cell.
								echo $rows_html;
								?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div class="pagination" id="stats-history-foot">
					<div class="pagination__sizes" role="group" aria-label="<?php echo esc_attr__( '每页显示条数', 'sakura-imgopt' ); ?>">
						<span><?php echo esc_html__( '每页', 'sakura-imgopt' ); ?></span>
						<button type="button" class="pagination__link is-current" data-size="25">25</button>
						<button type="button" class="pagination__link" data-size="50">50</button>
						<button type="button" class="pagination__link" data-size="100">100</button>
					</div>
					<span class="pagination__summary" id="stats-history-summary"></span>
					<div class="pagination__pages">
						<button type="button" class="pagination__link" id="stats-page-prev" aria-label="<?php echo esc_attr__( '上一页', 'sakura-imgopt' ); ?>">‹</button>
						<span id="stats-page-now">1 / 1</span>
						<button type="button" class="pagination__link" id="stats-page-next" aria-label="<?php echo esc_attr__( '下一页', 'sakura-imgopt' ); ?>">›</button>
					</div>
				</div>
				<?php
			}
		);
	}
);
