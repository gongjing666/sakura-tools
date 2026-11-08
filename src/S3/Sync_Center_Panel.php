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
		?>
		<section class="sakura-s3-metrics" id="sakura-s3-metrics" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_upload_metrics' ) ); ?>">
			<div class="sakura-s3-metrics-head">
				<div>
					<h2><?php esc_html_e( '上传吞吐', 'sakura-imgopt' ); ?></h2>
					<p class="description"><?php esc_html_e( '上传完成时实时记录成功量、失败率与字节数，页面每 30 秒自动刷新。', 'sakura-imgopt' ); ?></p>
				</div>
				<div class="sakura-s3-metrics-actions">
					<span class="spinner" id="sakura-s3-metrics-spinner"></span>
					<span id="sakura-s3-metrics-updated" class="sakura-s3-metrics-updated"><?php echo esc_html( '' !== $metrics['updated']['ago'] ? sprintf( /* translators: %s: relative time. */ __( '更新于 %s', 'sakura-imgopt' ), $metrics['updated']['ago'] ) : __( '尚未记录', 'sakura-imgopt' ) ); ?></span>
					<button type="button" class="button button-small" id="sakura-s3-metrics-refresh"><?php esc_html_e( '刷新指标', 'sakura-imgopt' ); ?></button>
				</div>
			</div>
			<div class="sakura-s3-metric-grid">
				<div class="sakura-s3-metric">
					<span class="sakura-s3-metric-label"><?php esc_html_e( '今日成功', 'sakura-imgopt' ); ?></span>
					<strong id="sakura-s3-metric-ok"><?php echo esc_html( (string) (int) $metrics['today']['ok'] ); ?></strong>
					<small id="sakura-s3-metric-rate"><?php echo esc_html( sprintf( /* translators: 1: failed attempts, 2: failure rate. */ __( '失败 %1$d · 失败率 %2$s', 'sakura-imgopt' ), (int) $metrics['today']['fail'], (string) $metrics['today']['rate_text'] ) ); ?></small>
				</div>
				<div class="sakura-s3-metric">
					<span class="sakura-s3-metric-label"><?php esc_html_e( '平均耗时', 'sakura-imgopt' ); ?></span>
					<strong id="sakura-s3-metric-avg"><?php echo esc_html( (string) $metrics['today']['avg_text'] ); ?></strong>
					<small id="sakura-s3-metric-max"><?php echo esc_html( sprintf( /* translators: %s: slowest duration. */ __( '最慢 %s', 'sakura-imgopt' ), (string) $metrics['today']['max_text'] ) ); ?></small>
				</div>
				<div class="sakura-s3-metric">
					<span class="sakura-s3-metric-label"><?php esc_html_e( '今日上传量', 'sakura-imgopt' ); ?></span>
					<strong id="sakura-s3-metric-bytes"><?php echo esc_html( (string) $metrics['today']['bytes_text'] ); ?></strong>
					<small id="sakura-s3-metric-week"><?php echo esc_html( sprintf( /* translators: %s: week failure rate. */ __( '近 14 天失败率 %s', 'sakura-imgopt' ), (string) $metrics['week']['rate_text'] ) ); ?></small>
				</div>
				<div class="sakura-s3-metric sakura-s3-metric-wide">
					<span class="sakura-s3-metric-label"><?php esc_html_e( '最后错误', 'sakura-imgopt' ); ?></span>
					<strong id="sakura-s3-metric-error" title="<?php echo esc_attr( (string) $last['message'] ); ?>"><?php echo esc_html( $last['has'] ? $last['message'] : __( '暂无错误记录', 'sakura-imgopt' ) ); ?></strong>
					<small id="sakura-s3-metric-error-ago"><?php echo esc_html( $last['has'] ? $last['ago'] : '' ); ?></small>
				</div>
			</div>
		</section>

		<?php if ( $count > 0 ) : ?>
			<p class="description sakura-s3-sync-hint">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: number of unsynced attachments, 2: sync center link. */
						__( '有 %1$d 个附件尚未同步，前往 %2$s 处理。', 'sakura-imgopt' ),
						(int) $count,
						'<a href="' . esc_url( \Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'sync' ) ) . '">' . esc_html__( 'S3 同步中心', 'sakura-imgopt' ) . '</a>'
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Sync_Center_Panel', __NAMESPACE__ . '\\Sync_Panel' );
