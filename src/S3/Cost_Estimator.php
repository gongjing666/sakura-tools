<?php
/**
 * Cloud storage price sheet and monthly bill estimator.
 *
 * @package Sakura\ImageOptimizer
 */

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates what one month of storage + CDN traffic costs at each vendor.
 *
 * The sheet below is a snapshot of the vendors' official price pages taken on
 * {@see Cost_Estimator::PRICED_AT}. It is a calculator, not a scraper: nothing
 * here touches the network, so the page keeps working when a vendor site is
 * slow, redesigned or blocked.
 */
final class Cost_Estimator {

	/**
	 * Date the prices were copied from the official pages.
	 */
	public const PRICED_AT = '2026-11-16';

	/**
	 * Relative unit used by the request inputs.
	 */
	public const REQUEST_UNIT = 10000;

	/**
	 * Default demand shown in the form.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'storage_gb' => 100.0,
			'traffic_gb' => 300.0,
			'read_wan'   => 100.0,
			'write_wan'  => 1.0,
			'image_gb'   => 0.0,
			'hit_rate'   => 80.0,
			'busy_rate'  => 100.0,
			'https_rate' => 100.0,
			'mode'       => 'cdn',
			'fx'         => 7.2,
		);
	}

	/**
	 * Clamp a submitted demand into a sane range.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input ): array {
		$defaults = self::defaults();

		return array(
			'storage_gb' => self::bound( $input, 'storage_gb', 0.0, 100000000.0, $defaults['storage_gb'] ),
			'traffic_gb' => self::bound( $input, 'traffic_gb', 0.0, 100000000.0, $defaults['traffic_gb'] ),
			'read_wan'   => self::bound( $input, 'read_wan', 0.0, 100000000.0, $defaults['read_wan'] ),
			'write_wan'  => self::bound( $input, 'write_wan', 0.0, 100000000.0, $defaults['write_wan'] ),
			'image_gb'   => self::bound( $input, 'image_gb', 0.0, 100000000.0, $defaults['image_gb'] ),
			'hit_rate'   => self::bound( $input, 'hit_rate', 0.0, 100.0, $defaults['hit_rate'] ),
			'busy_rate'  => self::bound( $input, 'busy_rate', 0.0, 100.0, $defaults['busy_rate'] ),
			'https_rate' => self::bound( $input, 'https_rate', 0.0, 100.0, $defaults['https_rate'] ),
			'mode'       => isset( $input['mode'] ) && 'direct' === $input['mode'] ? 'direct' : 'cdn',
			'fx'         => self::bound( $input, 'fx', 0.5, 20.0, $defaults['fx'] ),
		);
	}

	/**
	 * Compute every vendor's monthly bill and sort it from cheap to expensive.
	 *
	 * @param array<string,mixed> $input Raw or normalized demand.
	 * @return array<string,mixed>
	 */
	public static function estimate( array $input ): array {
		$demand = self::normalize( $input );
		$rows   = array();

		$ranked = array();
		$others = array();

		foreach ( self::provider_sheet() as $key => $sheet ) {
			$row = self::estimate_one( $key, $sheet, $demand );

			if ( ! empty( $row['self_hosted'] ) ) {
				$others[] = $row;
			} else {
				$ranked[] = $row;
			}
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $a['total'] <=> $b['total'];
			}
		);

		foreach ( $ranked as $index => $row ) {
			$ranked[ $index ]['rank']     = $index + 1;
			$ranked[ $index ]['cheapest'] = 0 === $index;
		}

		foreach ( $others as $index => $row ) {
			$others[ $index ]['rank']     = 0;
			$others[ $index ]['cheapest'] = false;
		}

		$rows = array_merge( $ranked, $others );

