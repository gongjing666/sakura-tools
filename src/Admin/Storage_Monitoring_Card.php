<?php
/**
 * Data for the monitoring and alerting card on the S3 screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Modal;
use Sakura\ImageOptimizer\S3\Alerting;
use Sakura\ImageOptimizer\S3\Prometheus;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the monitoring card once, for the storage island.
 *
 * The card owns two server-side surfaces: the read-only Prometheus endpoint,
 * whose token must never leave the server other than inside that URL, and the
 * webhook alert option. Both are written through Storage_Monitoring_Controller,
 * which reuses Alerting and Prometheus; this class only shapes the data React
 * renders and never writes anything itself.
 */
final class Storage_Monitoring_Card {

	/**
	 * Card id inside Layout::PAGES['s3'].
	 */
	public const ID = 'monitoring';

	/**
	 * Build the card payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$placement = Layout::card( Layout::layout_for_page( 'storage-connection' ), self::ID );

		return array(
			'id'          => self::ID,
			'kicker'      => 'Monitoring',
			'title'       => __( '监控与告警', 'sakura-imgopt' ),
			'description' => __( 'Prometheus 只读端点供监控系统抓取；Webhook 在死信或失败率超阈值时推送 JSON，可直接接 Slack、飞书、钉钉或自建网关。', 'sakura-imgopt' ),
			'wide'        => true,
			'placement'   => null === $placement ? null : array( $placement[0], $placement[1], $placement[2] ),
			'form'        => array(
				'id'   => 'sakura-s3-alerts-form',
				'save' => __( '保存告警设置', 'sakura-imgopt' ),
			),
			'prometheus'  => array(
				'title'        => __( 'Prometheus 抓取端点', 'sakura-imgopt' ),
				'title_id'     => 'sakura-monitor-prometheus-title',
				'description'  => __( '地址含只读令牌，请按密钥管理；令牌泄露后可立即轮换。也支持 Authorization: Bearer 令牌。', 'sakura-imgopt' ),
				// Generated here, never in the browser: token() rotates an
				// unreadable value on read, so the URL is always usable.
				'url'          => Prometheus::url(),
				'copy_label'   => __( '复制地址', 'sakura-imgopt' ),
				'rotate_label' => __( '轮换令牌', 'sakura-imgopt' ),
				'field'        => array(
					'type'         => 'text',
					'id'           => 'sakura-s3-prometheus-url',
					'label'        => __( '抓取地址', 'sakura-imgopt' ),
					'readonly'     => true,
					'full'         => true,
					'autocomplete' => 'off',
					'spellcheck'   => false,
				),
			),
			'alert'       => array(
				'title'      => __( 'Webhook 告警', 'sakura-imgopt' ),
				'title_id'   => 'sakura-monitor-alerts-title',
				'test_label' => __( '发送测试告警', 'sakura-imgopt' ),
				'enabled'    => array(
					'key'   => 'enabled',
					'id'    => 'sakura-s3-alert-enabled',
					'label' => __( '告警开关', 'sakura-imgopt' ),
					'hint'  => __( '启用后才会推送；保存或测试都会校验地址是否可访问。', 'sakura-imgopt' ),
					'text'  => __( '启用 Webhook 告警', 'sakura-imgopt' ),
				),
				'webhook'    => self::webhook_field(),
				'thresholds' => self::threshold_fields(),
			),
			'values'      => Alerting::get(),
			'strings'     => self::strings(),
		);
	}

	/**
	 * The webhook endpoint row.
	 *
	 * @return array<string,mixed>
	 */
	private static function webhook_field(): array {
		return array(
			'key'         => 'webhook',
			'type'        => 'url',
			'id'          => 'sakura-s3-alert-webhook',
			'label'       => __( 'Webhook 地址', 'sakura-imgopt' ),
			'placeholder' => 'https://hooks.slack.com/services/...',
			'full'        => true,
		);
	}

	/**
	 * The three threshold rows, side by side across the panel.
	 *
	 * Keys match the option keys Alerting::sanitize() reads, so the island can
	 * post its draft back under the same names without a translation table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function threshold_fields(): array {
		return array(
			array(
				'key'   => 'fail_rate',
				'type'  => 'number',
				'id'    => 'sakura-s3-alert-rate',
				'label' => __( '失败率阈值（%）', 'sakura-imgopt' ),
				'min'   => 1,
				'max'   => 100,
				'step'  => 1,
			),
			array(
				'key'   => 'min_attempts',
				'type'  => 'number',
				'id'    => 'sakura-s3-alert-attempts',
				'label' => __( '最少尝试次数', 'sakura-imgopt' ),
				'min'   => 1,
				'max'   => 100000,
				'step'  => 1,
			),
			array(
				'key'   => 'cooldown',
				'type'  => 'number',
				'id'    => 'sakura-s3-alert-cooldown',
				'label' => __( '冷却时间（分钟）', 'sakura-imgopt' ),
				'min'   => 5,
				'max'   => 1440,
				'step'  => 1,
			),
		);
	}

	/**
	 * Strings the island only renders, plus the saved/test/rotated prompts.
	 *
	 * Prompt titles live here because the card owns the three actions it
	 * reports on; the message always comes from the REST response.
	 *
	 * @return array<string,mixed>
	 */
	private static function strings(): array {
		return array(
			'saving'       => __( '保存中…', 'sakura-imgopt' ),
			'testing'      => __( '发送中…', 'sakura-imgopt' ),
			'rotating'     => __( '轮换中…', 'sakura-imgopt' ),
			'copied'       => __( '已复制到剪贴板。', 'sakura-imgopt' ),
			'copy_failed'  => __( '复制失败，请手动选中地址后复制。', 'sakura-imgopt' ),
			'dismiss'      => __( '知道了', 'sakura-imgopt' ),
			'close'        => __( '关闭', 'sakura-imgopt' ),
			'closeIcon'    => Modal::close_icon(),
			'mascot'       => Modal::mascot(),
			'failed'       => __( '设置未保存，请稍后重试。', 'sakura-imgopt' ),
			/* translators: %d: HTTP status code returned by the REST endpoint. */
			'failedStatus' => __( '服务器返回 %d，请稍后重试。', 'sakura-imgopt' ),
			'network'      => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
			'notices'      => array(
				'saved'         => array(
					'tone'   => 'ok',
					'kicker' => 'MONITORING / SAVED',
					'title'  => __( '告警设置已保存', 'sakura-imgopt' ),
				),
				'saved_dropped' => array(
					'tone'   => 'warn',
					'kicker' => 'MONITORING / SAVED',
					'title'  => __( '已保存，但地址被清空', 'sakura-imgopt' ),
				),
				'test_ok'       => array(
					'tone'   => 'ok',
					'kicker' => 'MONITORING / TEST',
					'title'  => __( '测试告警已发送', 'sakura-imgopt' ),
				),
				'test_bad'      => array(
					'tone'   => 'bad',
					'kicker' => 'MONITORING / TEST',
					'title'  => __( '测试告警没发出去', 'sakura-imgopt' ),
				),
				'rotated'       => array(
					'tone'   => 'ok',
					'kicker' => 'PROMETHEUS / ROTATED',
					'title'  => __( '令牌已轮换', 'sakura-imgopt' ),
				),
				'failed'        => array(
					'tone'   => 'bad',
					'kicker' => 'MONITORING / ERROR',
					'title'  => __( '操作没有完成', 'sakura-imgopt' ),
				),
			),
		);
	}
}
