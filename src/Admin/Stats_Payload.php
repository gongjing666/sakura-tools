<?php
/**
 * Payload for the optimisation statistics tab.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Page;
use Sakura\ImageOptimizer\Admin\Rest\Image_Controller;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the statistics tab renders, computed on the server.
 */
final class Stats_Payload {

	/**
	 * Rows per page the pagination offers.
	 *
	 * @var array<int,int>
	 */
	public const PER_PAGE = array( 25, 50, 100 );

	/**
	 * Stats repository.
	 *
	 * @var Stats_Repository_Interface
	 */
	private $stats;

	/**
	 * Constructor.
	 *
	 * @param Stats_Repository_Interface $stats Stats repository.
	 */
	public function __construct( Stats_Repository_Interface $stats ) {
		$this->stats = $stats;
	}

	/**
	 * Build the payload.
	 *
	 * @param int $page     Page number, 1-based.
	 * @param int $per_page Rows per page.
	 * @return array<string,mixed>
	 */
	public function build( int $page = 1, int $per_page = 25 ): array {
		$stats = $this->stats->get_stats();
		$logs  = $this->stats->get_recent_logs( 100 );
		$total = count( $logs );

		$per_page = in_array( $per_page, self::PER_PAGE, true ) ? $per_page : 25;
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, $page ), $pages );
		$rows     = array_slice( $logs, ( $page - 1 ) * $per_page, $per_page );

		$saved     = max( 0, (int) $stats['total_source_size'] - (int) $stats['total_target_size'] );
		$avg_ratio = ( (int) $stats['total_source_size'] > 0 )
			? round( ( 1 - (int) $stats['total_target_size'] / (int) $stats['total_source_size'] ) * 100, 1 )
			: 0.0;

		$payload = array(
			'tab'        => 'stats',
			'chrome'     => Shell::header_data( 'image', 'stats' ),
			'strings'    => $this->strings(),
			'rest'       => array(
				'url'   => esc_url_raw( rest_url( Image_Controller::NS . '/image/stats' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			'page'       => array(
				'id'      => 'image-stats',
				'module'  => 'image',
				'tab'     => 'stats',
				'eyebrow' => 'OPTIMIZATION LEDGER / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( '优化统计', 'sakura-imgopt' ),
				'lede'    => __( '汇总图片处理结果、空间节省与最近执行记录，便于持续观察媒体优化效果。', 'sakura-imgopt' ),
				'status'  => array(
					'label'  => __( '已统计', 'sakura-imgopt' ),
					'value'  => number_format_i18n( (int) $stats['total_processed'] ),
					'unit'   => __( '总处理次数', 'sakura-imgopt' ),
					'sub'    => sprintf(
						/* translators: %s: average compression ratio */
						__( '平均压缩率 %s%%', 'sakura-imgopt' ),
						number_format_i18n( $avg_ratio, 1 )
					),
					'id'     => 'sakura-stats-hero-value',
					'sub_id' => 'sakura-stats-hero-sub',
					'tone'   => 'neutral',
				),
				'actions' => array(
					array(
						'label'   => __( '返回图像优化', 'sakura-imgopt' ),
						'url'     => Registry::url( 'image', 'rules' ),
						'variant' => 'ghost',
					),
				),
				'island'  => true,
			),
			'kpis'       => $this->kpis( $stats, $saved, $avg_ratio ),
			'history'    => array(
				'id'          => 'history',
				'kicker'      => 'History',
				'title'       => __( '最近处理记录', 'sakura-imgopt' ),
				'description' => ! empty( $stats['last_reset'] )
					/* translators: %s: timestamp of the last reset */
					? sprintf( __( '上次重置：%s', 'sakura-imgopt' ), (string) $stats['last_reset'] )
					: __( '可在下方切换每页 25 / 50 / 100 条', 'sakura-imgopt' ),
				'placement'   => $this->placement(),
				'wide'        => true,
				'columns'     => array(
					__( '时间', 'sakura-imgopt' ),
					__( '图片', 'sakura-imgopt' ),
					__( '格式', 'sakura-imgopt' ),
					__( '引擎', 'sakura-imgopt' ),
					__( '来源', 'sakura-imgopt' ),
					__( '大小变化', 'sakura-imgopt' ),
					__( '状态', 'sakura-imgopt' ),
				),
				'rows'        => $this->rows( $rows ),
				'empty'       => __( '暂无记录。', 'sakura-imgopt' ),
				'pagination'  => array(
					'page'     => $page,
					'per_page' => $per_page,
					'total'    => $total,
					'pages'    => $pages,
					'options'  => self::PER_PAGE,
				),
			),
		);

		$payload['head'] = Page::head_data( $payload['page'] );

		return $payload;
	}

	/**
	 * The six KPI cells.
	 *
	 * @param array<string,mixed> $stats     Aggregate totals.
	 * @param int                 $saved     Bytes saved.
	 * @param float               $avg_ratio Average ratio.
	 * @return array<int,array<string,mixed>>
	 */
	private function kpis( array $stats, int $saved, float $avg_ratio ): array {
		return array(
			array(
				'label' => __( '总处理次数', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) $stats['total_processed'] ),
				'unit'  => __( '次', 'sakura-imgopt' ),
				'sub'   => __( '含上传与批量处理', 'sakura-imgopt' ),
			),
			array(
				'label' => __( '成功', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) $stats['total_success'] ),
				'unit'  => __( '次', 'sakura-imgopt' ),
				'sub'   => __( '已完成优化', 'sakura-imgopt' ),
			),
			array(
				'label' => __( '失败', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) $stats['total_failed'] ),
				'unit'  => __( '次', 'sakura-imgopt' ),
				'sub'   => __( '保留原图未处理', 'sakura-imgopt' ),
			),
			array(
				'label' => __( '跳过', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) $stats['total_skipped'] ),
				'unit'  => __( '次', 'sakura-imgopt' ),
				'sub'   => __( '体积未减小，保留原图', 'sakura-imgopt' ),
			),
			array(
				'label' => __( '累计节省空间', 'sakura-imgopt' ),
				'value' => size_format( $saved, 2 ),
				'sub'   => sprintf(
					/* translators: 1: original size, 2: optimized size */
					__( '原始 %1$s → 优化后 %2$s', 'sakura-imgopt' ),
					size_format( (int) $stats['total_source_size'], 2 ),
					size_format( (int) $stats['total_target_size'], 2 )
				),
			),
			array(
				'label'    => __( '平均压缩率', 'sakura-imgopt' ),
				'value'    => (string) $avg_ratio,
				'unit'     => '%',
				'progress' => min( 100, max( 0, $avg_ratio ) ),
			),
		);
	}

	/**
	 * Structured history rows.
	 *
	 * @param array<int,array<string,mixed>> $logs Log rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $logs ): array {
		$rows = array();

		foreach ( $logs as $log ) {
			$aid        = isset( $log['attachment_id'] ) ? (int) $log['attachment_id'] : 0;
			$title      = $aid ? (string) get_the_title( $aid ) : '';
			$src_format = strtoupper( (string) ( $log['source_format'] ?? '' ) );
			$dst_format = strtoupper( (string) ( $log['target_format'] ?? '' ) );
			$status     = (string) ( $log['status'] ?? '' );
			$ok         = 'success' === $status;
			$skipped    = 'skipped' === $status;

			if ( $ok ) {
				$size = size_format( (int) ( $log['source_size'] ?? 0 ), 1 ) . ' → ' . size_format( (int) ( $log['target_size'] ?? 0 ), 1 );
			} elseif ( $skipped ) {
				$size = size_format( (int) ( $log['source_size'] ?? 0 ), 1 ) . ' → ' . __( '未改动', 'sakura-imgopt' );
			} else {
				$size = '—';
			}

			if ( $ok ) {
				$badge = array(
					'label' => sprintf(
						/* translators: %s: saving percentage */
						__( '节省 %s%%', 'sakura-imgopt' ),
						(string) ( $log['ratio'] ?? 0 )
					),
					'tone'  => 'ok',
					'title' => '',
				);
			} elseif ( $skipped ) {
				$badge = array(
					'label' => __( '已跳过', 'sakura-imgopt' ),
					'tone'  => 'warn',
					'title' => (string) ( $log['error'] ?? '' ),
				);
			} else {
				$badge = array(
					'label' => __( '失败', 'sakura-imgopt' ),
					'tone'  => 'bad',
					'title' => (string) ( $log['error'] ?? '' ),
				);
			}

			$rows[] = array(
				'time'   => (string) ( $log['time'] ?? '' ),
				'name'   => '' !== $title ? $title : '#' . $aid,
				'url'    => $aid ? (string) get_edit_post_link( $aid ) : '',
				'format' => '' !== $dst_format ? ( $src_format . ' → ' . $dst_format ) : $src_format,
				'engine' => strtoupper( (string) ( $log['engine'] ?? '' ) ),
				'source' => ( ( $log['mode'] ?? '' ) === 'batch' ) ? __( '批量', 'sakura-imgopt' ) : __( '上传', 'sakura-imgopt' ),
				'size'   => $size,
				'badge'  => $badge,
			);
		}

		return $rows;
	}

	/**
	 * Grid placement of the history card.
	 *
	 * @return array<int,int>|null
	 */
	private function placement(): ?array {
		$spot = Layout::card( Layout::layout_for_page( 'image-stats' ), 'history' );

		return null === $spot ? null : array( $spot[0], $spot[1], $spot[2] );
	}

	/**
	 * UI strings.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saving'       => __( '保存中…', 'sakura-imgopt' ),
			'saved'        => __( '已保存', 'sakura-imgopt' ),
			'dismiss'      => __( '知道了', 'sakura-imgopt' ),
			'close'        => __( '关闭', 'sakura-imgopt' ),
			'working'      => __( '处理中…', 'sakura-imgopt' ),
			'failed'       => __( '操作未完成，请稍后重试。', 'sakura-imgopt' ),
			'network'      => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
			'reset'        => __( '重置统计', 'sakura-imgopt' ),
			'resetConfirm' => __( '确定要重置汇总统计吗？（每张图片的处理记录会保留）', 'sakura-imgopt' ),
			'perPage'      => __( '每页', 'sakura-imgopt' ),
			'prev'         => __( '上一页', 'sakura-imgopt' ),
			'next'         => __( '下一页', 'sakura-imgopt' ),
			/* translators: %s: total number of log rows. */
			'totalRows'    => __( '共 %s 条', 'sakura-imgopt' ),
		);
	}
}
