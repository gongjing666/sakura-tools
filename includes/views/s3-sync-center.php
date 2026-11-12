<?php
/**
 * S3 sync center view built on the shared admin UI components.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string,mixed> $snapshot */
/** @var array<int,array<string,mixed>> $rows */
/** @var array<string,int> $counts */
/** @var int $total */
/** @var array<int,array<string,mixed>> $failed */
/** @var int $failed_total */
/** @var int $jobs */
/** @var array<int,array<string,mixed>> $logs */
/** @var \Sakura\ImageOptimizer\S3\Options $options */
/** @var string $settings_url */
/** @var string $action_nonce */
/** @var string $scan_nonce */
/** @var string $retry_nonce */
/** @var string $test_nonce */
/** @var string $export_url */
/** @var array<string,mixed> $uploads */
/** @var bool $is_configured */

use Sakura\ImageOptimizer\S3\Inventory;
use Sakura\ImageOptimizer\S3\Providers;

$action_nonce = isset( $action_nonce ) ? (string) $action_nonce : '';
$ui           = '\Sakura\ImageOptimizer\Admin\UI';

$local_files    = (int) ( $snapshot['local']['files'] ?? 0 );
$local_bytes    = (int) ( $snapshot['local']['bytes'] ?? 0 );
$cloud_objects  = (int) ( $snapshot['cloud']['objects'] ?? 0 );
$cloud_bytes    = (int) ( $snapshot['cloud']['bytes'] ?? 0 );
$synced         = (int) ( $counts['synced'] ?? 0 );
$pending        = (int) ( $counts['pending'] ?? 0 );
$failed_count   = (int) ( $counts['failed'] ?? 0 );
$remote         = (int) ( $counts['remote'] ?? 0 );
$mismatch       = (int) ( $counts['mismatch'] ?? 0 );
$difference     = max( 0, $total - $synced );
$coverage       = $total > 0 ? ( $synced / $total ) * 100 : 0;
$scan_time      = (int) ( $snapshot['scanned_at'] ?? 0 );

$scan_obj       = isset( $snapshot['scan'] ) && is_object( $snapshot['scan'] ) ? $snapshot['scan'] : null;
$scan_state     = isset( $scan_state ) ? (string) $scan_state : ( $scan_obj ? (string) $scan_obj->state : '' );
$scan_phase     = isset( $scan_phase ) ? (string) $scan_phase : ( $scan_obj ? (string) $scan_obj->phase : '' );
$scan_running   = in_array( $scan_state, array( 'queued', 'running' ), true );
$scan_resumable = ! empty( $scan_resumable ) || in_array( $scan_state, array( 'failed', 'cancelled' ), true );
$scan_processed = $scan_obj ? (int) $scan_obj->processed : 0;
$scan_state_labels = array(
	'queued'    => __( '排队中', 'sakura-imgopt' ),
	'running'   => __( '进行中', 'sakura-imgopt' ),
	'done'      => __( '已完成', 'sakura-imgopt' ),
	'failed'    => __( '已中断', 'sakura-imgopt' ),
	'cancelled' => __( '已停止', 'sakura-imgopt' ),
);
$scan_phase_labels = array(
	'local'     => __( '扫描本地目录', 'sakura-imgopt' ),
	'remote'    => __( '读取云端文件列表', 'sakura-imgopt' ),
	'reconcile' => __( '核对汇总', 'sakura-imgopt' ),
);
$index_enabled    = isset( $index_enabled ) ? (bool) $index_enabled : true;
$legacy_error     = isset( $legacy_error ) ? (string) $legacy_error : '';
$legacy_built     = isset( $legacy_built ) ? (int) $legacy_built : 0;
$metrics          = isset( $metrics ) && is_array( $metrics ) ? $metrics : array();
$jobs_summary     = isset( $jobs_summary ) && is_array( $jobs_summary ) ? $jobs_summary : array();
$job_rows         = isset( $job_rows ) && is_array( $job_rows ) ? $job_rows : array();
$toggle_nonce     = isset( $toggle_nonce ) ? (string) $toggle_nonce : '';
$refresh_nonce    = isset( $refresh_nonce ) ? (string) $refresh_nonce : '';
$metric_pages     = (int) ( $metrics['pages'] ?? 0 );
$metric_batches   = (int) ( $metrics['batches'] ?? 0 );
$metric_batch_ms  = (int) ( $metrics['batch_ms'] ?? 0 );
$metric_429       = (int) ( $metrics['s3_429'] ?? 0 );
$metric_5xx       = (int) ( $metrics['s3_5xx'] ?? 0 );
$queue_waiting    = (int) ( $jobs_summary['queued'] ?? 0 ) + (int) ( $jobs_summary['running'] ?? 0 );
$queue_dead       = (int) ( $jobs_summary['dead'] ?? 0 );
$legacy_label     = $legacy_built ? wp_date( 'm-d H:i', $legacy_built ) : '';
$legacy_message   = '';

