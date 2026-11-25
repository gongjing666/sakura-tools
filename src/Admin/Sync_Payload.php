<?php
/**
 * Data the sync center island renders.
 *
 * The classic view in includes/views/s3-sync-center.php still owns the fallback
 * path (a checkout without a built bundle), so the arithmetic below mirrors that
 * view line for line instead of replacing it: one renderer is always reachable
 * and the other can be compared against it. When a number changes, both files
 * change together.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Page;
use Sakura\ImageOptimizer\S3\Bucket_Label;
use Sakura\ImageOptimizer\S3\Ignored_Paths;
use Sakura\ImageOptimizer\S3\Inventory;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Providers;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Attachment_Adopter;
use Sakura\ImageOptimizer\Sync\Sync_Calibrator;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the module's view data into the island payload.
 */
final class Sync_Payload {

	/**
	 * Page id, also the Layout and Page_Assets key.
	 */
	public const PAGE = 'storage-sync';

	/**
	 * View data from Sync_Center_Module::page_data().
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Storage options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Client configuration from the module (nonces, URLs, copy).
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data    View data.
	 * @param array<string,mixed> $config  window.sakuraPage.data, nonces included.
	 * @param Options|null        $options Storage options.
	 */
	public function __construct( array $data, array $config = array(), ?Options $options = null ) {
		$this->data    = $data;
		$this->config  = $config;
		$this->options = $options ?? new Options();
	}

