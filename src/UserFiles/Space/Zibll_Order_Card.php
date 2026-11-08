<?php
/**
 * Theme bridge: renders 文件空间扩容 orders in the Zibll order centre.
 *
 * The theme maps order types 1-11 to its own labels and icons; anything else
 * falls back to “付费内容” plus a generic document icon, which is what a
 * storage top-up looked like before this class existed. We cannot change the
 * theme's map, but the theme exposes two filters on the user order centre:
 *
 *   - `user_order_list_card`      ($html, $order_type, $order)
 *   - `zibpay_order_title`        ($title, $order)
 *
 * and reads `order_data.product_title` / `order_data.product_image` for the
 * admin list, so the same metadata serves both surfaces.
 *
 * @package Sakura\ImageOptimizer
 */

namespace Nboke\UserFiles\Space;

defined( 'ABSPATH' ) || exit;

/**
 * Custom card + title for the storage top-up order type.
 */
final class Zibll_Order_Card {

	/**
	 * Must match Space_Store::ORDER_TYPE.
	 */
	public const ORDER_TYPE = '21';

	/**
	 * Registers the filters. Safe to call when Zibll is not active: the theme
	 * simply never applies these hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'user_order_list_card', array( __CLASS__, 'list_card' ), 10, 3 );
		add_filter( 'zibpay_order_title', array( __CLASS__, 'order_title' ), 10, 2 );
		add_filter( 'user_order_details_modal', array( __CLASS__, 'details_modal' ), 10, 3 );
	}

	/**
	 * Thumbnail URL with the scheme the current request is served with.
	 *
	 * @return string
	 */
	private static function image_of( array $data ): string {
		$image = isset( $data['product_image'] ) ? trim( (string) $data['product_image'] ) : '';

		if ( '' !== $image ) {
			return esc_url_raw( $image );
		}

		return self::image_url();
	}

	/**
	 * Bundled icon used when a package has no custom order icon.
	 *
	 * @return string
	 */
	private static function image_url(): string {
		return set_url_scheme( SAKURA_TOOLS_URL . 'assets/space-package.svg', is_ssl() ? 'https' : 'http' );
	}

