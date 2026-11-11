<?php
/**
 * S3 sync status panel.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Upload-queue metrics panel shown on the S3 storage screen.
 *
 * The per-attachment failure list lives in the dedicated sync center; this
 * panel only surfaces throughput and points there when something failed.
 */
final class Sync_Center_Panel {

	/**
	 * Render the panel.
	 *
	 * @param Sync_Status $status State store.
	 */
	public static function render( Sync_Status $status ): void {
		$count   = count( $status->failed_attachments( 20 ) );
		$metrics = ( new Upload_Metrics() )->snapshot();
		$last    = $metrics['last_error'];

		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'upload-metrics',
				'title'       => __( '上传吞吐', 'sakura-imgopt' ),
				'description' => __( '上传完成时实时记录成功量、失败率与字节数，页面每 30 秒自动刷新。', 'sakura-imgopt' ),
				'class'       => 'card--wide',
			),
			static function () use ( $metrics, $last ): void {
				?>
				<div class="metrics-panel" id="sakura-s3-metrics" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_upload_metrics' ) ); ?>">
					<div class="metrics-toolbar">
						<span class="metrics-toolbar__status" id="sakura-s3-metrics-updated"><?php echo esc_html( '' !== $metrics['updated']['ago'] ? sprintf( /* translators: %s: relative time. */ __( '更新于 %s', 'sakura-imgopt' ), $metrics['updated']['ago'] ) : __( '尚未记录', 'sakura-imgopt' ) ); ?></span>
						<span class="metrics-toolbar__spinner" id="sakura-s3-metrics-spinner" hidden><?php esc_html_e( '正在刷新…', 'sakura-imgopt' ); ?></span>
						<button type="button" class="btn btn--ghost btn--small" id="sakura-s3-metrics-refresh" data-sakura-action="refresh-metrics"><?php esc_html_e( '刷新指标', 'sakura-imgopt' ); ?></button>
					</div>

					<div class="kpis kpis--metrics">
						<div class="kpi">
							<span class="kpi__label"><?php esc_html_e( '今日成功', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value" id="sakura-s3-metric-ok"><?php echo esc_html( (string) (int) $metrics['today']['ok'] ); ?></strong>
							<small class="kpi__small" id="sakura-s3-metric-rate"><?php echo esc_html( sprintf( /* translators: 1: failed attempts, 2: failure rate. */ __( '失败 %1$d · 失败率 %2$s', 'sakura-imgopt' ), (int) $metrics['today']['fail'], (string) $metrics['today']['rate_text'] ) ); ?></small>
						</div>
						<div class="kpi">
							<span class="kpi__label"><?php esc_html_e( '平均耗时', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value" id="sakura-s3-metric-avg"><?php echo esc_html( (string) $metrics['today']['avg_text'] ); ?></strong>
							<small class="kpi__small" id="sakura-s3-metric-max"><?php echo esc_html( sprintf( /* translators: %s: slowest duration. */ __( '最慢 %s', 'sakura-imgopt' ), (string) $metrics['today']['max_text'] ) ); ?></small>
						</div>
						<div class="kpi">
							<span class="kpi__label"><?php esc_html_e( '今日上传量', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value" id="sakura-s3-metric-bytes"><?php echo esc_html( (string) $metrics['today']['bytes_text'] ); ?></strong>
							<small class="kpi__small" id="sakura-s3-metric-week"><?php echo esc_html( sprintf( /* translators: %s: week failure rate. */ __( '近 14 天失败率 %s', 'sakura-imgopt' ), (string) $metrics['week']['rate_text'] ) ); ?></small>
						</div>
						<div class="kpi kpi--wide<?php echo $last['has'] ? ' kpi--bad' : ''; ?>">
							<span class="kpi__label"><?php esc_html_e( '最后错误', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value kpi__value--text" id="sakura-s3-metric-error" title="<?php echo esc_attr( (string) $last['message'] ); ?>"><?php echo esc_html( $last['has'] ? $last['message'] : __( '暂无错误记录', 'sakura-imgopt' ) ); ?></strong>
							<small class="kpi__small" id="sakura-s3-metric-error-ago"><?php echo esc_html( $last['has'] ? $last['ago'] : '' ); ?></small>
						</div>
					</div>
				</div>
				<?php
			}
		);

		if ( $count > 0 ) {
			\Sakura\ImageOptimizer\Admin\UI::notice(
				sprintf(
					/* translators: 1: number of unsynced attachments, 2: sync center link. */
					__( '有 %1$d 个附件尚未同步，前往 %2$s 处理。', 'sakura-imgopt' ),
					(int) $count,
					'<a href="' . esc_url( \Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'sync' ) ) . '">' . esc_html__( 'S3 同步中心', 'sakura-imgopt' ) . '</a>'
				),
				'warn'
			);
		}
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Sync_Center_Panel', __NAMESPACE__ . '\\Sync_Panel' );
