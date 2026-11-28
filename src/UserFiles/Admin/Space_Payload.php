<?php
/**
 * Bootstrap payload for the Zibll-only file space tab.
 *
 * The page keeps the six-key contract: page / head / chrome / cards / strings /
 * rest. Card data travels on each card, the REST endpoints are named in `rest`,
 * and every write happens through Space_Settings_Controller. There is no
 * options.php form left on this screen.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\Payload_Contract;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Data builder for files-zibll.
 */
final class Space_Payload {

	/**
	 * Page id, also the App_Manifest route and the payload contract id.
	 */
	public const PAGE = 'files-zibll';

	/**
	 * Build the complete contract document.
	 *
	 * Card order comes from Layout::PAGES, never from a second list: the page
	 * contract test compares the two, and a mismatch reorders cards silently.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$layout   = Layout::layout_for_page( self::PAGE );
		$order    = Layout::PAGES[ $layout ]['order'] ?? array();
		$sections = array();

		foreach ( $order as $id ) {
			$sections[ $id ] = Space_Cards::data( $id );
		}

		$page = $this->page_args( $sections[ Space_Cards::DIAG ] ?? array() );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'chrome'  => Shell::header_data( 'files', Space_Page::TAB ),
			'cards'   => $this->cards( $layout, $order, $sections ),
			'strings' => $this->strings(),
			'rest'    => $this->rest(),
			'data'    => array( 'layout' => $layout ),
		);
	}

	/**
	 * Page chrome derived from the diagnostics snapshot.
	 *
	 * @param array<string,mixed> $diag Diagnostic card data.
	 * @return array<string,mixed>
	 */
	private function page_args( array $diag ): array {
		$theme = isset( $diag['theme'] ) && is_array( $diag['theme'] ) ? $diag['theme'] : array();
		$ready = (int) ( $diag['ready'] ?? 0 );
		$total = (int) ( $diag['total'] ?? 0 );
		$name  = (string) ( $theme['name'] ?? '' );

		return array(
			'id'      => self::PAGE,
			'module'  => 'files',
			'tab'     => Space_Page::TAB,
			'eyebrow' => 'ZIBLL BRIDGE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
			'title'   => __( '子比专属', 'sakura-imgopt' ),
			'lede'    => __( '主题能力、支付通道、前台入口与等级加成集中在这里；所有数值都在运行时从 zibll 读取，不写死主题侧的名称或价格。', 'sakura-imgopt' ),
			'status'  => array(
				'label' => __( '子比桥接', 'sakura-imgopt' ),
				'value' => $ready . ' / ' . $total,
				'unit'  => __( '项能力', 'sakura-imgopt' ),
				'sub'   => '' !== $name ? $name : __( '未识别主题', 'sakura-imgopt' ),
				'tone'  => $ready === $total ? 'ok' : ( $ready >= 5 ? 'warn' : 'bad' ),
			),
		);
	}