	/**
	 * Replaces the generic “付费内容” row inside the details modal of our orders.
	 *
	 * @param string $html       Default modal HTML.
	 * @param string $order_type Order type slug.
	 * @param mixed  $order      Order row.
	 * @return string
	 */
	public static function details_modal( $html, $order_type, $order ) {
		if ( self::ORDER_TYPE !== (string) $order_type || ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$generic = function_exists( 'zibpay_get_pay_type_name' )
			? (string) zibpay_get_pay_type_name( self::ORDER_TYPE )
			: '';

		if ( '' === $generic ) {
			return $html;
		}

		return str_replace(
			$generic,
			__( '空间扩容', 'sakura-imgopt' ),
			$html
		);
	}

	/**
	 * Whether an order belongs to the storage module.
	 *
	 * @param mixed $order Order row or array.
	 * @return bool
	 */
	private static function is_ours( $order ): bool {
		$order = (array) $order;

		return self::ORDER_TYPE === (string) ( $order['order_type'] ?? '' );
	}

	/**
	 * Order metadata written at creation time (product_title / bytes / days).
	 *
	 * @param mixed $order Order row.
	 * @return array<string,mixed>
	 */
	private static function order_data( $order ): array {
		$order = (array) $order;
		$id    = (int) ( $order['id'] ?? 0 );

		if ( $id > 0 && class_exists( '\zibpay' ) ) {
			$data = call_user_func( array( '\zibpay', 'get_meta' ), $id, 'order_data' );

			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return array();
	}

	/**
	 * The `other` payload written by Space_Store at creation time.
	 *
	 * Older orders (or orders created before the metadata existed) only carry
	 * this payload, so it doubles as the fallback for title, bytes and days.
	 *
	 * @param mixed $order Order row.
	 * @return array<string,mixed>
	 */
	private static function order_other( $order ): array {
		$order = (array) $order;
		$other = $order['other'] ?? array();

		if ( is_string( $other ) && '' !== $other ) {
			$other = maybe_unserialize( $other );
		}

		return is_array( $other ) ? $other : array();
	}

	/**
	 * Product name shown in the order centre.
	 *
	 * Order of preference: metadata snapshot, `other.label`, `other.plan_id`,
	 * and only then a neutral fallback. The package label is never hardcoded
	 * here, because admins rename packages and the order must keep matching
	 * the shop entry it was bought from.
	 *
	 * @param array<string,mixed> $order Order row.
	 * @param array<string,mixed> $data  Order meta.
	 * @return string
	 */
	private static function title_of( array $order, array $data ): string {
		foreach ( array( 'product_title', 'product_label' ) as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				return (string) $data[ $field ];
			}
		}

		$other = self::order_other( $order );

		if ( ! empty( $other['label'] ) ) {
			return (string) $other['label'];
		}

		$plan_id = (string) ( $other['plan_id'] ?? $data['plan_id'] ?? '' );

		if ( '' !== $plan_id ) {
			return sprintf(
				/* translators: %s: storage package id, e.g. p10. */
				__( '扩容包 %s', 'sakura-imgopt' ),
				$plan_id
			);
		}

		return __( '空间扩容', 'sakura-imgopt' );
	}

	/**
	 * Human title used by the theme's order-title helper.
	 *
	 * @param string $title Existing title.
	 * @param mixed  $order Order row.
	 * @return string
	 */
	public static function order_title( $title, $order ) {
		if ( ! self::is_ours( $order ) ) {
			return $title;
		}

		return self::title_of( (array) $order, self::order_data( $order ) );
	}

	/**
	 * Replaces the default card for our order type only.
	 *
	 * @param string $html       Card HTML provided by the theme.
	 * @param string $order_type Order type slug.
	 * @param mixed  $order      Order row.
	 * @return string
	 */
	public static function list_card( $html, $order_type, $order ) {
		if ( self::ORDER_TYPE !== (string) $order_type || ! is_array( $order ) ) {
			return $html;
		}

		$data   = self::order_data( $order );
		$status = (string) ( $order['status'] ?? '0' );

		$title = self::title_of( $order, $data );
		$other = self::order_other( $order );

		$bytes = (int) ( $data['bytes'] ?? $other['bytes'] ?? 0 );
		$days  = (int) ( $data['days'] ?? $other['days'] ?? 0 );

		$size = $bytes > 0
			? size_format( $bytes, 0 )
			: '';

		$duration = $days > 0
			? sprintf(
				/* translators: %d: number of days the extra storage stays valid. */
				__( '%d 天有效', 'sakura-imgopt' ),
				$days
			)
			: __( '永久有效', 'sakura-imgopt' );

		$meta_line = trim( implode( ' · ', array_filter( array( $size, $duration ) ) ) );

		$status_label = self::status_label( $status );

		$is_points = isset( $order['pay_type'] ) && 'points' === (string) $order['pay_type'];

		$points = 0;

		if ( $is_points ) {
			$points = function_exists( 'zibpay_get_order_pay_points' )
				? (int) zibpay_get_order_pay_points( $order )
				: (int) ( $order['pay_price'] ?? 0 );
		}

		$amount = (float) ( $order['pay_price'] ?? 0 );

		if ( $amount <= 0 ) {
			$amount = (float) ( $order['order_price'] ?? 0 );
		}

		if ( $is_points && $points > 0 ) {
			$amount_html = '<b>' . esc_html( number_format_i18n( $points ) ) . '</b><span class="pay-mark px12">' . esc_html__( '积分', 'sakura-imgopt' ) . '</span>';
		} else {
			$amount_html = $amount > 0
				? '<span class="pay-mark px12">' . ( function_exists( 'zibpay_get_pay_mark' ) ? wp_kses_post( zibpay_get_pay_mark() ) : '¥' ) . '</span><b>' . esc_html( number_format_i18n( $amount, 2 ) ) . '</b>'
				: '';
		}

		$buttons = self::buttons( $order, $status );

		$image = self::image_of( $data );

		return '<div class="zib-widget ajax-item mb10 order-item user-order-item order-type-' . esc_attr( self::ORDER_TYPE ) . '">'
			. '<div class="order-content flex show-order-modal pointer" data-order-id="' . esc_attr( (string) ( $order['id'] ?? 0 ) ) . '">'
			. '<div class="order-thumb mr10"><img class="radius8 fit-cover" src="' . esc_url( $image ) . '" alt="' . esc_attr__( '文件空间扩容', 'sakura-imgopt' ) . '"></div>'
			. '<div class="flex1 flex jsb xx">'
			. '<div class="flex1 flex jsb">'
			. '<div class="flex1 mr10">'
			. '<div class="order-title">' . esc_html( $title ) . '</div>'
			. '<span class="pay-tag badg badg-sm mr6">' . esc_html__( '空间扩容', 'sakura-imgopt' ) . '</span>'
			. ( '' !== $meta_line ? '<span class="muted-color em09">' . esc_html( $meta_line ) . '</span>' : '' )
			. '<div class="muted-color em09 mt6">' . esc_html( (string) ( $order['create_time'] ?? '' ) ) . '</div>'
			. '</div>'
			. '<div class="flex xx ab">'
			. '<div class="mb10">' . wp_kses_post( $status_label ) . '</div>'
			. '<div class="unit-price">' . wp_kses_post( $amount_html ) . '</div>'
			. '</div>'
			. '</div>'
			. '</div>'
			. '</div>'
			. ( '' !== $buttons ? '<div class="order-footer mt10"><div class="text-right mt10">' . wp_kses_post( $buttons ) . '</div></div>' : '' )
			. '</div>';
	}

	/**
	 * Status chip in theme colours.
	 *
	 * @param string $status Order status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case '1':
				return '<span class="c-green">' . esc_html__( '已支付', 'sakura-imgopt' ) . '</span>';
			case '-1':
				return '<span class="c-red">' . esc_html__( '交易已关闭', 'sakura-imgopt' ) . '</span>';
			case '-2':
				return '<span class="muted-color">' . esc_html__( '已退款', 'sakura-imgopt' ) . '</span>';
			default:
				return '<span class="c-red">' . esc_html__( '待支付', 'sakura-imgopt' ) . '</span>';
		}
	}

	/**
	 * Action buttons: pay/close while unpaid, a jump to the file panel once paid.
	 *
	 * @param array<string,mixed> $order  Order row.
	 * @param string              $status Order status.
	 * @return string
	 */
	private static function buttons( array $order, string $status ): string {
		$out = '';

		if ( '0' === $status ) {
			if ( function_exists( 'zibpay_get_order_close_link' ) ) {
				$out .= zibpay_get_order_close_link( $order, 'but mr6', __( '关闭订单', 'sakura-imgopt' ) );
			}

			if ( function_exists( 'zibpay_get_order_pay_link' ) ) {
				$out .= zibpay_get_order_pay_link( $order, 'but c-red', __( '立即支付', 'sakura-imgopt' ) );
			}

			return $out;
		}

		if ( '1' === $status && function_exists( 'zib_get_user_center_url' ) ) {
			$out .= '<a href="' . esc_url( zib_get_user_center_url( 'files' ) ) . '" class="but c-blue">' . esc_html__( '查看容量', 'sakura-imgopt' ) . '</a>';
		}

		return $out;
	}
}