if ( ! $index_enabled ) {
	$scan_state       = 'compat';
	$scan_running     = false;
	$scan_resumable   = false;
	$scan_state_labels['compat'] = __( '兼容模式', 'sakura-imgopt' );
	$scan_state_label = $scan_state_labels['compat'];
	$scan_tone        = 'neutral';
	$legacy_message   = $legacy_error
		? sprintf( /* translators: 1: build time, 2: error. */ __( '兼容模式：同步记录已停用，当前为缓存快照（%1$s），云端列举失败：%2$s。', 'sakura-imgopt' ), esc_html( $legacy_label ? $legacy_label : '—' ), esc_html( $legacy_error ) )
		: sprintf( /* translators: %s: build time. */ __( '兼容模式：同步记录已停用，当前显示 %s 构建的缓存快照；扫描、搜索与分页暂不可用，重新启用同步记录后恢复。', 'sakura-imgopt' ), esc_html( $legacy_label ? $legacy_label : '—' ) );
} else {
	$scan_state_label = $scan_state_labels[ $scan_state ] ?? __( '未开始', 'sakura-imgopt' );
	$scan_tone        = 'done' === $scan_state ? 'ok' : ( 'failed' === $scan_state ? 'bad' : ( 'cancelled' === $scan_state ? 'neutral' : ( $scan_running ? 'info' : 'neutral' ) ) );
}
$scan_phase_label = $scan_phase_labels[ $scan_phase ] ?? '';
$scan_meta_text   = sprintf( /* translators: 1: scan time, 2: phase. */ __( '同步记录表 %1$s · %2$s', 'sakura-imgopt' ), $scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' ), $scan_running && $scan_phase_label ? sprintf( '%s %s', $scan_phase_label, number_format_i18n( $scan_processed ) ) : __( '实时读取', 'sakura-imgopt' ) );

$bucket         = (string) $options->get( 'bucket', '' );
$endpoint       = (string) $options->get( 'endpoint', '' );
$provider       = (string) $options->get( 'provider', '' );
$provider_label = Providers::all()[ $provider ] ?? $provider;
$upload_path    = $options->upload_path();
$upload_base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
$region         = (string) $options->get( 'region', '' );
$acl            = (string) $options->get( 'object_acl', '' );
$upload_url     = (string) get_option( 'upload_url_path', '' );
$upload_url     = '' !== $upload_url ? $upload_url : $upload_base;
$upload_url     = (string) preg_replace( '#^https?://#', '', untrailingslashit( $upload_url ) );
$cloud_types    = isset( $snapshot['cloud']['types'] ) && is_array( $snapshot['cloud']['types'] ) ? $snapshot['cloud']['types'] : array();
$mix_total      = array_sum( array_map( 'intval', $cloud_types ) );
$mix_colors     = array(
	'image'   => 'var(--g-accent)',
	'video'   => 'var(--g-ink-soft)',
	'archive' => 'var(--g-muted)',
	'other'   => 'var(--g-line-strong)',
);
$mix            = array();
$mix_stops      = array();
$mix_cursor     = 0.0;
foreach ( $mix_colors as $mix_type => $mix_color ) {
	$mix_bytes          = isset( $cloud_types[ $mix_type ] ) ? max( 0, (int) $cloud_types[ $mix_type ] ) : 0;
	$mix_pct            = $mix_total > 0 ? ( $mix_bytes / $mix_total ) * 100 : 0;
	$mix[ $mix_type ]   = array( 'bytes' => $mix_bytes, 'pct' => $mix_pct, 'color' => $mix_color );
	if ( $mix_pct > 0 ) {
		$mix_stops[] = $mix_color . ' ' . round( $mix_cursor, 2 ) . '% ' . round( $mix_cursor + $mix_pct, 2 ) . '%';
		$mix_cursor += $mix_pct;
	}
}
$mix_gradient = $mix_stops ? 'conic-gradient(' . implode( ', ', $mix_stops ) . ')' : 'conic-gradient(var(--g-accent-soft) 0 100%)';
$cloud_value  = $cloud_bytes >= 1073741824 ? $cloud_bytes / 1073741824 : $cloud_bytes / 1048576;
$cloud_unit   = $cloud_bytes >= 1073741824 ? 'GB' : 'MB';

$activity = array();
for ( $day_offset = 6; $day_offset >= 0; $day_offset-- ) {
	$timestamp            = strtotime( '-' . $day_offset . ' days' );
	$day_key              = wp_date( 'Y-m-d', $timestamp );
	$activity[ $day_key ] = array( 'ok' => 0, 'fail' => 0, 'label' => wp_date( 'm/d', $timestamp ) );
}
foreach ( $rows as $activity_row ) {
	$activity_day = wp_date( 'Y-m-d', (int) ( $activity_row['mtime'] ?? 0 ) );
	if ( isset( $activity[ $activity_day ] ) ) {
		if ( 'failed' === ( $activity_row['status'] ?? '' ) ) {
			++$activity[ $activity_day ]['fail'];
		} else {
			++$activity[ $activity_day ]['ok'];
		}
	}
}
$activity_max = 1;
$activity_ok  = 0;
$activity_bad = 0;
foreach ( $activity as $activity_item ) {
	$activity_max = max( $activity_max, $activity_item['ok'] + $activity_item['fail'] );
	$activity_ok += $activity_item['ok'];
	$activity_bad += $activity_item['fail'];
}

$status_labels = array(
	'synced'   => __( '已同步', 'sakura-imgopt' ),
	'pending'  => __( '待上传', 'sakura-imgopt' ),
	'failed'   => __( '同步失败', 'sakura-imgopt' ),
	'remote'   => __( '仅云端', 'sakura-imgopt' ),
	'mismatch' => __( '内容不一致', 'sakura-imgopt' ),
);
$status_tones = array(
	'synced'   => 'ok',
	'pending'  => 'warn',
	'failed'   => 'bad',
	'remote'   => 'neutral',
	'mismatch' => 'warn',
);
$type_labels = array(
	'image'   => __( '图片', 'sakura-imgopt' ),
	'video'   => __( '视频', 'sakura-imgopt' ),
	'archive' => __( '压缩包', 'sakura-imgopt' ),
	'other'   => __( '其他', 'sakura-imgopt' ),
);
$cron_next    = wp_next_scheduled( \Sakura\ImageOptimizer\Sync\Action_Worker::HOOK );
$scan_label   = $scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' );
$media_label  = $upload_url ? $upload_url : __( '未接管媒体 URL', 'sakura-imgopt' );
$status_text  = $is_configured ? __( '连接正常', 'sakura-imgopt' ) : __( '配置不完整', 'sakura-imgopt' );
$page_cursor  = isset( $page_cursor ) ? (string) $page_cursor : '';
$page_more    = ! empty( $page_more );
$page_size    = 25;
$summary_text = sprintf( /* translators: 1: start, 2: end, 3: total. */ __( '显示 %1$s–%2$s 项，共 %3$s 项', 'sakura-imgopt' ), number_format_i18n( 1 ), number_format_i18n( min( $page_size, $total ) ), number_format_i18n( $total ) );
$missing_media = ( new \Sakura\ImageOptimizer\Sync\Attachment_Adopter() )->count_missing();
$ignored_rules = \Sakura\ImageOptimizer\S3\Ignored_Paths::all();
$ignored_saved = isset( $_GET['ignored_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.

$page_status_sub = sprintf(
	/* translators: 1: provider, 2: coverage. */
	__( '%1$s · 覆盖率 %2$s%%', 'sakura-imgopt' ),
	$provider_label,
	number_format_i18n( $coverage, 1 )
);

ob_start();
?>
<div id="sakura-sync-state" data-scan-id="<?php echo esc_attr( (string) ( isset( $scan_id ) ? (int) $scan_id : 0 ) ); ?>" data-scan-state="<?php echo esc_attr( $scan_state ); ?>" data-scan-resumable="<?php echo $scan_resumable ? '1' : '0'; ?>" data-index-enabled="<?php echo $index_enabled ? '1' : '0'; ?>" hidden></div>

<?php if ( ! $index_enabled ) : ?>
	<?php \Sakura\ImageOptimizer\Admin\UI::alert( $legacy_message, 'warn', __( '兼容模式', 'sakura-imgopt' ) ); ?>
	<div class="button-row">
		<button type="button" class="btn btn--small" data-sakura-action="toggle-index" data-sakura-enabled="1" data-sakura-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"><?php esc_html_e( '启用同步记录', 'sakura-imgopt' ); ?></button>
	</div>
<?php endif; ?>

<?php if ( $scan_running || $scan_resumable ) : ?>
	<?php
	$scan_notice = $scan_running
		? sprintf( /* translators: 1: phase, 2: processed. */ __( '核对进行中（%1$s，已处理 %2$s 个），可关闭页面由后台继续；也可随时停止。', 'sakura-imgopt' ), $scan_phase_label ? $scan_phase_label : __( '准备中', 'sakura-imgopt' ), number_format_i18n( $scan_processed ) )
		: __( '上一次核对中断，同步记录仍可用；点击“继续核对”会从断点续跑，不会产生重复行。', 'sakura-imgopt' );
	\Sakura\ImageOptimizer\Admin\UI::notice( $scan_notice, 'info' );
	?>
<?php endif; ?>

<?php if ( $difference > 0 || $failed_total > 0 ) : ?>
	<?php
	$difference_notice = sprintf(
		/* translators: 1: difference, 2: pending, 3: failed, 4: remote. */
		__( '最近一次全量核对发现 <strong>%1$s 项差异</strong>：%2$s 个只在本地上传、%3$s 个上传失败、%4$s 个只在云端。建议先处理失败项，再执行批量上传（按附件补齐全部分辨率并逐个校验）。', 'sakura-imgopt' ),
		esc_html( number_format_i18n( $difference ) ),
		esc_html( number_format_i18n( $pending ) ),
		esc_html( number_format_i18n( $failed_count ) ),
		esc_html( number_format_i18n( $remote ) )
	);
	\Sakura\ImageOptimizer\Admin\UI::alert( $difference_notice, 'warn' );
	?>
<?php endif; ?>

<section class="kpis" id="summary" aria-label="<?php esc_attr_e( '同步摘要', 'sakura-imgopt' ); ?>">
	<article class="kpi">
		<span class="kpi__label"><?php esc_html_e( '本地媒体', 'sakura-imgopt' ); ?></span>
		<span class="kpi__value"><?php echo esc_html( number_format_i18n( $local_files ) ); ?><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></span>
		<span class="kpi__sub"><?php echo esc_html( Inventory::format_bytes( $local_bytes ) . ' · uploads' ); ?></span>
		<span class="kpi__sub"><?php echo esc_html( sprintf( /* translators: %s: scan time. */ __( '最近扫描 %s', 'sakura-imgopt' ), $scan_label ) ); ?></span>
	</article>
	<article class="kpi">
		<span class="kpi__label"><?php esc_html_e( '云端文件', 'sakura-imgopt' ); ?></span>
		<span class="kpi__value"><?php echo esc_html( number_format_i18n( $cloud_objects ) ); ?><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></span>
		<span class="kpi__sub"><?php echo esc_html( Inventory::format_bytes( $cloud_bytes ) . ' · ' . ( $bucket ? $bucket : '—' ) ); ?></span>
		<span class="kpi__sub"><?php esc_html_e( '按云存储清单统计', 'sakura-imgopt' ); ?></span>
	</article>
	<article class="kpi">
		<span class="kpi__label"><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?></span>
		<span class="kpi__value"><?php echo esc_html( number_format_i18n( $synced ) ); ?><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></span>
		<span class="kpi__sub"><?php echo esc_html( sprintf( /* translators: %s: coverage. */ __( '%s%% 覆盖率', 'sakura-imgopt' ), number_format_i18n( $coverage, 1 ) ) ); ?></span>
		<div class="kpi__progress"><div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) (int) round( $coverage ) ); ?>"><span class="progress__bar" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $coverage ) ) ); ?>%"></span></div></div>
	</article>
	<article class="kpi">
		<span class="kpi__label"><?php esc_html_e( '待处理差异', 'sakura-imgopt' ); ?></span>
		<span class="kpi__value"><?php echo esc_html( number_format_i18n( $difference ) ); ?><small><?php esc_html_e( '项', 'sakura-imgopt' ); ?></small></span>
		<span class="kpi__sub"><?php echo esc_html( sprintf( /* translators: 1: pending, 2: failed, 3: remote. */ __( '%1$d 待上传 · %2$d 失败 · %3$d 仅云端', 'sakura-imgopt' ), $pending, $failed_count, $remote ) ); ?></span>
		<span class="kpi__sub"><?php echo esc_html( sprintf( /* translators: %d: mismatch. */ __( '其中 %d 项需人工核对', 'sakura-imgopt' ), $mismatch ) ); ?></span>
	</article>
	<article class="kpi">
		<span class="kpi__label"><?php esc_html_e( '失败任务', 'sakura-imgopt' ); ?></span>
		<span class="kpi__value"><?php echo esc_html( number_format_i18n( $failed_total ) ); ?><small><?php esc_html_e( '个', 'sakura-imgopt' ); ?></small></span>
		<span class="kpi__sub"><?php echo esc_html( sprintf( /* translators: %d: jobs. */ __( '重试队列 %d 个任务', 'sakura-imgopt' ), $jobs ) ); ?></span>
		<span class="kpi__sub"><?php echo esc_html( $cron_next ? __( '动作队列已调度', 'sakura-imgopt' ) : __( '动作队列按需调度', 'sakura-imgopt' ) ); ?></span>
	</article>
</section>

<?php
$sakura_sync_cards = static function () use (
	$scan_tone,
	$scan_state,
	$scan_state_label,
	$scan_meta_text,
	$scan_label,
	$scan_running,
	$scan_resumable,
	$scan_phase_label,
	$scan_processed,
	$coverage,
	$synced,
	$total,
	$local_files,
	$cloud_objects,
	$difference,
	$index_enabled,
	$scan_nonce,
	$refresh_nonce,
	$retry_nonce,
	$failed_total,
	$activity,
	$activity_max,
	$activity_ok,
	$activity_bad,
	$pending,
	$failed_count,
	$remote,
	$mismatch,
	$is_configured,
	$provider_label,
	$bucket,
	$endpoint,
	$media_label,
	$jobs,
	$queue_waiting,
	$queue_dead,
	$metric_pages,
	$metric_batches,
	$metric_batch_ms,
	$metric_429,
	$metric_5xx,
	$cron_next,
	$toggle_nonce,
	$test_nonce,
	$settings_url,
	$mix,
	$mix_gradient,
	$cloud_value,
	$cloud_unit,
	$local_bytes,
	$cloud_bytes,
	$type_labels,
	$rows,
	$page_cursor,
	$page_more,
	$page_size,
	$summary_text,
	$missing_media,
	$export_url,
	$action_nonce,
	$status_labels,
	$status_tones,
	$logs,
	$scan_time,
	$ignored_saved,
	$ignored_rules
): void {
	?>
	<div class="card-grid" data-sakura-layout="sync" data-sakura-group="main">

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'ignored',
				'anchor'      => 'ignored',
				'class'       => 'card--wide',
				'kicker'      => __( 'Exclusions', 'sakura-imgopt' ),
				'title'       => __( '忽略规则', 'sakura-imgopt' ),
				'description' => __( '核对与迁移会跳过这些路径。插件默认忽略自己的备份目录与缓存目录，这里追加你自己的规则：每行一条，相对于 uploads 根目录，可以写目录（如 2024/05），也可以写单个文件（如 .htaccess）。', 'sakura-imgopt' ),
				'save'        => __( '保存规则', 'sakura-imgopt' ),
				'form'        => array(
					'id'      => 'sakura-s3-ignored-form',
					'action'  => admin_url( 'admin-post.php' ),
					'markers' => 'none',
					'hidden'  => array( 'action' => 'sakura_s3_ignored_save' ),
				),
			),
			static function () use ( $ignored_saved, $ignored_rules ): void {
				if ( $ignored_saved ) {
					\Sakura\ImageOptimizer\Admin\UI::notice( __( '忽略规则已保存。点击「重新核对（本地 + 云端）」后生效。', 'sakura-imgopt' ), 'ok' );
				}
				\Sakura\ImageOptimizer\Admin\UI::field(
					array(
						'type'  => 'hidden',
						'name'  => '_wpnonce',
						'value' => wp_create_nonce( 'sakura_s3_ignored_save' ),
					)
				);
				?>
				<div class="field-list">
					<?php
					\Sakura\ImageOptimizer\Admin\UI::field(
						array(
							'type'  => 'textarea',
							'id'    => 'sufm-ignored-paths',
							'name'  => 'ignored_paths',
							'label' => __( '忽略路径', 'sakura-imgopt' ),
							'hint'  => __( '支持目录与单个文件，开头结尾的斜杠可省略。清空保存即恢复默认规则。插件默认规则始终生效。', 'sakura-imgopt' ),
							'value' => \Sakura\ImageOptimizer\S3\Ignored_Paths::to_text(),
							'full'  => true,
							'attrs' => array(
								'rows'        => 6,
								'spellcheck'  => 'false',
								'placeholder' => "2024/05\nprivate/\n.htaccess",
							),
						)
					);
					?>
					<p class="small-text"><?php echo esc_html( sprintf( /* translators: %d: active rules. */ __( '当前 %d 条生效', 'sakura-imgopt' ), count( $ignored_rules ) ) ); ?></p>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'tasks',
				'anchor'      => 'tasks',
				'class'       => 'card--wide',
				'kicker'      => __( 'Task Center', 'sakura-imgopt' ),
				'title'       => __( '任务中心', 'sakura-imgopt' ),
				'description' => __( '核对、重试与补传都从这里开始；失败任务保留错误与重试次数。', 'sakura-imgopt' ),
			),
			static function () use ( $scan_tone, $scan_state, $scan_state_label, $scan_meta_text, $scan_label, $scan_running, $scan_resumable, $scan_phase_label, $scan_processed, $coverage, $synced, $total, $local_files, $cloud_objects, $difference, $index_enabled, $scan_nonce, $refresh_nonce, $retry_nonce, $failed_total, $activity, $activity_max, $activity_ok, $activity_bad, $pending, $failed_count, $remote ): void {
				?>
				<div class="task-summary">
					<div class="task-summary__head">
						<div>
							<div class="task-summary__title">
								<strong><?php esc_html_e( '最近一次全量核对', 'sakura-imgopt' ); ?></strong>
								<?php \Sakura\ImageOptimizer\Admin\UI::badge( $scan_state_label, $scan_tone ); ?>
							</div>
							<p class="small-text" id="sakura-sync-scan-meta" data-state="<?php echo esc_attr( $scan_state ); ?>"><?php echo esc_html( $scan_meta_text ); ?></p>
						</div>
						<div class="task-summary__percent"><?php echo esc_html( number_format_i18n( $coverage, 1 ) ); ?>%</div>
					</div>
					<div class="progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) (int) round( $coverage ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( '同步覆盖进度', 'sakura-imgopt' ); ?>"><span class="progress__bar" style="width:<?php echo esc_attr( (string) min( 100, max( 0, $coverage ) ) ); ?>%"></span></div>
					<div class="progress-meta">
						<span><?php echo wp_kses_post( sprintf( /* translators: 1: synced, 2: total, 3: local files, 4: cloud objects. */ __( '<strong>%1$s</strong> / %2$s 个文件 · 本地 %3$s · 云端 %4$s', 'sakura-imgopt' ), esc_html( number_format_i18n( $synced ) ), esc_html( number_format_i18n( $total ) ), esc_html( number_format_i18n( $local_files ) ), esc_html( number_format_i18n( $cloud_objects ) ) ) ); ?></span>
						<span><?php echo esc_html( $index_enabled ? __( '同步记录表 · 实时读取', 'sakura-imgopt' ) : __( '兼容模式 · 只读快照', 'sakura-imgopt' ) ); ?></span>
					</div>
					<div class="task-stats">
						<span class="task-stat"><span><?php esc_html_e( '本地文件', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $local_files ) ); ?></strong></span>
						<span class="task-stat"><span><?php esc_html_e( '云端文件', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $cloud_objects ) ); ?></strong></span>
						<span class="task-stat"><span><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $synced ) ); ?></strong></span>
						<span class="task-stat"><span><?php esc_html_e( '待处理', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $difference ) ); ?></strong></span>
					</div>
					<div class="button-row task-actions">
						<button type="button" class="btn btn--primary btn--small" data-sakura-action="scan" data-sakura-nonce="<?php echo esc_attr( $scan_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>><?php esc_html_e( '开始完整核对', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--small" data-sakura-action="refresh" data-sakura-nonce="<?php echo esc_attr( $refresh_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>><?php esc_html_e( '刷新差异', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--small" id="sakura-sync-cancel" data-sakura-action="cancel" data-sakura-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_sync_cancel' ) ); ?>" <?php echo $scan_running ? '' : 'hidden'; ?>><?php esc_html_e( '停止核对', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--primary btn--small" id="sakura-sync-resume" data-sakura-action="resume" data-sakura-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_sync_resume' ) ); ?>" <?php echo $scan_resumable ? '' : 'hidden'; ?>><?php esc_html_e( '继续核对', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--small" id="sakura-sync-retry-all" data-sakura-action="retry-all" data-sakura-attachment="0" data-sakura-nonce="<?php echo esc_attr( $retry_nonce ); ?>" <?php disabled( 0 === $failed_total ); ?>><?php echo esc_html( sprintf( /* translators: %d: failed. */ __( '重试失败 (%d)', 'sakura-imgopt' ), $failed_total ) ); ?></button>
						<a class="btn btn--ghost btn--small" href="#logs"><?php esc_html_e( '查看日志', 'sakura-imgopt' ); ?></a>
					</div>
				</div>

				<div class="activity">
					<div class="activity__head">
						<h3 class="activity__title"><?php esc_html_e( '近 7 天文件变更', 'sakura-imgopt' ); ?></h3>
						<div class="activity__legend">
							<span><i class="activity__dot activity__dot--ok"></i><?php esc_html_e( '正常', 'sakura-imgopt' ); ?></span>
							<span><i class="activity__dot activity__dot--bad"></i><?php esc_html_e( '失败', 'sakura-imgopt' ); ?></span>
							<span><?php echo esc_html( sprintf( /* translators: 1: ok, 2: failed. */ __( '共 %1$s / %2$s 个样本', 'sakura-imgopt' ), number_format_i18n( $activity_ok ), number_format_i18n( $activity_bad ) ) ); ?></span>
						</div>
					</div>
					<div class="activity__chart" aria-label="<?php esc_attr_e( '近七天文件变更', 'sakura-imgopt' ); ?>">
						<?php foreach ( $activity as $activity_item ) : ?>
							<?php
							$ok_height   = max( 2, (int) round( ( $activity_item['ok'] / $activity_max ) * 72 ) );
							$fail_height = $activity_item['fail'] > 0 ? max( 2, (int) round( ( $activity_item['fail'] / $activity_max ) * 72 ) ) : 0;
							?>
							<div class="activity__day">
								<div class="activity__bars">
									<span class="activity__bar activity__bar--ok" style="height:<?php echo esc_attr( (string) $ok_height ); ?>px" title="<?php echo esc_attr( sprintf( /* translators: 1: day, 2: count. */ __( '%1$s：正常 %2$d', 'sakura-imgopt' ), $activity_item['label'], $activity_item['ok'] ) ); ?>"></span>
									<?php if ( $fail_height > 0 ) : ?>
										<span class="activity__bar activity__bar--bad" style="height:<?php echo esc_attr( (string) $fail_height ); ?>px" title="<?php echo esc_attr( sprintf( /* translators: 1: day, 2: count. */ __( '%1$s：失败 %2$d', 'sakura-imgopt' ), $activity_item['label'], $activity_item['fail'] ) ); ?>"></span>
									<?php endif; ?>
								</div>
								<span class="activity__label"><?php echo esc_html( $activity_item['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'     => 'health',
				'kicker' => __( 'Connection', 'sakura-imgopt' ),
				'title'  => __( '连接与队列健康', 'sakura-imgopt' ),
			),
			static function () use ( $is_configured, $provider_label, $bucket, $endpoint, $media_label, $scan_label, $local_files, $cloud_objects, $jobs, $queue_waiting, $queue_dead, $metric_pages, $metric_batches, $metric_batch_ms, $metric_429, $metric_5xx, $cron_next, $index_enabled, $toggle_nonce, $test_nonce, $settings_url ): void {
				?>
				<div class="health">
					<div class="health__status<?php echo $is_configured ? '' : ' is-warn'; ?>">
						<span class="health__dot" aria-hidden="true"></span>
						<strong><?php echo esc_html( $is_configured ? __( '配置可用', 'sakura-imgopt' ) : __( '配置不完整', 'sakura-imgopt' ) ); ?></strong>
						<small id="sakura-sync-latency"><?php esc_html_e( '凭据已加密', 'sakura-imgopt' ); ?></small>
					</div>
					<dl class="health__list">
						<div class="health__row"><dt><?php esc_html_e( '服务商', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $provider_label ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '存储桶', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $bucket ? $bucket : '—' ); ?></dd></div>
						<div class="health__row"><dt>Endpoint</dt><dd class="health__mono"><?php echo esc_html( $endpoint ? $endpoint : '—' ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '媒体 URL', 'sakura-imgopt' ); ?></dt><dd class="health__mono"><?php echo esc_html( $media_label ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '最近核对', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: time, 2: local, 3: cloud. */ __( '%1$s · %2$s / %3$s', 'sakura-imgopt' ), $scan_label, number_format_i18n( $local_files ), number_format_i18n( $cloud_objects ) ) ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '重试队列', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: jobs. */ __( '%d 个任务', 'sakura-imgopt' ), $jobs ) ); ?> · <?php echo esc_html( sprintf( /* translators: 1: waiting, 2: dead. */ __( '动作队列 %1$d 待执行 / %2$d 死信', 'sakura-imgopt' ), $queue_waiting, $queue_dead ) ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '本轮指标', 'sakura-imgopt' ); ?></dt><dd class="health__mono"><?php echo esc_html( sprintf( /* translators: 1: pages, 2: batches, 3: avg ms, 4: 429, 5: 5xx. */ __( '页面 %1$d · 批次 %2$d · 均 %3$d ms · 429 %4$d · 5xx %5$d', 'sakura-imgopt' ), $metric_pages, $metric_batches, $metric_batch_ms, $metric_429, $metric_5xx ) ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '定时任务', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $cron_next ? __( '已调度', 'sakura-imgopt' ) : __( '未调度', 'sakura-imgopt' ) ); ?> · <?php esc_html_e( '按需执行', 'sakura-imgopt' ); ?></dd></div>
						<div class="health__row"><dt><?php esc_html_e( '凭据', 'sakura-imgopt' ); ?></dt><dd><?php esc_html_e( '已加密', 'sakura-imgopt' ); ?> · <?php esc_html_e( '未展示', 'sakura-imgopt' ); ?></dd></div>
					</dl>
					<div class="button-row health__actions">
						<button type="button" class="btn btn--small" data-sakura-action="toggle-index" data-sakura-enabled="<?php echo $index_enabled ? '0' : '1'; ?>" data-sakura-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"><?php echo $index_enabled ? esc_html__( '停用同步记录', 'sakura-imgopt' ) : esc_html__( '启用同步记录', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--small" data-sakura-action="test-connection" data-sakura-nonce="<?php echo esc_attr( $test_nonce ); ?>"><?php esc_html_e( '测试连接', 'sakura-imgopt' ); ?></button>
						<a class="btn btn--ghost btn--small" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '连接设置', 'sakura-imgopt' ); ?></a>
					</div>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'     => 'storage',
				'kicker' => __( 'Storage Mix', 'sakura-imgopt' ),
				'title'  => __( '云端体积分布', 'sakura-imgopt' ),
			),
			static function () use ( $mix, $mix_gradient, $cloud_value, $cloud_unit, $cloud_objects, $local_bytes, $cloud_bytes, $type_labels ): void {
				?>
				<div class="storage-mix">
					<div class="storage-mix__donut-wrap">
						<div class="storage-mix__donut" role="img" aria-label="<?php esc_attr_e( '云端文件体积分布', 'sakura-imgopt' ); ?>" style="background:<?php echo esc_attr( $mix_gradient ); ?>">
							<div class="storage-mix__center">
								<strong><?php echo esc_html( number_format_i18n( $cloud_value, 2 ) ); ?></strong>
								<span><?php echo esc_html( $cloud_unit . ' · ' . number_format_i18n( $cloud_objects ) ); ?> <?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></span>
							</div>
						</div>
						<div class="storage-mix__legend">
							<?php foreach ( $mix as $mix_type => $mix_item ) : ?>
								<div class="storage-mix__legend-row"><span class="storage-mix__swatch" style="background:<?php echo esc_attr( $mix_item['color'] ); ?>"></span><?php echo esc_html( $type_labels[ $mix_type ] ); ?> <strong><?php echo esc_html( number_format_i18n( $mix_item['pct'], 1 ) ); ?>%</strong></div>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="storage-mix__foot">
						<span><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '本地 %s', 'sakura-imgopt' ), Inventory::format_bytes( $local_bytes ) ) ); ?></span>
						<strong><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '云端 %s', 'sakura-imgopt' ), Inventory::format_bytes( $cloud_bytes ) ) ); ?></strong>
						<span><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '差 %s', 'sakura-imgopt' ), Inventory::format_bytes( abs( $local_bytes - $cloud_bytes ) ) ) ); ?></span>
					</div>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'reconcile',
				'anchor'      => 'reconcile',
				'class'       => 'card--wide',
				'kicker'      => __( 'Reconciliation', 'sakura-imgopt' ),
				'title'       => sprintf( /* translators: %d: total. */ __( '文件核对（%d 项差异）', 'sakura-imgopt' ), $total ),
				'description' => __( '按文件名、大小和修改时间比对本地与云端；删除操作始终二次确认。', 'sakura-imgopt' ),
				'actions'     => array(
					array(
						'label'   => __( '重新核对（本地 + 云端）', 'sakura-imgopt' ),
						'button'  => true,
						'variant' => 'primary',
						'attrs'   => array(
							'data-sakura-action' => 'scan',
							'data-sakura-nonce'  => $scan_nonce,
						),
					),
					array(
						'label'   => __( '导出当前筛选', 'sakura-imgopt' ),
						'url'     => $export_url,
						'variant' => 'ghost',
						'id'      => 'sakura-sync-export-recon',
						'attrs'   => array( 'data-sakura-role' => 'export' ),
					),
					array(
						'label'   => sprintf( /* translators: %d: files missing an attachment row. */ __( '补建媒体库记录（%d）', 'sakura-imgopt' ), (int) $missing_media ),
						'button'  => true,
						'variant' => 'ghost',
						'id'      => 'sakura-sync-adopt',
						'attrs'   => array( 'data-sakura-action' => 'adopt' ),
						'disabled' => 0 === (int) $missing_media,
					),
				),
			),
			static function () use ( $total, $pending, $failed_count, $remote, $mismatch, $synced, $rows, $page_cursor, $page_more, $page_size, $summary_text, $status_labels, $status_tones, $type_labels ): void {
				?>
				<div class="recon-toolbar">
					<div class="filter-chips" role="tablist" aria-label="<?php esc_attr_e( '差异筛选', 'sakura-imgopt' ); ?>">
						<button type="button" class="filter-chip is-active" role="tab" aria-selected="true" data-sakura-filter-value="all"><?php esc_html_e( '全部', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></button>
						<button type="button" class="filter-chip" role="tab" aria-selected="false" data-sakura-filter-value="pending"><?php esc_html_e( '待上传', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span></button>
						<button type="button" class="filter-chip" role="tab" aria-selected="false" data-sakura-filter-value="failed"><?php esc_html_e( '失败', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $failed_count ) ); ?></span></button>
						<button type="button" class="filter-chip" role="tab" aria-selected="false" data-sakura-filter-value="remote"><?php esc_html_e( '仅云端', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $remote ) ); ?></span></button>
						<button type="button" class="filter-chip" role="tab" aria-selected="false" data-sakura-filter-value="mismatch"><?php esc_html_e( '内容不一致', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $mismatch ) ); ?></span></button>
						<button type="button" class="filter-chip" role="tab" aria-selected="false" data-sakura-filter-value="synced"><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?> <span class="filter-chip__count"><?php echo esc_html( number_format_i18n( $synced ) ); ?></span></button>
					</div>
					<div class="recon-tools">
						<label class="recon-search">
							<span class="screen-reader-text"><?php esc_html_e( '搜索文件', 'sakura-imgopt' ); ?></span>
							<input id="file-search" type="search" placeholder="<?php esc_attr_e( '按路径前缀搜索，如 2024/05/…', 'sakura-imgopt' ); ?>" />
						</label>
						<label class="screen-reader-text" for="type-filter"><?php esc_html_e( '文件类型', 'sakura-imgopt' ); ?></label>
						<select id="type-filter">
							<option value="all"><?php esc_html_e( '全部类型', 'sakura-imgopt' ); ?></option>
							<option value="image"><?php esc_html_e( '图片', 'sakura-imgopt' ); ?></option>
							<option value="video"><?php esc_html_e( '视频', 'sakura-imgopt' ); ?></option>
							<option value="archive"><?php esc_html_e( '压缩包', 'sakura-imgopt' ); ?></option>
							<option value="other"><?php esc_html_e( '其他', 'sakura-imgopt' ); ?></option>
						</select>
						<label class="screen-reader-text" for="date-filter"><?php esc_html_e( '时间范围', 'sakura-imgopt' ); ?></label>
						<select id="date-filter">
							<option value="all"><?php esc_html_e( '全部时间', 'sakura-imgopt' ); ?></option>
							<option value="7"><?php esc_html_e( '最近 7 天', 'sakura-imgopt' ); ?></option>
							<option value="30"><?php esc_html_e( '最近 30 天', 'sakura-imgopt' ); ?></option>
						</select>
					</div>
				</div>

				<p class="small-text recon-adopt-status" id="adopt-progress-text" role="status" aria-live="polite" hidden></p>

				<div class="batch-bar" id="recon-batch" hidden>
					<span class="batch-bar__count"><strong id="batch-count">0</strong> <?php esc_html_e( '项已选', 'sakura-imgopt' ); ?> <span class="small-text"><?php esc_html_e( '最多 100 项', 'sakura-imgopt' ); ?></span></span>
					<div class="button-row">
						<button type="button" class="btn btn--quiet btn--small" id="batch-upload"><?php esc_html_e( '批量上传', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--quiet btn--small" id="batch-download"><?php esc_html_e( '批量下载', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--danger btn--small" id="batch-delete"><?php esc_html_e( '批量删除远端', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--quiet btn--small" id="batch-clear"><?php esc_html_e( '清除选择', 'sakura-imgopt' ); ?></button>
					</div>
				</div>

				<div class="batch-progress" id="batch-progress" hidden>
					<div class="batch-progress__head">
						<span id="batch-progress-text"></span>
						<span id="batch-progress-pct">0%</span>
					</div>
					<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="progress__bar" style="width:0%"></span></div>
				</div>

				<div class="table-scroll">
					<table class="table table--responsive" aria-label="<?php esc_attr_e( '本地与云端文件核对', 'sakura-imgopt' ); ?>">
						<thead>
							<tr>
								<th scope="col" class="cell-select"><input type="checkbox" id="select-all" data-sakura-role="select-all" aria-label="<?php esc_attr_e( '全选当前页', 'sakura-imgopt' ); ?>" /></th>
								<th scope="col"><?php esc_html_e( '文件', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '类型', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '大小', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '本地', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '云端', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '最后变化', 'sakura-imgopt' ); ?></th>
								<th scope="col"><?php esc_html_e( '操作', 'sakura-imgopt' ); ?></th>
							</tr>
						</thead>
						<tbody id="recon-body" data-cursor="<?php echo esc_attr( (string) $page_cursor ); ?>" data-more="<?php echo $page_more ? '1' : '0'; ?>" data-total="<?php echo esc_attr( (string) $total ); ?>">
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$row_status = (string) ( $row['status'] ?? 'synced' );
								$row_type   = (string) ( $row['type'] ?? 'other' );
								$row_name   = (string) ( $row['name'] ?? '' );
								$row_path   = (string) ( $row['path'] ?? '' );
								$row_key    = (string) ( $row['key'] ?? '' );
								if ( '' !== $row_key ) {
									$row_path = $row_key;
								} elseif ( '' !== $row_path && '/' === $row_path[0] ) {
									$sufm_uploads = wp_get_upload_dir();
									$row_path     = ltrim( str_replace( trailingslashit( (string) $sufm_uploads['basedir'] ), '', $row_path ), '/' );
								}
								$row_size   = (int) ( $row['size'] ?? 0 );
								$row_has_l  = ! empty( $row['local'] );
								$row_has_c  = ! empty( $row['cloud'] );
								$row_mtime  = (int) ( $row['mtime'] ?? 0 );
								$row_ext    = strtoupper( (string) pathinfo( $row_name, PATHINFO_EXTENSION ) );
								$row_preview = isset( $row['preview'] ) && is_array( $row['preview'] ) ? $row['preview'] : array();
								if ( empty( $row_preview ) ) {
									$row_preview = array(
										'name'     => $row_name,
										'path'     => $row_path,
										'kind'     => $row_type,
										'status'   => $row_status,
										'size'     => $row_size,
										'url'      => '',
										'download' => '',
									);
								}
								?>
								<tr data-group="<?php echo esc_attr( $row_status ); ?>" data-type="<?php echo esc_attr( $row_type ); ?>" data-key="<?php echo esc_attr( $row_key ); ?>" data-mtime="<?php echo esc_attr( (string) $row_mtime ); ?>">
									<td class="cell-select"><input type="checkbox" class="row-select" value="<?php echo esc_attr( $row_key ); ?>" data-status="<?php echo esc_attr( $row_status ); ?>" data-local="<?php echo $row_has_l ? '1' : '0'; ?>" data-cloud="<?php echo $row_has_c ? '1' : '0'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file name. */ __( '选择 %s', 'sakura-imgopt' ), $row_name ) ); ?>" /></td>
									<td>
										<div class="file-cell">
											<span class="file-cell__name"><?php echo esc_html( $row_name ); ?></span>
											<span class="file-cell__path"><?php echo esc_html( $row_path ); ?></span>
										</div>
									</td>
									<td><span class="type-pill"><?php echo esc_html( $row_ext ? $row_ext : '—' ); ?></span></td>
									<td><?php echo esc_html( $row_has_l ? Inventory::format_bytes( $row_size ) : '—' ); ?></td>
									<td><span class="loc <?php echo $row_has_l ? 'loc--yes' : 'loc--no'; ?>"><span class="loc__dot" aria-hidden="true"></span><?php echo $row_has_l ? esc_html__( '已存在', 'sakura-imgopt' ) : esc_html__( '缺失', 'sakura-imgopt' ); ?></span></td>
									<td><span class="loc <?php echo $row_has_c ? ( 'mismatch' === $row_status ? 'loc--warn' : 'loc--yes' ) : 'loc--no'; ?>"><span class="loc__dot" aria-hidden="true"></span><?php echo $row_has_c ? ( 'mismatch' === $row_status ? esc_html__( '内容不一致', 'sakura-imgopt' ) : esc_html__( '已存在', 'sakura-imgopt' ) ) : esc_html__( '缺失', 'sakura-imgopt' ); ?></span></td>
									<td><?php \Sakura\ImageOptimizer\Admin\UI::badge( $status_labels[ $row_status ] ?? $row_status, $status_tones[ $row_status ] ?? 'neutral' ); ?></td>
									<td><?php echo esc_html( $row_mtime ? wp_date( 'm/d H:i', $row_mtime ) : '—' ); ?></td>
									<td>
										<div class="action-cell">
											<?php if ( 'failed' === $row_status ) : ?>
												<?php if ( $row_has_l ) : ?>
													<button type="button" class="link-btn" data-sakura-action="row-upload" data-sakura-key="<?php echo esc_attr( $row_key ); ?>" data-sakura-confirm="<?php echo $row_has_c ? 'REPLACE' : ''; ?>"><?php esc_html_e( '重新上传', 'sakura-imgopt' ); ?></button>
												<?php else : ?>
													<button type="button" class="link-btn" data-sakura-action="row-download" data-sakura-key="<?php echo esc_attr( $row_key ); ?>"><?php esc_html_e( '下载', 'sakura-imgopt' ); ?></button>
												<?php endif; ?>
											<?php elseif ( 'pending' === $row_status ) : ?>
												<button type="button" class="link-btn" data-sakura-action="row-upload" data-sakura-key="<?php echo esc_attr( $row_key ); ?>"><?php esc_html_e( '上传', 'sakura-imgopt' ); ?></button>
											<?php elseif ( 'remote' === $row_status ) : ?>
												<button type="button" class="link-btn" data-sakura-action="row-download" data-sakura-key="<?php echo esc_attr( $row_key ); ?>"><?php esc_html_e( '下载', 'sakura-imgopt' ); ?></button>
												<button type="button" class="link-btn link-btn--danger" data-sakura-action="row-delete" data-sakura-key="<?php echo esc_attr( $row_key ); ?>" data-sakura-name="<?php echo esc_attr( $row_name ); ?>" data-sakura-size="<?php echo esc_attr( Inventory::format_bytes( (int) ( $row['cloud_size'] ?? 0 ) ) ); ?>"><?php esc_html_e( '删除远端', 'sakura-imgopt' ); ?></button>
											<?php elseif ( 'mismatch' === $row_status ) : ?>
												<button type="button" class="link-btn" data-sakura-action="row-upload" data-sakura-confirm="REPLACE" data-sakura-key="<?php echo esc_attr( $row_key ); ?>"><?php esc_html_e( '重新上传', 'sakura-imgopt' ); ?></button>
											<?php endif; ?>
											<button type="button" class="link-btn" data-sakura-preview="<?php echo esc_attr( (string) wp_json_encode( $row_preview ) ); ?>" data-sakura-name="<?php echo esc_attr( $row_name ); ?>"><?php esc_html_e( '详情', 'sakura-imgopt' ); ?></button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr class="empty-row" id="recon-empty"<?php echo $rows ? ' hidden' : ''; ?>>
								<td colspan="9"><div class="empty-state"><strong class="empty-state__title"><?php esc_html_e( '没有符合筛选条件的文件。', 'sakura-imgopt' ); ?></strong></div></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="pagination" id="recon-foot">
					<div class="pagination__sizes" role="group" aria-label="<?php esc_attr_e( '每页显示行数', 'sakura-imgopt' ); ?>">
						<span><?php esc_html_e( '每页', 'sakura-imgopt' ); ?></span>
						<button type="button" class="pagination__link is-current" data-recon-size="<?php echo esc_attr( (string) $page_size ); ?>"><?php echo esc_html( (string) $page_size ); ?></button>
						<button type="button" class="pagination__link" data-recon-size="50">50</button>
						<button type="button" class="pagination__link" data-recon-size="100">100</button>
					</div>
					<span class="pagination__summary" id="recon-summary"><?php echo esc_html( $summary_text ); ?></span>
					<div class="pagination__pages" id="recon-pager" aria-label="<?php esc_attr_e( '分页', 'sakura-imgopt' ); ?>"></div>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'logs',
				'anchor'      => 'logs',
				'class'       => 'card--wide',
				'kicker'      => __( 'Activity', 'sakura-imgopt' ),
				'title'       => __( '同步日志', 'sakura-imgopt' ),
				'description' => __( '默认显示最近 6 条；可查询更多，完整日志可在任务详情中查看。', 'sakura-imgopt' ),
				'actions'     => array(
					array(
						'label'   => __( '导出差异表格（CSV）', 'sakura-imgopt' ),
						'url'     => $export_url,
						'variant' => 'ghost',
						'id'      => 'sakura-sync-export-logs',
						'attrs'   => array( 'data-sakura-role' => 'export' ),
					),
				),
			),
			static function () use ( $logs, $scan_time ): void {
				?>
				<div class="log-toolbar">
					<div class="filter-chips" aria-label="<?php esc_attr_e( '日志级别', 'sakura-imgopt' ); ?>">
						<button type="button" class="filter-chip is-active" data-log-level="all"><?php esc_html_e( '全部', 'sakura-imgopt' ); ?></button>
						<button type="button" class="filter-chip" data-log-level="error"><?php esc_html_e( '错误', 'sakura-imgopt' ); ?></button>
						<button type="button" class="filter-chip" data-log-level="warning"><?php esc_html_e( '警告', 'sakura-imgopt' ); ?></button>
						<button type="button" class="filter-chip" data-log-level="info"><?php esc_html_e( '信息', 'sakura-imgopt' ); ?></button>
					</div>
				</div>
				<div class="log-list" id="log-list">
					<?php if ( $logs ) : ?>
						<?php foreach ( array_slice( $logs, 0, 6 ) as $log ) : ?>
							<?php
							$log_level = (string) ( $log['level'] ?? 'info' );
							$log_tone  = 'error' === $log_level ? 'bad' : ( 'warning' === $log_level ? 'warn' : 'info' );
							$log_text  = 'error' === $log_level ? __( '错误', 'sakura-imgopt' ) : ( 'warning' === $log_level ? __( '警告', 'sakura-imgopt' ) : __( '信息', 'sakura-imgopt' ) );
							?>
							<div class="log-item" data-level="<?php echo esc_attr( $log_level ); ?>">
								<span class="log-item__time"><?php echo esc_html( wp_date( 'H:i:s', (int) $log['time'] ) ); ?></span>
								<div class="log-item__body">
									<div class="log-item__title"><?php echo esc_html( (string) $log['message'] ); ?> <?php \Sakura\ImageOptimizer\Admin\UI::badge( $log_text, $log_tone ); ?></div>
									<div class="log-item__meta"><?php echo esc_html( 'scan: ' . ( $scan_time ? gmdate( 'Ymd-His', $scan_time ) : '—' ) ); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="empty-state"><strong class="empty-state__title"><?php esc_html_e( '暂无同步事件。', 'sakura-imgopt' ); ?></strong></div>
					<?php endif; ?>
				</div>
				<div class="log-more"<?php echo count( $logs ) > 6 ? '' : ' hidden'; ?>>
					<button type="button" class="btn btn--ghost btn--small" id="sakura-logs-more"><?php echo esc_html( count( $logs ) > 6 ? sprintf( /* translators: %d: number of remaining log rows. */ __( '查询更多日志（还有 %d 条）', 'sakura-imgopt' ), count( $logs ) - 6 ) : __( '查询更多日志', 'sakura-imgopt' ) ); ?></button>
				</div>
				<?php
			}
		);
		?>

		<?php
		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'notes',
				'kicker'      => __( 'Notes', 'sakura-imgopt' ),
				'title'       => __( '核对口径', 'sakura-imgopt' ),
				'description' => __( '页面显示的是差异，不直接等于“未同步”。', 'sakura-imgopt' ),
			),
			static function (): void {
				?>
				<ul class="notes-list">
					<li><strong><?php esc_html_e( '已同步：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '本地与云端 Key 存在，且大小/修改时间在容差内。', 'sakura-imgopt' ); ?></li>
					<li><strong><?php esc_html_e( '待上传：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '本地有、云端无；包含排队中的任务。', 'sakura-imgopt' ); ?></li>
					<li><strong><?php esc_html_e( '仅云端：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '云端有、本地无；不会自动删除，避免误删 CDN 回源文件。', 'sakura-imgopt' ); ?></li>
					<li><strong><?php esc_html_e( '内容不一致：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( 'Key 相同但大小或内容（MD5/ETag）不一致，需重新上传或人工核对。', 'sakura-imgopt' ); ?></li>
					<li><strong><?php esc_html_e( '危险操作：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '删除云端文件必须二次确认；批量删除会先列出数量与对象。', 'sakura-imgopt' ); ?></li>
				</ul>
				<?php
			}
		);
		?>
	</div>
	<?php
};
$sakura_sync_cards();

$sync_body = ob_get_clean();

\Sakura\ImageOptimizer\Admin\UI::page(
	array(
		'id'      => 'storage-sync',
		'module'  => 'storage',
		'tab'     => 'sync',
		'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( 'S3 同步中心', 'sakura-imgopt' ),
		'lede'    => __( '把本地媒体库和云存储放在一起对照：看清哪些已同步、哪些只在一端、哪些失败，并能继续迁移。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '连接状态', 'sakura-imgopt' ),
			'value' => $status_text,
			'sub'   => $page_status_sub,
			'tone'  => $is_configured ? ( ( $failed_total > 0 || ! $index_enabled ) ? 'warn' : 'ok' ) : 'bad',
		),
		'actions' => array(
			array(
				'label'   => __( '导出差异表格（CSV）', 'sakura-imgopt' ),
				'url'     => $export_url,
				'variant' => 'ghost',
				'id'      => 'sakura-sync-export',
				'attrs'   => array( 'data-sakura-role' => 'export' ),
			),
			array(
				'label'   => __( '连接设置', 'sakura-imgopt' ),
				'url'     => $settings_url,
				'variant' => 'quiet',
			),
		),
	),
	static function () use ( $sync_body ): void {
		echo $sync_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
	}
);
