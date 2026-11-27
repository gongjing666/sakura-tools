<?php
/**
 * Builds the delete-queue island payload and its polling snapshot.
 *
 * The screen is a React island: PHP prints the shell and one bootstrap
 * document, then the client polls the snapshot endpoint. Every number, label
 * and derived string is computed here so the first paint, the REST response
 * and the classic fallback cannot drift.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\Rest\Storage_Controller;
use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Admin\UI\Modal;
use Sakura\ImageOptimizer\S3\Delete_Jobs;
use Sakura\ImageOptimizer\S3\Delete_Batch;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles everything the delete-queue island renders.
 */
final class Deletes_Payload {

	/**
	 * Page id, also the Layout and Page_Assets key.
	 */
	public const PAGE = 'storage-deletes';

	/**
	 * Default rows per page.
	 */
	public const PER_PAGE = 25;

	/**
	 * Window used for the throughput line.
	 */
	public const RECENT_MINUTES = 10;

	/**
	 * A pending queue with no activity for this long is called stalled.
	 */
	public const STALE_SECONDS = 600;

	/**
	 * Queue store.
	 *
	 * @var Delete_Jobs
	 */
	private Delete_Jobs $jobs;

	/**
	 * Constructor.
	 *
	 * @param Delete_Jobs|null $jobs Queue store.
	 */
	public function __construct( ?Delete_Jobs $jobs = null ) {
		$this->jobs = $jobs ?? new Delete_Jobs();
	}