	/**
	 * Whole payload handed to React through the bootstrap script.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$view = $this->numbers();
		$page = $this->page_args( $view );

		return array(
			'version' => SAKURA_TOOLS_VERSION,
			'page'    => $page,
			'head'    => Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'storage', 'sync' ),
			'view'    => $view,
			'cards'   => $this->cards( $view ),
			'rows'    => $this->rows(),
			'logs'    => $this->logs( $view ),
			'config'  => $this->config( $view ),
			'strings' => $this->strings(),
		);
	}

	/**
	 * Every number and label the page shows, computed once.
	 *
	 * @return array<string,mixed>
	 */
	private function numbers(): array {
		$data     = $this->data;
		$snapshot = isset( $data['snapshot'] ) && is_array( $data['snapshot'] ) ? $data['snapshot'] : array();
		$counts   = isset( $data['counts'] ) && is_array( $data['counts'] ) ? $data['counts'] : array();
		$total    = isset( $data['total'] ) ? (int) $data['total'] : 0;

		$local_files   = (int) ( $snapshot['local']['files'] ?? 0 );
		$local_bytes   = (int) ( $snapshot['local']['bytes'] ?? 0 );
		$cloud_objects = (int) ( $snapshot['cloud']['objects'] ?? 0 );
		$cloud_bytes   = (int) ( $snapshot['cloud']['bytes'] ?? 0 );
		$synced        = (int) ( $counts['synced'] ?? 0 );
		$pending       = (int) ( $counts['pending'] ?? 0 );
		$failed_count  = (int) ( $counts['failed'] ?? 0 );
		$remote        = (int) ( $counts['remote'] ?? 0 );
		$mismatch      = (int) ( $counts['mismatch'] ?? 0 );
		$difference    = max( 0, $total - $synced );
		$coverage      = $total > 0 ? ( $synced / $total ) * 100 : 0.0;
		$scan_time     = (int) ( $snapshot['scanned_at'] ?? 0 );

		$scan_obj       = isset( $snapshot['scan'] ) && is_object( $snapshot['scan'] ) ? $snapshot['scan'] : null;
		$scan_state     = isset( $data['scan_state'] ) ? (string) $data['scan_state'] : ( $scan_obj ? (string) $scan_obj->state : '' );
		$scan_phase     = isset( $data['scan_phase'] ) ? (string) $data['scan_phase'] : ( $scan_obj ? (string) $scan_obj->phase : '' );
		$scan_running   = in_array( $scan_state, array( 'queued', 'running' ), true );
		$scan_resumable = ! empty( $data['scan_resumable'] ) || in_array( $scan_state, array( 'failed', 'cancelled' ), true );
		$scan_processed = $scan_obj ? (int) $scan_obj->processed : 0;
		$scan_total     = $scan_obj ? (int) $scan_obj->total_remote : 0;

		$calibrate_age   = $scan_time > 0 ? max( 0, time() - $scan_time ) : 0;
		$calibrate_stale = $scan_obj && ! $scan_running && $calibrate_age >= Sync_Calibrator::STALE_SECONDS;

		$index_enabled   = isset( $data['index_enabled'] ) ? (bool) $data['index_enabled'] : true;
		$is_configured   = isset( $data['is_configured'] ) ? (bool) $data['is_configured'] : false;
		$storage_enabled = isset( $data['is_enabled'] ) ? (bool) $data['is_enabled'] : false;
		$frozen          = $is_configured && ! $storage_enabled;
		$gate            = $this->options->reconcile_pending();
		$reconcile_gate  = is_array( $gate ) ? $gate : array();
		$reconcile_gated = ! $frozen && $is_configured && $storage_enabled && array() !== $reconcile_gate;
		$locked          = $frozen || $reconcile_gated;

		$legacy_error   = isset( $data['legacy_error'] ) ? (string) $data['legacy_error'] : '';
		$legacy_built   = isset( $data['legacy_built'] ) ? (int) $data['legacy_built'] : 0;
		$legacy_label   = $legacy_built ? wp_date( 'm-d H:i', $legacy_built ) : '';
		$legacy_message = '';
		$state_label    = '';
		$state_tone     = 'neutral';

		$state_labels = array(
			'queued'    => __( '排队中', 'sakura-imgopt' ),
			'running'   => __( '进行中', 'sakura-imgopt' ),
			'done'      => __( '已完成', 'sakura-imgopt' ),
			'failed'    => __( '已中断', 'sakura-imgopt' ),
			'cancelled' => __( '已停止', 'sakura-imgopt' ),
		);
		$phase_labels = array(
			'local'     => __( '扫描本地目录', 'sakura-imgopt' ),
			'remote'    => __( '读取云端文件列表', 'sakura-imgopt' ),
			'reconcile' => __( '核对汇总', 'sakura-imgopt' ),
		);

		if ( ! $index_enabled ) {
			$scan_state       = 'compat';
			$scan_running     = false;
			$scan_resumable   = false;
			$state_labels['compat'] = __( '兼容模式', 'sakura-imgopt' );
			$state_label      = $state_labels['compat'];
			$state_tone       = 'neutral';
			$legacy_message   = '' !== $legacy_error
				? sprintf(
					/* translators: 1: build time, 2: error. */
					__( '兼容模式：同步记录已停用，当前为缓存快照（%1$s），云端列举失败：%2$s。', 'sakura-imgopt' ),
					esc_html( '' !== $legacy_label ? $legacy_label : '—' ),
					esc_html( $legacy_error )
				)
				: sprintf(
					/* translators: %s: build time. */
					__( '兼容模式：同步记录已停用，当前显示 %s 构建的缓存快照；扫描、搜索与分页暂不可用，重新启用同步记录后恢复。', 'sakura-imgopt' ),
					esc_html( '' !== $legacy_label ? $legacy_label : '—' )
				);
		} else {
			$state_label = $state_labels[ $scan_state ] ?? __( '未开始', 'sakura-imgopt' );
			$state_tone  = 'done' === $scan_state ? 'ok' : ( 'failed' === $scan_state ? 'bad' : ( 'cancelled' === $scan_state ? 'neutral' : ( $scan_running ? 'info' : 'neutral' ) ) );
		}

		$phase_label = $phase_labels[ $scan_phase ] ?? '';

		$bucket         = (string) $this->options->get( 'bucket', '' );
		$space          = $this->options->space();
		$provider       = (string) $this->options->get( 'provider', '' );
		$bucket_display = '' !== $bucket ? Bucket_Label::display( $bucket, $space, $provider ) : '';
		$provider_label = Providers::all()[ $provider ] ?? $provider;
		$bucket_label   = '' !== $bucket ? $bucket_display : __( '未配置', 'sakura-imgopt' );
		$uploads        = isset( $data['uploads'] ) && is_array( $data['uploads'] ) ? $data['uploads'] : array();
		$upload_base    = (string) ( $uploads['baseurl'] ?? '' );
		$upload_url     = (string) get_option( 'upload_url_path', '' );
		$upload_url     = '' !== $upload_url ? $upload_url : $upload_base;
		$media_label    = '' !== $upload_url ? (string) preg_replace( '#^https?://#', '', untrailingslashit( $upload_url ) ) : '';

		$cloud_types = isset( $snapshot['cloud']['types'] ) && is_array( $snapshot['cloud']['types'] ) ? $snapshot['cloud']['types'] : array();
		$mix_total   = array_sum( array_map( 'intval', $cloud_types ) );
		$mix_colors  = array(
			'image'   => 'var(--g-petal-3)',
			'video'   => 'var(--g-sky)',
			'archive' => 'var(--g-sun)',
			'other'   => '#c9b6c2',
		);
		$mix_items   = array();
		$mix_stops   = array();
		$mix_cursor  = 0.0;
		foreach ( $mix_colors as $mix_type => $mix_color ) {
			$mix_bytes = isset( $cloud_types[ $mix_type ] ) ? max( 0, (int) $cloud_types[ $mix_type ] ) : 0;
			$mix_pct   = $mix_total > 0 ? ( $mix_bytes / $mix_total ) * 100 : 0.0;

			$type_names = array(
				'image'   => __( '图片', 'sakura-imgopt' ),
				'video'   => __( '视频', 'sakura-imgopt' ),
				'archive' => __( '压缩包', 'sakura-imgopt' ),
				'other'   => __( '其他', 'sakura-imgopt' ),
			);
			$mix_items[] = array(
				'type'      => $mix_type,
				'label'     => $type_names[ $mix_type ],
				'bytes'     => $mix_bytes,
				'pct'       => $mix_pct,
				'pct_label' => number_format_i18n( $mix_pct, 1 ),
				'color'     => $mix_color,
			);

			if ( $mix_pct > 0 ) {
				$mix_stops[]  = $mix_color . ' ' . round( $mix_cursor, 2 ) . '% ' . round( $mix_cursor + $mix_pct, 2 ) . '%';
				$mix_cursor  += $mix_pct;
			}
		}

		$mix_gradient = $mix_stops
			? 'conic-gradient(' . implode( ', ', $mix_stops ) . ')'
			: 'conic-gradient(var(--g-track) 0 100%)';
		$cloud_value  = $cloud_bytes >= 1073741824 ? $cloud_bytes / 1073741824 : $cloud_bytes / 1048576;
		$cloud_unit   = $cloud_bytes >= 1073741824 ? 'GB' : 'MB';

		// The donut now reads the live index (cloud objects by kind), so a site
		// that never ran a full scan still sees its real shape after an upload.
		$mix_state = 'ready';
		if ( ! $index_enabled ) {
			$mix_state = 'compat';
		} elseif ( $scan_running && 0 === $cloud_objects ) {
			$mix_state = 'scanning';
		} elseif ( $cloud_objects > 0 ) {
			$mix_state = 'ready';
		} elseif ( $scan_resumable ) {
			$mix_state = 'interrupted';
		} elseif ( ! $scan_obj ) {
			$mix_state = 'unscanned';
		} else {
			$mix_state = 'empty';
		}

		$mix_copy = array(
			'unscanned'   => array(
				__( '还没有核对记录', 'sakura-imgopt' ),
				__( '这张卡片的占比只来自完整核对写入的记录。点“开始完整核对”扫描本地目录与对象存储后，这里会按图片、视频、压缩包、其他显示云端体积占比。', 'sakura-imgopt' ),
			),
			'scanning'    => array(
				__( '正在核对', 'sakura-imgopt' ),
				__( '核对跑完之后，这里会自动显示云端对象的类型占比，无需手动刷新。', 'sakura-imgopt' ),
			),
			'interrupted' => array(
				__( '上次核对没有跑完', 'sakura-imgopt' ),
				__( '云端对象还没统计完，所以暂时没有分布数据。点“继续核对”会从断点续跑，跑完这里就会出现占比。', 'sakura-imgopt' ),
			),
			'empty'       => array(
				__( '云端暂时没有对象', 'sakura-imgopt' ),
				__( '同步记录里还没有云端对象。上传过的文件会立刻计入，历史对象需要跑一次完整核对才会录入。', 'sakura-imgopt' ),
			),
			'compat'      => array(
				__( '云端暂时没有对象', 'sakura-imgopt' ),
				__( '兼容模式下实时读取云端清单，当前没有可统计的对象。', 'sakura-imgopt' ),
			),
		);
		$mix_title = $mix_copy[ $mix_state ][0] ?? '';
		$mix_desc  = $mix_copy[ $mix_state ][1] ?? '';

		if ( 'ready' === $mix_state ) {
			if ( $scan_running ) {
				$mix_desc = __( '核对正在进行，占比按同步记录实时统计；核对跑完会再校准一次。', 'sakura-imgopt' );
			} elseif ( $scan_time ) {
				$mix_desc = sprintf(
					/* translators: %s: scan time. */
					__( '按同步记录实时统计，上传与删除会立刻反映在这里；最近一次完整核对在 %s。', 'sakura-imgopt' ),
					wp_date( 'm-d H:i', $scan_time )
				);
			} else {
				$mix_desc = __( '按同步记录实时统计，上传与删除会立刻反映在这里；还没有完整核对记录，跑一次核对可以补齐历史上没有走过插件的对象。', 'sakura-imgopt' );
			}
		}

		$metrics         = isset( $data['metrics'] ) && is_array( $data['metrics'] ) ? $data['metrics'] : array();
		$jobs_summary    = isset( $data['jobs_summary'] ) && is_array( $data['jobs_summary'] ) ? $data['jobs_summary'] : array();
		$queue_waiting   = (int) ( $jobs_summary['queued'] ?? 0 ) + (int) ( $jobs_summary['running'] ?? 0 );
		$queue_dead      = (int) ( $jobs_summary['dead'] ?? 0 );

		$scan_label = $scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' );

		// KPI strip and the Sakura Frontline rail: the three numbers the page
		// answers first ("do the three sides agree?"), then the breakdown.
		$kpis      = array(
			array(
				'label' => __( '本地媒体', 'sakura-imgopt' ),
				'value' => number_format_i18n( $local_files ),
				'unit'  => __( '个文件', 'sakura-imgopt' ),
				'subs'  => array(
					Inventory::format_bytes( $local_bytes ) . ' · uploads',
					sprintf(
						/* translators: %s: last scan time. */
						__( '最近扫描 %s', 'sakura-imgopt' ),
						$scan_label
					),
				),
			),
			array(
				'label' => __( '云端文件', 'sakura-imgopt' ),
				'value' => number_format_i18n( $cloud_objects ),
				'unit'  => __( '个文件', 'sakura-imgopt' ),
				'subs'  => array(
					Inventory::format_bytes( $cloud_bytes ) . ' · ' . ( '' !== $bucket_display ? $bucket_display : '—' ),
					__( '按云存储清单统计', 'sakura-imgopt' ),
				),
				'title' => $bucket,
			),
			array(
				'label'   => __( '已同步', 'sakura-imgopt' ),
				'value'   => number_format_i18n( $synced ),
				'unit'    => __( '个文件', 'sakura-imgopt' ),
				'subs'    => array(
					sprintf(
						/* translators: %s: coverage percentage. */
						__( '%s%% 覆盖率', 'sakura-imgopt' ),
						number_format_i18n( $coverage, 1 )
					),
				),
				'progress' => $coverage,
				'synced'   => $coverage >= 99.95,
			),
			array(
				'label' => __( '待处理差异', 'sakura-imgopt' ),
				'value' => number_format_i18n( $difference ),
				'unit'  => __( '项', 'sakura-imgopt' ),
				'subs'  => array(
					sprintf(
						/* translators: 1: pending uploads, 2: failed uploads, 3: remote-only files. */
						__( '%1$d 待上传 · %2$d 失败 · %3$d 仅云端', 'sakura-imgopt' ),
						$pending,
						$failed_count,
						$remote
					),
					sprintf(
						/* translators: %d: items that need a manual check. */
						__( '其中 %d 项需人工核对', 'sakura-imgopt' ),
						$mismatch
					),
				),
			),
			array(
				'label' => __( '失败任务', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) $data['failed_total'] ),
				'unit'  => __( '个', 'sakura-imgopt' ),
				'subs'  => array(
					sprintf(
						/* translators: %d: retry jobs. */
						__( '重试队列 %d 个任务', 'sakura-imgopt' ),
						(int) $data['jobs']
					),
					wp_next_scheduled( Action_Worker::HOOK )
						? __( '动作队列已调度', 'sakura-imgopt' )
						: __( '动作队列按需调度', 'sakura-imgopt' ),
				),
			),
		);
		$frontline = array(
			'caption'  => __( '本地 → 云端 → 索引，一条线上的三站', 'sakura-imgopt' ),
			'note'     => sprintf(
				/* translators: %s: last scan time. */
				__( '最近核对 %s', 'sakura-imgopt' ),
				$scan_label
			),
			'stations' => array(
				array(
					'value' => number_format_i18n( $local_files ),
					'label' => __( '本地媒体', 'sakura-imgopt' ),
					'sub'   => Inventory::format_bytes( $local_bytes ),
					'tone'  => 'sky',
				),
				array(
					'value' => number_format_i18n( $cloud_objects ),
					'label' => __( '云端文件', 'sakura-imgopt' ),
					'sub'   => Inventory::format_bytes( $cloud_bytes ),
					'tone'  => 'petal',
				),
				array(
					'value' => number_format_i18n( $coverage, 1 ) . '%',
					'label' => __( '已同步', 'sakura-imgopt' ),
					/* translators: %s: number of files verified on all three sides. */
					'sub'   => sprintf( __( '%s 个文件三方一致', 'sakura-imgopt' ), number_format_i18n( $synced ) ),
					'tone'  => 'sun',
				),
			),
		);

