<?php
/**
 * Upload throughput card for the storage connection island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\Rest\Storage_Metrics_Controller;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Upload_Metrics;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the "上传吞吐" card once, for the first paint and for every poll.
 *
 * The old panel printed these numbers in PHP and then patched them again in
 * storage-connection.js, so one sentence existed in two languages and the two
 * could disagree — the server output a bare count while a refresh added
 * thousands separators, and only the refresh knew how to explain a failure.
 * Everything React needs is therefore computed here, once: the numbers, the
 * units, the sentences around them and the tone of the last-error tile. React
 * only decides where those text nodes go.
 */
final class Storage_Metrics_Card {

	/**
	 * Card id, and the key Layout::PAGES['s3'] places the card by.
	 */
	public const ID = 'upload-metrics';

	/**
	 * Poll interval, in milliseconds.
	 *
	 * Same cadence as the old panel: a fixed 30 s tick that skips while the
	 * document is hidden rather than pausing and resuming the timer.
	 */
	public const POLL_MS = 30000;

	/**
	 * Read the card.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$metrics = ( new Upload_Metrics() )->snapshot();
		$today   = (array) ( $metrics['today'] ?? array() );
		$week    = (array) ( $metrics['week'] ?? array() );
		$last    = (array) ( $metrics['last_error'] ?? array() );
		$updated = (array) ( $metrics['updated'] ?? array() );

		return array(
			'id'          => self::ID,
			'kicker'      => 'THROUGHPUT',
			'title'       => __( '上传吞吐', 'sakura-imgopt' ),
			'description' => __( '上传完成时实时记录成功量、失败率与字节数，页面每 30 秒自动刷新。', 'sakura-imgopt' ),
			'wide'        => true,
			'placement'   => Layout::card( Layout::layout_for_page( 'storage-connection' ), self::ID ),
			'poll_ms'     => self::POLL_MS,
			'rest'        => array(
				'refresh' => esc_url_raw( Storage_Metrics_Controller::url() ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			),
			'updated'     => self::updated_line( $updated ),
			'kpis'        => self::kpis( $today, $week, $last ),
			'unsynced'    => self::unsynced(),
			'strings'     => array(
				'refresh'      => __( '刷新指标', 'sakura-imgopt' ),
				'loading'      => __( '正在刷新…', 'sakura-imgopt' ),
				'failed'       => __( '指标刷新失败，请稍后重试。', 'sakura-imgopt' ),
				'network'      => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
				'errorUnknown' => __( '上传失败，没有记录到错误信息。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * The four tiles, carrying the numbers and units the old panel printed.
	 *
	 * @param array<string, mixed> $today Today bucket, already formatted.
	 * @param array<string, mixed> $week  14-day bucket, already formatted.
	 * @param array<string, mixed> $last  Last error record.
	 * @return array<int, array<string, mixed>>
	 */
	private static function kpis( array $today, array $week, array $last ): array {
		$has_error = ! empty( $last['has'] );
		$message   = trim( (string) ( $last['message'] ?? '' ) );
		$ago       = (string) ( $last['ago'] ?? '' );
		$known     = $has_error && '' !== $message;

		return array(
			array(
				'key'   => 'ok',
				'label' => __( '今日成功', 'sakura-imgopt' ),
				'value' => number_format_i18n( (int) ( $today['ok'] ?? 0 ) ),
				'sub'   => sprintf(
					/* translators: 1: failed uploads today, 2: failure rate as a percentage. */
					__( '失败 %1$d · 失败率 %2$s', 'sakura-imgopt' ),
					(int) ( $today['fail'] ?? 0 ),
					(string) ( $today['rate_text'] ?? '—' )
				),
				'title' => '',
				'tone'  => '',
				'wide'  => false,
				'text'  => false,
			),
			array(
				'key'   => 'avg',
				'label' => __( '平均耗时', 'sakura-imgopt' ),
				'value' => (string) ( $today['avg_text'] ?? '—' ),
				'sub'   => sprintf(
					/* translators: %s: duration of the slowest upload today. */
					__( '最慢 %s', 'sakura-imgopt' ),
					(string) ( $today['max_text'] ?? '—' )
				),
				'title' => '',
				'tone'  => '',
				'wide'  => false,
				'text'  => false,
			),
			array(
				'key'   => 'bytes',
				'label' => __( '今日上传量', 'sakura-imgopt' ),
				'value' => (string) ( $today['bytes_text'] ?? '0 B' ),
				'sub'   => sprintf(
					/* translators: 1: number of days in the rolling window, 2: failure rate as a percentage. */
					__( '近 %1$d 天失败率 %2$s', 'sakura-imgopt' ),
					Upload_Metrics::KEEP_DAYS,
					(string) ( $week['rate_text'] ?? '—' )
				),
				'title' => '',
				'tone'  => '',
				'wide'  => false,
				'text'  => false,
			),
			array(
				'key'   => 'error',
				'label' => __( '最后错误', 'sakura-imgopt' ),
				'value' => $known
					? $message
					: ( $has_error ? __( '上传失败，没有记录到错误信息。', 'sakura-imgopt' ) : __( '暂无错误记录', 'sakura-imgopt' ) ),
				'sub'   => $has_error ? $ago : '',
				'title' => $known ? $message : '',
				'tone'  => $has_error ? 'bad' : '',
				'wide'  => true,
				'text'  => true,
			),
		);
	}

	/**
	 * The "更新于 …" line above the tiles.
	 *
	 * @param array<string, mixed> $updated Updated block of the snapshot.
	 * @return array<string, mixed>
	 */
	private static function updated_line( array $updated ): array {
		$ago = (string) ( $updated['ago'] ?? '' );

		return array(
			'at'   => (int) ( $updated['at'] ?? 0 ),
			'ago'  => $ago,
			'text' => '' !== $ago
				? sprintf(
					/* translators: %s: how long ago the last upload was recorded. */
					__( '更新于 %s', 'sakura-imgopt' ),
					$ago
				)
				: __( '尚未记录', 'sakura-imgopt' ),
		);
	}

	/**
	 * How many attachments are still waiting for the cloud.
	 *
	 * The old panel printed this under the card through UI::notice() after the
	 * numbers. Keeping the count in the payload lets a poll retire the line once
	 * the sync centre drains the queue, and splitting the sentence around the link
	 * lets React build a real anchor instead of injecting HTML.
	 *
	 * @return array<string, mixed>|null Null when nothing is waiting.
	 */
	private static function unsynced(): ?array {
		$count = count( ( new Sync_Status() )->failed_attachments( 20 ) );

		if ( $count < 1 ) {
			return null;
		}

		return array(
			'count'  => $count,
			'before' => sprintf(
				/* translators: %d: number of attachments that are not in the cloud yet. */
				__( '有 %d 个附件尚未同步，前往', 'sakura-imgopt' ),
				$count
			),
			'link'   => array(
				'url'   => Registry::url( 'storage', 'sync' ),
				'label' => __( 'S3 同步中心', 'sakura-imgopt' ),
			),
			'after'  => __( '处理。', 'sakura-imgopt' ),
		);
	}
}