	/**
	 * The whole bootstrap document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$state    = $this->request_state();
		$page     = $this->request_page();
		$snapshot = $this->snapshot( $state, $page, self::PER_PAGE );
		$page_args = $this->page_args( $snapshot );

		return array(
			'version'  => SAKURA_TOOLS_VERSION,
			'page'     => $page_args,
			'head'     => UI\Page::head_data( $page_args ),
			'chrome'   => Shell::header_data( 'storage', 'deletes' ),
			'card'     => $this->card(),
			'snapshot' => $snapshot,
			'config'   => $this->config(),
			'strings'  => $this->strings(),
		);
	}

	/**
	 * One live snapshot, used by the first paint and by every poll.
	 *
	 * @param string $state    State filter.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array<string,mixed>
	 */
	public function snapshot( string $state = 'all', int $page = 1, int $per_page = self::PER_PAGE ): array {
		$state    = $this->normalize_state( $state );
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : self::PER_PAGE;

		$counts   = $this->jobs->counts();
		$activity = $this->jobs->activity( self::RECENT_MINUTES );
		$total    = $this->jobs->total_rows( $state );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, $page ), $pages );
		$rows     = $this->jobs->page_rows( $state, $page, $per_page );
		$running  = $this->jobs->running_rows( 5 );

		$pending       = $counts['queued'] + $counts['running'];
		$done_recent   = $activity['done_recent'];
		$rate          = $done_recent > 0 ? $done_recent / self::RECENT_MINUTES : 0.0;
		$eta           = ( $rate > 0 && $pending > 0 ) ? (int) ceil( $pending / $rate * MINUTE_IN_SECONDS ) : null;
		$last_activity = $this->timestamp( $activity['last_activity'] );
		$last_done     = $this->timestamp( $activity['last_done'] );
		$stalled       = $pending > 0 && ( $last_activity <= 0 || ( time() - $last_activity ) >= self::STALE_SECONDS );
		$next_due      = $this->timestamp( $this->jobs->next_due() );
		$next_in       = $next_due > 0 ? max( 0, $next_due - time() ) : null;

		return array(
			'state'           => $state,
			'generated_at'    => time(),
			'generated_label' => wp_date( 'H:i:s' ),
			'counts'          => $counts,
			'activity'        => array(
				'pending'               => $pending,
				'recent_minutes'        => self::RECENT_MINUTES,
				'done_recent'           => $done_recent,
				'done_hour'             => $activity['done_hour'],
				'rate_per_min'          => $rate,
				'rate_label'            => $this->rate_label( $rate ),
				'eta_seconds'           => $eta,
				'eta_label'             => null === $eta ? __( '暂无速度数据', 'sakura-imgopt' ) : $this->duration_label( $eta, true ),
				'next_due_in'           => $next_in,
				'next_due_label'        => null === $next_in ? __( '没有排队任务', 'sakura-imgopt' ) : $this->countdown_label( $next_in ),
				'last_done_label'       => $last_done > 0 ? wp_date( 'm-d H:i:s', $last_done ) : __( '暂无完成记录', 'sakura-imgopt' ),
				'last_activity_seconds' => $last_activity > 0 ? max( 0, time() - $last_activity ) : null,
				'stalled'               => $stalled,
				'stalled_label'         => $stalled ? $this->stalled_label( $last_activity ) : '',
				'retention_days'        => Delete_Jobs::DONE_RETENTION_DAYS,
			),
			'running'         => array_map( array( $this, 'running_row' ), $running ),
			'dispatch'        => array(
				'policy' => Delete_Batch::read(),
				'queued' => $this->jobs->queued_stats(),
			),
			'rows'            => array_map( array( $this, 'row' ), $rows ),
			'pagination'      => array(
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => $pages,
				'total'    => $total,
				'from'     => $total > 0 ? ( $page - 1 ) * $per_page + 1 : 0,
				'to'       => min( $total, $page * $per_page ),
			),
			'status'          => $this->head_status( $counts, $pending, $last_activity, $last_done ),
		);
	}

	/**
	 * Page arguments shared by the first paint and the React head.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,mixed>
	 */
	public function page_args( array $snapshot ): array {
		return array(
			'id'       => self::PAGE,
			'module'   => 'storage',
			'tab'      => 'deletes',
			'eyebrow'  => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
			'title'    => __( '删除队列', 'sakura-imgopt' ),
			'lede'     => __( '云端对象删除后进入这里，由 WP-Cron 逐条执行；排队、执行与完成状态实时更新，失败的记录按退避时间重试。', 'sakura-imgopt' ),
			'status'   => (array) $snapshot['status'],
			'actions'  => array(
				array(
					'label'   => __( '打开同步中心', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'sync' ),
					'variant' => 'primary',
				),
				array(
					'label'   => __( '连接配置', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'connection' ),
					'variant' => 'ghost',
				),
			),
			'island'   => true,
			'skeleton' => array(
				'cards' => array( 'queue' ),
			),
		);
	}

	/**
	 * Card chrome for the one card this screen has.
	 *
	 * @return array<string,mixed>
	 */
	private function card(): array {
		return array(
			'id'          => 'queue',
			'kicker'      => 'QUEUE DETAIL',
			'title'       => __( '队列明细', 'sakura-imgopt' ),
			'description' => sprintf(
				/* translators: %s: retention in days. */
				__( '删除任务先排进这里，由 WP-Cron 逐条执行。排队、执行中、已完成与已放弃都会保留；完成的记录保留 %s 天后自动清理。', 'sakura-imgopt' ),
				number_format_i18n( Delete_Jobs::DONE_RETENTION_DAYS )
			),
			'wide'        => true,
			'placement'   => Layout::card( 'deletes', 'queue' ),
		);
	}

	/**
	 * Client configuration: polling cadence, REST base and export endpoint.
	 *
	 * @return array<string,mixed>
	 */
	private function config(): array {
		return array(
			'per_page'  => self::PER_PAGE,
			'poll'      => array(
				'active' => 4000,
				'idle'   => 15000,
				'max'    => 30000,
			),
			'rest'      => array(
				'url'   => esc_url_raw( rest_url( Storage_Controller::NS . '/storage/deletes' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			'export'    => array(
				'url' => esc_url_raw(
					add_query_arg(
						array(
							'action'   => 'sakura_s3_delete_queue',
							'task'     => 'export',
							'_wpnonce' => wp_create_nonce( 'sakura_s3_delete_queue' ),
						),
						admin_url( 'admin-post.php' )
					)
				),
			),
			'stale_seconds' => self::STALE_SECONDS,
		);
	}

	/**
	 * All copy the island reads.
	 *
	 * @return array<string,mixed>
	 */
	private function strings(): array {
		return array(
			'stateLabels'      => array(
				'all'     => __( '全部', 'sakura-imgopt' ),
				'queued'  => __( '排队', 'sakura-imgopt' ),
				'running' => __( '执行中', 'sakura-imgopt' ),
				'done'    => __( '已完成', 'sakura-imgopt' ),
				'dead'    => __( '已放弃', 'sakura-imgopt' ),
				'failed'  => __( '失败', 'sakura-imgopt' ),
			),
			'resolutionLabels' => array(
				'deleted' => __( '已删除', 'sakura-imgopt' ),
				'kept'    => __( '已保留', 'sakura-imgopt' ),
				'skipped' => __( '已跳过', 'sakura-imgopt' ),
			),
			'table'            => array(
				'select'      => __( '选择', 'sakura-imgopt' ),
				'selectAll'   => __( '全选当前页', 'sakura-imgopt' ),
				'id'          => __( 'ID', 'sakura-imgopt' ),
				'object'      => __( '对象', 'sakura-imgopt' ),
				'state'       => __( '状态', 'sakura-imgopt' ),
				'attempts'    => __( '尝试', 'sakura-imgopt' ),
				'progress'    => __( '进度 / 说明', 'sakura-imgopt' ),
				'updated'     => __( '更新时间', 'sakura-imgopt' ),
				'emptyAll'    => __( '队列为空', 'sakura-imgopt' ),
				'emptyState'  => __( '当前筛选下没有记录', 'sakura-imgopt' ),
				'emptyAllHint' => __( '删除对象时任务会自动排进这里；当前没有排队、执行中或已完成的记录。', 'sakura-imgopt' ),
				'emptyStateHint' => __( '换个状态看，或点「全部」回到完整队列。', 'sakura-imgopt' ),
				/* translators: 1: attachment id, 2: profile id. */
				'ref'         => __( '附件 #%1$s · 配置 %2$s', 'sakura-imgopt' ),
				'keyTitle'    => __( '对象 key', 'sakura-imgopt' ),
			),
			'live'             => array(
				'title'        => __( '实时队列', 'sakura-imgopt' ),
				'auto'         => __( '自动更新中', 'sakura-imgopt' ),
				'paused'       => __( '已暂停自动更新', 'sakura-imgopt' ),
				'pause'        => __( '暂停更新', 'sakura-imgopt' ),
				'resume'       => __( '继续更新', 'sakura-imgopt' ),
				'refresh'      => __( '立即刷新', 'sakura-imgopt' ),
				/* translators: %s: time of the last refresh. */
				'lastUpdated'  => __( '最后更新 %s', 'sakura-imgopt' ),
				'pending'      => __( '待处理', 'sakura-imgopt' ),
				'running'      => __( '执行中', 'sakura-imgopt' ),
				'done'         => __( '已完成', 'sakura-imgopt' ),
				'dead'         => __( '已放弃', 'sakura-imgopt' ),
				/* translators: %s: queued rows. */
				'queuedShort'  => __( '排队 %s', 'sakura-imgopt' ),
				/* translators: %s: rows waiting to retry. */
				'retryingShort' => __( '其中 %s 个等待重试', 'sakura-imgopt' ),
				/* translators: 1: window in minutes, 2: completed rows. */
				'recent'       => __( '近 %1$s 分钟完成 %2$s 个', 'sakura-imgopt' ),
				/* translators: %s: completions per minute. */
				'rate'         => __( '%s 个/分', 'sakura-imgopt' ),
				/* translators: %s: estimated remaining time. */
				'eta'          => __( '预计剩余 %s', 'sakura-imgopt' ),
				/* translators: %s: countdown to the next due job. */
				'next'         => __( '下一批：%s', 'sakura-imgopt' ),
				/* translators: 1: completed since opening the page, 2: pending when it opened. */
				'session'      => __( '本次打开后完成 %1$s / %2$s', 'sakura-imgopt' ),
				'sessionEmpty' => __( '打开时没有待处理任务', 'sakura-imgopt' ),
				'stalledTitle' => __( '队列可能停滞', 'sakura-imgopt' ),
				'stalledHint'  => __( '可在服务器执行 wp cron event run sakura_imgopt_s3_delete_tick，或访问站点触发 WP-Cron。', 'sakura-imgopt' ),
				/* translators: 1: row id, 2: object key, 3: time since the last activity. */
				'runningNow'   => __( '正在执行 #%1$s · %2$s（最近活动 %3$s）', 'sakura-imgopt' ),
				/* translators: %s: retention in days. */
				'retention'    => __( '已完成记录保留 %s 天，可导出 CSV 归档。', 'sakura-imgopt' ),
				'noRunning'    => __( '目前没有正在执行的任务。', 'sakura-imgopt' ),
			),
			'actions'          => array(
				'retry'       => __( '重试选中', 'sakura-imgopt' ),
				'drop'        => __( '丢弃选中', 'sakura-imgopt' ),
				'export'      => __( '导出 CSV', 'sakura-imgopt' ),
				/* translators: %s: selected rows. */
				'selected'    => __( '已选 %s 项', 'sakura-imgopt' ),
				'retryHint'   => __( '只重排排队中、失败或已放弃的记录；已完成不会重试。', 'sakura-imgopt' ),
				'dropHint'    => __( '丢弃只删队列记录，不会删除云端对象。', 'sakura-imgopt' ),
				'exportHint'  => __( '未选择时导出全部记录。', 'sakura-imgopt' ),
				'retrying'    => __( '正在重排…', 'sakura-imgopt' ),
				'dropping'    => __( '正在丢弃…', 'sakura-imgopt' ),
				'refreshing'  => __( '正在刷新…', 'sakura-imgopt' ),
			),
			'notices'          => array(
				'retryTitle'  => __( '已重排任务', 'sakura-imgopt' ),
				'dropTitle'   => __( '已丢弃队列记录', 'sakura-imgopt' ),
				'errorTitle'  => __( '操作没有完成', 'sakura-imgopt' ),
				'refreshFail' => __( '自动刷新失败，正在重试。', 'sakura-imgopt' ),
				'kicker'      => 'DELETE QUEUE',
			),
			'dropConfirm'      => array(
				'title'   => __( '丢弃队列记录', 'sakura-imgopt' ),
				'text'    => __( '只会删除队列记录，不会删除云端对象；这些对象会留在桶里。确定丢弃选中的记录吗？', 'sakura-imgopt' ),
				'confirm' => __( '丢弃记录', 'sakura-imgopt' ),
			),
			'pagination'       => array(
				'label'   => __( '队列分页', 'sakura-imgopt' ),
				/* translators: 1: first row shown, 2: last row shown, 3: total rows. */
				'summary' => __( '显示 %1$s–%2$s 项，共 %3$s 项', 'sakura-imgopt' ),
				'perPage' => __( '每页', 'sakura-imgopt' ),
				'prev'    => __( '上一页', 'sakura-imgopt' ),
				'next'    => __( '下一页', 'sakura-imgopt' ),
			),
			'aria'             => array(
				'filters' => __( '队列状态筛选', 'sakura-imgopt' ),
				'table'   => __( '删除队列', 'sakura-imgopt' ),
				'live'    => __( '实时队列进度', 'sakura-imgopt' ),
			),
			'notice'           => array(
				'closeLabel' => __( '关闭', 'sakura-imgopt' ),
				'button'     => __( '知道了', 'sakura-imgopt' ),
				'closeIcon'  => Modal::close_icon(),
				'mascot'     => Modal::mascot(),
			),
		);
	}

	/**
	 * Header status card, refreshed by every snapshot.
	 *
	 * @param array<string,int> $counts        Counts.
	 * @param int               $pending       Queued + running.
	 * @param int               $last_activity Last activity timestamp.
	 * @param int               $last_done     Last completion timestamp.
	 * @return array<string,mixed>
	 */
	private function head_status( array $counts, int $pending, int $last_activity, int $last_done ): array {
		$dead    = $counts['dead'];
		$stalled = $pending > 0 && ( $last_activity <= 0 || ( time() - $last_activity ) >= self::STALE_SECONDS );
		$tone    = 'ok';
		$badge   = array(
			'text' => __( '空闲', 'sakura-imgopt' ),
			'tone' => 'neutral',
		);

		if ( $stalled ) {
			$tone  = 'warn';
			$badge = array(
				'text' => __( '可能停滞', 'sakura-imgopt' ),
				'tone' => 'warn',
			);
		} elseif ( $pending > 0 ) {
			$tone  = 'warn';
			$badge = array(
				'text' => __( '运行中', 'sakura-imgopt' ),
				'tone' => 'ok',
			);
		} elseif ( $dead > 0 ) {
			$tone  = 'warn';
			$badge = array(
				'text' => __( '有待处理', 'sakura-imgopt' ),
				'tone' => 'warn',
			);
		}

		$latest = max( $last_activity, $last_done );

		return array(
			'label'  => __( '队列状态', 'sakura-imgopt' ),
			'value'  => $pending > 0 ? sprintf( /* translators: %s: queued and running rows. */ __( '待处理 %s', 'sakura-imgopt' ), number_format_i18n( $pending ) ) : __( '队列空闲', 'sakura-imgopt' ),
			'sub'    => sprintf(
				/* translators: 1: completed rows, 2: abandoned rows, 3: last update time. */
				__( '已完成 %1$s · 已放弃 %2$s · 最近更新 %3$s', 'sakura-imgopt' ),
				number_format_i18n( $counts['done'] ),
				number_format_i18n( $dead ),
				$latest > 0 ? wp_date( 'm-d H:i', $latest ) : __( '暂无记录', 'sakura-imgopt' )
			),
			'tone'   => $tone,
			'badge'  => $badge,
			'id'     => 'sakura-deletes-status-value',
			'sub_id' => 'sakura-deletes-status-sub',
		);
	}

	/**
	 * One table row, formatted for display.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function row( array $row ): array {
		$state      = (string) ( $row['state'] ?? 'queued' );
		$attempts   = max( 0, (int) ( $row['attempts'] ?? 0 ) );
		$resolution = (string) ( $row['resolution'] ?? '' );
		$next_at    = $this->timestamp( (string) ( $row['next_at'] ?? '' ) );
		$next_in    = $next_at > 0 ? max( 0, $next_at - time() ) : null;
		$updated    = $this->timestamp( (string) ( $row['updated_at'] ?? '' ) );
		$completed  = $this->timestamp( (string) ( $row['completed_at'] ?? '' ) );
		$created    = $this->timestamp( (string) ( $row['created_at'] ?? '' ) );
		$percent    = min( 100.0, ( $attempts / Delete_Jobs::MAX_ATTEMPTS ) * 100 );
		$badge      = array(
			'label' => __( '排队', 'sakura-imgopt' ),
			'tone'  => 'info',
		);
		$progress   = array(
			'kind'    => 'queued',
			'percent' => 0.0,
			'label'   => __( '等待执行', 'sakura-imgopt' ),
		);

		if ( 'running' === $state ) {
			$badge    = array(
				'label' => __( '执行中', 'sakura-imgopt' ),
				'tone'  => 'warn',
			);
			$progress = array(
				'kind'    => 'running',
				'percent' => null,
				'label'   => sprintf(
					/* translators: %s: time since the worker last touched the row. */
					__( '正在删除 · 最近活动 %s', 'sakura-imgopt' ),
					$this->ago_label( $updated )
				),
			);
		} elseif ( 'done' === $state ) {
			$label    = $this->resolution_label( $resolution );
			$badge    = array(
				'label' => $label,
				'tone'  => 'deleted' === $resolution ? 'ok' : 'neutral',
			);
			$progress = array(
				'kind'    => 'done',
				'percent' => 100.0,
				'label'   => $completed > 0
					? sprintf( /* translators: %s: completion time. */ __( '完成于 %s', 'sakura-imgopt' ), wp_date( 'm-d H:i:s', $completed ) )
					: __( '已完成', 'sakura-imgopt' ),
			);
		} elseif ( 'dead' === $state ) {
			$badge    = array(
				'label' => __( '已放弃', 'sakura-imgopt' ),
				'tone'  => 'bad',
			);
			$progress = array(
				'kind'    => 'dead',
				'percent' => $percent,
				'label'   => sprintf(
					/* translators: 1: attempts, 2: maximum attempts. */
					__( '已尝试 %1$s / %2$s 次', 'sakura-imgopt' ),
					number_format_i18n( $attempts ),
					number_format_i18n( Delete_Jobs::MAX_ATTEMPTS )
				),
			);
		} elseif ( 'failed' === $state ) {
			$badge    = array(
				'label' => __( '失败', 'sakura-imgopt' ),
				'tone'  => 'bad',
			);
			$progress = array(
				'kind'    => 'dead',
				'percent' => $percent,
				'label'   => sprintf(
					/* translators: 1: attempts, 2: maximum attempts. */
					__( '已尝试 %1$s / %2$s 次', 'sakura-imgopt' ),
					number_format_i18n( $attempts ),
					number_format_i18n( Delete_Jobs::MAX_ATTEMPTS )
				),
			);
		} elseif ( $attempts > 0 ) {
			$badge    = array(
				'label' => __( '等待重试', 'sakura-imgopt' ),
				'tone'  => 'warn',
			);
			$progress = array(
				'kind'    => 'retrying',
				'percent' => $percent,
				'label'   => null === $next_in
					? sprintf( /* translators: %s: attempts. */ __( '第 %s 次失败，等待重试', 'sakura-imgopt' ), number_format_i18n( $attempts ) )
					: sprintf(
						/* translators: 1: attempts, 2: countdown. */
						__( '第 %1$s 次失败，约 %2$s后重试', 'sakura-imgopt' ),
						number_format_i18n( $attempts ),
						$this->countdown_label( $next_in )
					),
			);
		}

		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'key'             => (string) ( $row['object_key'] ?? '' ),
			'attachment_id'   => (int) ( $row['attachment_id'] ?? 0 ),
			'profile_id'      => (string) ( $row['profile_id'] ?? '' ),
			'state'           => $state,
			'badge'           => $badge,
			'attempts'        => $attempts,
			'max_attempts'    => Delete_Jobs::MAX_ATTEMPTS,
			'attempt_label'   => sprintf( '%1$s / %2$s', number_format_i18n( $attempts ), number_format_i18n( Delete_Jobs::MAX_ATTEMPTS ) ),
			'attempt_percent' => $percent,
			'progress'        => $progress,
			'error'           => (string) ( $row['last_error'] ?? '' ),
			'updated_label'   => $updated > 0 ? wp_date( 'm-d H:i:s', $updated ) : '—',
			'completed_label' => $completed > 0 ? wp_date( 'm-d H:i:s', $completed ) : '',
			'resolution'      => $resolution,
			'resolution_label' => '' !== $resolution ? $this->resolution_label( $resolution ) : '',
			'duration_label'  => ( $completed > 0 && $created > 0 ) ? $this->duration_label( max( 0, $completed - $created ) ) : '',
			'next_in'         => $next_in,
			'next_label'      => null === $next_in ? '' : $this->countdown_label( $next_in ),
		);
	}

	/**
	 * One currently running row for the live strip.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function running_row( array $row ): array {
		$updated = $this->timestamp( (string) ( $row['updated_at'] ?? '' ) );
		$age     = $updated > 0 ? max( 0, time() - $updated ) : null;

		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'key'         => (string) ( $row['object_key'] ?? '' ),
			'attempts'    => max( 0, (int) ( $row['attempts'] ?? 0 ) ),
			'age_seconds' => $age,
			'age_label'   => null === $age ? __( '刚刚', 'sakura-imgopt' ) : $this->ago_seconds( $age ),
		);
	}

	/**
	 * State slug whitelist for the URL and REST.
	 *
	 * @param string $state Raw state.
	 */
	private function normalize_state( string $state ): string {
		return in_array( $state, array( 'queued', 'running', 'done', 'dead', 'failed' ), true ) ? $state : 'all';
	}

	/**
	 * State filter from the current admin URL.
	 */
	private function request_state(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		return isset( $_GET['state'] ) ? $this->normalize_state( sanitize_key( wp_unslash( (string) $_GET['state'] ) ) ) : 'all';
	}

	/**
	 * Page number from the current admin URL.
	 */
	private function request_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		return isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	}

	/**
	 * GMT datetime to timestamp, zero for empty or placeholder values.
	 *
	 * @param string $value GMT datetime.
	 */
	private function timestamp( string $value ): int {
		if ( '' === $value || str_starts_with( $value, '1970-01-01' ) ) {
			return 0;
		}

		return (int) strtotime( $value . ' UTC' );
	}

	/**
	 * Human duration.
	 *
	 * @param int  $seconds Seconds.
	 * @param bool $approx  Prefix with "约" when true.
	 */
	private function duration_label( int $seconds, bool $approx = false ): string {
		$seconds = max( 0, $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			$label = sprintf( /* translators: %s: seconds. */ __( '%s 秒', 'sakura-imgopt' ), number_format_i18n( $seconds ) );
		} elseif ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );
			$rest    = $seconds % MINUTE_IN_SECONDS;
			$label   = $rest > 0
				? sprintf( /* translators: 1: minutes, 2: seconds. */ __( '%1$s 分 %2$s 秒', 'sakura-imgopt' ), number_format_i18n( $minutes ), number_format_i18n( $rest ) )
				: sprintf( /* translators: %s: minutes. */ __( '%s 分钟', 'sakura-imgopt' ), number_format_i18n( $minutes ) );
		} else {
			$hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
			$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
			$label   = $minutes > 0
				? sprintf( /* translators: 1: hours, 2: minutes. */ __( '%1$s 小时 %2$s 分', 'sakura-imgopt' ), number_format_i18n( $hours ), number_format_i18n( $minutes ) )
				: sprintf( /* translators: %s: hours. */ __( '%s 小时', 'sakura-imgopt' ), number_format_i18n( $hours ) );
		}

		return $approx ? sprintf( /* translators: %s: duration. */ __( '约 %s', 'sakura-imgopt' ), $label ) : $label;
	}

	/**
	 * Short countdown, e.g. "12 秒" or "2 分 5 秒".
	 *
	 * @param int $seconds Seconds.
	 */
	private function countdown_label( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( 0 === $seconds ) {
			return __( '即将执行', 'sakura-imgopt' );
		}

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return sprintf( /* translators: %s: seconds. */ __( '%s 秒', 'sakura-imgopt' ), number_format_i18n( $seconds ) );
		}

		return sprintf(
			/* translators: 1: minutes, 2: seconds. */
			__( '%1$s 分 %2$s 秒', 'sakura-imgopt' ),
			number_format_i18n( (int) floor( $seconds / MINUTE_IN_SECONDS ) ),
			number_format_i18n( $seconds % MINUTE_IN_SECONDS )
		);
	}

	/**
	 * "Last touched" label for a running row.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function ago_label( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( '未知', 'sakura-imgopt' );
		}

		return $this->ago_seconds( max( 0, time() - $timestamp ) );
	}

	/**
	 * "N ago" from an elapsed number of seconds.
	 *
	 * @param int $seconds Elapsed seconds.
	 */
	private function ago_seconds( int $seconds ): string {
		if ( $seconds < 5 ) {
			return __( '刚刚', 'sakura-imgopt' );
		}

		return sprintf( /* translators: %s: duration. */ __( '%s前', 'sakura-imgopt' ), $this->duration_label( $seconds ) );
	}

	/**
	 * Completion rate formatted for the live strip.
	 *
	 * @param float $rate Completions per minute.
	 */
	private function rate_label( float $rate ): string {
		if ( $rate <= 0 ) {
			return '—';
		}

		return number_format_i18n( $rate, $rate < 10 ? 1 : 0 );
	}

	/**
	 * Stalled explanation with the actual idle time.
	 *
	 * @param int $last_activity Last activity timestamp.
	 */
	private function stalled_label( int $last_activity ): string {
		if ( $last_activity <= 0 ) {
			return __( '队列建立后还没有活动记录，WP-Cron 可能没有运行。', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: %s: idle duration. */
			__( '队列已 %s 没有推进，WP-Cron 可能没有运行。', 'sakura-imgopt' ),
			$this->duration_label( max( 0, time() - $last_activity ), true )
		);
	}

	/**
	 * Display label for a completion resolution.
	 *
	 * @param string $resolution deleted | kept | skipped.
	 */
	private function resolution_label( string $resolution ): string {
		switch ( $resolution ) {
			case 'deleted':
				return __( '已删除', 'sakura-imgopt' );
			case 'kept':
				return __( '已保留', 'sakura-imgopt' );
			case 'skipped':
				return __( '已跳过', 'sakura-imgopt' );
		}

		return __( '已完成', 'sakura-imgopt' );
	}
}
