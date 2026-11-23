<?php
/**
 * Payload for the backup tab.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Page;
use Sakura\ImageOptimizer\Admin\Rest\Image_Controller;
use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Backup\Exposure_Probe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the backup tab renders, computed on the server.
 */
final class Backup_Payload {

	/**
	 * Card ids in Layout order.
	 *
	 * @return array<int,string>
	 */
	public static function card_ids(): array {
		return array( 'usage', 'policy', 'access' );
	}

	/**
	 * Build the payload.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$backup = new Backup_Repository();
		$usage  = $backup->get_usage();

		$payload = array(
			'tab'     => 'backup',
			'chrome'  => Shell::header_data( 'image', 'backup' ),
			'strings' => $this->strings(),
			'rest'    => array(
				'url'   => esc_url_raw( rest_url( Image_Controller::NS . '/image/backup' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			'page'    => array(
				'id'      => 'image-backup',
				'module'  => 'image',
				'tab'     => 'backup',
				'eyebrow' => 'IMAGE RECOVERY / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( '备份管理', 'sakura-imgopt' ),
				'lede'    => __( '管理优化前的原图快照与自动清理策略。清空备份后，已优化图片将无法一键还原。', 'sakura-imgopt' ),
				'status'  => array(
					'label' => __( '原图备份', 'sakura-imgopt' ),
					'value' => number_format_i18n( (int) $usage['count'] ),
					'unit'  => __( '个文件', 'sakura-imgopt' ),
					'sub'   => size_format( (int) $usage['bytes'], 2 ),
					'id'    => 'sakura-backup-hero-count',
					'sub_id' => 'sakura-backup-hero-size',
					'tone'  => 'neutral',
				),
				'island'  => true,
			),
		);

		$payload['head']  = Page::head_data( $payload['page'] );
		$payload['cards'] = $this->cards( $usage );

		return $payload;
	}

	/**
	 * The three cards.
	 *
	 * @param array<string,mixed> $usage Backup usage.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( array $usage ): array {
		$usage['bytes'] = (int) $usage['bytes'];
		$usage['count'] = (int) $usage['count'];

		return array(
			$this->usage_card( $usage ),
			$this->policy_card(),
			$this->access_card(),
		);
	}

	/**
	 * Storage card.
	 *
	 * @param array<string,mixed> $usage Usage.
	 * @return array<string,mixed>
	 */
	private function usage_card( array $usage ): array {
		return $this->card(
			'usage',
			'Storage',
			__( '占用概览', 'sakura-imgopt' ),
			__( '备份用于「一键还原」，清空后已优化图片将无法还原为原图。', 'sakura-imgopt' ),
			array(
				'kpis'    => array(
					array(
						'label' => __( '占用空间', 'sakura-imgopt' ),
						'value' => size_format( $usage['bytes'], 2 ),
						'id'    => 'sakura-backup-size',
					),
					array(
						'label' => __( '备份文件', 'sakura-imgopt' ),
						'value' => number_format_i18n( $usage['count'] ),
						'id'    => 'sakura-backup-count',
					),
				),
				'buttons' => array(
					array(
						'action' => 'clean',
						'label'  => __( '立即清理过期备份', 'sakura-imgopt' ),
					),
					array(
						'action'  => 'purge',
						'label'   => __( '清空全部备份', 'sakura-imgopt' ),
						'variant' => 'danger',
					),
				),
			)
		);
	}

	/**
	 * Directory exposure card.
	 *
	 * @return array<string,mixed>
	 */
	private function policy_card(): array {
		$probe   = Exposure_Probe::latest();
		$time    = is_array( $probe ) && isset( $probe['time'] ) ? (int) $probe['time'] : 0;
		$fresh   = $time > 0 && ( time() - $time ) < DAY_IN_SECONDS;
		$state   = $fresh && isset( $probe['state'] ) ? (string) $probe['state'] : '';
		$tone    = 'exposed' === $state ? 'bad' : ( 'protected' === $state ? 'ok' : 'neutral' );
		$server  = Exposure_Probe::server();
		$uploads = wp_upload_dir();
		$path    = (string) wp_parse_url(
			trailingslashit( rtrim( (string) $uploads['baseurl'], '/' ) . '/' . \Sakura\ImageOptimizer\Backup::DIR ),
			PHP_URL_PATH
		);

		$message = $fresh && isset( $probe['message'] )
			? sprintf(
				/* translators: 1: detection time, 2: detection result. */
				__( '上次检测（%1$s）：%2$s', 'sakura-imgopt' ),
				wp_date( 'Y-m-d H:i', $time ),
				(string) $probe['message']
			)
			: __( '尚未检测。点击下面的按钮，服务器会实际请求备份目录里的一个文件来判断。', 'sakura-imgopt' );

		return $this->card(
			'policy',
			'Security',
			__( '备份目录访问保护', 'sakura-imgopt' ),
			__( '如果别人能直接从网上把备份的原图下载走，优化就白做了。插件会自动加一层访问限制，但只对 Apache 服务器有效。', 'sakura-imgopt' ),
			array(
				'kpis'    => array(
					array(
						'label' => __( '当前服务器', 'sakura-imgopt' ),
						'value' => (string) $server['label'],
						'sub'   => $server['reads_htaccess']
							? __( '会自动拦截', 'sakura-imgopt' )
							: __( '不会自动拦截，需要在站点配置里加规则', 'sakura-imgopt' ),
					),
					array(
						'label' => __( '目录保护', 'sakura-imgopt' ),
						'value' => 'bad' === $tone
							? __( '可被公网访问', 'sakura-imgopt' )
							: ( 'ok' === $tone ? __( '已拦截', 'sakura-imgopt' ) : __( '待检测', 'sakura-imgopt' ) ),
						'sub'   => __( '建议每月检测一次', 'sakura-imgopt' ),
						'tone'  => $tone,
					),
				),
				'message' => array(
					'text' => $message,
					'tone' => $tone,
					'id'   => 'sakura-probe-state',
				),
				'hint'    => __( 'Nginx、OpenResty、IIS、Caddy 等服务器，请在站点配置里拒绝这个路径：', 'sakura-imgopt' ),
				'code'    => 'location ^~ ' . untrailingslashit( $path ) . '/ { deny all; }',
				'buttons' => array(
					array(
						'action' => 'probe',
						'label'  => __( '检测是否可被公网访问', 'sakura-imgopt' ),
					),
				),
			)
		);
	}