		return array(
			'input'     => $demand,
			'rows'      => $rows,
			'priced_at' => self::PRICED_AT,
			'mode'      => $demand['mode'],
		);
	}

	/**
	 * Charge a quantity through a cumulative tier table.
	 *
	 * @param float                     $amount Quantity in GB (or requests).
	 * @param array<int,array{0:?float,1:float}> $tiers  Pairs of [upper bound, unit price]; null bounds the last tier.
	 * @return float
	 */
	public static function tiered( float $amount, array $tiers ): float {
		$cost = 0.0;
		$from = 0.0;

		foreach ( $tiers as $tier ) {
			$limit = $tier[0];
			$price = (float) $tier[1];

			if ( null === $limit ) {
				$cost += max( 0.0, $amount - $from ) * $price;
				break;
			}

			$slice = min( $amount, $limit ) - $from;

			if ( $slice > 0.0 ) {
				$cost += $slice * $price;
			}

			if ( $amount <= $limit ) {
				break;
			}

			$from = $limit;
		}

		return $cost;
	}

	/**
	 * Vendor price sheet.
	 *
	 * Storage/CDN/origin/direct values are yuan per GB per bill. The first tier
	 * of a table doubles as the free allowance, so [ 10, 0.0 ] means "the first
	 * 10 GB are free".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function provider_sheet(): array {
		return array(
			'dogecloud' => array(
				'name'        => __( '多吉云', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( 10.0, 0.0 ), array( null, 0.09 ) ),
				'cdn'         => array( array( 20.0, 0.0 ), array( null, 0.11 ) ),
				'origin'      => array( array( null, 0.0 ) ),
				'direct'      => array( 'busy' => 0.5, 'idle' => 0.5 ),
				'cdn_req'     => array( 'price' => 0.04, 'free' => 200.0 ),
				'storage_req' => array( 'read' => 0.0, 'write' => 0.0, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image'       => array( array( null, 0.0 ) ),
				'note'        => __( '存储按 0.003 元/GB/日；每月 10GB 存储、20GB CDN 流量、200 万次 HTTPS 请求免费；SDK 直下 0.5 元/GB；CDN 回源与图片处理免费。', 'sakura-imgopt' ),
				'source'      => 'https://www.dogecloud.com/price?product=oss',
				'cdn_source'  => 'https://www.dogecloud.com/price?product=cdn',
			),
			'bitiful'   => array(
				'name'        => __( '缤纷云', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( 50.0, 0.0 ), array( 1000.0, 0.065 ), array( 10000.0, 0.055 ), array( 100000.0, 0.045 ), array( null, 0.04 ) ),
				'cdn'         => array( array( 10.0, 0.0 ), array( 1000.0, 0.12 ), array( 10000.0, 0.10 ), array( 100000.0, 0.09 ), array( null, 0.08 ) ),
				'origin'      => array( array( 10.0, 0.0 ), array( 1000.0, 0.10 ), array( 10000.0, 0.085 ), array( 100000.0, 0.07 ), array( null, 0.055 ) ),
				'direct'      => array( 'busy' => 0.26, 'idle' => 0.208 ),
				'cdn_req'     => array( 'price' => 0.035, 'free' => 10.0, 'all_protocols' => true ),
				'storage_req' => array( 'read' => 0.02, 'write' => 0.02, 'free_read' => 10.0, 'free_write' => 10.0 ),
				'image'       => array( array( null, 0.0 ) ),
				'note'        => __( '存储 50GB 以内免费；每类流量 10GB/月、每类请求 10 万次/月免费；同小时 CDN 出口流量可抵扣存储费；直出 00:00-08:00 按 8 折。', 'sakura-imgopt' ),
				'source'      => 'https://docs.bitiful.com/prices/detail/storage-prices',
				'cdn_source'  => 'https://docs.bitiful.com/prices/detail/transfer-prices',
			),
			'aliyun'    => array(
				'name'        => __( '阿里云 OSS', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( null, 0.12 ) ),
				'cdn'         => array( array( 10000.0, 0.24 ), array( 50000.0, 0.23 ), array( 100000.0, 0.21 ), array( 1000000.0, 0.18 ), array( null, 0.15 ) ),
				'origin'      => array( array( null, 0.15 ) ),
				'direct'      => array( 'busy' => 0.5, 'idle' => 0.25 ),
				'cdn_req'     => array( 'price' => 0.05, 'free' => 0.0 ),
				'storage_req' => array( 'read' => 0.01, 'write' => 0.01, 'free_read' => 2000.0, 'free_write' => 500.0 ),
				'image'       => array( array( 10240.0, 0.0 ), array( null, 0.025 ) ),
				'note'        => __( '标准存储 0.12 元/GB/月；外网流出 00:00-08:00 0.25、08:00-24:00 0.50 元/GB；CDN 回源 0.15 元/GB；CDN HTTPS 请求 0.05 元/万次；存储读请求每月 2000 万次、写请求 500 万次免费。', 'sakura-imgopt' ),
				'source'      => 'https://www.aliyun.com/price/detail/oss',
				'cdn_source'  => 'https://www.aliyun.com/price/detail/cdn',
			),
			'tencent'   => array(
				'name'        => __( '腾讯云 COS', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( null, 0.118 ) ),
				'cdn'         => array( array( 2000.0, 0.21 ), array( 10000.0, 0.20 ), array( 50000.0, 0.18 ), array( 100000.0, 0.15 ), array( null, 0.11 ) ),
				'origin'      => array( array( null, 0.15 ) ),
				'direct'      => array( 'busy' => 0.5, 'idle' => 0.5 ),
				'cdn_req'     => array( 'price' => 0.05, 'free' => 300.0 ),
				'storage_req' => array( 'read' => 0.01, 'write' => 0.01, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image'       => array( array( 10240.0, 0.0 ), array( null, 0.025 ) ),
				'note'        => __( '标准存储 0.118 元/GB/月；外网下行 0.5 元/GB；CDN 回源 0.15 元/GB；CDN HTTPS 请求 0.05 元/万次、每账户每月 300 万次免费；COS 读写请求各 0.01 元/万次，无免费额度。', 'sakura-imgopt' ),
				'source'      => 'https://buy.cloud.tencent.com/price/cos',
				'cdn_source'  => 'https://buy.cloud.tencent.com/price/cdn',
			),
			'qiniu'     => array(
				'name'        => __( '七牛云 KODO', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( 10.0, 0.0 ), array( null, 0.115 ) ),
				'cdn'         => array( array( 10.0, 0.0 ), array( 100000.0, 0.28 ), array( 1000000.0, 0.23 ), array( null, 0.18 ) ),
				'cdn_http'    => array( array( 10.0, 0.0 ), array( 100000.0, 0.24 ), array( 1000000.0, 0.19 ), array( null, 0.14 ) ),
				'origin'      => array( array( null, 0.15 ) ),
				'direct'      => array( 'busy' => 0.26, 'idle' => 0.26 ),
				'cdn_req'     => array( 'price' => 0.0, 'free' => 0.0 ),
				'storage_req' => array( 'read' => 0.01, 'write' => 0.01, 'free_read' => 100.0, 'free_write' => 10.0 ),
				'image'       => array( array( 10240.0, 0.0 ), array( null, 0.025 ) ),
				'note'        => __( '标准存储 0.115 元/GB/月，每月 10GB 免费；HTTPS 下载流量按上表（HTTP 下载 0.24 元/GB 起）；CDN 回源 0.15 元/GB；静态 CDN 请求不单独计费；存储读请求每月 100 万次、写 10 万次免费。', 'sakura-imgopt' ),
				'source'      => 'https://www.qiniu.com/prices/kodo',
				'cdn_source'  => 'https://www.qiniu.com/prices/cdn',
			),
			'upyun'     => array(
				'name'             => __( '又拍云', 'sakura-imgopt' ),
				'tag'              => __( '国内', 'sakura-imgopt' ),
				'storage'          => array( array( null, 0.0 ) ),
				'storage_daily'    => 0.0043,
				'offset_traffic'   => true,
				'cdn'              => array( array( null, 0.29 ) ),
				'origin'           => array( array( null, 0.0 ) ),
				'direct'           => array( 'busy' => 0.5, 'idle' => 0.5 ),
				'cdn_req'          => array( 'price' => 0.05, 'free' => 0.0 ),
				'storage_req'      => array( 'read' => 0.0, 'write' => 0.0, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image'            => array( array( null, 0.0 ) ),
				'note'             => __( '存储 0.0043 元/GB/日（约 0.129 元/GB/月），当日 CDN 流量与存储量 1:1 抵扣；CDN 国内 0.29 元/GB、亚太 0.89、欧美 0.39；回源免费；存储请求免费，HTTPS 请求 0.05 元/万次。标准类型直出流量官方未公开，直出按低频 0.5 元/GB 兜底。', 'sakura-imgopt' ),
				'source'           => 'https://www.upyun.com/pricing',
				'cdn_source'       => 'https://www.upyun.com/price_instruction',
			),
			'baidu'     => array(
				'name'        => __( '百度云 BOS', 'sakura-imgopt' ),
				'tag'         => __( '国内', 'sakura-imgopt' ),
				'storage'     => array( array( null, 0.119 ) ),
				'cdn'         => array( array( 10000.0, 0.20 ), array( 50000.0, 0.17 ), array( 100000.0, 0.14 ), array( 1000000.0, 0.12 ), array( null, 0.10 ) ),
				'origin'      => array( array( null, 0.14 ) ),
				'direct'      => array( 'busy' => 0.49, 'idle' => 0.25 ),
				'cdn_req'     => array( 'price' => 0.0, 'free' => 0.0 ),
				'storage_req' => array( 'read' => 0.01, 'write' => 0.01, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image'       => array( array( 10240.0, 0.0 ), array( null, 0.025 ) ),
				'note'        => __( '标准存储 0.119 元/GB/月；外网流出忙时（08:00-24:00）0.49、闲时 0.25 元/GB；CDN 回源 0.14 元/GB；静态 HTTPS 请求不单独计费；存储读写请求各 0.01 元/万次。', 'sakura-imgopt' ),
				'source'      => 'https://cloud.baidu.com/product-price/bos.html',
				'cdn_source'  => 'https://cloud.baidu.com/product-price/cdn.html',
			),
			'cloudflare' => array(
				'name'        => 'Cloudflare R2',
				'tag'         => __( '海外', 'sakura-imgopt' ),
				'usd'         => true,
				'storage'     => array( array( 10.0, 0.0 ), array( null, 0.015 ) ),
				'cdn'         => array( array( null, 0.0 ) ),
				'origin'      => array( array( null, 0.0 ) ),
				'direct'      => array( 'busy' => 0.0, 'idle' => 0.0 ),
				'cdn_req'     => array( 'price' => 0.0, 'free' => 0.0 ),
				'storage_req' => array( 'read' => 0.0036, 'write' => 0.045, 'free_read' => 1000.0, 'free_write' => 100.0 ),
				'image_excluded' => true,
				'note'        => __( '美元计价：存储 $0.015/GB/月（前 10GB 免费）；出网免费；Class A 写 $4.50/百万（前 100 万免费）、Class B 读 $0.36/百万（前 1000 万免费）；无最低存储期。CDN 组合按 Cloudflare 免费 CDN 计，出网仍为 0。', 'sakura-imgopt' ),
				'source'      => 'https://developers.cloudflare.com/r2/pricing/',
				'cdn_source'  => 'https://developers.cloudflare.com/r2/pricing/',
			),
			'backblaze' => array(
				'name'            => 'Backblaze B2',
				'tag'             => __( '海外', 'sakura-imgopt' ),
				'usd'             => true,
				'storage'         => array( array( 10.0, 0.0 ), array( null, 0.0068 ) ),
				'cdn'             => array( array( null, 0.0 ) ),
				'origin'          => array( array( null, 0.0 ) ),
				'direct'          => array( 'busy' => 0.01, 'idle' => 0.01 ),
				'direct_free_x'   => 3.0,
				'cdn_req'         => array( 'price' => 0.0, 'free' => 0.0 ),
				'storage_req'     => array( 'read' => 0.0, 'write' => 0.0, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image_excluded'  => true,
				'note'            => __( '美元计价：存储 $6.95/TB/月（约 $0.0068/GB，前 10GB 免费）；出网流量为平均存储量的 3 倍以内免费，超出 $0.01/GB；交易请求免费。CDN 组合按经 Cloudflare Bandwidth Alliance 出网免费计。', 'sakura-imgopt' ),
				'source'          => 'https://www.backblaze.com/cloud-storage/pricing',
				'cdn_source'      => 'https://www.backblaze.com/cloud-storage/pricing',
			),
			'minio'     => array(
				'name'        => __( 'MinIO（自建）', 'sakura-imgopt' ),
				'tag'         => __( '自建', 'sakura-imgopt' ),
				'self_hosted' => true,
				'storage'     => array( array( null, 0.0 ) ),
				'cdn'         => array( array( null, 0.0 ) ),
				'origin'      => array( array( null, 0.0 ) ),
				'direct'      => array( 'busy' => 0.0, 'idle' => 0.0 ),
				'cdn_req'     => array( 'price' => 0.0, 'free' => 0.0 ),
				'storage_req' => array( 'read' => 0.0, 'write' => 0.0, 'free_read' => 0.0, 'free_write' => 0.0 ),
				'image'       => array( array( null, 0.0 ) ),
				'note'        => __( '自建对象存储没有云服务账单；服务器、磁盘、带宽与运维成本需自行核算，这里只显示 0。', 'sakura-imgopt' ),
				'source'      => 'https://min.io/pricing',
				'cdn_source'  => '',
			),
		);
	}

	/**
	 * Build one vendor row.
	 *
	 * @param string              $key    Vendor id.
	 * @param array<string,mixed> $sheet  Vendor price sheet.
	 * @param array<string,mixed> $demand Normalized demand.
	 * @return array<string,mixed>
	 */
	private static function estimate_one( string $key, array $sheet, array $demand ): array {
		$mode       = $demand['mode'];
		$storage_gb = (float) $demand['storage_gb'];
		$traffic_gb = (float) $demand['traffic_gb'];
		$image_gb   = (float) $demand['image_gb'];
		$hit        = min( 1.0, max( 0.0, $demand['hit_rate'] / 100 ) );
		$https      = min( 1.0, max( 0.0, $demand['https_rate'] / 100 ) );
		$miss_gb    = $traffic_gb * ( 1 - $hit );

		if ( isset( $sheet['storage_daily'] ) ) {
			$storage = self::daily_storage( $storage_gb, $traffic_gb, $mode, (float) $sheet['storage_daily'], ! empty( $sheet['offset_traffic'] ) );
		} else {
			$storage = self::tiered( $storage_gb, $sheet['storage'] );
		}

		if ( 'cdn' === $mode ) {
			if ( isset( $sheet['cdn_http'] ) ) {
				$egress = $https * self::tiered( $traffic_gb, $sheet['cdn'] )
					+ ( 1 - $https ) * self::tiered( $traffic_gb, $sheet['cdn_http'] );
			} else {
				$egress = self::tiered( $traffic_gb, $sheet['cdn'] );
			}
			$origin   = self::tiered( $miss_gb, $sheet['origin'] );
			$reads    = $demand['read_wan'] * ( 1 - $hit );
			$cdn_read = $demand['read_wan'];
		} else {
			$egress   = self::direct_cost( $sheet, $traffic_gb, $demand['busy_rate'] );
			$origin   = 0.0;
			$reads    = $demand['read_wan'];
			$cdn_read = 0.0;
		}

		if ( 'direct' === $mode && isset( $sheet['direct_free_x'] ) ) {
			$free   = $storage_gb * (float) $sheet['direct_free_x'];
			$egress = max( 0.0, $traffic_gb - $free ) * (float) $sheet['direct']['idle'];
		}

		$storage_req = max( 0.0, $reads - $sheet['storage_req']['free_read'] ) * $sheet['storage_req']['read']
			+ max( 0.0, $demand['write_wan'] - $sheet['storage_req']['free_write'] ) * $sheet['storage_req']['write'];
		$cdn_paid    = empty( $sheet['cdn_req']['all_protocols'] ) ? $cdn_read * $https : $cdn_read;
		$cdn_req     = max( 0.0, $cdn_paid - $sheet['cdn_req']['free'] ) * $sheet['cdn_req']['price'];
		$requests    = $storage_req + $cdn_req;

		$image = isset( $sheet['image'] ) ? self::tiered( $image_gb, $sheet['image'] ) : 0.0;
		if ( ! empty( $sheet['image_excluded'] ) ) {
			$image = 0.0;
		}

		$total = $storage + $egress + $origin + $requests + $image;

		$usd = ! empty( $sheet['usd'] );
		$fx  = (float) $demand['fx'];

		if ( $usd ) {
			$storage   *= $fx;
			$egress    *= $fx;
			$origin    *= $fx;
			$requests  *= $fx;
			$image     *= $fx;
			$total     *= $fx;
		}

		return array(
			'key'          => $key,
			'name'         => $sheet['name'],
			'tag'          => $sheet['tag'],
			'usd'          => $usd,
			'self_hosted'  => ! empty( $sheet['self_hosted'] ),
			'storage'      => round( $storage, 2 ),
			'egress'       => round( $egress, 2 ),
			'origin'       => round( $origin, 2 ),
			'requests'     => round( $requests, 2 ),
			'image'        => round( $image, 2 ),
			'image_excluded' => ! empty( $sheet['image_excluded'] ),
			'total'        => round( $total, 2 ),
			'mode'         => $mode,
			'note'         => $sheet['note'],
			'source'       => $sheet['source'],
			'cdn_source'   => $sheet['cdn_source'],
			'billing'      => self::billing_summary( $sheet, $demand, $storage_gb, $traffic_gb, $miss_gb, $reads, $cdn_read, $image_gb ),
		);
	}

	/**
	 * Daily-billed storage with the CDN traffic offset used by Upyun.
	 *
	 * The offset is applied to GB-days: 30 x storage minus monthly CDN traffic.
	 *
	 * @param float  $storage_gb Monthly stored GB.
	 * @param float  $traffic_gb Monthly CDN traffic in GB.
	 * @param string $mode       Traffic path.
	 * @param float  $daily_rate Yuan per GB per day.
	 * @param bool   $offset     Whether CDN traffic offsets storage.
	 * @return float
	 */
	private static function daily_storage( float $storage_gb, float $traffic_gb, string $mode, float $daily_rate, bool $offset ): float {
		$units = $storage_gb * 30.0;

		if ( 'cdn' === $mode && $offset ) {
			$units = max( 0.0, $units - $traffic_gb );
		}

		return $units * $daily_rate;
	}

	/**
	 * Blend busy and idle direct egress prices.
	 *
	 * @param array<string,mixed> $sheet Vendor sheet.
	 * @param float               $traffic_gb Monthly traffic.
	 * @param float               $busy_rate Share of traffic served in busy hours.
	 * @return float
	 */
	private static function direct_cost( array $sheet, float $traffic_gb, float $busy_rate ): float {
		$busy = min( 100.0, max( 0.0, $busy_rate ) ) / 100.0;

		return $traffic_gb * ( $busy * (float) $sheet['direct']['busy'] + ( 1 - $busy ) * (float) $sheet['direct']['idle'] );
	}

	/**
	 * Human readable billing lines shown when a row is expanded.
	 *
	 * @param array<string,mixed> $sheet      Vendor sheet.
	 * @param array<string,mixed> $demand     Normalized demand.
	 * @param float               $storage_gb Storage GB.
	 * @param float               $traffic_gb Traffic GB.
	 * @param float               $miss_gb    Traffic that misses the cache.
	 * @param float               $reads      Origin read requests in units of ten thousand.
	 * @param float               $cdn_read   CDN read requests in units of ten thousand.
	 * @param float               $image_gb   Image processing volume in GB.
	 * @return array<int,array{label:string,value:string}>
	 */
	private static function billing_summary( array $sheet, array $demand, float $storage_gb, float $traffic_gb, float $miss_gb, float $reads, float $cdn_read, float $image_gb ): array {
		$lines = array(
			array(
				'label' => __( '存储', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: %s: storage size in gigabytes. */
					__( '%s GB × 单价（含免费额度）', 'sakura-imgopt' ),
					self::num( $storage_gb )
				),
			),
		);

		if ( 'cdn' === $demand['mode'] ) {
			$lines[] = array(
				'label' => __( 'CDN 出口', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: 1: egress traffic in GB, 2: cache hit rate percentage. */
					__( '%1$s GB（命中率 %2$s%%）', 'sakura-imgopt' ),
					self::num( $traffic_gb ),
					self::num( (float) $demand['hit_rate'] )
				),
			);
			$lines[] = array(
				'label' => __( 'CDN 回源', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: %s: traffic that missed the cache, in GB. */
					__( '%s GB（未命中部分）', 'sakura-imgopt' ),
					self::num( $miss_gb )
				),
			);
			$lines[] = array(
				'label' => __( 'CDN 请求', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: %s: CDN read requests in units of ten thousand. */
					__( '%s 万次读请求', 'sakura-imgopt' ),
					self::num( $cdn_read )
				),
			);
		} else {
			$lines[] = array(
				'label' => __( '直出流量', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: 1: direct traffic in GB, 2: busy-hour percentage. */
					__( '%1$s GB（忙时占比 %2$s%%）', 'sakura-imgopt' ),
					self::num( $traffic_gb ),
					self::num( (float) $demand['busy_rate'] )
				),
			);
		}

		if ( $image_gb > 0 ) {
			if ( ! empty( $sheet['image_excluded'] ) ) {
				$image_note = __( '该厂商按转换次数计费，本页未计入', 'sakura-imgopt' );
			} elseif ( ! isset( $sheet['image'] ) ) {
				$image_note = __( '该厂商无图片处理服务', 'sakura-imgopt' );
			} elseif ( 0.0 === (float) $sheet['image'][0][1] && null === $sheet['image'][0][0] ) {
				$image_note = __( '免费', 'sakura-imgopt' );
			} else {
				$image_note = __( '前 10TB 免费，超出 0.025 元/GB', 'sakura-imgopt' );
			}

			$lines[] = array(
				'label' => __( '图片处理', 'sakura-imgopt' ),
				'value' => sprintf(
					/* translators: 1: image processing volume in GB, 2: billing rule. */
					__( '%1$s GB（%2$s）', 'sakura-imgopt' ),
					self::num( $image_gb ),
					$image_note
				),
			);
		}

		$lines[] = array(
			'label' => __( '存储请求', 'sakura-imgopt' ),
			'value' => sprintf(
					/* translators: 1: read requests, 2: write requests, both in units of ten thousand. */
					__( '读 %1$s 万次 + 写 %2$s 万次', 'sakura-imgopt' ),
					self::num( $reads ),
					self::num( (float) $demand['write_wan'] )
				),
		);

		if ( ! empty( $sheet['usd'] ) ) {
			$lines[] = array(
				'label' => __( '汇率', 'sakura-imgopt' ),
				'value' => sprintf( '1 USD = %s CNY', self::num( (float) $demand['fx'] ) ),
			);
		}

		return $lines;
	}

	/**
	 * Format a number without trailing zeros.
	 *
	 * @param float $value Number.
	 * @return string
	 */
	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Read one numeric field with a fallback and bounds.
	 *
	 * @param array<string,mixed> $input   Input.
	 * @param string              $key     Field name.
	 * @param float               $min     Lower bound.
	 * @param float               $max     Upper bound.
	 * @param float               $fallback Fallback.
	 * @return float
	 */
	private static function bound( array $input, string $key, float $min, float $max, float $fallback ): float {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return $fallback;
		}

		return min( $max, max( $min, (float) $input[ $key ] ) );
	}
}
