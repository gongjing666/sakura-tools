<?php
/**
 * S3 sync center view, ported from the approved static mockup.
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

$log_payload = array();
foreach ( $logs as $log ) {
	$log_level  = (string) ( $log['level'] ?? 'info' );
	$log_badge  = 'error' === $log_level ? 'badge--bad' : ( 'warning' === $log_level ? 'badge--warn' : 'badge--info' );
	$log_label  = 'error' === $log_level ? __( '错误', 'sakura-imgopt' ) : ( 'warning' === $log_level ? __( '警告', 'sakura-imgopt' ) : __( '信息', 'sakura-imgopt' ) );
	$log_payload[] = array(
		'time'    => wp_date( 'H:i:s', (int) ( $log['time'] ?? 0 ) ),
		'level'   => $log_level,
		'message' => (string) ( $log['message'] ?? '' ),
		'badge'   => $log_badge,
		'label'   => $log_label,
		'scan'    => 'scan: ' . ( $scan_time ? gmdate( 'Ymd-His', $scan_time ) : '—' ),
	);
}

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
$scan_state_label = $scan_state_labels[ $scan_state ] ?? __( '未开始', 'sakura-imgopt' );
$scan_phase_label = $scan_phase_labels[ $scan_phase ] ?? '';
$scan_badge       = 'done' === $scan_state ? 'badge--ok' : ( 'failed' === $scan_state ? 'badge--bad' : ( 'cancelled' === $scan_state ? 'badge--muted' : ( $scan_running ? 'badge--info' : 'badge--muted' ) ) );
$index_enabled   = isset( $index_enabled ) ? (bool) $index_enabled : true;
$legacy_error    = isset( $legacy_error ) ? (string) $legacy_error : '';
$legacy_built    = isset( $legacy_built ) ? (int) $legacy_built : 0;
$metrics         = isset( $metrics ) && is_array( $metrics ) ? $metrics : array();
$jobs_summary    = isset( $jobs_summary ) && is_array( $jobs_summary ) ? $jobs_summary : array();
$job_rows        = isset( $job_rows ) && is_array( $job_rows ) ? $job_rows : array();
$toggle_nonce    = isset( $toggle_nonce ) ? (string) $toggle_nonce : '';
$refresh_nonce   = isset( $refresh_nonce ) ? (string) $refresh_nonce : '';
$metric_pages    = (int) ( $metrics['pages'] ?? 0 );
$metric_batches  = (int) ( $metrics['batches'] ?? 0 );
$metric_batch_ms = (int) ( $metrics['batch_ms'] ?? 0 );
$metric_429      = (int) ( $metrics['s3_429'] ?? 0 );
$metric_5xx      = (int) ( $metrics['s3_5xx'] ?? 0 );
$queue_waiting   = (int) ( $jobs_summary['queued'] ?? 0 ) + (int) ( $jobs_summary['running'] ?? 0 );
$queue_dead     = (int) ( $jobs_summary['dead'] ?? 0 );
$legacy_label    = $legacy_built ? wp_date( 'm-d H:i', $legacy_built ) : '';
if ( ! $index_enabled ) {
	$scan_state     = 'compat';
	$scan_running   = false;
	$scan_resumable = false;
	$scan_state_labels['compat'] = __( '兼容模式', 'sakura-imgopt' );
	$scan_state_label = $scan_state_labels['compat'];
	$scan_badge       = 'badge--muted';
}
$scan_phase_label = $scan_phase_labels[ $scan_phase ] ?? '';
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
$mix_colors     = array( 'image' => '#2b2d30', 'video' => '#5f6265', 'archive' => '#9a9b96', 'other' => '#dcdad3' );
$mix            = array();
$mix_stops      = array();
$mix_cursor     = 0.0;
foreach ( $mix_colors as $mix_type => $mix_color ) {
	$mix_bytes = isset( $cloud_types[ $mix_type ] ) ? max( 0, (int) $cloud_types[ $mix_type ] ) : 0;
	$mix_pct   = $mix_total > 0 ? ( $mix_bytes / $mix_total ) * 100 : 0;
	$mix[ $mix_type ] = array( 'bytes' => $mix_bytes, 'pct' => $mix_pct, 'color' => $mix_color );
	if ( $mix_pct > 0 ) {
		$mix_stops[] = $mix_color . ' ' . round( $mix_cursor, 2 ) . '% ' . round( $mix_cursor + $mix_pct, 2 ) . '%';
		$mix_cursor += $mix_pct;
	}
}
$mix_gradient = $mix_stops ? 'conic-gradient(' . implode( ', ', $mix_stops ) . ')' : 'conic-gradient(#e9e7e1 0 100%)';
$cloud_value  = $cloud_bytes >= 1073741824 ? $cloud_bytes / 1073741824 : $cloud_bytes / 1048576;
$cloud_unit   = $cloud_bytes >= 1073741824 ? 'GB' : 'MB';

$activity = array();
for ( $day_offset = 6; $day_offset >= 0; $day_offset-- ) {
	$timestamp = strtotime( '-' . $day_offset . ' days' );
	$day_key   = wp_date( 'Y-m-d', $timestamp );
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
$status_badges = array(
	'synced'   => 'badge--ok',
	'pending'  => 'badge--warn',
	'failed'   => 'badge--bad',
	'remote'   => 'badge--muted',
	'mismatch' => 'badge--warn',
);
$type_labels = array(
	'image'   => __( '图片', 'sakura-imgopt' ),
	'video'   => __( '视频', 'sakura-imgopt' ),
	'archive' => __( '压缩包', 'sakura-imgopt' ),
	'other'   => __( '其他', 'sakura-imgopt' ),
);
$cron_next   = wp_next_scheduled( \Sakura\ImageOptimizer\Sync\Action_Worker::HOOK );
$scan_label  = $scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' );
$media_label = $upload_url ? $upload_url : __( '未接管媒体 URL', 'sakura-imgopt' );
$status_text = $is_configured ? __( '连接正常', 'sakura-imgopt' ) : __( '配置不完整', 'sakura-imgopt' );
$page_cursor = isset( $page_cursor ) ? (string) $page_cursor : '';
$page_more   = ! empty( $page_more );
$page_size   = 25;
$summary_text = sprintf( /* translators: 1: start, 2: end, 3: total. */ __( '显示 %1$s–%2$s 项，共 %3$s 项', 'sakura-imgopt' ), number_format_i18n( 1 ), number_format_i18n( min( $page_size, $total ) ), number_format_i18n( $total ) );
?>
<div class="sakura-sync-app" data-scan-id="<?php echo esc_attr( (string) ( isset( $scan_id ) ? (int) $scan_id : 0 ) ); ?>" data-scan-state="<?php echo esc_attr( $scan_state ); ?>" data-index-enabled="<?php echo $index_enabled ? '1' : '0'; ?>">

	<?php if ( ! $index_enabled ) : ?>
			<div class="alert-strip" role="status">
				<svg class="icon" aria-hidden="true"><use href="#i-alert"></use></svg>
				<span><?php echo wp_kses_post( $legacy_error ? sprintf( /* translators: %s: error. */ __( '兼容模式：同步记录已停用，当前为缓存快照（%1$s），云端列举失败：%2$s。', 'sakura-imgopt' ), esc_html( $legacy_label ? $legacy_label : '—' ), esc_html( $legacy_error ) ) : sprintf( /* translators: %s: build time. */ __( '兼容模式：同步记录已停用，当前显示 <strong>%s</strong> 构建的缓存快照；扫描、搜索与分页暂不可用，重新启用同步记录后恢复。', 'sakura-imgopt' ), esc_html( $legacy_label ? $legacy_label : '—' ) ) ); ?></span>
				<button class="btn btn--small" type="button" id="sakura-sync-toggle-index" data-enabled="1" data-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"><?php esc_html_e( '启用同步记录', 'sakura-imgopt' ); ?></button>
			</div>
	<?php endif; ?>


	<svg class="svg-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
		<symbol id="i-cloud" viewBox="0 0 24 24"><path d="M7.4 18.2h9.1a4.3 4.3 0 0 0 .7-8.55 6 6 0 0 0-11.4 1.6A4.1 4.1 0 0 0 7.4 18.2Z"/></symbol>
		<symbol id="i-file" viewBox="0 0 24 24"><path d="M6 2.8h7.3L19 8.5v12.7H6z"/><path d="M13.3 2.8v5.7H19"/><path d="M9 13h6M9 16.5h6"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="m8 12.2 2.6 2.6L16.4 9"/></symbol>
		<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3.6 21 19.8H3z"/><path d="M12 9v4.6M12 17.2h.01"/></symbol>
		<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M18.2 9A7.5 7.5 0 0 0 5 6.5L3.8 8.2M5.8 15A7.5 7.5 0 0 0 19 17.5l1.2-1.7"/></symbol>
		<symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.7" cy="10.7" r="6.4"/><path d="m15.5 15.5 4.2 4.2"/></symbol>
		<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4.5M7 9.2l5-5 5 5"/><path d="M5 15.5v3.2h14v-3.2"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4.5v11.3M7 11.5l5 5 5-5"/><path d="M5 18.5h14"/></symbol>
		<symbol id="i-play" viewBox="0 0 24 24"><path d="m8 5 11 7-11 7z"/></symbol>
		<symbol id="i-pause" viewBox="0 0 24 24"><path d="M9 5v14M15 5v14"/></symbol>
		<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5l3 1.7"/></symbol>
		<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14.5 14.5 10"/><path d="M8.2 16.8 6.5 18.5a3.2 3.2 0 0 1-4.5-4.5L5 11"/><path d="m15.8 7.2 1.7-1.7a3.2 3.2 0 0 1 4.5 4.5L19 13"/></symbol>
		<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3.3 19 6v5.3c0 4.4-2.9 7.6-7 9.4-4.1-1.8-7-5-7-9.4V6z"/><path d="m9 12 2 2 4-4"/></symbol>
		<symbol id="i-trash" viewBox="0 0 24 24"><path d="M4.5 7h15M9 7V4.5h6V7M7 7l.8 13h8.4L17 7"/><path d="M10 11v5M14 11v5"/></symbol>
		<symbol id="i-external" viewBox="0 0 24 24"><path d="M13 4h7v7M20 4l-9 9"/><path d="M18 14v5H5V6h5"/></symbol>
	</svg>
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>

	<div class="app">
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'storage',
			'sync',
			array(
				'status'      => esc_html( $status_text ) . ' <small>· ' . esc_html( $is_configured ? __( '配置完整', 'sakura-imgopt' ) : __( '请检查设置', 'sakura-imgopt' ) ) . '</small>',
				'status_tone' => $is_configured ? 'ok' : 'warn',
			)
		);
		?>

		<section class="hero" aria-labelledby="sakura-sync-title">
			<div class="hero-copy">
				<p class="eyebrow"><?php esc_html_e( 'Object Storage / Sync Center', 'sakura-imgopt' ); ?></p>
				<h1 id="sakura-sync-title"><?php esc_html_e( 'S3 同步中心', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '把本地媒体库和云存储放在一起对照：看清哪些已同步、哪些只在一端、哪些失败，并能继续迁移。', 'sakura-imgopt' ); ?></p>
				<p class="hero-note"><?php esc_html_e( '名词说明：「核对」指对比本地与云端的文件清单；「已停止重试」表示失败多次后不再自动重试。', 'sakura-imgopt' ); ?></p>
				<div class="hero-actions">
					<button class="btn btn--primary" type="button" id="sakura-sync-scan" data-nonce="<?php echo esc_attr( $scan_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>>
						<svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg>
						<?php esc_html_e( '开始完整核对', 'sakura-imgopt' ); ?>
					</button>
					<button class="btn" type="button" id="sakura-sync-scan-2" data-nonce="<?php echo esc_attr( $refresh_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>>
						<svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
						<?php esc_html_e( '刷新差异', 'sakura-imgopt' ); ?>
					</button>
					<a class="btn btn--ghost" href="<?php echo esc_url( $export_url ); ?>"><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> <?php esc_html_e( '导出差异表格（CSV）', 'sakura-imgopt' ); ?></a>
					<span class="hero-note"><svg class="icon" style="width:14px;height:14px" aria-hidden="true"><use href="#i-shield"></use></svg> <?php esc_html_e( '只读取元数据，不移动文件', 'sakura-imgopt' ); ?></span>
				</div>
			</div>
			<aside class="hero-status" aria-label="<?php esc_attr_e( '连接摘要', 'sakura-imgopt' ); ?>">
				<div class="hero-status-top">
					<span class="hero-status-label<?php echo $is_configured ? '' : ' is-warn'; ?>"><span class="dot"></span> <?php echo esc_html( $status_text ); ?></span>
					<small><?php echo esc_html( sprintf( /* translators: %s: scan time. */ __( '最近核对 %s', 'sakura-imgopt' ), $scan_label ) ); ?></small>
				</div>
				<div class="hero-status-main">
					<strong><?php echo esc_html( $bucket ? $bucket : __( '尚未配置存储桶', 'sakura-imgopt' ) ); ?></strong>
					<span>· <?php echo esc_html( $provider_label ); ?></span>
				</div>
				<div class="hero-status-sub"><?php echo esc_html( implode( ' · ', array_filter( array( $provider_label, $region, $acl ) ) ) ); ?></div>
				<div class="hero-status-bar"><span style="width:<?php echo esc_attr( (string) min( 100, max( 0, $coverage ) ) ); ?>%"></span></div>
				<div class="hero-status-sub"><?php echo esc_html( sprintf( /* translators: 1: coverage, 2: cloud size. */ __( '同步覆盖率 %1$s%% · 云端 %2$s', 'sakura-imgopt' ), number_format_i18n( $coverage, 1 ), Inventory::format_bytes( $cloud_bytes ) ) ); ?></div>
			</aside>
		</section>

		<nav class="page-nav" aria-label="<?php esc_attr_e( '本页跳转', 'sakura-imgopt' ); ?>">
			<span class="page-nav-label"><?php esc_html_e( '本页', 'sakura-imgopt' ); ?></span>
			<a class="active" href="#summary"><?php esc_html_e( '总览', 'sakura-imgopt' ); ?></a>
			<a href="#tasks"><?php esc_html_e( '任务中心', 'sakura-imgopt' ); ?></a>
			<a href="#reconcile"><?php esc_html_e( '文件核对', 'sakura-imgopt' ); ?></a>
			<a href="#logs"><?php esc_html_e( '日志', 'sakura-imgopt' ); ?></a>
			<a href="#storage"><?php esc_html_e( '存储分布', 'sakura-imgopt' ); ?></a>
			<a href="#ignored"><?php esc_html_e( '忽略规则', 'sakura-imgopt' ); ?></a>
		</nav>

		<section id="summary" class="kpis" aria-label="<?php esc_attr_e( '同步摘要', 'sakura-imgopt' ); ?>">
			<article class="kpi">
				<div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '本地媒体', 'sakura-imgopt' ); ?></span><span class="kpi-icon"><svg class="icon" aria-hidden="true"><use href="#i-file"></use></svg></span></div>
				<div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $local_files ) ); ?></strong><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></div>
				<div class="kpi-sub"><?php echo esc_html( Inventory::format_bytes( $local_bytes ) . ' · uploads' ); ?></div>
				<div class="kpi-foot"><span class="dot"></span> <?php echo esc_html( sprintf( /* translators: %s: scan time. */ __( '最近扫描 %s', 'sakura-imgopt' ), $scan_label ) ); ?></div>
			</article>
			<article class="kpi">
				<div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '云端文件', 'sakura-imgopt' ); ?></span><span class="kpi-icon"><svg class="icon" aria-hidden="true"><use href="#i-cloud"></use></svg></span></div>
				<div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $cloud_objects ) ); ?></strong><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></div>
				<div class="kpi-sub"><?php echo esc_html( Inventory::format_bytes( $cloud_bytes ) . ' · ' . ( $bucket ? $bucket : '—' ) ); ?></div>
				<div class="kpi-foot"><span class="dot"></span> <?php esc_html_e( '按云存储清单统计', 'sakura-imgopt' ); ?></div>
			</article>
			<article class="kpi kpi--ok">
				<div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?></span><span class="kpi-icon"><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg></span></div>
				<div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $synced ) ); ?></strong><small><?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></small></div>
				<div class="kpi-sub"><?php echo esc_html( sprintf( /* translators: %s: coverage. */ __( '%s%% 覆盖率', 'sakura-imgopt' ), number_format_i18n( $coverage, 1 ) ) ); ?></div>
				<div class="kpi-progress"><span style="width:<?php echo esc_attr( (string) min( 100, max( 0, $coverage ) ) ); ?>%"></span></div>
			</article>
			<article class="kpi kpi--warn">
				<div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '待处理差异', 'sakura-imgopt' ); ?></span><span class="kpi-icon"><svg class="icon" aria-hidden="true"><use href="#i-alert"></use></svg></span></div>
				<div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $difference ) ); ?></strong><small><?php esc_html_e( '项', 'sakura-imgopt' ); ?></small></div>
				<div class="kpi-sub"><?php echo esc_html( sprintf( /* translators: 1: pending, 2: failed, 3: remote. */ __( '%1$d 待上传 · %2$d 失败 · %3$d 仅云端', 'sakura-imgopt' ), $pending, $failed_count, $remote ) ); ?></div>
				<div class="kpi-foot"><span class="dot"></span> <?php echo esc_html( sprintf( /* translators: %d: mismatch. */ __( '其中 %d 项需人工核对', 'sakura-imgopt' ), $mismatch ) ); ?></div>
			</article>
			<article class="kpi kpi--bad">
				<div class="kpi-top"><span class="kpi-label"><?php esc_html_e( '失败任务', 'sakura-imgopt' ); ?></span><span class="kpi-icon"><svg class="icon" aria-hidden="true"><use href="#i-alert"></use></svg></span></div>
				<div class="kpi-value"><strong><?php echo esc_html( number_format_i18n( $failed_total ) ); ?></strong><small><?php esc_html_e( '个', 'sakura-imgopt' ); ?></small></div>
				<div class="kpi-sub"><?php echo esc_html( sprintf( /* translators: %d: jobs. */ __( '重试队列 %d 个任务', 'sakura-imgopt' ), $jobs ) ); ?></div>
				<div class="kpi-foot"><span class="dot"></span> <?php echo esc_html( $cron_next ? __( '动作队列已调度', 'sakura-imgopt' ) : __( '动作队列按需调度', 'sakura-imgopt' ) ); ?></div>
			</article>
		</section>

		<?php if ( $scan_running || $scan_resumable ) : ?>
			<div class="alert-strip" role="status">
				<svg class="icon" aria-hidden="true"><use href="#i-clock"></use></svg>
				<span>
					<?php
					if ( $scan_running ) {
						echo esc_html( sprintf( /* translators: 1: phase, 2: processed. */ __( '核对进行中（%1$s，已处理 %2$s 个），可关闭页面由后台继续；也可随时停止。', 'sakura-imgopt' ), $scan_phase_label ? $scan_phase_label : __( '准备中', 'sakura-imgopt' ), number_format_i18n( $scan_processed ) ) );
					} else {
						esc_html_e( '上一次核对中断，同步记录仍可用；点击“继续核对”会从断点续跑，不会产生重复行。', 'sakura-imgopt' );
					}
					?>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( $difference > 0 || $failed_total > 0 ) : ?>
			<div class="alert-strip" role="status">
				<svg class="icon" aria-hidden="true"><use href="#i-alert"></use></svg>
				<span><?php echo wp_kses_post( sprintf( /* translators: 1: difference, 2: pending, 3: failed, 4: remote. */ __( '最近一次全量核对发现 <strong>%1$s 项差异</strong>：%2$s 个只在本地上传、%3$s 个上传失败、%4$s 个只在云端。建议先处理失败项，再执行批量上传（按附件补齐全部分辨率并逐个校验）。', 'sakura-imgopt' ), esc_html( number_format_i18n( $difference ) ), esc_html( number_format_i18n( $pending ) ), esc_html( number_format_i18n( $failed_count ) ), esc_html( number_format_i18n( $remote ) ) ) ); ?></span>
				<a class="btn btn--small" href="#reconcile" data-filter="failed"><?php esc_html_e( '查看失败项', 'sakura-imgopt' ); ?></a>
			</div>
		<?php endif; ?>



		<?php $sufm_ignored_rules = \Sakura\ImageOptimizer\S3\Ignored_Paths::all(); ?>

		<div class="sync-layout" data-sakura-layout="sync" data-sakura-group="main">

			<section id="ignored" class="card" data-sakura-card="ignored" aria-labelledby="ignored-title">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'Exclusions', 'sakura-imgopt' ); ?></p>
						<h2 id="ignored-title"><?php esc_html_e( '忽略规则', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '核对与迁移会跳过这些路径。插件默认忽略自己的备份目录与缓存目录，这里追加你自己的规则：每行一条，相对于 uploads 根目录，可以写目录（如 2024/05），也可以写单个文件（如 .htaccess）。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<?php if ( isset( $_GET['ignored_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect. ?>
						<div class="alert-strip is-ok" role="status">
							<svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg>
							<span><?php esc_html_e( '忽略规则已保存。点击「重新核对（本地 + 云端）」后生效。', 'sakura-imgopt' ); ?></span>
						</div>
					<?php endif; ?>
					<form class="sufm-ignore-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sakura_s3_ignored_save" />
						<?php wp_nonce_field( 'sakura_s3_ignored_save' ); ?>

						<div class="sufm-ignore-field">
							<div class="sufm-ignore-fieldhead">
								<label class="sufm-ignore-label" for="sufm-ignored-paths"><?php esc_html_e( '忽略路径', 'sakura-imgopt' ); ?></label>
								<span class="sufm-ignore-count"><?php echo esc_html( sprintf( /* translators: %d: active rules. */ __( '当前 %d 条生效', 'sakura-imgopt' ), count( $sufm_ignored_rules ) ) ); ?></span>
							</div>
							<textarea id="sufm-ignored-paths" name="ignored_paths" rows="6" class="sufm-ignore-input" spellcheck="false" placeholder="2024/05&#10;private/&#10;.htaccess"><?php echo esc_textarea( \Sakura\ImageOptimizer\S3\Ignored_Paths::to_text() ); ?></textarea>
							<p class="sufm-ignore-hint"><?php esc_html_e( '支持目录与单个文件，开头结尾的斜杠可省略。清空保存即恢复默认规则。', 'sakura-imgopt' ); ?></p>
						</div>

						<div class="card-savebar">
							<span class="card-savebar__note"><?php esc_html_e( '保存后需重新核对才会生效。', 'sakura-imgopt' ); ?></span>
							<button type="submit" class="btn btn--primary"><?php esc_html_e( '保存规则', 'sakura-imgopt' ); ?></button>
						</div>
					</form>
				</div>
			</section>

			<section id="tasks" class="card task-center" data-sakura-card="tasks" aria-labelledby="task-title">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'Task Center', 'sakura-imgopt' ); ?></p>
						<h2 id="task-title"><?php esc_html_e( '任务中心', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '核对、重试与补传都从这里开始；失败任务保留错误与重试次数。', 'sakura-imgopt' ); ?></p>
					</div>
					<div class="card-head-actions">
						<button class="btn btn--small" type="button" id="sakura-sync-scan-3" data-nonce="<?php echo esc_attr( $scan_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>>
							<svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg>
							<?php esc_html_e( '刷新', 'sakura-imgopt' ); ?>
						</button>
					</div>
				</div>
				<div class="task-tabs" role="tablist" aria-label="<?php esc_attr_e( '任务分类', 'sakura-imgopt' ); ?>">
					<button class="task-tab is-active" role="tab" aria-selected="true" type="button" data-filter="all"><?php esc_html_e( '全部差异', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></button>
					<button class="task-tab" role="tab" aria-selected="false" type="button" data-filter="failed"><?php esc_html_e( '失败', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $failed_count ) ); ?></span></button>
					<button class="task-tab" role="tab" aria-selected="false" type="button" data-filter="pending"><?php esc_html_e( '待上传', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span></button>
					<button class="task-tab" role="tab" aria-selected="false" type="button" data-filter="remote"><?php esc_html_e( '仅云端', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $remote ) ); ?></span></button>
				</div>
				<div class="task-panel">
					<div class="task-main">
						<div class="task-main-top">
							<div>
								<div class="task-title">
									<strong><?php esc_html_e( '最近一次全量核对', 'sakura-imgopt' ); ?></strong>
									<span class="badge <?php echo esc_attr( $scan_badge ); ?>"><span class="dot"></span> <?php echo esc_html( $scan_state_label ); ?></span>
									<small id="sakura-sync-scan-meta" data-state="<?php echo esc_attr( $scan_state ); ?>"><?php echo esc_html( sprintf( /* translators: 1: scan time, 2: phase. */ __( '同步记录表 %1$s · %2$s', 'sakura-imgopt' ), $scan_label, $scan_running && $scan_phase_label ? sprintf( '%s %s', $scan_phase_label, number_format_i18n( $scan_processed ) ) : __( '实时读取', 'sakura-imgopt' ) ) ); ?></small>
								</div>
								<p style="margin:6px 0 0;color:var(--muted);font-size:13.5px"><?php esc_html_e( '读取本地图片目录和云端文件清单；不移动文件，可随时刷新。', 'sakura-imgopt' ); ?></p>
							</div>
							<div class="task-percent"><?php echo esc_html( number_format_i18n( $coverage, 1 ) ); ?>%</div>
						</div>
						<div class="progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) (int) round( $coverage ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( '同步覆盖进度', 'sakura-imgopt' ); ?>"><span style="width:<?php echo esc_attr( (string) min( 100, max( 0, $coverage ) ) ); ?>%"></span></div>
						<div class="progress-meta">
							<span><?php echo wp_kses_post( sprintf( /* translators: 1: synced, 2: total, 3: local files, 4: cloud objects. */ __( '<strong>%1$s</strong> / %2$s 个文件 · 本地 %3$s · 云端 %4$s', 'sakura-imgopt' ), esc_html( number_format_i18n( $synced ) ), esc_html( number_format_i18n( $total ) ), esc_html( number_format_i18n( $local_files ) ), esc_html( number_format_i18n( $cloud_objects ) ) ) ); ?></span>
							<span><?php esc_html_e( '同步记录表 · 实时读取', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="task-stats">
							<div class="task-stat"><span><?php esc_html_e( '本地文件', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $local_files ) ); ?></strong></div>
							<div class="task-stat"><span><?php esc_html_e( '云端文件', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $cloud_objects ) ); ?></strong></div>
							<div class="task-stat"><span><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( number_format_i18n( $synced ) ); ?></strong></div>
							<div class="task-stat"><span><?php esc_html_e( '待处理', 'sakura-imgopt' ); ?></span><strong style="color:var(--warn)"><?php echo esc_html( number_format_i18n( $difference ) ); ?></strong></div>
						</div>
						<div class="task-controls">
							<div class="left">
								<button class="btn btn--small" type="button" id="sakura-sync-scan-empty" data-nonce="<?php echo esc_attr( $scan_nonce ); ?>" <?php disabled( ! $index_enabled ); ?>><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> <?php esc_html_e( '重新核对', 'sakura-imgopt' ); ?></button>
								<button class="btn btn--small" type="button" id="sakura-sync-cancel" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_sync_cancel' ) ); ?>" <?php echo $scan_running ? '' : 'hidden'; ?>><svg class="icon" aria-hidden="true"><use href="#i-pause"></use></svg> <?php esc_html_e( '停止核对', 'sakura-imgopt' ); ?></button>
								<button class="btn btn--small btn--primary" type="button" id="sakura-sync-resume" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_sync_resume' ) ); ?>" <?php echo $scan_resumable ? '' : 'hidden'; ?>><svg class="icon" aria-hidden="true"><use href="#i-play"></use></svg> <?php esc_html_e( '继续核对', 'sakura-imgopt' ); ?></button>
								<button class="btn btn--small" type="button" id="sakura-sync-retry-all" data-id="0" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>" <?php disabled( 0 === $failed_total ); ?>><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> <?php echo esc_html( sprintf( /* translators: %d: failed. */ __( '重试失败 (%d)', 'sakura-imgopt' ), $failed_total ) ); ?></button>
								<a class="btn btn--small" href="#logs"><?php esc_html_e( '查看日志', 'sakura-imgopt' ); ?></a>
							</div>
							<span class="right"><?php echo $index_enabled ? esc_html__( '同步记录表实时读取 · 扫描可断点续跑', 'sakura-imgopt' ) : esc_html__( '兼容模式 · 只读快照', 'sakura-imgopt' ); ?></span>
						</div>
					</div>

					<div class="chart-block">
						<div class="chart-head">
							<h3><?php esc_html_e( '近 7 天文件变更', 'sakura-imgopt' ); ?></h3>
							<div class="chart-legend"><span><i class="legend-dot ok"></i><?php esc_html_e( '正常', 'sakura-imgopt' ); ?></span><span><i class="legend-dot bad"></i><?php esc_html_e( '失败', 'sakura-imgopt' ); ?></span><span><?php echo esc_html( sprintf( /* translators: 1: ok, 2: failed. */ __( '共 %1$s / %2$s 个样本', 'sakura-imgopt' ), number_format_i18n( $activity_ok ), number_format_i18n( $activity_bad ) ) ); ?></span></div>
						</div>
						<div class="chart" aria-label="<?php esc_attr_e( '近七天文件变更', 'sakura-imgopt' ); ?>">
							<?php foreach ( $activity as $activity_item ) : ?>
								<?php
								$ok_height   = max( 2, (int) round( ( $activity_item['ok'] / $activity_max ) * 72 ) );
								$fail_height = $activity_item['fail'] > 0 ? max( 2, (int) round( ( $activity_item['fail'] / $activity_max ) * 72 ) ) : 0;
								?>
								<div class="chart-day">
									<div class="chart-bars">
										<span class="bar ok" style="height:<?php echo esc_attr( (string) $ok_height ); ?>px" title="<?php echo esc_attr( sprintf( /* translators: 1: day, 2: count. */ __( '%1$s：正常 %2$d', 'sakura-imgopt' ), $activity_item['label'], $activity_item['ok'] ) ); ?>"></span>
										<?php if ( $fail_height > 0 ) : ?>
											<span class="bar bad" style="height:<?php echo esc_attr( (string) $fail_height ); ?>px" title="<?php echo esc_attr( sprintf( /* translators: 1: day, 2: count. */ __( '%1$s：失败 %2$d', 'sakura-imgopt' ), $activity_item['label'], $activity_item['fail'] ) ); ?>"></span>
										<?php endif; ?>
									</div>
									<span class="chart-label"><?php echo esc_html( $activity_item['label'] ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</section>

			<section class="card" data-sakura-card="health" aria-labelledby="health-title">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'Connection', 'sakura-imgopt' ); ?></p>
							<h2 id="health-title"><?php esc_html_e( '连接与队列健康', 'sakura-imgopt' ); ?></h2>
						</div>
					</div>
					<div class="health">
						<div class="health-status<?php echo $is_configured ? '' : ' is-warn'; ?>">
							<div class="health-status-main"><span class="dot"></span> <?php echo esc_html( $is_configured ? __( '配置可用', 'sakura-imgopt' ) : __( '配置不完整', 'sakura-imgopt' ) ); ?></div>
							<small id="sakura-sync-latency"><?php esc_html_e( '凭据已加密', 'sakura-imgopt' ); ?></small>
						</div>
						<dl class="health-list">
							<div class="health-row"><dt><?php esc_html_e( '服务商', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $provider_label ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '存储桶', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( $bucket ? $bucket : '—' ); ?></dd></div>
							<div class="health-row"><dt>Endpoint</dt><dd class="mono"><?php echo esc_html( $endpoint ? $endpoint : '—' ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '媒体 URL', 'sakura-imgopt' ); ?></dt><dd class="mono"><?php echo esc_html( $media_label ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '最近核对', 'sakura-imgopt' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: time, 2: local, 3: cloud. */ __( '%1$s · %2$s / %3$s', 'sakura-imgopt' ), $scan_label, number_format_i18n( $local_files ), number_format_i18n( $cloud_objects ) ) ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '重试队列', 'sakura-imgopt' ); ?></dt><dd><span class="ok-text"><?php echo esc_html( sprintf( /* translators: %d: jobs. */ __( '%d 个任务', 'sakura-imgopt' ), $jobs ) ); ?></span> · <?php echo esc_html( sprintf( /* translators: 1: waiting, 2: dead. */ __( '动作队列 %1$d 待执行 / %2$d 死信', 'sakura-imgopt' ), (int) $queue_waiting, (int) $queue_dead ) ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '本轮指标', 'sakura-imgopt' ); ?></dt><dd class="mono"><?php echo esc_html( sprintf( /* translators: 1: pages, 2: batches, 3: avg ms, 4: 429, 5: 5xx. */ __( '页面 %1$d · 批次 %2$d · 均 %3$d ms · 429 %4$d · 5xx %5$d', 'sakura-imgopt' ), $metric_pages, $metric_batches, $metric_batch_ms, $metric_429, $metric_5xx ) ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '定时任务', 'sakura-imgopt' ); ?></dt><dd><span class="ok-text"><?php echo $cron_next ? esc_html__( '已调度', 'sakura-imgopt' ) : esc_html__( '未调度', 'sakura-imgopt' ); ?></span> · <?php esc_html_e( '按需执行', 'sakura-imgopt' ); ?></dd></div>
							<div class="health-row"><dt><?php esc_html_e( '凭据', 'sakura-imgopt' ); ?></dt><dd><span class="ok-text"><?php esc_html_e( '已加密', 'sakura-imgopt' ); ?></span> · <?php esc_html_e( '未展示', 'sakura-imgopt' ); ?></dd></div>
						</dl>
						<div class="health-actions">
							<button class="btn btn--small" type="button" id="sakura-sync-toggle-index-2" data-enabled="<?php echo $index_enabled ? '0' : '1'; ?>" data-nonce="<?php echo esc_attr( $toggle_nonce ); ?>"><?php echo $index_enabled ? esc_html__( '停用同步记录', 'sakura-imgopt' ) : esc_html__( '启用同步记录', 'sakura-imgopt' ); ?></button>
							<button class="btn btn--small" type="button" id="sakura-sync-test" data-nonce="<?php echo esc_attr( $test_nonce ); ?>"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> <?php esc_html_e( '测试连接', 'sakura-imgopt' ); ?></button>
							<a class="btn btn--small btn--ghost" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '连接设置', 'sakura-imgopt' ); ?></a>
						</div>
					</div>
				</section>

			<section class="card" data-sakura-card="storage" aria-labelledby="storage-title">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'Storage Mix', 'sakura-imgopt' ); ?></p>
							<h2 id="storage-title"><?php esc_html_e( '云端体积分布', 'sakura-imgopt' ); ?></h2>
						</div>
					</div>
					<div class="storage-card">
						<div class="donut-wrap">
							<div class="donut" role="img" aria-label="<?php esc_attr_e( '云端文件体积分布', 'sakura-imgopt' ); ?>" style="background:<?php echo esc_attr( $mix_gradient ); ?>"><div class="donut-center"><strong><?php echo esc_html( number_format_i18n( $cloud_value, 2 ) ); ?></strong><span><?php echo esc_html( $cloud_unit ); ?> · <?php echo esc_html( number_format_i18n( $cloud_objects ) ); ?> <?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></span></div></div>
							<div class="legend">
								<?php foreach ( $mix as $mix_type => $mix_item ) : ?>
									<div class="legend-row"><span class="swatch" style="background:<?php echo esc_attr( $mix_item['color'] ); ?>"></span><?php echo esc_html( $type_labels[ $mix_type ] ); ?> <strong><?php echo esc_html( number_format_i18n( $mix_item['pct'], 1 ) ); ?>%</strong></div>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="storage-foot"><span><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '本地 %s', 'sakura-imgopt' ), Inventory::format_bytes( $local_bytes ) ) ); ?></span><strong><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '云端 %s', 'sakura-imgopt' ), Inventory::format_bytes( $cloud_bytes ) ) ); ?></strong><span><?php echo esc_html( sprintf( /* translators: %s: size. */ __( '差 %s', 'sakura-imgopt' ), Inventory::format_bytes( abs( $local_bytes - $cloud_bytes ) ) ) ); ?></span></div>
					</div>
				</section>

		<?php $sakura_missing_media = ( new \Sakura\ImageOptimizer\Sync\Attachment_Adopter() )->count_missing(); ?>
		<section id="reconcile" class="card recon" data-sakura-card="reconcile" aria-labelledby="recon-title">
			<div class="recon-head">
				<div>
					<p class="section-kicker"><?php esc_html_e( 'Reconciliation', 'sakura-imgopt' ); ?></p>
					<h2 id="recon-title"><?php esc_html_e( '文件核对', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( sprintf( /* translators: %d: total. */ __( '%d 项差异', 'sakura-imgopt' ), $total ) ); ?></span></h2>
					<p style="margin:4px 0 0;color:var(--muted);font-size:13px"><?php esc_html_e( '按文件名、大小和修改时间比对本地与云端；删除操作始终二次确认。', 'sakura-imgopt' ); ?></p>
				</div>
				<div class="recon-actions">
					<button class="btn btn--small btn--primary" type="button" id="sakura-sync-scan-7" data-nonce="<?php echo esc_attr( $scan_nonce ); ?>"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> <?php esc_html_e( '重新核对（本地 + 云端）', 'sakura-imgopt' ); ?></button>
					<a class="btn btn--small" href="<?php echo esc_url( $export_url ); ?>"><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> <?php esc_html_e( '导出当前筛选', 'sakura-imgopt' ); ?></a>
					<button class="btn btn--small" type="button" id="sakura-sync-adopt" data-nonce="<?php echo esc_attr( $action_nonce ); ?>" title="<?php esc_attr_e( '为「本地有文件、媒体库没有记录」的对象补建附件', 'sakura-imgopt' ); ?>" <?php disabled( 0 === (int) $sakura_missing_media ); ?>>
						<svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg>
						<?php echo esc_html( sprintf( /* translators: %d: files missing an attachment row. */ __( '补建媒体库记录（%d）', 'sakura-imgopt' ), (int) $sakura_missing_media ) ); ?>
					</button>
					<span class="description" id="adopt-progress-text" role="status" aria-live="polite" hidden></span>
				</div>
			</div>
			<div class="filter-row">
				<div class="segmented" role="tablist" aria-label="<?php esc_attr_e( '差异筛选', 'sakura-imgopt' ); ?>">
					<button class="seg-btn is-active" type="button" role="tab" aria-selected="true" data-filter="all"><?php esc_html_e( '全部', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></button>
					<button class="seg-btn" type="button" role="tab" aria-selected="false" data-filter="pending"><?php esc_html_e( '待上传', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span></button>
					<button class="seg-btn" type="button" role="tab" aria-selected="false" data-filter="failed"><?php esc_html_e( '失败', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $failed_count ) ); ?></span></button>
					<button class="seg-btn" type="button" role="tab" aria-selected="false" data-filter="remote"><?php esc_html_e( '仅云端', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $remote ) ); ?></span></button>
					<button class="seg-btn" type="button" role="tab" aria-selected="false" data-filter="mismatch"><?php esc_html_e( '内容不一致', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $mismatch ) ); ?></span></button>
					<button class="seg-btn" type="button" role="tab" aria-selected="false" data-filter="synced"><?php esc_html_e( '已同步', 'sakura-imgopt' ); ?> <span class="count"><?php echo esc_html( number_format_i18n( $synced ) ); ?></span></button>
				</div>
				<div class="table-tools">
					<label class="search">
						<svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
						<span class="sr-only"><?php esc_html_e( '搜索文件', 'sakura-imgopt' ); ?></span>
						<input id="file-search" type="search" placeholder="<?php esc_attr_e( '按路径前缀搜索，如 2024/05/…', 'sakura-imgopt' ); ?>">
					</label>
					<label class="sr-only" for="type-filter"><?php esc_html_e( '文件类型', 'sakura-imgopt' ); ?></label>
					<select id="type-filter" class="select">
						<option value="all"><?php esc_html_e( '全部类型', 'sakura-imgopt' ); ?></option>
						<option value="image"><?php esc_html_e( '图片', 'sakura-imgopt' ); ?></option>
						<option value="video"><?php esc_html_e( '视频', 'sakura-imgopt' ); ?></option>
						<option value="archive"><?php esc_html_e( '压缩包', 'sakura-imgopt' ); ?></option>
						<option value="other"><?php esc_html_e( '其他', 'sakura-imgopt' ); ?></option>
					</select>
					<label class="sr-only" for="date-filter"><?php esc_html_e( '时间范围', 'sakura-imgopt' ); ?></label>
					<select id="date-filter" class="select">
						<option value="all"><?php esc_html_e( '全部时间', 'sakura-imgopt' ); ?></option>
						<option value="7"><?php esc_html_e( '最近 7 天', 'sakura-imgopt' ); ?></option>
						<option value="30"><?php esc_html_e( '最近 30 天', 'sakura-imgopt' ); ?></option>
					</select>
				</div>
			</div>
			<div class="batch-bar" id="recon-batch" hidden>
				<span class="batch-count"><strong id="batch-count">0</strong> <?php esc_html_e( '项已选', 'sakura-imgopt' ); ?> <span class="batch-limit"><?php esc_html_e( '最多 100 项', 'sakura-imgopt' ); ?></span></span>
				<div class="batch-actions">
					<button class="btn btn--ghost btn--sm" type="button" id="batch-upload"><?php esc_html_e( '批量上传', 'sakura-imgopt' ); ?></button>
					<button class="btn btn--ghost btn--sm" type="button" id="batch-download"><?php esc_html_e( '批量下载', 'sakura-imgopt' ); ?></button>
					<button class="btn btn--ghost btn--sm batch-danger" type="button" id="batch-delete"><?php esc_html_e( '批量删除远端', 'sakura-imgopt' ); ?></button>
					<button class="link-btn" type="button" id="batch-clear"><?php esc_html_e( '清除选择', 'sakura-imgopt' ); ?></button>
				</div>
			</div>
			<div class="batch-progress" id="batch-progress" hidden>
				<div class="batch-progress-head">
					<span class="batch-progress-text" id="batch-progress-text"></span>
					<span class="batch-progress-pct" id="batch-progress-pct">0%</span>
				</div>
				<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span style="width:0%"></span></div>
			</div>
			<div class="table-wrap">
				<table aria-label="<?php esc_attr_e( '本地与云端文件核对', 'sakura-imgopt' ); ?>">
					<thead>
						<tr>
							<th scope="col" class="col-select"><input type="checkbox" id="select-all" aria-label="<?php esc_attr_e( '全选当前页', 'sakura-imgopt' ); ?>"></th>
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
							$row_cloud  = (int) ( $row['cloud_size'] ?? 0 );
							$row_has_l  = ! empty( $row['local'] );
							$row_has_c  = ! empty( $row['cloud'] );
							$row_id     = (int) ( $row['id'] ?? 0 );
							$row_mtime  = (int) ( $row['mtime'] ?? 0 );
							$row_ext    = strtoupper( (string) pathinfo( $row_name, PATHINFO_EXTENSION ) );
							$detail_url = $row_id ? (string) get_edit_post_link( $row_id, 'raw' ) : add_query_arg( array( 's' => rawurlencode( $row_name ) ), admin_url( 'upload.php' ) );
							?>
							<tr data-group="<?php echo esc_attr( $row_status ); ?>" data-type="<?php echo esc_attr( $row_type ); ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-mtime="<?php echo esc_attr( (string) $row_mtime ); ?>">
								<td class="col-select"><input type="checkbox" class="row-select" value="<?php echo esc_attr( (string) $row['key'] ); ?>" data-status="<?php echo esc_attr( $row_status ); ?>" data-local="<?php echo $row_has_l ? '1' : '0'; ?>" data-cloud="<?php echo $row_has_c ? '1' : '0'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file name. */ __( '选择 %s', 'sakura-imgopt' ), $row_name ) ); ?>"></td>
								<td>
									<div class="file-cell">
										<span class="file-thumb"><svg class="icon" aria-hidden="true"><use href="#i-file"></use></svg></span>
										<span>
											<span class="file-name"><?php echo esc_html( $row_name ); ?></span>
											<span class="file-path"><?php echo esc_html( $row_path ); ?></span>
										</span>
									</div>
								</td>
								<td><span class="type-pill"><?php echo esc_html( $row_ext ? $row_ext : '—' ); ?></span></td>
								<td><?php echo esc_html( $row_has_l ? Inventory::format_bytes( $row_size ) : '—' ); ?></td>
								<td><?php if ( $row_has_l ) : ?><span class="loc yes"><span class="dot"></span><?php esc_html_e( '已存在', 'sakura-imgopt' ); ?></span><?php else : ?><span class="loc no"><span class="dot"></span><?php esc_html_e( '缺失', 'sakura-imgopt' ); ?></span><?php endif; ?></td>
								<td><?php if ( $row_has_c ) : ?><span class="loc <?php echo 'mismatch' === $row_status ? 'warn' : 'yes'; ?>"><span class="dot"></span><?php echo 'mismatch' === $row_status ? esc_html__( '内容不一致', 'sakura-imgopt' ) : esc_html__( '已存在', 'sakura-imgopt' ); ?></span><?php else : ?><span class="loc no"><span class="dot"></span><?php esc_html_e( '缺失', 'sakura-imgopt' ); ?></span><?php endif; ?></td>
								<td><span class="badge <?php echo esc_attr( $status_badges[ $row_status ] ?? 'badge--muted' ); ?>"><span class="dot"></span><?php echo esc_html( $status_labels[ $row_status ] ?? $row_status ); ?></span></td>
								<td><?php echo esc_html( $row_mtime ? wp_date( 'm/d H:i', $row_mtime ) : '—' ); ?></td>
								<td>
									<div class="action-cell">
										<?php if ( 'failed' === $row_status ) : ?>
											<?php if ( $row_has_l ) : ?>
												<button class="link-btn sakura-sync-act" type="button" data-sync-action="upload" data-confirm="<?php echo $row_has_c ? 'REPLACE' : ''; ?>" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '重新上传', 'sakura-imgopt' ); ?></button>
											<?php else : ?>
												<button class="link-btn sakura-sync-act" type="button" data-sync-action="download" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '下载', 'sakura-imgopt' ); ?></button>
											<?php endif; ?>
										<?php elseif ( 'pending' === $row_status ) : ?>
											<button class="link-btn sakura-sync-act" type="button" data-sync-action="upload" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '上传', 'sakura-imgopt' ); ?></button>
										<?php elseif ( 'remote' === $row_status ) : ?>
											<button class="link-btn sakura-sync-act" type="button" data-sync-action="download" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '下载', 'sakura-imgopt' ); ?></button>
											<button class="link-btn danger sakura-sync-delete" type="button" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-name="<?php echo esc_attr( (string) $row['name'] ); ?>" data-size="<?php echo esc_attr( Inventory::format_bytes( (int) $row['cloud_size'] ) ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '删除远端', 'sakura-imgopt' ); ?></button>
										<?php elseif ( 'mismatch' === $row_status ) : ?>
											<button class="link-btn sakura-sync-act" type="button" data-sync-action="upload" data-confirm="REPLACE" data-key="<?php echo esc_attr( (string) $row['key'] ); ?>" data-nonce="<?php echo esc_attr( $action_nonce ); ?>"><?php esc_html_e( '重新上传', 'sakura-imgopt' ); ?></button>
										<?php endif; ?>
										<a class="link-btn" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( '详情', 'sakura-imgopt' ); ?></a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="empty-row" id="recon-empty"><?php esc_html_e( '没有符合筛选条件的文件。', 'sakura-imgopt' ); ?></div>
			</div>
			<div class="stats-history-foot" id="recon-foot">
				<div class="stats-page-size" role="group" aria-label="<?php esc_attr_e( '每页显示行数', 'sakura-imgopt' ); ?>">
					<span><?php esc_html_e( '每页', 'sakura-imgopt' ); ?></span>
					<button type="button" class="stats-size-btn is-active" data-recon-size="25">25</button>
					<button type="button" class="stats-size-btn" data-recon-size="50">50</button>
					<button type="button" class="stats-size-btn" data-recon-size="100">100</button>
				</div>
				<span class="stats-history-summary" id="recon-summary"><?php echo esc_html( $summary_text ); ?></span>
				<div class="stats-history-pager" id="recon-pager" aria-label="<?php esc_attr_e( '分页', 'sakura-imgopt' ); ?>"></div>
			</div>
		</section>

			<section id="logs" class="card logs" data-sakura-card="logs" aria-labelledby="logs-title">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'Activity', 'sakura-imgopt' ); ?></p>
						<h2 id="logs-title"><?php esc_html_e( '同步日志', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '默认显示最近 6 条；可查询更多，完整日志可在任务详情中查看。', 'sakura-imgopt' ); ?></p>
					</div>
					<div class="log-filters" aria-label="<?php esc_attr_e( '日志级别', 'sakura-imgopt' ); ?>">
						<button class="chip is-active" type="button" data-level="all"><?php esc_html_e( '全部', 'sakura-imgopt' ); ?></button>
						<button class="chip" type="button" data-level="error"><?php esc_html_e( '错误', 'sakura-imgopt' ); ?></button>
						<button class="chip" type="button" data-level="warning"><?php esc_html_e( '警告', 'sakura-imgopt' ); ?></button>
						<button class="chip" type="button" data-level="info"><?php esc_html_e( '信息', 'sakura-imgopt' ); ?></button>
					</div>
				</div>
				<div class="log-list" id="log-list">
					<?php if ( $logs ) : ?>
						<?php foreach ( array_slice( $logs, 0, 6 ) as $log ) : ?>
							<?php
							$log_level = (string) ( $log['level'] ?? 'info' );
							$log_badge = 'error' === $log_level ? 'badge--bad' : ( 'warning' === $log_level ? 'badge--warn' : 'badge--info' );
							$log_text  = 'error' === $log_level ? __( '错误', 'sakura-imgopt' ) : ( 'warning' === $log_level ? __( '警告', 'sakura-imgopt' ) : __( '信息', 'sakura-imgopt' ) );
							?>
							<div class="log-item" data-level="<?php echo esc_attr( $log_level ); ?>">
								<span class="log-time"><?php echo esc_html( wp_date( 'H:i:s', (int) $log['time'] ) ); ?></span>
								<div class="log-body">
									<div class="log-title"><?php echo esc_html( (string) $log['message'] ); ?> <span class="badge <?php echo esc_attr( $log_badge ); ?>"><?php echo esc_html( $log_text ); ?></span></div>
									<div class="log-object"><?php echo esc_html( 'scan: ' . ( $scan_time ? gmdate( 'Ymd-His', $scan_time ) : '—' ) ); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p style="padding:12px 22px;color:var(--muted);font-size:13px"><?php esc_html_e( '暂无同步事件。', 'sakura-imgopt' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="logs-more"<?php echo count( $logs ) > 6 ? '' : ' hidden'; ?>>
					<button class="btn btn--small" type="button" id="sakura-logs-more"><?php /* translators: %d: number of remaining log rows */ echo esc_html( count( $logs ) > 6 ? sprintf( __( '查询更多日志（还有 %d 条）', 'sakura-imgopt' ), count( $logs ) - 6 ) : __( '查询更多日志', 'sakura-imgopt' ) ); ?></button>
				</div>
				<script type="application/json" id="sakura-logs-data"><?php echo wp_json_encode( $log_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ); ?></script>
				<div class="logs-foot"><a class="btn btn--small btn--ghost" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( '导出差异表格（CSV）', 'sakura-imgopt' ); ?> <svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg></a></div>
			</section>

			<section id="storage" class="card" data-sakura-card="notes" aria-labelledby="storage-note-title">
				<div class="card-head">
					<div>
						<p class="section-kicker"><?php esc_html_e( 'Notes', 'sakura-imgopt' ); ?></p>
						<h2 id="storage-note-title"><?php esc_html_e( '核对口径', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '页面显示的是差异，不直接等于“未同步”。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="storage-card storage-card--notes">
					<p style="margin:0 0 9px"><strong><?php esc_html_e( '已同步：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '本地与云端 Key 存在，且大小/修改时间在容差内。', 'sakura-imgopt' ); ?></p>
					<p style="margin:0 0 9px"><strong><?php esc_html_e( '待上传：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '本地有、云端无；包含排队中的任务。', 'sakura-imgopt' ); ?></p>
					<p style="margin:0 0 9px"><strong><?php esc_html_e( '仅云端：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '云端有、本地无；不会自动删除，避免误删 CDN 回源文件。', 'sakura-imgopt' ); ?></p>
					<p style="margin:0 0 9px"><strong><?php esc_html_e( '内容不一致：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( 'Key 相同但大小或内容（MD5/ETag）不一致，需重新上传或人工核对。', 'sakura-imgopt' ); ?></p>
					<p style="margin:0"><strong><?php esc_html_e( '危险操作：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '删除云端文件必须二次确认；批量删除要求输入 DELETE 并勾选风险确认。', 'sakura-imgopt' ); ?></p>
				</div>
			</section>
		</div>

		<div class="sakura-modal" id="sakura-sync-delete-modal" hidden aria-hidden="true">
			<div class="sakura-modal__backdrop" data-modal-close></div>
			<div class="sakura-modal__card" role="dialog" aria-modal="true" aria-labelledby="sakura-sync-delete-title" aria-describedby="sakura-sync-delete-desc">
				<div class="sakura-modal__icon" aria-hidden="true"><svg class="icon"><use href="#i-alert"></use></svg></div>
				<p class="section-kicker"><?php esc_html_e( 'Danger Zone', 'sakura-imgopt' ); ?></p>
				<h2 id="sakura-sync-delete-title"><?php esc_html_e( '从云端永久删除这个文件？', 'sakura-imgopt' ); ?></h2>
				<p id="sakura-sync-delete-desc"><?php esc_html_e( '此操作会直接从云存储删除文件，不可撤销；不会删除本地文件，但使用 CDN 的页面可能马上出现图片 404。请确认这个文件确实不再需要。', 'sakura-imgopt' ); ?></p>
				<div class="sakura-modal__object">
					<code id="sakura-sync-delete-key"></code>
					<span><span id="sakura-sync-delete-name"></span> · <span id="sakura-sync-delete-size"></span></span>
				</div>
				<label class="sakura-modal__type" for="sakura-sync-delete-confirm-text">
					<span><?php esc_html_e( '请输入 DELETE 以确认（区分大小写）', 'sakura-imgopt' ); ?></span>
					<input type="text" id="sakura-sync-delete-confirm-text" autocomplete="off" spellcheck="false" inputmode="latin" placeholder="DELETE" />
				</label>
				<p class="sakura-modal__error" id="sakura-sync-delete-error" role="alert" hidden></p>
				<div class="sakura-modal__actions">
					<button class="btn btn--ghost" type="button" data-modal-close><?php esc_html_e( '取消', 'sakura-imgopt' ); ?></button>
					<button class="btn btn--danger" type="button" id="sakura-sync-delete-confirm" disabled><?php esc_html_e( '永久删除云端文件', 'sakura-imgopt' ); ?></button>
				</div>
			</div>
		</div>

		
		<footer class="page-footer">
			<span><svg class="icon" style="width:14px;height:14px" aria-hidden="true"><use href="#i-shield"></use></svg> <?php esc_html_e( '扫描只读；上传、下载、删除均由行内动作显式触发。', 'sakura-imgopt' ); ?></span>
			<span>樱花工具箱 · S3 同步中心</span>
		</footer>
	</div>
</div>
