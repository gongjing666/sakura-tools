<?php
/**
 * Bootstrap payload for the cloud-cost island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Admin\UI\Modal;
use Sakura\ImageOptimizer\S3\Cost_Estimator;

/**
 * The calculator is stateless: this payload carries the fields, the first
 * estimate and every label, and the REST endpoint returns the same shape.
 */
final class Cost_Payload {

	/**
	 * Page id, also the Layout and Page_Assets key.
	 */
	public const PAGE = 'storage-cost';

	/**
	 * First estimate.
	 *
	 * @var array<string,mixed>
	 */
	private array $result;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $result Existing result, or null to price defaults.
	 */
	public function __construct( ?array $result = null ) {
		$this->result = $result ?? Cost_Estimator::estimate( Cost_Estimator::defaults() );
	}

	/**
	 * The whole bootstrap document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$cards = $this->cards();
		$page  = $this->page_args( $cards );

		return array(
			'version' => SAKURA_TOOLS_VERSION,
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'storage', 'cost' ),
			'cards'   => $cards,
			'strings' => $this->strings(),
			'rest'    => Payload_Contract::rest_for( rest_url( Rest\Cost_Controller::NS . '/storage/cost' ), self::PAGE ),
			'data'    => $this->data(),
		);
	}

	/**
	 * Page arguments shared by the first paint and the React head.
	 *
	 * @param array<int,array<string,mixed>> $cards Card metadata.
	 * @return array<string,mixed>
	 */
	private function page_args( array $cards ): array {
		return array(
			'id'       => self::PAGE,
			'module'   => 'storage',
			'tab'      => 'cost',
			'eyebrow'  => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
			'title'    => __( '云存储费用估算', 'sakura-imgopt' ),
			'lede'     => __( '填一个月的大致用量，横向比较各家的存储、CDN 流量、请求与图片处理费用。价格全部来自官方定价页。', 'sakura-imgopt' ),
			'status'   => array(
				'label' => __( '价格基准', 'sakura-imgopt' ),
				'value' => Cost_Estimator::PRICED_AT,
				'sub'   => __( '官方定价，含免费额度', 'sakura-imgopt' ),
				'tone'  => 'ok',
			),
			'actions'  => array(
				array(
					'label'   => __( '连接配置', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'connection' ),
					'variant' => 'quiet',
				),
				array(
					'label'   => __( '链接改写', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'rewrite' ),
					'variant' => 'quiet',
				),
			),
			'island'   => true,
			'skeleton' => array(
				'cards' => array_map(
					static function ( array $card ): string {
						return (string) $card['id'];
					},
					$cards
				),
			),
		);
	}

	/**
	 * Card chrome shared by PHP and React.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cards(): array {
		$cards = array(
			array(
				'id'          => 'demand',
				'kicker'      => __( 'Step 1', 'sakura-imgopt' ),
				'title'       => __( '你的需求', 'sakura-imgopt' ),
				'description' => __( '填一个月的真实用量。请求按「万次」计，填 100 就是 100 万次。', 'sakura-imgopt' ),
			),
			array(
				'id'          => 'table',
				'kicker'      => __( 'Step 2', 'sakura-imgopt' ),
				'title'       => __( '各家月费对比', 'sakura-imgopt' ),
				'description' => __( '按合计排序；点每行的「口径」可以看到这一家是怎么算出来的。', 'sakura-imgopt' ),
			),
			array(
				'id'          => 'notes',
				'kicker'      => __( 'Notes', 'sakura-imgopt' ),
				'title'       => __( '计算口径与价格来源', 'sakura-imgopt' ),
				'description' => __( '估算用于横向比较；下单前请以厂商计费页与账单为准。', 'sakura-imgopt' ),
			),
		);

		foreach ( $cards as $index => $card ) {
			$cards[ $index ]['placement'] = Layout::card( 'cost', (string) $card['id'] );
		}

		return $cards;
	}

	/**
	 * Everything the React form, table and note column read.
	 *
	 * @return array<string,mixed>
	 */
	private function data(): array {
		return array(
			'pricedAt' => Cost_Estimator::PRICED_AT,
			'result'   => $this->result,
			'fields'   => $this->fields(),
			'sources'  => $this->sources(),
			'notes'    => $this->notes(),
		);
	}

