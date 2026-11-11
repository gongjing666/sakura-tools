<?php
/**
 * Batch progress page on the refactored admin UI stack.
 *
 * Included from Media_Library_Module::render_progress_page(); uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var int $batch_size
 * @var int $delay
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \Sakura\ImageOptimizer\Media_Library_Module $this */

$user_id = get_current_user_id();
$queue   = get_transient( 'sakura_queue_' . $user_id );

if ( ! is_array( $queue ) ) {
	$queue = array();
}

$has_queue = ! empty( $queue['ids'] );
$total     = $has_queue ? max( 0, (int) ( $queue['total'] ?? 0 ) ) : 0;
$processed = $has_queue ? max( 0, (int) ( $queue['processed'] ?? 0 ) ) : 0;
$success   = $has_queue ? max( 0, (int) ( $queue['success'] ?? 0 ) ) : 0;
$fail      = $has_queue ? max( 0, (int) ( $queue['fail'] ?? 0 ) ) : 0;
$skipped   = $has_queue ? max( 0, (int) ( $queue['skipped'] ?? 0 ) ) : 0;
$mode      = $has_queue && 'restore' === ( $queue['mode'] ?? '' ) ? 'restore' : 'optimize';
$mode_text = 'restore' === $mode ? __( '还原', 'sakura-imgopt' ) : __( '优化', 'sakura-imgopt' );
$percent   = $total > 0 ? min( 100, (int) round( $processed / $total * 100 ) ) : 0;