	/**
	 * Policy card.
	 *
	 * @return array<string,mixed>
	 */
	private function access_card(): array {
		$settings = new Backup_Settings();
		$values   = $settings->values();

		$card          = $this->card(
			'access',
			'Policy',
			__( '自动清理策略', 'sakura-imgopt' ),
			__( '留空或填 0 表示不限制。', 'sakura-imgopt' ),
			array()
		);
		$card['values'] = $values;
		$card['form']   = array(
			'id'    => 'sakura-backup-policy-form',
			'save'  => __( '保存清理策略', 'sakura-imgopt' ),
			'field' => 'access',
		);
		$card['fields'] = array(
			array(
				'name'        => 'retention_days',
				'type'        => 'number',
				'id'          => 'sakura_imgopt_backup_retention_days',
				'label'       => __( '保留天数', 'sakura-imgopt' ),
				'hint'        => __( '超过这个天数的备份会被每天自动删除。填 0 表示永久保留。', 'sakura-imgopt' ),
				'min'         => 0,
				'step'        => 1,
				'input_class' => 'small-text',
			),
			array(
				'name'        => 'max_mb',
				'type'        => 'number',
				'id'          => 'sakura_imgopt_backup_max_mb',
				'label'       => __( '容量上限', 'sakura-imgopt' ),
				'hint'        => __( '备份占用超过这个容量时，会从最旧的备份开始自动删除。填 0 表示不限制。', 'sakura-imgopt' ),
				'min'         => 0,
				'step'        => 1,
				'input_class' => 'small-text',
			),
			array(
				'name'  => 'delete_data',
				'type'  => 'switch',
				'id'    => 'sakura_imgopt_delete_data_on_uninstall',
				'label' => __( '卸载时删除数据', 'sakura-imgopt' ),
				'text'  => __( '卸载插件时删除本插件产生的数据', 'sakura-imgopt' ),
				'hint'  => __( '默认关闭：卸载插件后仍保留设置、云存储密钥、统计数据与备份文件，重新安装可以接着用。开启后卸载会删除这些数据，并把本插件改过的图片地址和上传目录恢复原样。', 'sakura-imgopt' ),
				'full'  => true,
			),
		);

		return $card;
	}

	/**
	 * Card shell with the grid placement from Layout.
	 *
	 * @param string              $id          Card id.
	 * @param string              $kicker      Kicker.
	 * @param string              $title       Title.
	 * @param string              $description Description.
	 * @param array<string,mixed> $extra       Extra keys.
	 * @return array<string,mixed>
	 */
	private function card( string $id, string $kicker, string $title, string $description, array $extra ): array {
		$spot = Layout::card( Layout::layout_for_page( 'image-backup' ), $id );

		return array_merge(
			array(
				'id'          => $id,
				'kicker'      => $kicker,
				'title'       => $title,
				'description' => $description,
				'placement'   => null === $spot ? null : array( $spot[0], $spot[1], $spot[2] ),
			),
			$extra
		);
	}

	/**
	 * UI strings.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saving'  => __( '保存中…', 'sakura-imgopt' ),
			'saved'   => __( '已保存', 'sakura-imgopt' ),
			'dismiss' => __( '知道了', 'sakura-imgopt' ),
			'close'   => __( '关闭', 'sakura-imgopt' ),
			'closeIcon' => \Sakura\ImageOptimizer\Admin\UI\Modal::close_icon(),
			'mascot'    => \Sakura\ImageOptimizer\Admin\UI\Modal::mascot(),
			'working' => __( '处理中…', 'sakura-imgopt' ),
			'failed'  => __( '设置未保存，请稍后重试。', 'sakura-imgopt' ),
			'network' => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
		);
	}
}
