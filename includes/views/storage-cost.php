<?php
/**
 * Cloud storage cost estimator screen.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string,mixed> $defaults */
/** @var array<string,mixed> $result */
/** @var string $priced */

use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;

$demand = isset( $result['input'] ) && is_array( $result['input'] ) ? $result['input'] : $defaults;
$rows   = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
$path_mode   = isset( $demand['mode'] ) ? (string) $demand['mode'] : 'cdn';

$money = static function ( $value ): string {
	return '¥' . number_format( (float) $value, 2 );
};

$sources = array();
foreach ( $rows as $row ) {
	foreach ( array( 'source', 'cdn_source' ) as $field ) {
		$url = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		if ( '' !== $url && ! isset( $sources[ $url ] ) ) {
			$sources[ $url ] = (string) $row['name'];
		}
	}
}

ob_start();
?>
<div class="cost-form">
	<?php
	UI::field(
		array(
			'id'    => 'cost-storage',
			'type'  => 'number',
			'label' => __( '存储量（GB）', 'sakura-imgopt' ),
			'value' => (string) $demand['storage_gb'],
			'hint'  => __( '按标准存储估算，不含归档、低频等其它存储类型。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-traffic',
			'type'  => 'number',
			'label' => __( '月流量（GB）', 'sakura-imgopt' ),
			'value' => (string) $demand['traffic_gb'],
			'hint'  => __( '一个自然月从存储桶或 CDN 送到访问者的字节数。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-read',
			'type'  => 'number',
			'label' => __( '月读请求（万次）', 'sakura-imgopt' ),
			'value' => (string) $demand['read_wan'],
			'hint'  => __( '图片、文件被读取的次数；CDN 命中也会计一次 CDN 请求。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-write',
			'type'  => 'number',
			'label' => __( '月写请求（万次）', 'sakura-imgopt' ),
			'value' => (string) $demand['write_wan'],
			'hint'  => __( '上传、覆盖、删除等写入次数。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-image',
			'type'  => 'number',
			'label' => __( '月图片处理量（GB）', 'sakura-imgopt' ),
			'value' => (string) $demand['image_gb'],
			'hint'  => __( '按原图大小汇总；不做图片处理就填 0。阿里、腾讯、七牛、百度前 10TB 免费，多吉、缤纷、又拍免费。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-hit',
			'type'  => 'number',
			'label' => __( 'CDN 缓存命中率（%）', 'sakura-imgopt' ),
			'value' => (string) $demand['hit_rate'],
			'hint'  => __( '命中率越高，回源流量和源站读请求越少。只有 CDN 组合模式使用。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-busy',
			'type'  => 'number',
			'label' => __( '忙时流量占比（%）', 'sakura-imgopt' ),
			'value' => (string) $demand['busy_rate'],
			'hint'  => __( '阿里云、百度云等按闲忙时区分直出单价；全走白天就填 100。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-https',
			'type'  => 'number',
			'label' => __( 'HTTPS 请求占比（%）', 'sakura-imgopt' ),
			'value' => (string) $demand['https_rate'],
			'hint'  => __( '多数厂商只对 HTTPS 请求收费，HTTP 免费；七牛的 HTTPS 流量单价更高，会按比例混合。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'decimal' ),
		)
	);
	UI::field(
		array(
			'id'      => 'cost-mode',
			'type'    => 'select',
			'label'   => __( '流量路径', 'sakura-imgopt' ),
			'value'   => $path_mode,
			'options' => array(
				'cdn'    => __( 'CDN 组合（存储桶 + 同厂商 CDN）', 'sakura-imgopt' ),
				'direct' => __( '源站直出（不走 CDN）', 'sakura-imgopt' ),
			),
			'hint'    => __( 'CDN 组合会把回源流量与 CDN 出口流量一起算进去。', 'sakura-imgopt' ),
		)
	);
	UI::field(
		array(
			'id'    => 'cost-fx',
			'type'  => 'number',
			'label' => __( '美元汇率', 'sakura-imgopt' ),
			'value' => (string) $demand['fx'],
			'hint'  => __( 'Cloudflare R2、Backblaze B2 以美元计价，按此汇率折算人民币。', 'sakura-imgopt' ),
			'attrs' => array( 'min' => '0.5', 'step' => '0.1', 'inputmode' => 'decimal' ),
		)
	);
	?>
</div>
<div class="cost-actions">
	<button type="button" class="btn btn--primary" id="cost-estimate"><?php esc_html_e( '重新估算', 'sakura-imgopt' ); ?></button>
	<span class="cost-status" id="cost-status" role="status" aria-live="polite"></span>
</div>
<?php
UI::alert(
	sprintf(
		/* translators: %s: price sheet date. */
		__( '价格基准日：%s。所有单价来自厂商官方价格页；免费额度、阶梯累进、闲忙时与 CDN 回源都按官方规则参与计算。', 'sakura-imgopt' ),
		$priced
	),
	'info'
);
$demand_body = ob_get_clean();

ob_start();
?>
<p class="cost-meta" id="cost-meta">
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: traffic path label, 2: number of vendors. */
			__( '当前口径：%1$s；共比较 %2$s 家。按合计从低到高排列。', 'sakura-imgopt' ),
			'cdn' === $path_mode ? __( 'CDN 组合', 'sakura-imgopt' ) : __( '源站直出', 'sakura-imgopt' ),
			number_format_i18n( count( $rows ) )
		)
	);
	?>
</p>
<div class="table-scroll cost-table-wrap">
	<table class="table table--compact table--responsive cost-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( '存储商', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '存储费', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '流量费', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '请求费', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '处理费', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '合计 / 月', 'sakura-imgopt' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( '计费口径', 'sakura-imgopt' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="cost-rows">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$traffic   = (float) $row['egress'] + (float) $row['origin'];
				$detail_id = 'cost-detail-' . sanitize_key( (string) $row['key'] );
				?>
				<tr class="cost-row" data-cost-row="<?php echo esc_attr( (string) $row['key'] ); ?>">
					<td data-label="<?php esc_attr_e( '存储商', 'sakura-imgopt' ); ?>">
						<span class="cost-name">
							<?php echo esc_html( (string) $row['name'] ); ?>
							<?php if ( ! empty( $row['cheapest'] ) ) : ?>
								<?php UI::badge( __( '最低价', 'sakura-imgopt' ), 'ok' ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $row['usd'] ) ) : ?>
								<?php UI::badge( __( '美元计价', 'sakura-imgopt' ), 'neutral' ); ?>
							<?php endif; ?>
						</span>
						<span class="cost-tag"><?php echo esc_html( (string) $row['tag'] ); ?></span>
					</td>
					<td data-label="<?php esc_attr_e( '存储费', 'sakura-imgopt' ); ?>"><?php echo esc_html( $money( $row['storage'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '流量费', 'sakura-imgopt' ); ?>"><?php echo esc_html( $money( $traffic ) ); ?></td>
					<td data-label="<?php esc_attr_e( '请求费', 'sakura-imgopt' ); ?>"><?php echo esc_html( $money( $row['requests'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '处理费', 'sakura-imgopt' ); ?>"><?php echo esc_html( $money( $row['image'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '合计 / 月', 'sakura-imgopt' ); ?>"><strong class="cost-total"><?php echo esc_html( $money( $row['total'] ) ); ?></strong></td>
					<td>
						<button type="button" class="btn btn--quiet btn--small" data-cost-toggle="<?php echo esc_attr( (string) $row['key'] ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $detail_id ); ?>"><?php esc_html_e( '口径', 'sakura-imgopt' ); ?></button>
					</td>
				</tr>
				<tr class="cost-detail" id="<?php echo esc_attr( $detail_id ); ?>" data-cost-detail="<?php echo esc_attr( (string) $row['key'] ); ?>" hidden>
					<td colspan="7" data-label="">
						<div class="cost-detail__inner">
							<ul class="cost-detail__lines">
								<?php foreach ( (array) $row['billing'] as $line ) : ?>
									<li><span><?php echo esc_html( (string) $line['label'] ); ?></span> <?php echo esc_html( (string) $line['value'] ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="cost-detail__note"><?php echo esc_html( (string) $row['note'] ); ?></p>
							<p class="cost-detail__sources">
								<?php if ( '' !== (string) $row['source'] ) : ?>
									<a href="<?php echo esc_url( (string) $row['source'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '存储定价', 'sakura-imgopt' ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== (string) $row['cdn_source'] && (string) $row['cdn_source'] !== (string) $row['source'] ) : ?>
									<a href="<?php echo esc_url( (string) $row['cdn_source'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'CDN / 流量定价', 'sakura-imgopt' ); ?></a>
								<?php endif; ?>
							</p>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php
$table_body = ob_get_clean();

ob_start();
?>
<ul class="notes-list">
	<li><?php esc_html_e( 'HTTPS 占比只影响按请求计费的部分：阿里云 0.05、腾讯云 0.05（每月 300 万次免费）、多吉云 0.04（每月 200 万次免费）、又拍云 0.05 元/万次，HTTP 请求免费；缤纷云不区分协议；七牛云静态请求免费，但 HTTPS 下载流量单价高于 HTTP，按占比混合。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( 'CDN 组合模式假设访问全部经过同一厂商的 CDN：流量费 = CDN 出口 + 回源（回源量 = 流量 ×（1 − 命中率））。Cloudflare R2 与 Backblaze B2 经 Cloudflare 出网免费。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( '又拍云按「当日存储量 − 当日 CDN 流量」1:1 抵扣计费；标准类型直出流量官方未公开，直出模式按低频 0.5 元/GB 兜底。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( '阿里云直出 00:00-08:00 0.25、08:00-24:00 0.50 元/GB；百度云忙时 0.49、闲时 0.25 元/GB；缤纷云 00:00-08:00 直出 8 折。忙时占比会影响这三家。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( '图片处理按原图 GB 计：阿里云、腾讯云、七牛云、百度云每月前 10TB 免费、超出 0.025 元/GB；多吉云、缤纷云、又拍云免费；Cloudflare Images 按转换次数计费、Backblaze B2 无此服务，这两家未计入。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( '不含跨区域复制、归档/低频存储、最低存储期、HTTPS 证书、日志、内容审核与流量包折扣；实际账单以厂商计费为准。', 'sakura-imgopt' ); ?></li>
	<li><?php esc_html_e( 'MinIO 为自建部署，云费用为 0，服务器与带宽成本请自行核算。', 'sakura-imgopt' ); ?></li>
</ul>
<p class="cost-sources">
	<?php esc_html_e( '价格来源：', 'sakura-imgopt' ); ?>
	<?php $first = true; ?>
	<?php foreach ( $sources as $url => $name ) : ?>
		<?php if ( ! $first ) : ?> · <?php endif; ?>
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $name ); ?></a>
		<?php $first = false; ?>
	<?php endforeach; ?>
</p>
<?php
$notes_body = ob_get_clean();

$cards = static function () use ( $demand_body, $table_body, $notes_body ): void {
	UI::card(
		array(
			'id'          => 'demand',
			'kicker'      => __( 'Step 1', 'sakura-imgopt' ),
			'title'       => __( '你的需求', 'sakura-imgopt' ),
			'description' => __( '填一个月的真实用量。请求按「万次」计，填 100 就是 100 万次。', 'sakura-imgopt' ),
		),
		static function () use ( $demand_body ): void {
			echo $demand_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup.
		}
	);

	UI::card(
		array(
			'id'          => 'table',
			'kicker'      => __( 'Step 2', 'sakura-imgopt' ),
			'title'       => __( '各家月费对比', 'sakura-imgopt' ),
			'description' => __( '按合计排序；点每行的「口径」可以看到这一家是怎么算出来的。', 'sakura-imgopt' ),
		),
		static function () use ( $table_body ): void {
			echo $table_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup.
		}
	);

	UI::card(
		array(
			'id'          => 'notes',
			'kicker'      => __( 'Notes', 'sakura-imgopt' ),
			'title'       => __( '计算口径与价格来源', 'sakura-imgopt' ),
			'description' => __( '估算用于横向比较；下单前请以厂商计费页与账单为准。', 'sakura-imgopt' ),
		),
		static function () use ( $notes_body ): void {
			echo $notes_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup.
		}
	);
};

UI::page(
	array(
		'id'      => 'storage-cost',
		'module'  => 'storage',
		'tab'     => 'cost',
		'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '云存储费用估算', 'sakura-imgopt' ),
		'lede'    => __( '填一个月的大致用量，横向比较各家的存储、CDN 流量、请求与图片处理费用。价格全部来自官方定价页。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '价格基准', 'sakura-imgopt' ),
			'value' => $priced,
			'sub'   => __( '官方定价，含免费额度', 'sakura-imgopt' ),
			'tone'  => 'ok',
		),
		'actions' => array(
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
	),
	$cards
);
