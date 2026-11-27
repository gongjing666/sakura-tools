<?php
namespace Sakura\ImageOptimizer\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 优化统计
 *
 * 双层存储：
 *   ① 聚合层 — option  sakura_imgopt_stats   （看板秒开，无需遍历）
 *   ② 明细层 — postmeta sakura_imgopt_log     （每张附件的处理历史，随附件删除自动清理）
 */
class Stats {

	/** 聚合统计 option 名 */
	const OPTION_KEY = 'sakura_imgopt_stats';

	/** 单张附件明细 meta 名 */
	const META_KEY = 'sakura_imgopt_log';

	/** 每张附件最多保留的明细条数 */
	const MAX_LOGS_PER_ATTACHMENT = 10;

	/**
	 * 本次请求尚未落盘的增量（按字段累计）。
	 *
	 * 批量处理时逐张 update_option 会把同一个 option 反复读出、写回，
	 * 既放大 IO，也扩大并发丢更新的窗口；这里改为请求结束时合并一次。
	 *
	 * @var array<string,int>
	 */
	private static $pending = array();

	/**
	 * 是否已注册 shutdown 落盘回调。
	 *
	 * @var bool
	 */
	private static $pending_registered = false;

	/**
	 * 记录一次处理结果（同时写明细 + 累加聚合）。
	 *
	 * @param int    $attachment_id 附件 ID（0 表示未知，跳过明细只记聚合）
	 * @param array  $result        process_file() 返回结构
	 * @param string $source_path   处理前的文件路径（用于取源格式）
	 * @param string $mode          upload | batch
	 */
	public static function record( $attachment_id, array $result, $source_path, $mode = 'upload' ) {
		$success     = ! empty( $result['success'] );
		$skipped     = ! $success && ! empty( $result['skipped'] );
		$status      = $success ? 'success' : ( $skipped ? 'skipped' : 'failed' );
		$size_before = isset( $result['size_before'] ) ? (int) $result['size_before'] : 0;
		$size_after  = isset( $result['size_after'] ) ? (int) $result['size_after'] : 0;

		$source_format = strtolower( pathinfo( (string) $source_path, PATHINFO_EXTENSION ) );
		$target_format = $success && ! empty( $result['path'] )
			? strtolower( pathinfo( (string) $result['path'], PATHINFO_EXTENSION ) )
			: '';

		$ratio = 0.0;
		if ( $success && $size_before > 0 && $size_after > 0 ) {
			$ratio = round( ( 1 - $size_after / $size_before ) * 100, 1 );
		}

		$entry = array(
			'time'          => current_time( 'Y-m-d H:i:s' ),
			'mode'          => in_array( $mode, array( 'upload', 'batch' ), true ) ? $mode : 'upload',
			'engine'        => isset( $result['engine'] ) ? (string) $result['engine'] : '',
			'source_format' => $source_format,
			'target_format' => $target_format,
			'source_size'   => $size_before,
			'target_size'   => $size_after,
			'ratio'         => $ratio,
			'status'        => $status,
			'error'         => $success ? '' : ( isset( $result['error'] ) ? (string) $result['error'] : '' ),
		);

		// ① 明细层
		if ( $attachment_id > 0 ) {
			self::log( $attachment_id, $entry );
		}

		// ② 聚合层
		self::accumulate( $status, $size_before, $size_after );
	}

	/**
	 * 写入单张附件明细，超出上限自动淘汰最早记录。
	 *
	 * @param int   $attachment_id
	 * @param array $entry
	 */
	public static function log( $attachment_id, array $entry ) {
		$logs = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$logs[] = $entry;

		if ( count( $logs ) > self::MAX_LOGS_PER_ATTACHMENT ) {
			$logs = array_slice( $logs, -self::MAX_LOGS_PER_ATTACHMENT );
		}

		update_post_meta( $attachment_id, self::META_KEY, $logs );
	}

