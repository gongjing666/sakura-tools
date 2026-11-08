<?php
/**
 * View partial. Included from Media_Library_Module::render_progress_page(); uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var int $batch_size */
/** @var int $delay */

/** @var \Sakura\ImageOptimizer\Media_Library_Module $this */

$user_id = get_current_user_id();
$key     = 'sakura_queue_' . $user_id;
$queue   = get_transient( $key );
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<header class="topbar">
			<div class="brand">
				<span class="brand-mark">S</span>
				<strong>樱花工具箱</strong>
				<span class="path"><span class="slash">/</span> <?php esc_html_e( '批量处理', 'sakura-imgopt' ); ?></span>
			</div>
			<div class="top-actions">
				<span class="conn-pill"><span class="dot"></span> <?php esc_html_e( '批量任务运行中', 'sakura-imgopt' ); ?></span>
				<a class="btn btn--small" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( '返回媒体库', 'sakura-imgopt' ); ?></a>
			</div>
		</header>
		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">BATCH RUNNER / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php esc_html_e( '批量处理', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '批量任务在后台逐张执行，请保持此页面打开。任务结束后会给出成功、失败与跳过统计。', 'sakura-imgopt' ); ?></p>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top"><span class="hero-status-label"><span class="dot"></span> <?php esc_html_e( '队列', 'sakura-imgopt' ); ?></span></div>
				<div class="hero-status-main"><strong><?php echo esc_html( ! empty( $queue['total'] ) ? (string) (int) $queue['total'] : '0' ); ?></strong><span>· <?php esc_html_e( '张图片', 'sakura-imgopt' ); ?></span></div>
				<div class="hero-status-sub"><?php esc_html_e( '任务结束后自动汇总', 'sakura-imgopt' ); ?></div>
			</aside>
		</section>

	<?php if ( empty( $queue ) || empty( $queue['ids'] ) ) : ?>
		<section class="card">
			<div class="card-head"><div><p class="section-kicker">IDLE</p><h2><?php esc_html_e( '当前没有待处理任务', 'sakura-imgopt' ); ?></h2></div></div>
			<div class="card-body">
			<p><?php esc_html_e( '没有待处理的图片。请回到媒体库选择图片后重新发起批量优化或还原。', 'sakura-imgopt' ); ?></p>
			<div class="card-actions">
				<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="btn btn--primary">
					<?php esc_html_e( '返回媒体库', 'sakura-imgopt' ); ?>
				</a>
			</div>
			</div>
		</section>
	<?php else : ?>
		<?php
		$total   = (int) $queue['total'];
		$skipped = isset( $queue['skipped'] ) ? (int) $queue['skipped'] : 0;
		?>
		<?php if ( 'restore' === $queue['mode'] && $skipped > 0 ) : ?>
			<div class="notice notice-warning inline sakura-imgopt-inline-notice">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of skipped attachments. */
							__( '有 %s 张图片存放在云存储或 CDN 上，已跳过还原。', 'sakura-imgopt' ),
							number_format_i18n( $skipped )
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<section class="card">
			<div class="card-body">
			<div id="sakura-progress-wrap" class="sakura-progress">
				<div class="sakura-progress-status">
					<span id="sakura-status-text">
						<?php
						printf(
							/* translators: %s: Number of images queued for processing. */
							esc_html__( '准备处理 %s 张图片…', 'sakura-imgopt' ),
							esc_html( (string) $total )
						);
						?>
					</span>
				</div>

				<div class="sakura-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) max( 1, $total ) ); ?>" aria-valuenow="0" aria-label="<?php esc_attr_e( '批量处理进度', 'sakura-imgopt' ); ?>">
					<div id="sakura-progress-bar" class="sakura-progress-fill"></div>
				</div>

				<p class="sakura-progress-detail"><span id="sakura-progress-detail"></span></p>

				<div id="sakura-progress-done" class="sakura-progress-done" hidden>
					<div id="sakura-progress-summary" class="sakura-progress-summary"></div>
					<p class="sakura-imgopt-panel-actions">
						<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="button button-primary">
							<?php esc_html_e( '返回媒体库', 'sakura-imgopt' ); ?>
						</a>
					</p>
				</div>
			</div>
			</div>
		</section>

		<script>
		(function($) {
			var total   = <?php echo wp_json_encode( (int) $total ); ?>;
			var batch   = <?php echo (int) $batch_size; ?>;
			var delay   = <?php echo (int) $delay; ?>;
			var mode    = <?php echo wp_json_encode( (string) $queue['mode'] ); ?>;
			var running = true;
			var i18n    =
			<?php
			echo wp_json_encode(
				array(
					/* translators: 1: processed count, 2: total count. */
					'processing'      => __( '正在处理 %1$s / %2$s…', 'sakura-imgopt' ),
					/* translators: %s: number of successful items. */
					'successCount'    => __( '成功 %s 张', 'sakura-imgopt' ),
					/* translators: %s: number of failed items. */
					'failCount'       => __( '，失败 %s 张', 'sakura-imgopt' ),
					/* translators: %s: number of skipped items. */
					'skippedCount'    => __( '，跳过 %s 张', 'sakura-imgopt' ),
					'errorStop'       => __( '发生错误，已停止。', 'sakura-imgopt' ),
					'requestFailed'   => __( '请求失败，已停止。请刷新页面重试。', 'sakura-imgopt' ),
					'restore'         => __( '还原', 'sakura-imgopt' ),
					'optimize'        => __( '优化', 'sakura-imgopt' ),
					/* translators: %s: operation name (optimize or restore). */
					'doneTitle'       => __( '%s完成！', 'sakura-imgopt' ),
					/* translators: %s: total number of items. */
					'totalLine'       => __( '总计：%s 张', 'sakura-imgopt' ),
					/* translators: %s: successful item count markup. */
					'successLine'     => __( '成功：%s 张', 'sakura-imgopt' ),
					/* translators: %s: failed item count markup. */
					'failLine'        => __( '失败：%s 张', 'sakura-imgopt' ),
					/* translators: 1: skipped count, 2: skip reason label. */
					'skipLine'        => __( '跳过：%1$s 张%2$s', 'sakura-imgopt' ),
					'skipRestore'     => __( '（云存储或 CDN）', 'sakura-imgopt' ),
					'skipOptimize'    => __( '（动图、超过上限、格式不支持或体积没有变小）', 'sakura-imgopt' ),
				)
			);
			?>
			;

			function format1(template, value) {
				return template.replace('%s', value);
			}

			function format2(template, first, second) {
				return template.replace('%1$s', first).replace('%2$s', second);
			}

			function updateUI(data) {
				var pct = total > 0 ? Math.round((data.processed / total) * 100) : 0;
				$('#sakura-progress-bar').css('width', pct + '%');
				$('.sakura-progress-track').attr('aria-valuenow', pct);
				$('#sakura-status-text').text(format2(i18n.processing, data.processed, total));

				var detail = format1(i18n.successCount, data.success) + format1(i18n.failCount, data.fail);
				if (data.skipped > 0) {
					detail += format1(i18n.skippedCount, data.skipped);
				}
				$('#sakura-progress-detail').text(detail);
			}

			function processNext() {
				if (!running) {
					return;
				}

				$.post(ajaxurl, {
					action: 'sakura_process_batch',
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'sakura_process_batch' ) ); ?>'
				}, function(res) {
					if (!res || !res.success) {
						running = false;
						$('#sakura-status-text').text(i18n.errorStop);
						return;
					}

					var data = res.data;
					updateUI(data);

					if (data.processed >= total) {
						running = false;
						finish(data);
						return;
					}

					setTimeout(processNext, delay);
				}).fail(function() {
					running = false;
					$('#sakura-status-text').text(i18n.requestFailed);
				});
			}

			function finish(data) {
				var skippedCount = parseInt(data.skipped, 10) || 0;
				var skipLabel    = mode === 'restore' ? i18n.skipRestore : i18n.skipOptimize;
				var skipLine     = skippedCount > 0 ? '<br><span class="sakura-progress-skip">' + format2(i18n.skipLine, skippedCount, skipLabel) + '</span>' : '';
				var verb         = mode === 'restore' ? i18n.restore : i18n.optimize;

				$('#sakura-progress-done').prop('hidden', false).show();
				$('#sakura-progress-summary').html(
					'<strong>' + format1(i18n.doneTitle, verb) + '</strong><br>' +
					format1(i18n.totalLine, data.processed) + '<br>' +
					format1(i18n.successLine, '<span class="sakura-progress-success">' + data.success + '</span>') + '<br>' +
					format1(i18n.failLine, '<span class="sakura-progress-fail">' + data.fail + '</span>') +
					skipLine
				);
			}

			$(document).ready(function() {
				processNext();
			});
		})(jQuery);
		</script>
	<?php endif; ?>
	</div>
</div>