UI::page(
	array(
		'id'      => 'media-progress',
		'module'  => '',
		'eyebrow' => 'BATCH RUNNER / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '批量处理', 'sakura-imgopt' ),
		'lede'    => $has_queue
			? __( '批量任务在后台逐张执行，请保持此页面打开。任务结束后会给出成功、失败与跳过统计。', 'sakura-imgopt' )
			: __( '没有正在运行的批量任务。回到媒体库选择图片后，可以发起批量优化或还原。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '队列', 'sakura-imgopt' ),
			'value' => (string) $total,
			'unit'  => __( '张', 'sakura-imgopt' ),
			'sub'   => $has_queue
				? sprintf(
					/* translators: 1: operation name, 2: batch size. */
					__( '%1$s任务 · 每批 %2$s 张', 'sakura-imgopt' ),
					$mode_text,
					number_format_i18n( max( 1, (int) $batch_size ) )
				)
				: __( '等待新任务', 'sakura-imgopt' ),
			'tone'  => $has_queue ? 'ok' : 'neutral',
		),
		'actions' => array(
			array(
				'label'   => __( '返回媒体库', 'sakura-imgopt' ),
				'url'     => admin_url( 'upload.php' ),
				'variant' => 'ghost',
			),
		),
	),
	function () use ( $has_queue, $total, $processed, $success, $fail, $skipped, $mode, $mode_text, $percent, $batch_size, $delay ) {
		if ( ! $has_queue ) {
			UI::card(
				array(
					'id'          => 'progress-idle',
					'wide'        => true,
					'kicker'      => 'IDLE',
					'title'       => __( '当前没有待处理任务', 'sakura-imgopt' ),
					'description' => __( '批量队列只保存当前用户的待处理图片，任务结束后会自动清空。', 'sakura-imgopt' ),
				),
				static function () {
					UI::empty_state(
						__( '队列是空的', 'sakura-imgopt' ),
						__( '回到媒体库勾选图片，再使用「图像优化」或「还原原图」发起任务。', 'sakura-imgopt' ),
						array(
							'label' => __( '打开媒体库', 'sakura-imgopt' ),
							'url'   => admin_url( 'upload.php' ),
						)
					);
				}
			);
			return;
		}

		if ( 'restore' === $mode && $skipped > 0 ) {
			UI::alert(
				sprintf(
					/* translators: %s: number of skipped attachments. */
					__( '有 %s 张图片存放在云存储或 CDN 上，已跳过还原。', 'sakura-imgopt' ),
					number_format_i18n( $skipped )
				),
				'warn'
			);
		}

		UI::card(
			array(
				'id'          => 'progress-task',
				'wide'        => true,
				'kicker'      => 'TASK',
				'title'       => __( '本次任务', 'sakura-imgopt' ),
				'description' => __( '任务参数在发起批量处理时确定，刷新页面或重试不会改变队列。', 'sakura-imgopt' ),
			),
			static function () use ( $total, $mode_text, $batch_size, $delay ) {
				?>
				<div class="task-facts">
					<div class="task-fact">
						<span class="task-fact__label"><?php echo esc_html__( '操作', 'sakura-imgopt' ); ?></span>
						<strong class="task-fact__value"><?php echo esc_html( $mode_text ); ?></strong>
					</div>
					<div class="task-fact">
						<span class="task-fact__label"><?php echo esc_html__( '队列', 'sakura-imgopt' ); ?></span>
						<strong class="task-fact__value"><?php echo esc_html( number_format_i18n( $total ) ); ?><small><?php echo esc_html__( ' 张', 'sakura-imgopt' ); ?></small></strong>
					</div>
					<div class="task-fact">
						<span class="task-fact__label"><?php echo esc_html__( '节奏', 'sakura-imgopt' ); ?></span>
						<strong class="task-fact__value"><?php echo esc_html( sprintf( (string) __( '每批 %1$s 张', 'sakura-imgopt' ), number_format_i18n( max( 1, (int) $batch_size ) ) ) ); ?><small><?php echo esc_html( sprintf( (string) __( '，间隔 %s ms', 'sakura-imgopt' ), number_format_i18n( max( 0, (int) $delay ) ) ) ); ?></small></strong>
					</div>
				</div>
				<?php
			}
		);

		UI::card(
			array(
				'id'          => 'progress-live',
				'wide'        => true,
				'kicker'      => 'LIVE',
				'title'       => __( '处理进度', 'sakura-imgopt' ),
				'description' => __( '进度会自动推进；网络中断时可以在这里重试，剩余的图片仍会继续处理。', 'sakura-imgopt' ),
			),
			static function () use ( $total, $processed, $success, $fail, $skipped, $percent, $mode, $mode_text ) {
				$initial_status = $processed > 0
					? sprintf(
						/* translators: 1: processed count, 2: total count. */
						__( '已处理 %1$s / %2$s…', 'sakura-imgopt' ),
						number_format_i18n( $processed ),
						number_format_i18n( $total )
					)
					: sprintf(
						/* translators: %s: total number of queued images. */
						__( '准备处理 %s 张图片…', 'sakura-imgopt' ),
						number_format_i18n( $total )
					);
				?>
				<div
					id="sakura-progress-wrap"
					class="progress-panel"
					data-total="<?php echo esc_attr( (string) $total ); ?>"
					data-processed="<?php echo esc_attr( (string) $processed ); ?>"
					data-success="<?php echo esc_attr( (string) $success ); ?>"
					data-fail="<?php echo esc_attr( (string) $fail ); ?>"
					data-skipped="<?php echo esc_attr( (string) $skipped ); ?>"
					data-mode="<?php echo esc_attr( $mode ); ?>"
					data-verb="<?php echo esc_attr( $mode_text ); ?>"
				>
					<div class="progress-panel__head">
						<span id="sakura-status-text" class="progress-panel__status" role="status" aria-live="polite"><?php echo esc_html( $initial_status ); ?></span>
						<span id="sakura-progress-percent" class="progress-panel__percent"><?php echo esc_html( (string) $percent ); ?>%</span>
					</div>

					<div
						class="progress progress--lg"
						role="progressbar"
						aria-valuemin="0"
						aria-valuemax="<?php echo esc_attr( (string) max( 1, $total ) ); ?>"
						aria-valuenow="<?php echo esc_attr( (string) $processed ); ?>"
						aria-label="<?php esc_attr_e( '批量处理进度', 'sakura-imgopt' ); ?>"
					>
						<div id="sakura-progress-bar" class="progress__bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></div>
					</div>

					<p class="progress-panel__detail" id="sakura-progress-detail"></p>

					<div class="progress-counters" aria-live="polite">
						<div class="progress-counter progress-counter--ok">
							<span class="progress-counter__label"><?php echo esc_html__( '成功', 'sakura-imgopt' ); ?></span>
							<strong class="progress-counter__value" id="sakura-count-success"><?php echo esc_html( number_format_i18n( $success ) ); ?></strong>
						</div>
						<div class="progress-counter progress-counter--bad">
							<span class="progress-counter__label"><?php echo esc_html__( '失败', 'sakura-imgopt' ); ?></span>
							<strong class="progress-counter__value" id="sakura-count-fail"><?php echo esc_html( number_format_i18n( $fail ) ); ?></strong>
						</div>
						<div class="progress-counter">
							<span class="progress-counter__label"><?php echo esc_html__( '跳过', 'sakura-imgopt' ); ?></span>
							<strong class="progress-counter__value" id="sakura-count-skipped"><?php echo esc_html( number_format_i18n( $skipped ) ); ?></strong>
						</div>
					</div>

					<div id="sakura-progress-error" class="progress-error" role="alert" hidden>
						<p id="sakura-progress-error-text" class="progress-error__text"></p>
						<button type="button" class="btn btn--ghost btn--small" id="sakura-progress-retry"><?php echo esc_html__( '重试', 'sakura-imgopt' ); ?></button>
					</div>

					<div id="sakura-progress-done" class="progress-done" hidden>
						<div class="progress-done__head">
							<span class="badge badge--ok"><?php echo esc_html__( '完成', 'sakura-imgopt' ); ?></span>
							<h3 id="sakura-progress-summary" class="progress-done__title"></h3>
						</div>
						<dl class="progress-done__stats">
							<div class="progress-done__stat">
								<dt><?php echo esc_html__( '总计', 'sakura-imgopt' ); ?></dt>
								<dd id="sakura-done-total"><?php echo esc_html( number_format_i18n( $total ) ); ?></dd>
							</div>
							<div class="progress-done__stat progress-done__stat--ok">
								<dt><?php echo esc_html__( '成功', 'sakura-imgopt' ); ?></dt>
								<dd id="sakura-done-success"><?php echo esc_html( number_format_i18n( $success ) ); ?></dd>
							</div>
							<div class="progress-done__stat progress-done__stat--bad">
								<dt><?php echo esc_html__( '失败', 'sakura-imgopt' ); ?></dt>
								<dd id="sakura-done-fail"><?php echo esc_html( number_format_i18n( $fail ) ); ?></dd>
							</div>
							<div class="progress-done__stat" id="sakura-done-skip-row"<?php echo $skipped > 0 ? '' : ' hidden'; ?>>
								<dt><?php echo esc_html__( '跳过', 'sakura-imgopt' ); ?></dt>
								<dd id="sakura-done-skipped"><?php echo esc_html( number_format_i18n( $skipped ) ); ?></dd>
							</div>
						</dl>
						<p id="sakura-done-note" class="progress-done__note" hidden></p>
						<div class="progress-done__actions">
							<a class="btn btn--primary" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php echo esc_html__( '返回媒体库', 'sakura-imgopt' ); ?></a>
						</div>
					</div>
				</div>
				<?php
			}
		);
	}
);