	/**
	 * Form definitions; the React side renders these instead of hard-coding
	 * labels or bounds that could drift from the estimator's normalize().
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fields(): array {
		$input = isset( $this->result['input'] ) && is_array( $this->result['input'] ) ? $this->result['input'] : Cost_Estimator::defaults();

		return array(
			array(
				'key'       => 'storage_gb',
				'id'        => 'cost-storage',
				'type'      => 'number',
				'label'     => __( '存储量（GB）', 'sakura-imgopt' ),
				'hint'      => __( '按标准存储估算，不含归档、低频等其它存储类型。', 'sakura-imgopt' ),
				'value'     => (string) $input['storage_gb'],
				'min'       => '0',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'traffic_gb',
				'id'        => 'cost-traffic',
				'type'      => 'number',
				'label'     => __( '月流量（GB）', 'sakura-imgopt' ),
				'hint'      => __( '一个自然月从存储桶或 CDN 送到访问者的字节数。', 'sakura-imgopt' ),
				'value'     => (string) $input['traffic_gb'],
				'min'       => '0',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'read_wan',
				'id'        => 'cost-read',
				'type'      => 'number',
				'label'     => __( '月读请求（万次）', 'sakura-imgopt' ),
				'hint'      => __( '图片、文件被读取的次数；CDN 命中也会计一次 CDN 请求。', 'sakura-imgopt' ),
				'value'     => (string) $input['read_wan'],
				'min'       => '0',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'write_wan',
				'id'        => 'cost-write',
				'type'      => 'number',
				'label'     => __( '月写请求（万次）', 'sakura-imgopt' ),
				'hint'      => __( '上传、覆盖、删除等写入次数。', 'sakura-imgopt' ),
				'value'     => (string) $input['write_wan'],
				'min'       => '0',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'image_gb',
				'id'        => 'cost-image',
				'type'      => 'number',
				'label'     => __( '月图片处理量（GB）', 'sakura-imgopt' ),
				'hint'      => __( '按原图大小汇总；不做图片处理就填 0。阿里、腾讯、七牛、百度前 10TB 免费，多吉、缤纷、又拍免费。', 'sakura-imgopt' ),
				'value'     => (string) $input['image_gb'],
				'min'       => '0',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'hit_rate',
				'id'        => 'cost-hit',
				'type'      => 'number',
				'label'     => __( 'CDN 缓存命中率（%）', 'sakura-imgopt' ),
				'hint'      => __( '命中率越高，回源流量和源站读请求越少。只有 CDN 组合模式使用。', 'sakura-imgopt' ),
				'value'     => (string) $input['hit_rate'],
				'min'       => '0',
				'max'       => '100',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'busy_rate',
				'id'        => 'cost-busy',
				'type'      => 'number',
				'label'     => __( '忙时流量占比（%）', 'sakura-imgopt' ),
				'hint'      => __( '阿里云、百度云等按闲忙时区分直出单价；全走白天就填 100。', 'sakura-imgopt' ),
				'value'     => (string) $input['busy_rate'],
				'min'       => '0',
				'max'       => '100',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'       => 'https_rate',
				'id'        => 'cost-https',
				'type'      => 'number',
				'label'     => __( 'HTTPS 请求占比（%）', 'sakura-imgopt' ),
				'hint'      => __( '多数厂商只对 HTTPS 请求收费，HTTP 免费；七牛的 HTTPS 流量单价更高，会按比例混合。', 'sakura-imgopt' ),
				'value'     => (string) $input['https_rate'],
				'min'       => '0',
				'max'       => '100',
				'step'      => '1',
				'inputmode' => 'decimal',
			),
			array(
				'key'     => 'mode',
				'id'      => 'cost-mode',
				'type'    => 'select',
				'label'   => __( '流量路径', 'sakura-imgopt' ),
				'hint'    => __( 'CDN 组合会把回源流量与 CDN 出口流量一起算进去。', 'sakura-imgopt' ),
				'value'   => (string) $input['mode'],
				'options' => array(
					array(
						'value' => 'cdn',
						'label' => __( 'CDN 组合（存储桶 + 同厂商 CDN）', 'sakura-imgopt' ),
					),
					array(
						'value' => 'direct',
						'label' => __( '源站直出（不走 CDN）', 'sakura-imgopt' ),
					),
				),
			),
			array(
				'key'       => 'fx',
				'id'        => 'cost-fx',
				'type'      => 'number',
				'label'     => __( '美元汇率', 'sakura-imgopt' ),
				'hint'      => __( 'Cloudflare R2、Backblaze B2 以美元计价，按此汇率折算人民币。', 'sakura-imgopt' ),
				'value'     => (string) $input['fx'],
				'min'       => '0.5',
				'step'      => '0.1',
				'inputmode' => 'decimal',
			),
		);
	}

	/**
	 * Unique official price sources, keyed by URL.
	 *
	 * @return array<string,string>
	 */
	private function sources(): array {
		$rows    = isset( $this->result['rows'] ) && is_array( $this->result['rows'] ) ? $this->result['rows'] : array();
		$sources = array();

		foreach ( $rows as $row ) {
			foreach ( array( 'source', 'cdn_source' ) as $field ) {
				$url = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';

				if ( '' !== $url && ! isset( $sources[ $url ] ) ) {
					$sources[ $url ] = (string) ( $row['name'] ?? '' );
				}
			}
		}

		return $sources;
	}