	/**
	 * 累加聚合统计（只记增量，请求结束时统一落盘）。
	 *
	 * @param string $status       success | skipped | failed
	 * @param int    $size_before
	 * @param int    $size_after
	 */
	private static function accumulate( $status, $size_before, $size_after ) {
		$increment = array(
			'total_processed'   => 1,
			'total_success'     => 0,
			'total_skipped'     => 0,
			'total_failed'      => 0,
			'total_source_size' => 0,
			'total_target_size' => 0,
		);

		if ( 'success' === $status ) {
			++$increment['total_success'];
			$increment['total_source_size'] = max( 0, (int) $size_before );
			$increment['total_target_size'] = max( 0, (int) $size_after );
		} elseif ( 'skipped' === $status ) {
			++$increment['total_skipped'];
		} else {
			++$increment['total_failed'];
		}

		foreach ( $increment as $field => $value ) {
			self::$pending[ $field ] = ( self::$pending[ $field ] ?? 0 ) + (int) $value;
		}

		self::register_pending_flush();
	}

	/**
	 * 注册一次 shutdown 落盘（每个请求只注册一次，优先级 99 保证在其他回调之后）。
	 *
	 * @return void
	 */
	private static function register_pending_flush() {
		if ( self::$pending_registered ) {
			return;
		}

		self::$pending_registered = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ), 99 );
	}

	/**
	 * 把本次请求累计的增量合并写入 option。
	 *
	 * 写入前重新读一次存储值，只叠加增量，避免用请求开始时的快照覆盖
	 * 其他并发请求已经写入的结果。
	 *
	 * @return void
	 */
	public static function flush_pending() {
		if ( empty( self::$pending ) ) {
			return;
		}

		$pending       = self::$pending;
		self::$pending = array();

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_KEY, 'options' );
		}

		$stats = self::get_stats();
		foreach ( $pending as $field => $value ) {
			$stats[ $field ] = (int) ( $stats[ $field ] ?? 0 ) + (int) $value;
		}

		update_option( self::OPTION_KEY, $stats, false );
	}

	/**
	 * 读取聚合统计（带默认值，保证字段齐全）。
	 *
	 * @return array
	 */
	public static function get_stats() {
		$defaults = array(
			'total_processed'   => 0,
			'total_success'     => 0,
			'total_failed'      => 0,
			'total_skipped'     => 0,
			'total_source_size' => 0,
			'total_target_size' => 0,
			'last_reset'        => '',
		);

		$stats = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats = wp_parse_args( $stats, $defaults );

		// 本请求已产生但尚未落盘的增量也要对读方可见。
		foreach ( self::$pending as $field => $value ) {
			$stats[ $field ] = (int) ( $stats[ $field ] ?? 0 ) + (int) $value;
		}

		return $stats;
	}

	/**
	 * 读取某张附件的处理历史。
	 *
	 * @param int $attachment_id
	 * @return array
	 */
	public static function get_logs( $attachment_id ) {
		$logs = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * 查询最近 N 条明细（跨所有附件，按时间倒序）。
	 *
	 * 只扫描最近写入的 postmeta 行（按 meta_id 倒序 LIMIT），再在 PHP 内展开、
	 * 排序、截取，避免附件量大时把整张 postmeta 的日志行全部读进内存。
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_recent_logs( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 500, (int) $limit ) );

		/**
		 * 过滤扫描的 postmeta 行数（不是最终返回条数）。
		 *
		 * @param int $scan_limit 默认 4×limit，最少 100，最多 1000。
		 * @param int $limit      最终返回条数。
		 */
		$scan_limit = (int) apply_filters( 'sakura_imgopt_recent_logs_scan_limit', max( 100, $limit * 4 ), $limit );
		$scan_limit = max( $limit, min( 1000, $scan_limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
				self::META_KEY,
				$scan_limit
			)
		);

		$all = array();
		foreach ( $rows as $row ) {
			$logs = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $logs ) ) {
				continue;
			}
			foreach ( $logs as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry['attachment_id'] = (int) $row->post_id;
				$all[]                  = $entry;
			}
		}

		// 按时间倒序
		usort(
			$all,
			static function ( $a, $b ) {
				return strcmp( $b['time'] ?? '', $a['time'] ?? '' );
			}
		);

		return array_slice( $all, 0, $limit );
	}

	/**
	 * 重置聚合统计（明细 meta 保留，随附件存活）。
	 */
	public static function reset() {
		self::$pending = array();

		$stats = array(
			'total_processed'   => 0,
			'total_success'     => 0,
			'total_failed'      => 0,
			'total_skipped'     => 0,
			'total_source_size' => 0,
			'total_target_size' => 0,
			'last_reset'        => current_time( 'Y-m-d H:i:s' ),
		);
		update_option( self::OPTION_KEY, $stats, false );
	}
}