		return array(
			'kpis'           => $kpis,
			'frontline'      => $frontline,
			'total'          => $total,
			'synced'         => $synced,
			'pending'        => $pending,
			'failed_count'   => $failed_count,
			'remote'         => $remote,
			'mismatch'       => $mismatch,
			'difference'     => $difference,
			'coverage'       => $coverage,
			'local_files'    => $local_files,
			'local_bytes'    => $local_bytes,
			'cloud_objects'  => $cloud_objects,
			'cloud_bytes'    => $cloud_bytes,
			'local_bytes_label'   => Inventory::format_bytes( $local_bytes ),
			'diff_bytes_label'    => Inventory::format_bytes( abs( $local_bytes - $cloud_bytes ) ),
			'provider_label'      => $provider_label,
			'bucket_label'        => $bucket_label,
			'bucket_raw'          => $bucket,
			'endpoint'            => (string) $this->options->get( 'endpoint', '' ),
			'media_label'         => $media_label,
			'mix_state'           => $mix_state,
			'mix_title'           => $mix_title,
			'mix_desc'            => $mix_desc,
			'mix_gradient'        => $mix_gradient,
			'mix_items'           => $mix_items,
			'cloud_value_label'   => number_format_i18n( $cloud_value, 2 ),
			'cloud_unit'          => $cloud_unit,
			'cloud_bytes_label'   => Inventory::format_bytes( $cloud_bytes ),
			'coverage_label'      => number_format_i18n( $coverage, 1 ),
			'coverage_percent'    => round( $coverage ),
			'scan_time'      => $scan_time,
			'scan_label'     => $scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' ),
			'scan'           => array(
				'id'          => (int) ( $data['scan_id'] ?? 0 ),
				'state'       => $scan_state,
				'phase'       => $scan_phase,
				'running'     => $scan_running,
				'resumable'   => $scan_resumable,
				'processed'   => $scan_processed,
				'state_label' => $state_label,
				'state_tone'  => $state_tone,
				'phase_label' => $phase_label,
				'meta_text'   => sprintf(
					/* translators: 1: scan time, 2: phase. */
					__( '同步记录表 %1$s · %2$s', 'sakura-imgopt' ),
					$scan_time ? wp_date( 'm-d H:i', $scan_time ) : __( '尚未核对', 'sakura-imgopt' ),
					$scan_running && '' !== $phase_label ? sprintf( '%s %s', $phase_label, number_format_i18n( $scan_processed ) ) : __( '实时读取', 'sakura-imgopt' )
				),
			),
			'calibrate_stale' => $calibrate_stale,
			'index_enabled'   => $index_enabled,
			'is_configured'   => $is_configured,
			'storage_enabled' => $storage_enabled,
			'frozen'          => $frozen,
			'reconcile_gated' => $reconcile_gated,
			'reconcile_gate'  => array(
				'from'   => isset( $reconcile_gate['from'] ) ? (string) $reconcile_gate['from'] : '',
				'bucket' => isset( $reconcile_gate['bucket'] ) ? (string) $reconcile_gate['bucket'] : '',
			),
			'locked'          => $locked,
			'legacy_error'    => $legacy_error,
			'legacy_label'    => $legacy_label,
			'legacy_message'  => $legacy_message,
			'metrics'         => array(
				'pages'    => (int) ( $metrics['pages'] ?? 0 ),
				'batches'  => (int) ( $metrics['batches'] ?? 0 ),
				'batch_ms' => (int) ( $metrics['batch_ms'] ?? 0 ),
				's429'     => (int) ( $metrics['s3_429'] ?? 0 ),
				's5xx'     => (int) ( $metrics['s3_5xx'] ?? 0 ),
			),
			'queue'           => array(
				'waiting' => $queue_waiting,
				'dead'    => $queue_dead,
			),
			'jobs'            => (int) ( $data['jobs'] ?? 0 ),
			'failed_total'    => (int) ( $data['failed_total'] ?? 0 ),
			'cron_next'       => (bool) wp_next_scheduled( Action_Worker::HOOK ),
			'missing_media'   => ( new Attachment_Adopter() )->count_missing(),
			'ignored_rules'   => Ignored_Paths::to_text(),
			'ignored_saved'   => isset( $_GET['ignored_saved'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
			'page_cursor'     => (string) ( $data['page_cursor'] ?? '' ),
			'page_more'       => ! empty( $data['page_more'] ),
			'page_size'       => 25,
			'page_sizes'      => array( 25, 50, 100 ),
			'export_url'      => (string) ( $data['export_url'] ?? '' ),
			'settings_url'    => (string) ( $data['settings_url'] ?? '' ),
		);
	}

	/**
	 * Page head arguments, shared by the server frame and the React head.
	 *
	 * @param array<string,mixed> $view Numbers.
	 * @return array<string,mixed>
	 */
	private function page_args( array $view ): array {
		$bucket         = (string) $this->options->get( 'bucket', '' );
		$space          = $this->options->space();
		$provider       = (string) $this->options->get( 'provider', '' );
		$bucket_display = '' !== $bucket ? Bucket_Label::display( $bucket, $space, $provider ) : '';
		$provider_label = Providers::all()[ $provider ] ?? $provider;
		$bucket_label   = '' !== $bucket ? $bucket_display : __( '未配置', 'sakura-imgopt' );

		if ( ! $view['is_configured'] ) {
			$status_text = __( '配置不完整', 'sakura-imgopt' );
			$status_tone = 'bad';
		} elseif ( ! $view['storage_enabled'] ) {
			$status_text = __( '已停用', 'sakura-imgopt' );
			$status_tone = 'warn';
		} else {
			$status_text = __( '连接正常', 'sakura-imgopt' );
			$status_tone = 'ok';
		}

		if ( $view['failed_total'] > 0 || ! $view['index_enabled'] ) {
			$status_tone = 'warn';
		}

		$page = array(
			'id'       => self::PAGE,
			'module'   => 'storage',
			'tab'      => 'sync',
			'eyebrow'  => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
			'title'    => __( 'S3 同步中心', 'sakura-imgopt' ),
			'lede'     => __( '把本地媒体库和云存储放在一起对照：看清哪些已同步、哪些只在一端、哪些失败，并能继续迁移。', 'sakura-imgopt' ),
			'tagline'  => __( '<em>樱前线已经过境</em>：本地、云端、索引三条线停在同一点。', 'sakura-imgopt' ),
			'stamp'    => array(
				'zh'    => __( '桜', 'sakura-imgopt' ),
				'en'    => 'SAKURA TOOLS',
				'place' => 'aside',
			),
			'status'   => array(
				'label'     => __( '当前存储桶', 'sakura-imgopt' ),
				'value'     => $bucket_label,
				'title'     => $bucket,
				'sub'       => 'ok' === $status_tone ? '' : $provider_label . ' · ' . $status_text,
				'tone'      => $status_tone,
				'ping'      => true,
				'ping_name' => '' !== $provider_label ? $provider_label : $provider,
			),
			'actions'  => array(
				array(
					'label'   => __( '导出差异表格（CSV）', 'sakura-imgopt' ),
					'url'     => (string) ( $view['export_url'] ?? '' ),
					'variant' => 'ghost',
					'id'      => 'sakura-sync-export',
				),
				array(
					'label'   => __( '管理配置', 'sakura-imgopt' ),
					'url'     => (string) ( $view['settings_url'] ?? '' ),
					'variant' => 'quiet',
				),
			),
			'island'   => true,
			'skeleton' => array(
				'kpis'  => 5,
				'cards' => array( 'ignored', 'tasks', 'health', 'storage', 'notes', 'reconcile', 'logs' ),
			),
		);

		return $page;
	}

	/**
	 * Card chrome in reading order: the React body fills each one.
	 *
	 * @param array<string,mixed> $view Numbers.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $view ): array {
		$layout = Layout::layout_for_page( self::PAGE );

		$definitions = array(
			array(
				'id'          => 'ignored',
				'kicker'      => 'Exclusions',
				'title'       => __( '忽略规则', 'sakura-imgopt' ),
				'description' => __( '核对与迁移会跳过这些路径。插件默认忽略自己的备份目录与缓存目录，这里追加你自己的规则：每行一条，相对于 uploads 根目录，可以写目录（如 2024/05），也可以写单个文件（如 .htaccess）。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			array(
				'id'          => 'tasks',
				'kicker'      => 'Task Center',
				'title'       => __( '任务中心', 'sakura-imgopt' ),
				'description' => __( '核对、重试与补传都从这里开始；失败任务保留错误与重试次数。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			array(
				'id'          => 'health',
				'kicker'      => 'Connection',
				'title'       => __( '连接与队列健康', 'sakura-imgopt' ),
				'description' => '',
				'wide'        => false,
			),
			array(
				'id'          => 'storage',
				'kicker'      => 'Storage Mix',
				'title'       => __( '云端体积分布', 'sakura-imgopt' ),
				'description' => '',
				'wide'        => false,
			),
			array(
				'id'          => 'notes',
				'kicker'      => 'Notes',
				'title'       => __( '核对口径', 'sakura-imgopt' ),
				'description' => __( '页面显示的是差异，不直接等于“未同步”。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			array(
				'id'          => 'reconcile',
				'kicker'      => 'Reconciliation',
				'title'       => sprintf(
					/* translators: %d: number of differing items. */
					__( '文件核对（%d 项差异）', 'sakura-imgopt' ),
					(int) $view['difference']
				),
				'description' => __( '按文件名、大小和修改时间比对本地与云端；删除操作始终二次确认。', 'sakura-imgopt' ),
				'wide'        => true,
			),
			array(
				'id'          => 'logs',
				'kicker'      => 'Activity',
				'title'       => __( '同步日志', 'sakura-imgopt' ),
				'description' => __( '默认显示最近 6 条；可查询更多，完整日志可在任务详情中查看。', 'sakura-imgopt' ),
				'wide'        => true,
			),
		);

		$cards = array();
		foreach ( $definitions as $card ) {
			$card['placement'] = null;
			$spot              = '' === $layout ? null : Layout::card( $layout, (string) $card['id'] );
			if ( null !== $spot ) {
				$card['placement'] = array( (int) $spot[0], (int) $spot[1], (int) $spot[2] );
			} elseif ( $card['wide'] ) {
				$card['placement'] = array( 0, 1, 12 );
			}

			$cards[] = $card;
		}


		return $cards;
	}

	/**
	 * Table rows with the labels the table prints.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		$rows = isset( $this->data['rows'] ) && is_array( $this->data['rows'] ) ? $this->data['rows'] : array();

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

		$out = array();
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? 'synced' );
			$key    = (string) ( $row['key'] ?? '' );
			$name   = (string) ( $row['name'] ?? wp_basename( $key ) );
			$path   = (string) ( $row['path'] ?? $key );
			$type   = (string) ( $row['type'] ?? 'other' );
			$local  = ! empty( $row['local'] );
			$cloud  = ! empty( $row['cloud'] );
			$mtime  = (int) ( $row['mtime'] ?? 0 );
			$size   = (int) ( $row['size'] ?? 0 );
			$csize  = (int) ( $row['cloud_size'] ?? 0 );
			$ext    = strtoupper( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			// A row that once had a local twin but no longer does was cleaned up
			// by the "delete local after upload" policy, not lost. local_size is
			// preserved for exactly this distinction.
			$removed = ! $local && $size > 0;
			$preview = isset( $row['preview'] ) && is_array( $row['preview'] ) ? $row['preview'] : array(
				'name'     => $name,
				'path'     => $path,
				'kind'     => '' !== $type ? $type : 'other',
				'status'   => $status,
				'size'     => $local ? $size : $csize,
				'url'      => '',
				'download' => '',
			);

			$out[] = array(
				'key'          => $key,
				'name'         => $name,
				'path'         => $path,
				'type'         => $type,
				'type_label'   => $type_labels[ $type ] ?? $type_labels['other'],
				'ext'          => '' !== $ext ? $ext : '—',
				'status'       => $status,
				'status_label' => $status_labels[ $status ] ?? $status,
				'status_tone'  => $status_tones[ $status ] ?? 'neutral',
				'local'        => $local,
				'local_removed' => $removed,
				'cloud'        => $cloud,
				'mtime'        => $mtime,
				'date_label'   => $mtime ? wp_date( 'm/d H:i', $mtime ) : '—',
				'size'         => $size,
				'size_label'   => $local ? Inventory::format_bytes( $size ) : '—',
				'cloud_size'   => $csize,
				'cloud_label'  => $cloud ? Inventory::format_bytes( $csize ) : '—',
				'preview'      => $preview,
			);
		}

		return $out;
	}

	/**
	 * Log rows, newest first, capped the way the card renders them.
	 *
	 * @param array<string,mixed> $view Numbers.
	 * @return array<int,array<string,mixed>>
	 */
	private function logs( array $view ): array {
		$logs = isset( $this->data['logs'] ) && is_array( $this->data['logs'] ) ? $this->data['logs'] : array();
		$scan = (int) ( $view['scan_time'] ?? 0 );

		$out = array();
		foreach ( $logs as $log ) {
			$level  = (string) ( $log['level'] ?? 'info' );
			$badge  = 'error' === $level ? 'bad' : ( 'warning' === $level ? 'warn' : 'info' );
			$label  = 'error' === $level ? __( '错误', 'sakura-imgopt' ) : ( 'warning' === $level ? __( '警告', 'sakura-imgopt' ) : __( '信息', 'sakura-imgopt' ) );
			$time   = (int) ( $log['time'] ?? 0 );

			$out[] = array(
				'level'   => $level,
				'time'    => $time,
				'time_label' => $time ? wp_date( 'H:i:s', $time ) : '—',
				'message' => (string) ( $log['message'] ?? '' ),
				'badge'   => $badge,
				'label'   => $label,
				'scan'    => 'scan: ' . ( $scan ? gmdate( 'Ymd-His', $scan ) : '—' ),
			);
		}

		return $out;
	}

	/**
	 * Client configuration: the same keys the legacy controller reads from
	 * window.sakuraPage.data, so a request body stays byte-identical.
	 *
	 * @param array<string,mixed> $view Numbers.
	 * @return array<string,mixed>
	 */
	private function config( array $view ): array {
		$config = $this->config;

		$config['exportUrl']   = (string) ( $view['export_url'] ?? '' );
		$config['settingsUrl'] = (string) ( $view['settings_url'] ?? '' );
		$config['scanId']      = (int) ( $view['scan']['id'] ?? 0 );
		$config['scanState']   = (string) ( $view['scan']['state'] ?? '' );
		$config['resumable']   = (bool) ( $view['scan']['resumable'] ?? false );
		$config['frozen']      = (bool) ( $view['frozen'] ?? false );
		$config['gated']       = (bool) ( $view['reconcile_gated'] ?? false );

		return $config;
	}

	/**
	 * Card body copy that is not already in page_config().
	 *
	 * Nested groups (cards / notes / health / tasks / storage / reconcile / logs)
	 * so a card reads its own slice instead of a flat, prefixed key soup.
	 *
	 * @return array<string,mixed>
	 */
	private function strings(): array {
		return array(
			'cards' => array(
				// Nonce for the plain admin-post form on the ignore-rules card.
				'ignoredNonce'     => wp_create_nonce( 'sakura_s3_ignored_save' ),
				/* translators: %d: number of active ignore rules. */
				'ignoredNote'      => __( '当前 %d 条生效', 'sakura-imgopt' ),
				'ignoredSaved'     => __( '忽略规则已保存', 'sakura-imgopt' ),
				'ignoredSavedBody' => __( '新规则会从下一次核对开始生效。点「重新核对（本地 + 云端）」可以立刻重跑。', 'sakura-imgopt' ),
				'ignoredPlaceholder' => "2024/05/private\n.htaccess",
				'ignoredHint'      => __( '支持目录与单个文件，开头结尾的斜杠可省略。清空保存即恢复默认规则。插件默认规则始终生效。', 'sakura-imgopt' ),
				'ignoredLabel'     => __( '忽略路径', 'sakura-imgopt' ),
				'ignoredSave'      => __( '保存规则', 'sakura-imgopt' ),
			),
			'notes' => array(
				array(
					'label' => __( '已同步', 'sakura-imgopt' ),
					'text'  => __( '本地与云端 Key 存在，且大小/修改时间在容差内。', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '待上传', 'sakura-imgopt' ),
					'text'  => __( '本地有、云端无；包含排队中的任务。', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '仅云端', 'sakura-imgopt' ),
					'text'  => __( '云端有、本地无；不会自动删除，避免误删 CDN 回源文件。', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '内容不一致', 'sakura-imgopt' ),
					'text'  => __( 'Key 相同但大小或内容（MD5/ETag）不一致，需重新上传或人工核对。', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '搜索', 'sakura-imgopt' ),
					'text'  => __( '按对象 Key 任意位置匹配，只输入文件名也能找到；筛选后的条数与分页按匹配结果计算。', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '危险操作', 'sakura-imgopt' ),
					'text'  => __( '删除必须二次确认；属于用户文件空间的附件会连同附件记录、本地文件与容量一起删除，其它对象只删云端副本。批量删除会先列出数量与对象。', 'sakura-imgopt' ),
				),
			),
			'health' => array(
				'provider'   => __( '服务商', 'sakura-imgopt' ),
				'bucket'     => __( '存储桶', 'sakura-imgopt' ),
				'endpoint'   => __( 'Endpoint', 'sakura-imgopt' ),
				'mediaUrl'   => __( '媒体 URL', 'sakura-imgopt' ),
				'lastScan'   => __( '最近核对', 'sakura-imgopt' ),
				'retryQueue' => __( '重试队列', 'sakura-imgopt' ),
				/* translators: %d: queued retry jobs. */
				'retryQueueCount' => __( '%d 个任务', 'sakura-imgopt' ),
				/* translators: 1: queued action jobs, 2: dead-letter jobs. */
				'queueBreakdown' => __( '动作队列 %1$d 待执行 / %2$d 死信', 'sakura-imgopt' ),
				'metrics'    => __( '本轮指标', 'sakura-imgopt' ),
				'cron'       => __( '定时任务', 'sakura-imgopt' ),
				'credentials' => __( '凭据', 'sakura-imgopt' ),
				'credReady'  => __( '已加密', 'sakura-imgopt' ),
				'credValue'  => __( '已加密 · 未展示', 'sakura-imgopt' ),
				'test'       => __( '测试连接', 'sakura-imgopt' ),
				'manage'     => __( '管理配置', 'sakura-imgopt' ),
				'enableIndex' => __( '启用同步记录', 'sakura-imgopt' ),
				'disableIndex' => __( '停用同步记录', 'sakura-imgopt' ),
				'scheduled'  => __( '已调度', 'sakura-imgopt' ),
				'unscheduled' => __( '未调度', 'sakura-imgopt' ),
				'onDemand'   => __( '按需执行', 'sakura-imgopt' ),
				'healthy'    => __( '配置可用', 'sakura-imgopt' ),
				'unhealthyConfig' => __( '配置不完整', 'sakura-imgopt' ),
				'unhealthyOff'    => __( '已停用', 'sakura-imgopt' ),
				'mediaUnset' => __( '未接管媒体 URL', 'sakura-imgopt' ),
				'dash'       => '—',
			),
			'tasks' => array(
				'latest'      => __( '最近一次全量核对', 'sakura-imgopt' ),
				'startScan'   => __( '开始完整核对', 'sakura-imgopt' ),
				'refresh'     => __( '刷新差异', 'sakura-imgopt' ),
				'cancel'      => __( '停止核对', 'sakura-imgopt' ),
				'resume'      => __( '继续核对', 'sakura-imgopt' ),
				/* translators: %d: number of failed attachments. */
				'retryAll'    => __( '重试失败 (%d)', 'sakura-imgopt' ),
				'viewLogs'    => __( '查看日志', 'sakura-imgopt' ),
				'localFiles'  => __( '本地文件', 'sakura-imgopt' ),
				'cloudFiles'  => __( '云端文件', 'sakura-imgopt' ),
				'syncedFiles' => __( '已同步', 'sakura-imgopt' ),
				'pendingDiff' => __( '待处理', 'sakura-imgopt' ),
				'activityTitle' => __( '近 7 天文件变更', 'sakura-imgopt' ),
				'activityOk'  => __( '正常', 'sakura-imgopt' ),
				'activityBad' => __( '失败', 'sakura-imgopt' ),
				/* translators: 1: normal samples, 2: failed samples. */
				'activitySum' => __( '共 %1$s / %2$s 个样本', 'sakura-imgopt' ),
				'liveRead'    => __( '同步记录表 · 实时读取', 'sakura-imgopt' ),
				'compatRead'  => __( '兼容模式 · 只读快照', 'sakura-imgopt' ),
			),
			'storage' => array(
				'emptyScan'   => __( '开始完整核对', 'sakura-imgopt' ),
				'viewTasks'   => __( '查看任务中心', 'sakura-imgopt' ),
				'resume'      => __( '继续核对', 'sakura-imgopt' ),
				/* translators: %s: formatted local size. */
				'local'       => __( '本地 %s', 'sakura-imgopt' ),
				/* translators: %s: formatted cloud size. */
				'cloud'       => __( '云端 %s', 'sakura-imgopt' ),
				/* translators: %s: formatted size difference. */
				'diff'        => __( '差 %s', 'sakura-imgopt' ),
			),
			'reconcile' => array(
				'scanAgain'   => __( '重新核对（本地 + 云端）', 'sakura-imgopt' ),
				'export'      => __( '导出当前筛选', 'sakura-imgopt' ),
				/* translators: %d: attachments missing a media library record. */
				'adopt'       => __( '补建媒体库记录（%d）', 'sakura-imgopt' ),
				'searchLabel' => __( '搜索文件', 'sakura-imgopt' ),
				'searchPlaceholder' => __( '搜索文件名或路径，如 x8BnM.webp', 'sakura-imgopt' ),
				'typeLabel'   => __( '文件类型', 'sakura-imgopt' ),
				'dateLabel'   => __( '时间范围', 'sakura-imgopt' ),
				'allType'     => __( '全部类型', 'sakura-imgopt' ),
				'typeImage'   => __( '图片', 'sakura-imgopt' ),
				'typeVideo'   => __( '视频', 'sakura-imgopt' ),
				'typeArchive' => __( '压缩包', 'sakura-imgopt' ),
				'typeOther'   => __( '其他', 'sakura-imgopt' ),
				'allDate'     => __( '全部时间', 'sakura-imgopt' ),
				'last7'       => __( '最近 7 天', 'sakura-imgopt' ),
				'last30'      => __( '最近 30 天', 'sakura-imgopt' ),
				'filters'     => array(
					'all'      => __( '全部', 'sakura-imgopt' ),
					'pending'  => __( '待上传', 'sakura-imgopt' ),
					'failed'   => __( '同步失败', 'sakura-imgopt' ),
					'remote'   => __( '仅云端', 'sakura-imgopt' ),
					'mismatch' => __( '内容不一致', 'sakura-imgopt' ),
					'synced'   => __( '已同步', 'sakura-imgopt' ),
				),
				'columns'     => array(
					__( '文件', 'sakura-imgopt' ),
					__( '类型', 'sakura-imgopt' ),
					__( '大小', 'sakura-imgopt' ),
					__( '本地', 'sakura-imgopt' ),
					__( '云端', 'sakura-imgopt' ),
					__( '状态', 'sakura-imgopt' ),
					__( '最后变化', 'sakura-imgopt' ),
					__( '操作', 'sakura-imgopt' ),
				),
				'selectAll'   => __( '全选当前页', 'sakura-imgopt' ),
				'selected'    => __( '项已选', 'sakura-imgopt' ),
				'maxSelect'   => __( '最多 100 项', 'sakura-imgopt' ),
				'batchUpload' => __( '批量上传', 'sakura-imgopt' ),
				'batchDownload' => __( '批量下载', 'sakura-imgopt' ),
				'batchDelete' => __( '批量删除', 'sakura-imgopt' ),
				'batchClear'  => __( '清除选择', 'sakura-imgopt' ),
				'emptyRows'   => __( '没有符合筛选条件的文件。', 'sakura-imgopt' ),
				'perPage'     => __( '每页', 'sakura-imgopt' ),
				'exists'      => __( '已存在', 'sakura-imgopt' ),
				'missing'     => __( '缺失', 'sakura-imgopt' ),
				'localRemoved' => __( '已按策略删除', 'sakura-imgopt' ),
				'diffSize'    => __( '内容不一致', 'sakura-imgopt' ),
			),
			'logs' => array(
				'levels' => array(
					'all'     => __( '全部', 'sakura-imgopt' ),
					'error'   => __( '错误', 'sakura-imgopt' ),
					'warning' => __( '警告', 'sakura-imgopt' ),
					'info'    => __( '信息', 'sakura-imgopt' ),
				),
				'empty'  => __( '暂无同步事件。', 'sakura-imgopt' ),
				/* translators: %d: hidden log entries. */
				'more'   => __( '查询更多日志（还有 %d 条）', 'sakura-imgopt' ),
			),
		);
	}
}
