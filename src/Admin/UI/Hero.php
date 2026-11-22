<?php
/**
 * Default hero copy for the admin page heads.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the page head's two V2 pieces when a view does not pass its own.
 *
 * The tagline is the one place a settings screen speaks in a whole sentence
 * instead of a label, and the seal is the brand's signature in the lower right
 * corner. Both started as opt-in, which is why the sync centre used to be the
 * only page that looked finished; they live here now so every page reads as the
 * same product without 14 views each inventing a headline.
 *
 * A view may still override either one: `UI::page()` prefers the `tagline` and
 * `stamp` arguments it is given, and only falls back to this map.
 */
final class Hero {

	/**
	 * Page id => tagline.
	 *
	 * Each line gets exactly one <em>, because the gradient is a highlight, not
	 * a paint bucket: the whole sentence in gradient drops the smaller strokes
	 * under 3:1 against the canvas. Keep the emphasised half short.
	 *
	 * @return array<string,string>
	 */
	private static function taglines(): array {
		return array(
			'image-rules'         => __( '<em>把每一张图压到最轻</em>：裁剪、重命名、格式转换，规则定一次，剩下的交给队列。', 'sakura-imgopt' ),
			'image-thumb'         => __( '<em>尺寸名自己说话</em>：按显示位置决定要不要，不再依赖主题的样式名。', 'sakura-imgopt' ),
			'image-backup'        => __( '<em>原图先留一份</em>：优化前拍下快照，需要时一键还原。', 'sakura-imgopt' ),
			'image-stats'         => __( '<em>省下的空间会记账</em>：处理结果、节省与最近执行，都写在这本账上。', 'sakura-imgopt' ),
			'media-progress'      => __( '<em>队列在跑，你可以先走</em>：进度、失败与重试都留在这一页。', 'sakura-imgopt' ),
			'storage-connection'  => __( '<em>先连上，再谈同步</em>：厂商、密钥与存储桶，配对一次，处处可用。', 'sakura-imgopt' ),
			'storage-deletes'     => __( '<em>删除也要排队</em>：云端对象按退避时间逐条清，失败的重试到放弃为止。', 'sakura-imgopt' ),
			'storage-rewrite'     => __( '<em>换桶不换链接</em>：旧域名统一改写，先记账本，随时可回滚。', 'sakura-imgopt' ),
			'storage-cost'        => __( '<em>先算清楚再上传</em>：存储、流量、请求与图片处理，按官方定价横向比。', 'sakura-imgopt' ),
			'system-settings'     => __( '<em>先把底盘照清楚</em>：运行参数、图像引擎与版式外观，都在这里定。', 'sakura-imgopt' ),
			'files-settings'      => __( '<em>空间长什么样由你定</em>：主题色、限额与审核策略。', 'sakura-imgopt' ),
			'files-media'         => __( '<em>一眼看到谁传了什么</em>：左边账户、右边文件，勾选即可下架。', 'sakura-imgopt' ),
			'files-users'         => __( '<em>每个人的那一份</em>：容量、已用与归属，一屏对清。', 'sakura-imgopt' ),
			'files-queue'         => __( '<em>该批的批，该查的查</em>：申请排进这里，按策略逐条执行。', 'sakura-imgopt' ),
			'files-log'           => __( '<em>动过什么都留痕</em>：最近的操作记录按时间倒序。', 'sakura-imgopt' ),
			'files-zibll'         => __( '<em>与主题对齐</em>：能力、支付、入口与等级加成，运行时读取，不写死。', 'sakura-imgopt' ),
			'storage-sync'        => __( '<em>樱前线已经过境</em>：本地、云端、索引三条线停在同一点。', 'sakura-imgopt' ),
			'zibll-performance'   => __( '<em>一次通过，整站受益</em>：把主题每个请求都要跑的授权校验，换成已验证过的缓存。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Tagline for a page id.
	 *
	 * Returns an empty string for an unknown page so a newly added screen simply
	 * renders without one instead of inheriting a sentence that is not about it.
	 *
	 * @param string $id Sanitised page id.
	 * @return string
	 */
	public static function tagline( string $id ): string {
		$taglines = self::taglines();

		return isset( $taglines[ $id ] ) ? $taglines[ $id ] : '';
	}

	/**
	 * The page seal.
	 *
	 * The glyph is the brand mark, not the module name: it is the same seal on
	 * every screen, so the lower right corner answers "which product is this"
	 * the way the tile in the top bar does. Colour rides on currentColor, see
	 * Page::stamp().
	 *
	 * @return array<string,string>
	 */
	public static function stamp(): array {
		return array(
			'zh'    => __( '桜', 'sakura-imgopt' ),
			'en'    => 'SAKURA TOOLS',
			'place' => 'aside',
		);
	}
}
