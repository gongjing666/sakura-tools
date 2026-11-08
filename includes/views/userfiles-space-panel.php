<?php
/**
 * Front end view: space expansion dialog (P1 form, P1b layout).
 *
 * Rendered by \Nboke\UserFiles\Space_Panel::markup(). The entry point is the
 * toolbar button `#sufm-space-open`; this file only renders the <dialog>, so it
 * can be injected anywhere in the tab content without disturbing page flow.
 *
 * Theme first: every class below comes from the Zibll theme (box-body / but /
 * radius8 / c-blue / muted-2-color). Layout only lives in
 * assets/userfiles-space.css and only through theme CSS variables.
 *
 * @var array<string,mixed> $data Dialog payload from Space_Panel::data().
 *
 * @package Sakura\ImageOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sufm_space_plans   = isset( $data['plans'] ) && is_array( $data['plans'] ) ? $data['plans'] : array();
$sufm_space_methods = isset( $data['methods'] ) && is_array( $data['methods'] ) ? $data['methods'] : array();
$sufm_space_parts   = isset( $data['breakdown_parts'] ) && is_array( $data['breakdown_parts'] ) ? $data['breakdown_parts'] : array();
$sufm_space_quota   = isset( $data['quota_human'] ) ? (string) $data['quota_human'] : '';

$sufm_space_part_text = array();

foreach ( $sufm_space_parts as $sufm_space_part ) {
	$sufm_space_key   = (string) ( $sufm_space_part['key'] ?? '' );
	$sufm_space_label = (string) ( $sufm_space_part['label'] ?? '' );
	$sufm_space_human = (string) ( $sufm_space_part['human'] ?? '' );
	$sufm_space_bytes = (int) ( $sufm_space_part['bytes'] ?? 0 );

	if ( 'base' === $sufm_space_key ) {
		$sufm_space_part_text[] = $sufm_space_label . ' ' . $sufm_space_human;
		continue;
	}

	// Zero valued bonuses are noise in a one line summary.
	if ( 0 >= $sufm_space_bytes ) {
		continue;
	}

	$sufm_space_part_text[] = ( 'level' === $sufm_space_key || 'vip' === $sufm_space_key )
		? $sufm_space_label . ' +' . $sufm_space_human
		: $sufm_space_label . ' ' . $sufm_space_human;
}

$sufm_space_parts_line = array(
	sprintf(
		/* translators: %s: current quota, e.g. 21 GB. */
		__( '当前 %s', 'sakura-imgopt' ),
		$sufm_space_quota
	),
);

$sufm_space_parts_line = array_merge( $sufm_space_parts_line, $sufm_space_part_text );

$sufm_space_first_plan = '';
$sufm_space_first_way  = '';

foreach ( $sufm_space_plans as $sufm_space_plan ) {
	$sufm_space_first_plan = (string) ( $sufm_space_plan['id'] ?? '' );
	break;
}

foreach ( $sufm_space_methods as $sufm_space_way_id => $sufm_space_way ) {
	unset( $sufm_space_way );
	$sufm_space_first_way = (string) $sufm_space_way_id;
	break;
}

// Prefer the balance channel when the theme offers it.
if ( isset( $sufm_space_methods['balance'] ) ) {
	$sufm_space_first_way = 'balance';
}