	/**
	 * Notes copied from the server-rendered page so the island cannot drift.
	 *
	 * @return array<int,string>
	 */
	private function notes(): array {
		return array(
			__( 'HTTPS 占比只影响按请求计费的部分：阿里云 0.05、腾讯云 0.05（每月 300 万次免费）、多吉云 0.04（每月 200 万次免费）、又拍云 0.05 元/万次，HTTP 请求免费；缤纷云不区分协议；七牛云静态请求免费，但 HTTPS 下载流量单价高于 HTTP，按占比混合。', 'sakura-imgopt' ),
			__( 'CDN 组合模式假设访问全部经过同一厂商的 CDN：流量费 = CDN 出口 + 回源（回源量 = 流量 ×（1 − 命中率））。Cloudflare R2 与 Backblaze B2 经 Cloudflare 出网免费。', 'sakura-imgopt' ),
			__( '又拍云按「当日存储量 − 当日 CDN 流量」1:1 抵扣计费；标准类型直出流量官方未公开，直出模式按低频 0.5 元/GB 兜底。', 'sakura-imgopt' ),
			__( '阿里云直出 00:00-08:00 0.25、08:00-24:00 0.50 元/GB；百度云忙时 0.49、闲时 0.25 元/GB；缤纷云 00:00-08:00 直出 8 折。忙时占比会影响这三家。', 'sakura-imgopt' ),
			__( '图片处理按原图 GB 计：阿里云、腾讯云、七牛云、百度云每月前 10TB 免费、超出 0.025 元/GB；多吉云、缤纷云、又拍云免费；Cloudflare Images 按转换次数计费、Backblaze B2 无此服务，这两家未计入。', 'sakura-imgopt' ),
			__( '不含跨区域复制、归档/低频存储、最低存储期、HTTPS 证书、日志、内容审核与流量包折扣；实际账单以厂商计费为准。', 'sakura-imgopt' ),
			__( 'MinIO 为自建部署，云费用为 0，服务器与带宽成本请自行核算。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Labels used by the React form, table and source list.
	 *
	 * @return array<string,mixed>
	 */
	private function strings(): array {
		return array(
			'close'            => __( '关闭', 'sakura-imgopt' ),
			'dismiss'          => __( '知道了', 'sakura-imgopt' ),
			'closeIcon'        => Modal::close_icon(),
			'mascot'           => Modal::mascot(),
			'noticeKicker'     => 'COST ESTIMATOR',
			'noticeTitle'      => __( '估算结果', 'sakura-imgopt' ),
			'noticeErrorTitle' => __( '估算没有完成', 'sakura-imgopt' ),
			'estimate'      => __( '重新估算', 'sakura-imgopt' ),
			'estimating'    => __( '正在估算…', 'sakura-imgopt' ),
			'done'          => __( '已按当前需求重新估算。', 'sakura-imgopt' ),
			'unknown'       => __( '估算失败，请稍后重试。', 'sakura-imgopt' ),
			'cheapest'      => __( '最低价', 'sakura-imgopt' ),
			'usd'           => __( '美元计价', 'sakura-imgopt' ),
			'empty'         => __( '没有估算结果。', 'sakura-imgopt' ),
			'detail'        => __( '口径', 'sakura-imgopt' ),
			'priceSource'   => __( '价格来源：', 'sakura-imgopt' ),
			'storagePrice'  => __( '存储定价', 'sakura-imgopt' ),
			'cdnPrice'      => __( 'CDN / 流量定价', 'sakura-imgopt' ),
			'columns'       => array(
				'name'     => __( '存储商', 'sakura-imgopt' ),
				'storage'  => __( '存储费', 'sakura-imgopt' ),
				'traffic'  => __( '流量费', 'sakura-imgopt' ),
				'requests' => __( '请求费', 'sakura-imgopt' ),
				'image'    => __( '处理费', 'sakura-imgopt' ),
				'total'    => __( '合计 / 月', 'sakura-imgopt' ),
				'detail'   => __( '计费口径', 'sakura-imgopt' ),
			),
			/* translators: 1: traffic path label, 2: number of vendors. */
			'meta'          => __( '当前口径：%1$s；共比较 %2$s 家。按合计从低到高排列。', 'sakura-imgopt' ),
			'modeCdn'       => __( 'CDN 组合', 'sakura-imgopt' ),
			'modeDirect'    => __( '源站直出', 'sakura-imgopt' ),
			/* translators: %s: price sheet date. */
			'alert'         => __( '价格基准日：%s。所有单价来自厂商官方价格页；免费额度、阶梯累进、闲忙时与 CDN 回源都按官方规则参与计算。', 'sakura-imgopt' ),
		);
	}
}