	/**
	 * Card descriptors in Layout order, each carrying its own data.
	 *
	 * @param string                            $layout   Layout id.
	 * @param array<int,string>                 $order    Layout reading order.
	 * @param array<string,array<string,mixed>> $sections Per-card data.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( string $layout, array $order, array $sections ): array {
		$cards = array();

		foreach ( $order as $id ) {
			$meta = Space_Cards::meta( $id );

			if ( null === $meta ) {
				continue;
			}

			if ( Space_Cards::LEVEL === $id ) {
				$meta['description'] = sprintf(
					/* translators: %d: number of theme levels. */
					__( '按主题等级自动折算：当前主题共 %d 级，等级名与门槛积分都读自主题。每满一定积分增加一定容量，等级越高、门槛越高，加成越大。', 'sakura-imgopt' ),
					(int) ( $sections[ $id ]['max'] ?? 0 )
				);
			}

			$meta['id']        = $id;
			$meta['placement'] = Layout::card( $layout, $id ) ?? array( 0, 1, 12 );
			$meta['data']      = $sections[ $id ] ?? array();

			$cards[] = $meta;
		}

		return $cards;
	}

	/**
	 * Named REST endpoints. `url` is the sufm/v1 namespace, like the file list
	 * pages; every saving/reading action below has its own absolute URL.
	 *
	 * @return array<string,mixed>
	 */
	private function rest(): array {
		$rest = Payload_Contract::rest_for( rest_url( 'sufm/v1/' ), self::PAGE );

		$rest['settings']  = array( 'url' => esc_url_raw( rest_url( 'sufm/v1/admin/space/settings' ) ) );
		$rest['grants']    = array( 'url' => esc_url_raw( rest_url( 'sufm/v1/admin/space/grants' ) ) );
		$rest['reconcile'] = array( 'url' => esc_url_raw( rest_url( 'sufm/v1/admin/space/reconcile' ) ) );

		return $rest;
	}

	/**
	 * All user-facing strings used by the island.
	 *
	 * @return array<string,string>
	 */
	private static function strings(): array {
		return array(
			'unitMb' => __( 'MB', 'sakura-imgopt' ),
			'unitGb' => __( 'GB', 'sakura-imgopt' ),
			'saving' => __( '正在保存…', 'sakura-imgopt' ),
			'failed' => __( '保存失败，请稍后重试。', 'sakura-imgopt' ),
			'network' => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
			'close' => __( '关闭', 'sakura-imgopt' ),
			'dismiss' => __( '知道了', 'sakura-imgopt' ),
			'closeIcon' => UI\Modal::close_icon(),
			'mascot' => UI\Modal::mascot(),
			'confirmTitle' => __( '请确认', 'sakura-imgopt' ),
			'confirm' => __( '确认', 'sakura-imgopt' ),
			'cancel' => __( '取消', 'sakura-imgopt' ),
			'loading' => __( '正在读取…', 'sakura-imgopt' ),
			'empty' => __( '暂无数据', 'sakura-imgopt' ),
			'yes' => __( '是', 'sakura-imgopt' ),
			'no' => __( '否', 'sakura-imgopt' ),
			'unknown' => __( '未取到', 'sakura-imgopt' ),
			'themeTitle' => __( '当前主题', 'sakura-imgopt' ),
			'name' => __( '名称', 'sakura-imgopt' ),
			'version' => __( '版本', 'sakura-imgopt' ),
			'stylesheet' => __( '样式目录', 'sakura-imgopt' ),
			'template' => __( '模板目录', 'sakura-imgopt' ),
			'child' => __( '子主题', 'sakura-imgopt' ),
			'capsTitle' => __( '能力矩阵', 'sakura-imgopt' ),
			'capOk' => __( '可用', 'sakura-imgopt' ),
			'capMissing' => __( '缺失', 'sakura-imgopt' ),
			'capClosed' => __( '该通道已自动关闭', 'sakura-imgopt' ),
			'methodsTitle' => __( '已启用购买方式', 'sakura-imgopt' ),
			'noMethods' => __( '当前没有启用任何购买方式。可在下方「支付方式」卡片勾选并保存。', 'sakura-imgopt' ),
			'points' => __( '积分兑换', 'sakura-imgopt' ),
			'methodsNote' => __( '现金通道只显示已勾选且主题当前提供的项目；积分兑换由本插件直接扣减，不经过主题支付。', 'sakura-imgopt' ),
			'breakdownTitle' => __( '我的派生容量', 'sakura-imgopt' ),
			'base' => __( '基础', 'sakura-imgopt' ),
			'levelBonus' => __( '等级加成', 'sakura-imgopt' ),
			'vipBonus' => __( 'VIP 加成', 'sakura-imgopt' ),
			'granted' => __( '已购', 'sakura-imgopt' ),
			'computed' => __( '计算值', 'sakura-imgopt' ),
			'override' => __( '单独设定', 'sakura-imgopt' ),
			'final' => __( '最终生效', 'sakura-imgopt' ),
			'unset' => __( '未设定', 'sakura-imgopt' ),
			'mode' => __( '封顶语义', 'sakura-imgopt' ),
			'modeCap' => __( '封顶（cap）· 实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ),
			'modeReplace' => __( '替换（replace）· 单独设定直接覆盖计算值', 'sakura-imgopt' ),
			'themeMethods' => __( '主题支付通道', 'sakura-imgopt' ),
			'themeMethodsHint' => __( '余额由主题同步确认；二维码或跳转渠道必须确认能处理退款与超时订单后再勾选。', 'sakura-imgopt' ),
			'noThemeMethods' => __( '未读取到主题支付通道。请确认已登录后台，且主题已开启对应的支付方式。', 'sakura-imgopt' ),
			'balanceNote' => __( '同步到账，推荐启用。', 'sakura-imgopt' ),
			'externalNote' => __( '外部支付渠道，可能出现未支付订单，需要人工对账。', 'sakura-imgopt' ),
			'pointsEnabled' => __( '启用积分兑换', 'sakura-imgopt' ),
			'pointsHint' => __( '积分由本插件直接扣减，不经过主题支付；与现金通道互不影响。', 'sakura-imgopt' ),
			'pointsNote' => __( '关闭后前台隐藏积分入口，接口拒绝兑换；已售出的积分授权不受影响。', 'sakura-imgopt' ),
			'entryEnabled' => __( '启用前台入口', 'sakura-imgopt' ),
			'entryOn' => __( '当前：已启用', 'sakura-imgopt' ),
			'entryOff' => __( '当前：未启用（前台零入口）', 'sakura-imgopt' ),
			'currentTheme' => __( '当前主题', 'sakura-imgopt' ),
			'detectedEntry' => __( '检测到的入口', 'sakura-imgopt' ),
			'frontPosition' => __( '前台位置', 'sakura-imgopt' ),
			'frontUrl' => __( '前台地址', 'sakura-imgopt' ),
			'generic' => __( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' ),
			'userCenter' => __( '用户中心 → 文件管理', 'sakura-imgopt' ),
			'unpublished' => __( '尚未发布', 'sakura-imgopt' ),
			'entryNote' => __( '关闭只停用入口与前台上传接口，不删除文件，也不重置容量。', 'sakura-imgopt' ),
			'editorEnabled' => __( '启用编辑器文件面板', 'sakura-imgopt' ),
			'editorPages' => __( '生效页面', 'sakura-imgopt' ),
			'editorPagesValue' => __( '写文章 /newposts、发帖与编辑 /posts-edit', 'sakura-imgopt' ),
			'editorCondition' => __( '开启条件', 'sakura-imgopt' ),
			'editorConditionValue' => __( '桌面端，且浏览器窗口宽度 ≥ 1840px；不足时按钮不出现', 'sakura-imgopt' ),
			'editorPermission' => __( '权限', 'sakura-imgopt' ),
			'editorPermissionValue' => __( '已登录且拥有「管理自己的文件空间」权限；只看得到自己的文件', 'sakura-imgopt' ),
			'editorIndependent' => __( '与前台入口互不影响。', 'sakura-imgopt' ),
			'gateEnabled' => __( '启用扩容售卖', 'sakura-imgopt' ),
			'gateOn' => __( '当前：已启用', 'sakura-imgopt' ),
			'gateOff' => __( '当前：未启用（前台零入口）', 'sakura-imgopt' ),
			'gateEnableWarn' => __( '启用后将开启扩容售卖入口，用户可付费购买容量。确认继续？', 'sakura-imgopt' ),
			'gateDisableWarn' => __( '关闭后前台扩容入口会立即消失，已售出的授权仍然有效。确认继续？', 'sakura-imgopt' ),
			'applyBonuses' => __( '计算加成（已购授权）', 'sakura-imgopt' ),
			'applyBonusesLabel' => __( '计算已购授权', 'sakura-imgopt' ),
			'applyBonusesNote' => __( '关闭后，所有已购容量立即停止生效（不删任何文件）。', 'sakura-imgopt' ),
			'applyBonusesWarn' => __( '关闭「计算加成」会让所有已购容量立即停止生效（不删任何文件）。确认继续？', 'sakura-imgopt' ),
			'overrideMode' => __( '单独设定的封顶语义', 'sakura-imgopt' ),
			'modeCapShort' => __( '封顶（cap）：实际容量 = min(单独设定, 计算值)', 'sakura-imgopt' ),
			'modeReplaceShort' => __( '替换（replace）：单独设定直接覆盖计算值', 'sakura-imgopt' ),
			'levelRule' => __( '启用规则（关闭后回退到下方手填表）', 'sakura-imgopt' ),
			'pointsPerStep' => __( '每满积分', 'sakura-imgopt' ),
			'pointsPerStepHint' => __( '达到该积分后开始计算一级加成。', 'sakura-imgopt' ),
			'bytesPerStep' => __( '增加（MB）', 'sakura-imgopt' ),
			'bytesPerStepHint' => __( '每满一级增加的容量，单位 MB。', 'sakura-imgopt' ),
			'level' => __( '等级', 'sakura-imgopt' ),
			'threshold' => __( '门槛积分', 'sakura-imgopt' ),
			'bonusMb' => __( '加成（MB）', 'sakura-imgopt' ),
			'total' => __( '加成合计：', 'sakura-imgopt' ),
			'vipLevel' => __( 'VIP 等级', 'sakura-imgopt' ),
			'cashPackages' => __( '现金套餐', 'sakura-imgopt' ),
			'cashHint' => __( '用户在弹窗里看到的价格档位，按下单顺序展示；价格取主题支付通道。', 'sakura-imgopt' ),
			'pointsPackages' => __( '积分套餐', 'sakura-imgopt' ),
			'pointsPackagesHint' => __( '积分兑换按「每 GB 所需积分」折算；等价现金用主题积分汇率（pay_points_rate）反算。', 'sakura-imgopt' ),
			'perGb' => __( '每 GB 积分', 'sakura-imgopt' ),
			/* translators: 1: price in currency per GB, 2: points per currency unit. */
			'perGbHint' => __( '约 %1$s 元/GB（主题汇率 %2$s 积分 = 1 元）。', 'sakura-imgopt' ),
			'noPayRate' => __( '未取到主题积分汇率（pay_points_rate）。', 'sakura-imgopt' ),
			'maxGb' => __( '单笔上限（GB）', 'sakura-imgopt' ),
			'maxGbHint' => __( '保存时会被强制压到「每日上限」以内。', 'sakura-imgopt' ),
			'dailyCap' => __( '每日上限（GB）', 'sakura-imgopt' ),
			'dailyCapHint' => __( '同一用户一天内可兑换的总量上限。', 'sakura-imgopt' ),
			'planId' => __( '套餐 ID', 'sakura-imgopt' ),
			'planName' => __( '名称', 'sakura-imgopt' ),
			'capacityGb' => __( '容量（GB）', 'sakura-imgopt' ),
			'price' => __( '价格（元）', 'sakura-imgopt' ),
			'amount' => __( '金额', 'sakura-imgopt' ),
			'pointsRequired' => __( '所需积分', 'sakura-imgopt' ),
			'days' => __( '时长（天）', 'sakura-imgopt' ),
			'active' => __( '上架', 'sakura-imgopt' ),
			'remove' => __( '删除', 'sakura-imgopt' ),
			'addCash' => __( '＋ 添加现金套餐', 'sakura-imgopt' ),
			'addPoints' => __( '＋ 添加积分套餐', 'sakura-imgopt' ),
			'labelPlaceholder' => __( '如 10GB 扩容包', 'sakura-imgopt' ),
			'pointsLabelPlaceholder' => __( '留空自动生成', 'sakura-imgopt' ),
			'chooseIcon' => __( '选择图标', 'sakura-imgopt' ),
			'removeIcon' => __( '移除', 'sakura-imgopt' ),
			'iconPlaceholder' => __( '选择或粘贴图标地址', 'sakura-imgopt' ),
			'iconHint' => __( '对所有套餐生效（现金套餐与积分套餐共用）。建议 96×96（或 128×128）正方形 PNG/SVG，≤200 KB；留空使用默认的存储图标。图标会出现在后台订单列表、前台「我的订单」和订单详情弹窗。', 'sakura-imgopt' ),
			'noCash' => __( '暂无可用现金套餐，无法比较。', 'sakura-imgopt' ),
			/* translators: %s: lowest cash price per GB. */
			'cheapest' => __( '最便宜现金套餐：%s 元/GB。', 'sakura-imgopt' ),
			'far' => __( '积分定价明显偏离现金价（超过 2 倍）。', 'sakura-imgopt' ),
			/* translators: 1: GB already redeemed today, 2: daily GB cap, 3: number of redemptions. */
			'today' => __( '今日已兑换 %1$s GB / 上限 %2$s GB（%3$d 笔）。', 'sakura-imgopt' ),
			'planNote' => __( '套餐 ID 自动分配（A 为现金、P 为积分），删除后不复用；容量填 0 或价格/积分空着的行在保存时会被丢弃。', 'sakura-imgopt' ),
			'grantsEmpty' => __( '暂无授权记录', 'sakura-imgopt' ),
			'grantsEmptyMessage' => __( '授权会在用户完成现金订单或积分兑换后出现。', 'sakura-imgopt' ),
			'gateDisabledBanner' => __( '扩容尚未启用。前台不会出现任何入口；已存在的等级 / VIP 加成也不会影响容量。', 'sakura-imgopt' ),
			'grantsLoading' => __( '正在读取授权记录…', 'sakura-imgopt' ),
			'grantsError' => __( '授权记录加载失败，请稍后重试。', 'sakura-imgopt' ),
			'grantTimeUser' => __( '时间 / 用户', 'sakura-imgopt' ),
			'grantPackageSize' => __( '套餐 / 容量', 'sakura-imgopt' ),
			'grantExpiry' => __( '到期', 'sakura-imgopt' ),
			'grantState' => __( '状态', 'sakura-imgopt' ),
			'grantActions' => __( '操作', 'sakura-imgopt' ),
			'permanent' => __( '永久', 'sakura-imgopt' ),
			'grantExtendTitle' => __( '延长授权', 'sakura-imgopt' ),
			'grantExtendPrompt' => __( '请输入延长天数（1–3650）', 'sakura-imgopt' ),
			'grantExtend' => __( '延长', 'sakura-imgopt' ),
			'grantExtendDone' => __( '已延长授权。', 'sakura-imgopt' ),
			'grantRevokeTitle' => __( '撤销授权', 'sakura-imgopt' ),
			'grantRevokePrompt' => __( '输入「撤销」两个字确认本次操作', 'sakura-imgopt' ),
			'grantRevoke' => __( '撤销', 'sakura-imgopt' ),
			'grantRevokeDone' => __( '已撤销授权。', 'sakura-imgopt' ),
			'grantActionFailed' => __( '操作失败，请重试。', 'sakura-imgopt' ),
			'revokeWord' => __( '撤销', 'sakura-imgopt' ),
			'invalidDays' => __( '请输入 1–3650 之间的天数。', 'sakura-imgopt' ),
			'invalidWord' => __( '输入不匹配，操作已取消。', 'sakura-imgopt' ),
			'mediaUnavailable' => __( '媒体库未加载，请刷新页面后重试。', 'sakura-imgopt' ),
			'refreshGrants' => __( '刷新授权', 'sakura-imgopt' ),
			'reconcileCheck' => __( '检查差异', 'sakura-imgopt' ),
			'reconcileApply' => __( '修复差异', 'sakura-imgopt' ),
			'reconcileNotChecked' => __( '尚未检查。点击「检查差异」比对已付款订单与授权记录。', 'sakura-imgopt' ),
			'reconcileChecking' => __( '正在比对订单与授权…', 'sakura-imgopt' ),
			/* translators: %s: number of paid orders without a grant. */
			'reconcileMissing' => __( '发现 %s 笔「已付款但无授权」的订单。', 'sakura-imgopt' ),
			'reconcileClean' => __( '未发现差异。', 'sakura-imgopt' ),
			'reconcileHealing' => __( '正在补发授权…', 'sakura-imgopt' ),
			/* translators: %s: number of reissued grants. */
			'reconcileHealed' => __( '已补发 %s 笔授权。', 'sakura-imgopt' ),
			'reconcileFailed' => __( '对账查询失败。', 'sakura-imgopt' ),
			/* translators: %s: number of grants about to be reissued. */
			'reconcileConfirm' => __( '将按订单快照补发 %s 笔授权，确认？', 'sakura-imgopt' ),
			'reconcileNoItems' => __( '没有需要补发的订单。', 'sakura-imgopt' ),
			'orderNum' => __( '订单号', 'sakura-imgopt' ),
			'user' => __( '用户', 'sakura-imgopt' ),
			'payTime' => __( '支付时间', 'sakura-imgopt' ),
		);
	}
}