$sufm_space_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<dialog class="sufm-dialog sufm-space-dialog" id="sufm-space-dialog" aria-labelledby="sufm-space-dialog-title">
	<div class="sufm-space-dialog-head">
		<h3 class="em12" id="sufm-space-dialog-title"><?php esc_html_e( '空间扩容', 'sakura-imgopt' ); ?></h3>
		<button type="button" class="sufm-space-dialog-close" data-sufm-space-close aria-label="<?php esc_attr_e( '关闭', 'sakura-imgopt' ); ?>">&times;</button>
	</div>

	<div class="sufm-space-dialog-body">
		<p class="muted-2-color em09 sufm-space-parts">
			<?php echo esc_html( implode( ' · ', $sufm_space_parts_line ) ); ?>
		</p>

		<div class="sufm-space-plans" data-sufm-space-plans-cash role="radiogroup" aria-label="<?php esc_attr_e( '选择扩容套餐', 'sakura-imgopt' ); ?>">
			<?php foreach ( $sufm_space_plans as $sufm_space_plan ) : ?>
				<?php $sufm_space_plan_id = (string) ( $sufm_space_plan['id'] ?? '' ); ?>
				<label class="sufm-space-plan radius8 box-body">
					<input type="radio"
						name="sufm-space-plan"
						value="<?php echo esc_attr( $sufm_space_plan_id ); ?>"
						data-bytes="<?php echo esc_attr( (string) ( $sufm_space_plan['bytes'] ?? 0 ) ); ?>"
						data-bytes-human="<?php echo esc_attr( (string) ( $sufm_space_plan['bytes_human'] ?? '' ) ); ?>"
						data-price="<?php echo esc_attr( (string) ( $sufm_space_plan['price'] ?? 0 ) ); ?>"
						data-price-human="<?php echo esc_attr( (string) ( $sufm_space_plan['price_human'] ?? '' ) ); ?>"
						data-days="<?php echo esc_attr( (string) ( $sufm_space_plan['days'] ?? 0 ) ); ?>"
						<?php checked( $sufm_space_plan_id, $sufm_space_first_plan ); ?> />
					<strong class="sufm-space-plan-bytes"><?php echo esc_html( (string) ( $sufm_space_plan['bytes_human'] ?? '' ) ); ?></strong>
					<b class="c-blue sufm-space-plan-price"><?php echo esc_html( (string) ( $sufm_space_plan['price_human'] ?? '' ) ); ?></b>
					<em class="muted-2-color sufm-space-plan-days"><?php echo esc_html( (string) ( $sufm_space_plan['days_label'] ?? '' ) ); ?></em>
				</label>
			<?php endforeach; ?>

			
		</div>

		<?php
		$sufm_space_pts_plans = isset( $data['points_plans'] ) && is_array( $data['points_plans'] ) ? $data['points_plans'] : array();
		$sufm_space_first_pp  = '';

		foreach ( $sufm_space_pts_plans as $sufm_space_pp_first ) {
			$sufm_space_first_pp = (string) ( $sufm_space_pp_first['id'] ?? '' );
			break;
		}
		?>
		<?php if ( array() !== $sufm_space_pts_plans ) : ?>
			<div class="sufm-space-plans" data-sufm-space-plans-points role="radiogroup" aria-label="<?php esc_attr_e( '选择积分套餐', 'sakura-imgopt' ); ?>" hidden>
				<?php foreach ( $sufm_space_pts_plans as $sufm_space_pp ) : ?>
					<?php
					$sufm_space_pp_id     = (string) ( $sufm_space_pp['id'] ?? '' );
					$sufm_space_pp_gb     = isset( $sufm_space_pp['gb'] ) ? (float) $sufm_space_pp['gb'] : 0.0;
					$sufm_space_pp_points = isset( $sufm_space_pp['points'] ) ? (int) $sufm_space_pp['points'] : 0;
					$sufm_space_pp_days   = isset( $sufm_space_pp['days'] ) ? (int) $sufm_space_pp['days'] : 0;
					?>
					<label class="sufm-space-plan sufm-space-plan--points radius8 box-body">
						<input type="radio"
							name="sufm-space-points-plan"
							value="<?php echo esc_attr( $sufm_space_pp_id ); ?>"
							data-gb="<?php echo esc_attr( (string) $sufm_space_pp_gb ); ?>"
							data-points="<?php echo esc_attr( (string) $sufm_space_pp_points ); ?>"
							data-days="<?php echo esc_attr( (string) $sufm_space_pp_days ); ?>"
							<?php checked( $sufm_space_pp_id, $sufm_space_first_pp ); ?> />
						<strong class="sufm-space-plan-bytes"><?php echo esc_html( (string) $sufm_space_pp_gb . ' GB' ); ?></strong>
						<b class="c-blue sufm-space-plan-price"><?php echo esc_html( sprintf( /* translators: %s: points amount. */ __( '%s 积分', 'sakura-imgopt' ), number_format_i18n( $sufm_space_pp_points ) ) ); ?></b>
						<em class="muted-2-color sufm-space-plan-days"><?php echo esc_html( (string) ( $sufm_space_pp['days_label'] ?? '' ) ); ?></em>
					</label>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="sufm-space-methods" role="radiogroup" aria-label="<?php esc_attr_e( '选择支付方式', 'sakura-imgopt' ); ?>">
			<?php foreach ( $sufm_space_methods as $sufm_space_way_id => $sufm_space_way ) : ?>
				<label class="sufm-space-method but but-sm">
					<input type="radio"
						name="sufm-space-method"
						value="<?php echo esc_attr( (string) $sufm_space_way_id ); ?>"
						<?php checked( (string) $sufm_space_way_id, $sufm_space_first_way ); ?> />
					<span><?php echo esc_html( (string) ( $sufm_space_way['name'] ?? $sufm_space_way_id ) ); ?></span>
				</label>
			<?php endforeach; ?>
			<?php if ( ! empty( $data['points']['enabled'] ) ) : ?>
				<label class="sufm-space-method but but-sm">
					<input type="radio"
						name="sufm-space-method"
						value="points" />
					<span><?php esc_html_e( '积分', 'sakura-imgopt' ); ?></span>
				</label>
			<?php endif; ?>
		<?php
			$sufm_space_yuan = function_exists( 'zibpay_get_user_balance' ) ? (float) zibpay_get_user_balance( get_current_user_id() ) : 0.0;
			?>
			<span class="sufm-space-wallet sufm-space-wallet--balance">
				<?php esc_html_e( '余额', 'sakura-imgopt' ); ?>
				<strong>¥<?php echo esc_html( number_format( $sufm_space_yuan, 2 ) ); ?></strong>
			</span>
			<span class="sufm-space-wallet sufm-space-wallet--points">
				<?php esc_html_e( '积分', 'sakura-imgopt' ); ?>
				<strong><?php echo esc_html( number_format( (float) ( $data['points']['balance'] ?? 0 ), 0 ) ); ?></strong>
			</span>
		</div>

		<p class="sufm-space-dialog-summary" data-sufm-space-summary></p>
		<p class="muted-2-color em09">
			<?php esc_html_e( '购买后容量立即生效，到期后自动收回，不会删除任何已有文件。', 'sakura-imgopt' ); ?>
		</p>

		<?php $sufm_space_pts = isset( $data['points'] ) && is_array( $data['points'] ) ? $data['points'] : array(); ?>
		<?php if ( ! empty( $sufm_space_pts['enabled'] ) ) : ?>
			<?php
			$sufm_space_pts_per_gb  = isset( $sufm_space_pts['per_gb'] ) ? (int) $sufm_space_pts['per_gb'] : 0;
			$sufm_space_pts_balance = isset( $sufm_space_pts['balance'] ) ? (int) $sufm_space_pts['balance'] : 0;
			$sufm_space_pts_max     = isset( $sufm_space_pts['max_gb'] ) ? (float) $sufm_space_pts['max_gb'] : 10.0;
			$sufm_space_pts_cap     = isset( $sufm_space_pts['daily_cap_gb'] ) ? (float) $sufm_space_pts['daily_cap_gb'] : 20.0;
			$sufm_space_pts_used    = isset( $sufm_space_pts['used_gb'] ) ? (float) $sufm_space_pts['used_gb'] : 0.0;
			?>
			<div class="sufm-space-redeem"
				data-sufm-space-redeem
				data-per-gb="<?php echo esc_attr( (string) $sufm_space_pts_per_gb ); ?>"
				data-balance="<?php echo esc_attr( (string) $sufm_space_pts_balance ); ?>"
				data-max-gb="<?php echo esc_attr( (string) $sufm_space_pts_max ); ?>"
				data-cap-gb="<?php echo esc_attr( (string) $sufm_space_pts_cap ); ?>"
				data-used-gb="<?php echo esc_attr( (string) $sufm_space_pts_used ); ?>"
				hidden>

				<?php // Written by JS from the selected plan card; the server never reads it. ?>
				<input type="hidden" data-sufm-space-redeem-gb value="" />

				<span class="sufm-space-redeem-label"><?php esc_html_e( '容量', 'sakura-imgopt' ); ?></span>
				<span class="sufm-space-redeem-value" data-sufm-space-redeem-gb-view>&mdash;</span>

				<span class="sufm-space-redeem-label"><?php esc_html_e( '所需积分', 'sakura-imgopt' ); ?></span>
				<span class="sufm-space-redeem-value" data-sufm-space-redeem-cost-view>&mdash;</span>

				<span class="sufm-space-redeem-label"><?php esc_html_e( '有效期', 'sakura-imgopt' ); ?></span>
				<span class="sufm-space-redeem-value" data-sufm-space-redeem-days-view>&mdash;</span>

				<p class="sufm-space-redeem-preview muted-2-color em09" data-sufm-space-redeem-preview aria-live="polite">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: points balance, 2: redeemed GB today, 3: daily cap GB. */
							__( '余额 %1$d · 今日已兑 %2$s/%3$s GB', 'sakura-imgopt' ),
							$sufm_space_pts_balance,
							number_format( $sufm_space_pts_used, 2 ),
							number_format( $sufm_space_pts_cap, 2 )
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p class="sufm-space-error" data-sufm-space-error role="alert" aria-live="polite"></p>
		<p class="sufm-space-result" data-sufm-space-result role="status" aria-live="polite"></p>

		<div class="sufm-space-qr" data-sufm-space-qr hidden>
			<img alt="<?php esc_attr_e( '支付宝二维码', 'sakura-imgopt' ); ?>"
				data-sufm-space-qr-img
				width="180"
				height="180" />
			<p class="muted-2-color em09" data-sufm-space-qr-hint><?php esc_html_e( '请用支付宝扫描二维码完成支付', 'sakura-imgopt' ); ?></p>
			<a class="but but-sm" data-sufm-space-qr-open href="#" target="_blank" rel="noopener" hidden><?php esc_html_e( '在支付宝中打开', 'sakura-imgopt' ); ?></a>
		</div>
	</div>

	<div class="sufm-space-dialog-foot">
		<button type="button" class="but but-sm" data-sufm-space-close><?php esc_html_e( '取消', 'sakura-imgopt' ); ?></button>
		<button type="button" class="but c-blue but-sm" id="sufm-space-confirm"><?php esc_html_e( '确认支付', 'sakura-imgopt' ); ?></button>
		<button type="button" class="but but-sm sufm-space-redeem-go" data-sufm-space-redeem-go hidden><?php esc_html_e( '用积分兑换', 'sakura-imgopt' ); ?></button>
	</div>
</dialog>

<?php // JSON copy: readable (and parseable) even when this markup is injected by AJAX. ?>
<script type="application/json" data-sufm-space-json><?php echo wp_json_encode( $data, $sufm_space_json_flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
<script type="text/javascript">
	window.sufmSpacePanel = <?php echo wp_json_encode( $data, $sufm_space_json_flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> || {};
</script>
