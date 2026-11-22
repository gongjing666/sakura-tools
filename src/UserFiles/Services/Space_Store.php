<?php
/**
 * Space expansion store: plans, orders, idempotent grants.
 *
 * Wraps the theme payment plumbing without touching it:
 * ZibPay::add_order() -> zibpay_initiate_pay() -> payment_order_success.
 * Idempotency is enforced by UNIQUE(grant_key) = "order:{order_num}".
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Space_Settings;
use WP_Error;

/**
 * Plans / purchase / grants.
 */
final class Space_Store {

	/**
	 * ZibPay order type reserved for space expansions (theme only uses 1..11).
	 */
	public const ORDER_TYPE = '21';

	/**
	 * Product id prefix, also used to recognise our own orders.
	 */
	public const PRODUCT_PREFIX = 'sufm_space_';

	/**
	 * Option storing the automatic reconciliation state.
	 *
	 * Older versions stored a single timestamp here. reconcile_state() upgrades
	 * that scalar to an array on the next run.
	 */
	public const RECONCILE_OPTION = 'sufm_space_reconcile_last';

	/**
	 * Delays after consecutive automatic reconciliation failures.
	 *
	 * 15m, 30m, 1h, 2h, 4h, then every 6h. A success resets the series and the
	 * next run moves back to one day.
	 */
	private const RECONCILE_BACKOFF = array( 900, 1800, 3600, 7200, 14400, 21600 );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Optional events service (may be an instance or null).
	 *
	 * @var object|null
	 */
	private $events = null;

	/**
	 * Request cached payment methods.
	 *
	 * @var array<string,array<string,string>>|null
	 */
	private ?array $methods = null;

	/**
	 * Shared per-request answer for "does the grants table exist?".
	 *
	 * Two places ask this while one screen renders (the rules engine derives
	 * the quota, the store lists the grants), and each probe is a `SHOW TABLES`
	 * round trip Query Monitor reports as a duplicate. The answer lives on the
	 * class rather than the instance so every caller — and every store built by
	 * {@see self::instance()} — sees the same answer.
	 *
	 * @var bool|null
	 */
	private static ?bool $grants_table = null;

	/**
	 * Constructor.
	 *
	 * @param object|null $events Optional events service.
	 */
	public function __construct( $events = null ) {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		$this->events = $events;
	}

	/**
	 * Shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/* --------------------------------------------------------------------- */
	/* Plans                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Sellable plans (active only), enriched for the front end.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function plans(): array {
		$plans = array();

		foreach ( Space_Settings::packages() as $package ) {
			if ( empty( $package['active'] ) ) {
				continue;
			}

			$plans[] = $this->format_plan( $package );
		}

		return $plans;
	}

	/**
	 * Formats one plan definition.
	 *
	 * @param array<string,mixed> $package Raw plan.
	 * @return array<string,mixed>
	 */
	public function format_plan( array $package ): array {
		$id    = isset( $package['id'] ) ? (string) $package['id'] : '';
		$bytes = isset( $package['bytes'] ) ? (int) $package['bytes'] : 0;
		$price = isset( $package['price'] ) ? (float) $package['price'] : 0.0;
		$days  = isset( $package['days'] ) ? (int) $package['days'] : 0;
		$label = isset( $package['label'] ) && '' !== (string) $package['label']
			? (string) $package['label']
			: $id;

		return array(
			'id'          => $id,
			'label'       => $label,
			'image'       => self::order_icon_url(),
			'bytes'       => $bytes,
			'bytes_human' => self::bytes_human( $bytes ),
			'price'       => $price,
			'price_human' => self::money( $price ),
			'days'        => $days,
			'days_label'  => $this->days_label( $days ),
			'product_id'  => self::PRODUCT_PREFIX . $id,
		);
	}

	/**
	 * Finds an active plan by id.
	 *
	 * @param string $plan_id Plan id.
	 * @return array<string,mixed>|null
	 */
	public function plan( string $plan_id ): ?array {
		$plan_id = sanitize_key( $plan_id );

		if ( '' === $plan_id ) {
			return null;
		}

		foreach ( Space_Settings::packages() as $package ) {
			if ( ! isset( $package['id'] ) || $plan_id !== (string) $package['id'] ) {
				continue;
			}

			// A delisted package must not be purchasable through a stale id
			// (audit B11): treat it exactly like a missing plan.
			if ( empty( $package['active'] ) ) {
				return null;
			}

			return $this->format_plan( $package );
		}

		return null;
	}

	/**
	 * Payment methods available to the current user.
	 *
	 * The theme hides the balance channel when no user is logged in, so this must
	 * only be called with a user context (REST / admin page), never from cron.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function payment_methods(): array {
		if ( null !== $this->methods ) {
			return $this->methods;
		}

		$this->methods = array();

		if ( ! function_exists( 'zibpay_get_payment_methods' ) ) {
			return $this->methods;
		}

		try {
			$raw = zibpay_get_payment_methods( (int) self::ORDER_TYPE );
		} catch ( \Throwable $e ) {
			return $this->methods;
		}

		if ( ! is_array( $raw ) ) {
			return $this->methods;
		}

		foreach ( $raw as $key => $method ) {
			$id   = is_string( $key ) ? $key : (string) $key;
			$name = '';

			if ( is_array( $method ) && isset( $method['name'] ) ) {
				$name = (string) $method['name'];
			} elseif ( is_object( $method ) && isset( $method->name ) ) {
				$name = (string) $method->name;
			}

			if ( '' === $id ) {
				continue;
			}

			$this->methods[ $id ] = array( 'name' => '' !== $name ? $name : $id );
		}

		$this->methods = $this->filter_payment_methods( $this->methods );

		return $this->methods;
	}

	/**
	 * Keeps only the payment channels this plugin is allowed to sell through.
	 *
	 * The operator ticks channels in the space settings card; that stored list is
	 * the source of truth. Sites that can narrow it further (for example a
	 * staging site that must hide a channel) can still use the
	 * sufm_space_allowed_payment_methods filter, but it can never re-enable a
	 * channel that was left unticked.
	 *
	 * @param array<string,array<string,string>> $methods Methods keyed by id.
	 * @return array<string,array<string,string>>
	 */
	public function filter_payment_methods( array $methods ): array {
		$configured = Space_Settings::allowed_methods();
		$filtered   = apply_filters( 'sufm_space_allowed_payment_methods', $configured );

		if ( ! is_array( $filtered ) ) {
			$filtered = $configured;
		}

		// The stored allow-list is the single source of truth; the filter can
		// only narrow it, never add a channel the operator did not tick.
		$base = array();

		foreach ( $configured as $key ) {
			if ( is_string( $key ) ) {
				$key = sanitize_key( $key );

				if ( '' !== $key ) {
					$base[ $key ] = true;
				}
			}
		}

		if ( array() === $base ) {
			return array();
		}

		$keys = array();

		foreach ( $filtered as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = sanitize_key( $key );

			if ( isset( $base[ $key ] ) ) {
				$keys[ $key ] = true;
			}
		}

		if ( array() === $keys ) {
			return array();
		}

		return array_intersect_key( $methods, $keys );
	}

	/* --------------------------------------------------------------------- */
	/* Purchase                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Creates a theme order and asks the theme to pay it.
	 *
	 * The order row is never deleted on failure: it is the audit trail, and the
	 * failure is recorded through sufm_events / the sufm_space_event action.
	 *
	 * @param int    $user_id User id.
	 * @param string $plan_id Plan id (sanitize_key safe).
	 * @param string $method  Payment method key.
	 * @return array<string,mixed>|WP_Error
	 */
	public function purchase( int $user_id, string $plan_id, string $method ) {
		if ( ! Space_Settings::enabled() ) {
			return new WP_Error(
				'sufm_space_disabled',
				__( '扩容功能尚未开启。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->has_table() ) {
			return new WP_Error(
				'sufm_space_not_ready',
				__( '扩容数据表尚未就绪，请管理员先在后台打开一次「子比专属」页。', 'sakura-imgopt' ),
				array( 'status' => 503 )
			);
		}

		if ( 0 >= $user_id ) {
			return new WP_Error(
				'sufm_space_login_required',
				__( '请先登录。', 'sakura-imgopt' ),
				array( 'status' => 401 )
			);
		}

		$plan = $this->plan( $plan_id );

		if ( null === $plan ) {
			return new WP_Error(
				'sufm_space_plan_missing',
				__( '该套餐不存在或已下架。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$method  = sanitize_key( $method );
		$methods = $this->payment_methods();

		if ( ! isset( $methods[ $method ] ) ) {
			return new WP_Error(
				'sufm_space_method_missing',
				__( '该支付方式当前不可用。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'zibpay_initiate_pay' ) || ! class_exists( 'ZibPay' ) ) {
			return new WP_Error(
				'sufm_space_theme_missing',
				__( '主题支付组件不可用。', 'sakura-imgopt' ),
				array( 'status' => 503 )
			);
		}

		$order_num = '';
		$price     = (float) $plan['price'];
		$other     = array(
			'plan_id' => (string) $plan['id'],
			'label'   => (string) $plan['label'],
			'bytes'   => (int) $plan['bytes'],
			'days'    => (int) $plan['days'],
		);

		/*
		 * Zibll reads order_data.product_title / product_image for the admin
		 * order list and the user order centre; without them a storage top-up
		 * shows up as the generic “付费内容” row with a document icon.
		 */
		/*
		 * The order title is the package label exactly as the admin typed it.
		 * Packages can be renamed or given odd labels, so never hardcode a
		 * product name here: the shop, the order list and the receipt must all
		 * show the same string.
		 */
		$product_label = trim( (string) $plan['label'] );

		if ( '' === $product_label ) {
			$product_label = sprintf(
				/* translators: %s: storage package id, e.g. p10. */
				__( '扩容包 %s', 'sakura-imgopt' ),
				(string) $plan['id']
			);
		}

		$meta = array(
			'order_data' => array(
				'product_title' => $product_label,
				'product_label' => $product_label,
				'product_image' => self::order_icon_url(),
				'plan_id'       => (string) $plan['id'],
				'bytes'         => (int) $plan['bytes'],
				'days'          => (int) $plan['days'],
			),
		);

		$order_id = 0;

		$created = null;

		try {
			// ZibPay always generates its own order_num; the value passed in is ignored.
			$created = \ZibPay::add_order(
				array(
					'user_id'     => $user_id,
					'product_id'  => (string) $plan['product_id'],
					'order_price' => $price,
					'order_type'  => self::ORDER_TYPE,
					'status'      => '0',
					'other'       => $other,
					'meta'        => $meta,
				)
			);
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.purchase.failed',
				array(
					'owner_id'  => $user_id,
					'result'    => 'error',
					'order_num' => $order_num,
					'detail'    => array( 'stage' => 'add_order', 'message' => $e->getMessage() ),
				)
			);

			return new WP_Error(
				'sufm_space_order_failed',
				__( '创建订单失败，请稍后重试。', 'sakura-imgopt' ),
				array( 'status' => 500 )
			);
		}

		$order_num = is_array( $created ) ? (string) ( $created['order_num'] ?? '' ) : '';
		$order_id  = is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : 0;

		if ( '' === $order_num ) {
			$this->log_event(
				'space.purchase.failed',
				array(
					'owner_id'  => $user_id,
					'result'    => 'error',
					'order_num' => '',
					'detail'    => array( 'stage' => 'add_order', 'message' => 'empty order_num' ),
				)
			);

			return new WP_Error(
				'sufm_space_order_failed',
				__( '创建订单失败，请稍后重试。', 'sakura-imgopt' ),
				array( 'status' => 500 )
			);
		}

		$pay = array();

		try {
			$pay = zibpay_initiate_pay(
				array(
					'user_id'        => $user_id,
					'payment_method' => $method,
					'order_num'      => $order_num,
					'order_price'    => $price,
					'order_name'     => (string) $plan['label'],
				)
			);
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.purchase.failed',
				array(
					'owner_id'  => $user_id,
					'result'    => 'error',
					'order_num' => $order_num,
					'detail'    => array( 'stage' => 'initiate_pay', 'message' => $e->getMessage() ),
				)
			);

			return new WP_Error(
				'sufm_space_pay_failed',
				__( '发起支付失败，请稍后重试。', 'sakura-imgopt' ),
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $pay ) ) {
			$pay = array();
		}

		// Alipay / channel level failure: surface the theme message instead of
		// returning a hollow payok=false payload (the UI cannot explain that).
		if ( ! empty( $pay['error'] ) ) {
			$message = isset( $pay['msg'] ) && '' !== (string) $pay['msg']
				? (string) $pay['msg']
				: __( '发起支付失败，请稍后重试。', 'sakura-imgopt' );

			$this->log_event(
				'space.purchase.failed',
				array(
					'owner_id'  => $user_id,
					'result'    => 'error',
					'order_num' => $order_num,
					'detail'    => array(
						'stage'   => 'initiate_pay',
						'channel' => $method,
						'message' => $message,
					),
				)
			);

			return new WP_Error( 'sufm_space_pay_failed', $message, array( 'status' => 400 ) );
		}

		$payok = ! empty( $pay['payok'] );

		return array(
			'order_id'       => $order_id,
			'order_num'      => $order_num,
			'product_id'     => (string) $plan['product_id'],
			'plan_id'        => (string) $plan['id'],
			'label'          => (string) $plan['label'],
			'bytes'          => (int) $plan['bytes'],
			'bytes_human'    => (string) $plan['bytes_human'],
			'days'           => (int) $plan['days'],
			'price'          => $price,
			'price_human'    => (string) $plan['price_human'],
			'payment_method' => $method,
			'payok'          => $payok,
			'qr_code'        => isset( $pay['qr_code'] ) ? (string) $pay['qr_code'] : '',
			'url_qrcode'     => isset( $pay['url_qrcode'] ) ? (string) $pay['url_qrcode'] : '',
			// url_qrcode is the theme generated data URI (data:image/jpeg;base64,…),
			// qr_code is the qr.alipay.com payload used for the "open in app" link.
			'qr_image'       => isset( $pay['url_qrcode'] ) ? (string) $pay['url_qrcode'] : '',
			'check_sdk'      => isset( $pay['check_sdk'] ) ? (string) $pay['check_sdk'] : '',
			'expires_in'     => 300,
		);
	}

	/**
	 * Order status for the polling loop.
	 *
	 * Someone else's order number is reported exactly like a missing one, so the
	 * endpoint cannot be used to probe order existence.
	 *
	 * @param int    $user_id   Current user id.
	 * @param string $order_num Order number.
	 * @return array<string,mixed>|WP_Error
	 */
	public function order_status( int $user_id, string $order_num ) {
		$order_num = trim( $order_num );

		if ( 0 >= $user_id || '' === $order_num || ! $this->has_table() ) {
			return new WP_Error( 'sufm_order_not_found', __( '订单不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new WP_Error( 'sufm_order_not_found', __( '订单不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$orders = $wpdb->prefix . 'zibpay_order';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_num, status, user_id FROM {$orders} WHERE order_num = %s AND user_id = %d AND order_type = %s LIMIT 1",
				$order_num,
				$user_id,
				self::ORDER_TYPE
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'sufm_order_not_found', __( '订单不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$status = isset( $row['status'] ) ? (string) $row['status'] : '0';
		$state  = $this->status_state( $status );

		$out = array(
			'order_num' => isset( $row['order_num'] ) ? (string) $row['order_num'] : $order_num,
			'status'    => $status,
			'paid'      => ( '1' === $status ),
			'state'     => $state,
			'label'     => $this->status_label( $state ),
		);

		// Attach the granted size once the grant landed (paid + already granted).
		if ( true === $out['paid'] ) {
			$grant = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT bytes FROM {$this->table()} WHERE order_num = %s AND state = 'active' LIMIT 1",
					$out['order_num']
				),
				ARRAY_A
			);

			if ( is_array( $grant ) && isset( $grant['bytes'] ) ) {
				$out['bytes_human'] = self::bytes_human( (int) $grant['bytes'] );
			}
		}

		return $out;
	}

	/**
	 * Maps a raw zibpay status code to a machine state.
	 *
	 * @param string $status 0 / 1 / -1 / -2.
	 * @return string pending|paid|closed|refunded
	 */
	private function status_state( string $status ): string {
		switch ( $status ) {
			case '1':
				return 'paid';
			case '-1':
				return 'closed';
			case '-2':
				return 'refunded';
			default:
				return 'pending';
		}
	}

	/**
	 * Translated label for an order state.
	 *
	 * @param string $state Machine state.
	 * @return string
	 */
	private function status_label( string $state ): string {
		switch ( $state ) {
			case 'paid':
				return __( '已支付', 'sakura-imgopt' );
			case 'closed':
				return __( '已关闭', 'sakura-imgopt' );
			case 'refunded':
				return __( '已退款', 'sakura-imgopt' );
			default:
				return __( '待支付', 'sakura-imgopt' );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Grants                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Grants of one user, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function my_grants( int $user_id, int $limit = 20 ): array {
		if ( 0 >= $user_id || ! $this->has_table() ) {
			return array();
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, bytes, label, source, channel, state, expires_at, created_at FROM {$table} WHERE owner_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'format_grant' ), $rows ) : array();
	}

	/**
	 * Admin listing with filters and pagination.
	 *
	 * @param array<string,mixed> $args page / per / state / user_id.
	 * @return array<string,mixed>
	 */
	public function admin_grants( array $args ): array {
		$page    = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$per     = isset( $args['per'] ) ? max( 1, min( 100, (int) $args['per'] ) ) : 20;
		$state   = isset( $args['state'] ) ? sanitize_key( (string) $args['state'] ) : '';
		$user_id = isset( $args['user_id'] ) ? max( 0, (int) $args['user_id'] ) : 0;

		$empty = array(
			'items' => array(),
			'total' => 0,
			'page'  => $page,
			'per'   => $per,
			'ready' => $this->has_table(),
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return $empty;
		}

		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $state ) {
			$where[]  = 'state = %s';
			$params[] = $state;
		}

		if ( 0 < $user_id ) {
			$where[]  = 'owner_id = %d';
			$params[] = $user_id;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = 0;

		if ( array() === $params ) {
			$total = (int) $wpdb->get_var( $count_sql );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		}

		$list_sql  = "SELECT id, owner_id, bytes, label, source, channel, state, expires_at, created_at, order_num, price, points FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_args = array_merge( $params, array( $per, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );

		$items = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->format_grant( $row );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'per'   => $per,
			'ready' => true,
		);
	}

	/**
	 * Normalises a database row for REST / views.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	public function format_grant( array $row ): array {
		$bytes     = isset( $row['bytes'] ) ? (int) $row['bytes'] : 0;
		$expires   = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '';
		$created   = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		$state     = isset( $row['state'] ) ? (string) $row['state'] : '';
		$price     = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
		$points    = isset( $row['points'] ) ? (int) $row['points'] : 0;

		// Validity is computed on the raw UTC column: the display value below is
		// localised and can no longer be compared with the server clock.
		$expires_ts = '' === $expires ? 0 : (int) strtotime( $expires . ' UTC' );
		$remaining  = $expires_ts > 0 ? $expires_ts - time() : 0;

		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'owner_id'    => isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0,
			'bytes'       => $bytes,
			'bytes_human' => self::bytes_human( $bytes ),
			// Labels are free text from a package name; strip markup before the
			// value reaches any consumer (REST JSON, admin table, future exporters).
			'label'       => isset( $row['label'] ) ? wp_strip_all_tags( (string) $row['label'] ) : '',
			'source'      => isset( $row['source'] ) ? (string) $row['source'] : '',
			'channel'     => isset( $row['channel'] ) ? (string) $row['channel'] : '',
			'state'       => $state,
			'state_label' => $this->state_label( $state ),
			'order_num'   => isset( $row['order_num'] ) ? (string) $row['order_num'] : '',
			'price'       => $price,
			'price_human' => $points > 0 ? sprintf( '%d', $points ) : self::money( $price ),
			'points'      => $points,
			'expires_at'  => '' === $expires ? '' : self::local_time( $expires ),
			'created_at'  => '' === $created ? '' : self::local_time( $created ),
			'permanent'   => '' === $expires,
			'expires_ts'  => $expires_ts,
			// Whole days left, rounded up so "1 hour left" still reads as one day.
			'days_left'   => $expires_ts > 0 ? (int) max( 0, ceil( $remaining / DAY_IN_SECONDS ) ) : null,
			'expired'     => $expires_ts > 0 && $remaining <= 0,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Payment hook (priority 12)                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * payment_order_success handler.
	 *
	 * @param mixed $order Theme order row (object).
	 * @return void
	 */
	public function on_payment_success( $order ): void {
		try {
			$this->grant_from_order( $order );
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.purchase.failed',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'stage' => 'grant', 'message' => $e->getMessage() ),
				)
			);
		}
	}

	/**
	 * Maps a normalised order to the ledger columns stored on a grant row.
	 *
	 * The legacy implementation wrote 'points','points' and zero order fields for
	 * every channel. That made a cash balance order look like a points
	 * redemption and consumed the points daily allowance. The order row is the
	 * source of truth: only pay_type=points is a points grant.
	 *
	 * @param array<string,mixed> $data Output of normalise_order().
	 * @return array<string,mixed>
	 */
	public function grant_ledger_fields( array $data ): array {
		$channel = isset( $data['channel'] ) ? sanitize_key( (string) $data['channel'] ) : '';

		if ( strlen( $channel ) > 24 ) {
			$channel = substr( $channel, 0, 24 );
		}

		$source = 'points' === $channel ? 'points' : 'purchase';
		$points = isset( $data['points'] ) ? max( 0, (int) $data['points'] ) : 0;
		$price  = isset( $data['price'] ) ? max( 0.0, (float) $data['price'] ) : 0.0;

		if ( 'points' === $source ) {
			if ( 0 >= $points && 0.0 < $price ) {
				$points = (int) round( $price );
			}

			$price = 0.0;
		}

		return array(
			'source'    => $source,
			'channel'   => $channel,
			'order_id'  => isset( $data['order_id'] ) ? max( 0, (int) $data['order_id'] ) : 0,
			'order_num' => isset( $data['order_num'] ) ? (string) $data['order_num'] : '',
			'price'     => $price,
			'points'    => $points,
		);
	}

	/**
	 * Idempotent grant creation from a paid order.
	 *
	 * @param mixed $order Theme order row (object) or array.
	 * @return bool True when a new grant row was written.
	 */
	public function grant_from_order( $order ): bool {
		$data = $this->normalise_order( $order );

		if ( null === $data ) {
			return false;
		}

		$product_id = $data['product_id'];

		if ( 0 !== strpos( $product_id, self::PRODUCT_PREFIX ) ) {
			return false;
		}

		if ( '' === $data['order_num'] ) {
			return false;
		}

		if ( ! $this->has_table() ) {
			return false;
		}

		$bytes = $data['bytes'];
		$label = $data['label'];
		$days  = $data['days'];

		// Fallback: rebuild the plan from our own product id.
		if ( 0 >= $bytes ) {
			$plan_id = substr( $product_id, strlen( self::PRODUCT_PREFIX ) );
			$plan    = $this->plan( $plan_id );

			if ( null !== $plan ) {
				$bytes = (int) $plan['bytes'];
				$label = '' !== $label ? $label : (string) $plan['label'];
				$days  = (int) $plan['days'];
			}
		}

		if ( 0 >= $bytes ) {
			return false;
		}

		$user_id    = $data['user_id'];
		$grant_key  = 'order:' . $data['order_num'];
		$expires_at = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * 86400 ) ) : null;
		$now        = Space_Rules::now();
		$ledger     = $this->grant_ledger_fields( $data );
		$meta       = wp_json_encode(
			array(
				'plan_id'   => $data['plan_id'],
				'order_num' => $data['order_num'],
				'order_id'  => $data['order_id'],
				'channel'   => $data['channel'],
				'source'    => $ledger['source'],
				'points'    => $ledger['points'],
			)
		);

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table = $this->table();

		$columns = '(owner_id, grant_key, source, channel, bytes, label, expires_at, state, order_id, order_num, price, points, attempts, last_error, meta, created_at, updated_at)';

		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} {$columns} VALUES (%d, %s, %s, %s, %d, %s, %s, 'active', %d, %s, %f, %d, 0, '', %s, %s, %s)",
				$user_id,
				$grant_key,
				$ledger['source'],
				$ledger['channel'],
				$bytes,
				$label,
				$expires_at,
				$ledger['order_id'],
				$ledger['order_num'],
				$ledger['price'],
				$ledger['points'],
				$meta,
				$now,
				$now
			)
		);

		if ( 1 !== $inserted ) {
			// Already granted (or write failed); either way nothing new to add.
			return false;
		}

		Space_Rules::flush( $user_id );

		$this->log_event(
			'space.grant.created',
			array(
				'owner_id'  => $user_id,
				'result'    => 'ok',
				'order_num' => $data['order_num'],
				'detail'    => array(
					'bytes'   => $bytes,
					'label'   => $label,
					'expires' => null === $expires_at ? 'permanent' : $expires_at,
					'channel' => $data['channel'],
				),
			)
		);

		return true;
	}

	/**
	 * Extracts the fields we need from a theme order row.
	 *
	 * @param mixed $order Theme order row.
	 * @return array<string,mixed>|null
	 */
	private function normalise_order( $order ): ?array {
		$row = array();

		if ( is_object( $order ) ) {
			$row = get_object_vars( $order );
		} elseif ( is_array( $order ) ) {
			$row = $order;
		} else {
			return null;
		}

		$type = isset( $row['order_type'] ) ? (string) $row['order_type'] : '';

		if ( self::ORDER_TYPE !== $type ) {
			return null;
		}

		$other = isset( $row['other'] ) ? $this->decode_other( $row['other'] ) : array();

		$product_id = isset( $row['product_id'] ) ? (string) $row['product_id'] : '';
		$order_num  = isset( $row['order_num'] ) ? (string) $row['order_num'] : '';
		$pay_detail = isset( $row['pay_detail'] ) ? $this->decode_other( $row['pay_detail'] ) : array();
		$points     = 0;

		if ( isset( $other['points'] ) ) {
			$points = (int) $other['points'];
		} elseif ( isset( $pay_detail['points'] ) ) {
			$points = (int) $pay_detail['points'];
		}

		return array(
			'order_id'   => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'order_num'  => $order_num,
			'user_id'    => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'product_id' => $product_id,
			'price'      => isset( $row['order_price'] ) ? (float) $row['order_price'] : 0.0,
			'channel'    => isset( $row['pay_type'] ) ? (string) $row['pay_type'] : '',
			'plan_id'    => isset( $other['plan_id'] ) ? (string) $other['plan_id'] : '',
			'label'      => isset( $other['label'] ) ? (string) $other['label'] : '',
			'bytes'      => isset( $other['bytes'] ) ? (int) $other['bytes'] : 0,
			'days'       => isset( $other['days'] ) ? (int) $other['days'] : 0,
			'points'     => $points,
			'pay_detail' => $pay_detail,
		);
	}

	/**
	 * Decodes the theme `other` column (may be JSON, serialized or array).
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string,mixed>
	 */
	private function decode_other( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( function_exists( 'maybe_unserialize' ) ) {
			$decoded = maybe_unserialize( $raw );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Request cached grants table existence.
	 *
	 * @return bool
	 */
	public function has_table(): bool {
		return self::grants_table_exists();
	}

	/**
	 * Whether the grants table exists, memoised for the whole request.
	 *
	 * Query Monitor flags a second identical `SHOW TABLES` on the same request
	 * as a duplicate query, and the space panel and the quota derivation both
	 * ask while one screen renders — so the probe is answered once here.
	 *
	 * @return bool
	 */
	public static function grants_table_exists(): bool {
		if ( null !== self::$grants_table ) {
			return self::$grants_table;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return false;
		}

		$table  = (string) $wpdb->prefix . 'sufm_space_grants';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		self::$grants_table = ( $table === $exists );

		return self::$grants_table;
	}

	/**
	 * Drop the memoised grants-table answer.
	 *
	 * The schema installer can create the table inside a request (activation,
	 * upgrade guard), so a probe that ran before it must not outlive it.
	 */
	public static function forget_grants_table(): void {
		self::$grants_table = null;
	}

	/**
	 * Grants table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		return $prefix . 'sufm_space_grants';
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Human readable bytes.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function bytes_human( int $bytes ): string {
		if ( function_exists( 'size_format' ) ) {
			return (string) size_format( $bytes );
		}

		if ( $bytes >= 1073741824 ) {
			return round( $bytes / 1073741824, 1 ) . ' GB';
		}

		return round( $bytes / 1048576, 1 ) . ' MB';
	}

	/**
	 * Money formatting without a currency symbol assumption.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( float $amount ): string {
		$decimals = ( $amount === floor( $amount ) ) ? 0 : 2;
		$number   = function_exists( 'number_format_i18n' )
			? number_format_i18n( $amount, $decimals )
			: number_format( $amount, $decimals );

		return '¥' . $number;
	}

	/**
	 * Duration label.
	 *
	 * @param int $days Days, 0 = permanent.
	 * @return string
	 */
	private function days_label( int $days ): string {
		if ( 0 >= $days ) {
			return __( '永久', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: %s: number of days. */
			__( '%s 天', 'sakura-imgopt' ),
			function_exists( 'number_format_i18n' ) ? number_format_i18n( $days ) : (string) $days
		);
	}

	/**
	 * Translated grant state.
	 *
	 * @param string $state Raw state.
	 * @return string
	 */
	private function state_label( string $state ): string {
		switch ( $state ) {
			case 'active':
				return __( '生效中', 'sakura-imgopt' );
			case 'expired':
				return __( '已过期', 'sakura-imgopt' );
			case 'refunded':
				return __( '已退款', 'sakura-imgopt' );
			case 'revoked':
				return __( '已撤销', 'sakura-imgopt' );
			case 'pending':
				return __( '待重试', 'sakura-imgopt' );
			case 'refund_pending':
				return __( '退款处理中', 'sakura-imgopt' );
			case 'refund_failed':
				return __( '退款失败', 'sakura-imgopt' );
			default:
				return '' === $state ? __( '未知', 'sakura-imgopt' ) : $state;
		}
	}

	/**
	 * Converts a UTC database value to the site local time for display.
	 *
	 * @param string $gmt UTC datetime.
	 * @return string
	 */
	public static function local_time( string $gmt ): string {
		if ( function_exists( 'get_date_from_gmt' ) ) {
			return (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' );
		}

		return $gmt;
	}

	/**
	 * Best effort audit log: uses the events service when usable, otherwise a hook.
	 *
	 * @param string              $action Action name.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private function log_event( string $action, array $context ): void {
		$owner  = (int) ( $context['owner_id'] ?? 0 );
		$result = (string) ( $context['result'] ?? '' );
		$detail = $context['detail'] ?? array();
		$subject = (int) ( $context['order_id'] ?? 0 );

		$payload = array_merge( array( 'action' => $action ), $context );

		try {
			$events = $this->events;

			// The repository lives in the Repositories namespace; it has no
			// static instance(), so build one when the store was wired without it.
			if ( ! is_object( $events ) && class_exists( '\\Nboke\\UserFiles\\Repositories\\Events' ) ) {
				$events = new \Nboke\UserFiles\Repositories\Events();
			}

			if ( is_object( $events ) && is_callable( array( $events, 'log' ) ) ) {
				call_user_func(
					array( $events, 'log' ),
					array(
						'actor_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
						'owner_id'    => $owner,
						'action'      => $action,
						'result'      => '' !== $result ? $result : 'ok',
						'subject_id'  => $subject,
						'safe_detail' => function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $detail ) : '',
					)
				);
			}
		} catch ( \Throwable $e ) {
			$events = null;
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'sufm_space_event', $action, $payload );
		}
	}

	/**
	 * Revokes the grants of a refunded order and compensates the wallet.
	 *
	 * The hook passes zibpay_order.id, not order_num. Rows are first moved to
	 * refund_pending (atomic claim); the wallet refund is then attempted. A
	 * failure leaves the rows claimable by retry_refunds() on the next tick.
	 *
	 * @param mixed $order_id Order table primary key.
	 * @return void
	 */
	public function on_order_refunded( $order_id ): void {
		$order_id = (int) $order_id;

		if ( 0 >= $order_id || ! $this->has_table() ) {
			return;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$orders = $wpdb->prefix . 'zibpay_order';
		$order  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_num, order_type, user_id, order_price, pay_type, product_id, other, pay_detail FROM {$orders} WHERE id = %d",
				$order_id
			),
			ARRAY_A
		);

		if ( ! is_array( $order ) || ! isset( $order['order_type'] ) ) {
			return;
		}

		if ( self::ORDER_TYPE !== (string) $order['order_type'] ) {
			return;
		}

		$order_num = isset( $order['order_num'] ) ? (string) $order['order_num'] : '';

		if ( '' === $order_num ) {
			return;
		}

		$table = $this->table();
		$now   = Space_Rules::now();

		// Claim the grants first: a second hook (or the tick retry) sees zero
		// rows and never refunds the wallet twice.
		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = 'refund_pending', last_error = '', updated_at = %s WHERE order_num = %s AND state IN ('active','pending')",
				$now,
				$order_num
			)
		);

		if ( 0 >= $claimed ) {
			return;
		}

		$owners = $this->refund_owners( $order_num );
		$bytes  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(bytes),0) FROM {$table} WHERE order_num = %s AND state = 'refund_pending'",
				$order_num
			)
		);

		// The quota must drop even when the wallet refund has to be retried.
		foreach ( $owners as $owner_id ) {
			Space_Rules::flush( $owner_id );
		}

		$result = $this->settle_refund( $order, $order_num );

		if ( ! empty( $result['ok'] ) ) {
			$this->log_event(
				'space.grant.refunded',
				array(
					'owner_id'  => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
					'result'    => 'ok',
					'order_num' => $order_num,
					'detail'    => array(
						'order_id' => $order_id,
						'bytes'    => $bytes,
						'rows'     => $claimed,
						'channel'  => (string) ( $result['channel'] ?? '' ),
						'amount'   => $result['amount'] ?? 0,
						'kind'     => (string) ( $result['kind'] ?? '' ),
					),
				)
			);

			return;
		}

		$this->log_event(
			'space.grant.refund_deferred',
			array(
				'owner_id'  => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
				'result'    => 'error',
				'order_num' => $order_num,
				'detail'    => array(
					'order_id' => $order_id,
					'error'    => (string) ( $result['error'] ?? 'unknown' ),
					'attempts' => (int) ( $result['attempts'] ?? 0 ),
				),
			)
		);
	}

	/**
	 * Distinct owners of rows waiting for a refund.
	 *
	 * @param string $order_num Order number.
	 * @return int[]
	 */
	private function refund_owners( string $order_num ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$table = $this->table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT owner_id FROM {$table} WHERE order_num = %s AND state = 'refund_pending'",
				$order_num
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Attempts the wallet side of a refund for rows already in refund_pending.
	 *
	 * @param array<string,mixed> $order     Raw zibpay_order row.
	 * @param string              $order_num Order number.
	 * @return array<string,mixed>
	 */
	private function settle_refund( array $order, string $order_num ): array {
		$data = $this->normalise_order( $order );

		if ( null === $data ) {
			return $this->fail_refund( $order_num, 'order_invalid' );
		}

		$plan  = $this->wallet_refund_plan( $data );
		$ok    = true;
		$error = '';

		if ( 'balance' === $plan['kind'] && 0 < $plan['amount'] ) {
			$user_id = (int) $data['user_id'];

			if ( 0 >= $user_id ) {
				$ok    = false;
				$error = 'user_missing';
			} elseif ( $this->wallet_refund_recorded( $user_id, $order_num, 'balance' ) ) {
				// A previous attempt added the money but crashed before the
				// grant row was marked refunded: do not pay twice.
				$ok = true;
			} elseif ( ! function_exists( 'zibpay_update_user_balance' ) ) {
				$ok    = false;
				$error = 'wallet_unavailable';
			} else {
				$ok = (bool) zibpay_update_user_balance(
					$user_id,
					array(
						'order_num' => $order_num,
						'value'     => (float) $plan['amount'],
						'type'      => 'space_expand_refund',
						'desc'      => __( '空间扩容退款', 'sakura-imgopt' ),
					)
				);

				if ( ! $ok ) {
					$error = 'wallet_refund_failed';
				}
			}
		} elseif ( 'points' === $plan['kind'] && 0 < $plan['amount'] ) {
			$user_id = (int) $data['user_id'];

			if ( 0 >= $user_id ) {
				$ok    = false;
				$error = 'user_missing';
			} elseif ( $this->wallet_refund_recorded( $user_id, $order_num, 'points' ) ) {
				$ok = true;
			} elseif ( ! function_exists( 'zibpay_update_user_points' ) ) {
				$ok    = false;
				$error = 'wallet_unavailable';
			} else {
				$ok = (bool) zibpay_update_user_points(
					$user_id,
					array(
						'order_num' => $order_num,
						'value'     => (int) $plan['amount'],
						'type'      => 'space_expand_refund',
						'desc'      => __( '空间扩容退款', 'sakura-imgopt' ),
					)
				);

				if ( ! $ok ) {
					$error = 'wallet_refund_failed';
				}
			}
		}

		if ( ! $ok ) {
			$failure = $this->fail_refund( $order_num, $error );

			return array(
				'ok'       => false,
				'error'    => $error,
				'rows'     => (int) ( $failure['rows'] ?? 0 ),
				'attempts' => (int) ( $failure['attempts'] ?? 0 ),
				'channel'  => (string) $plan['channel'],
				'kind'     => (string) $plan['kind'],
				'amount'   => $plan['amount'],
			);
		}

		global $wpdb;

		$owners = $this->refund_owners( $order_num );
		$table  = $this->table();
		$now    = Space_Rules::now();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = 'refunded', last_error = '', attempts = attempts + 1, updated_at = %s WHERE order_num = %s AND state = 'refund_pending'",
				$now,
				$order_num
			)
		);

		foreach ( $owners as $owner_id ) {
			Space_Rules::flush( $owner_id );
		}

		return array(
			'ok'      => true,
			'error'   => '',
			'channel' => (string) $plan['channel'],
			'kind'    => (string) $plan['kind'],
			'amount'  => $plan['amount'],
		);
	}

	/**
	 * Records a failed refund attempt and moves rows to refund_failed at 5.
	 *
	 * @param string $order_num Order number.
	 * @param string $error     Machine error code.
	 * @return array<string,mixed>
	 */
	private function fail_refund( string $order_num, string $error ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array( 'rows' => 0, 'attempts' => 0 );
		}

		$table = $this->table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, owner_id, attempts FROM {$table} WHERE order_num = %s AND state = 'refund_pending' ORDER BY id ASC",
				$order_num
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array( 'rows' => 0, 'attempts' => 0 );
		}

		$now      = Space_Rules::now();
		$failed   = 0;
		$attempts = 0;

		foreach ( $rows as $row ) {
			$grant_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$owner_id = isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0;
			$count    = ( isset( $row['attempts'] ) ? (int) $row['attempts'] : 0 ) + 1;
			$state    = 5 <= $count ? 'refund_failed' : 'refund_pending';
			$attempts = max( $attempts, $count );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET state = %s, attempts = %d, last_error = %s, updated_at = %s WHERE id = %d AND state = 'refund_pending'",
					$state,
					$count,
					$error,
					$now,
					$grant_id
				)
			);

			if ( 'refund_failed' === $state ) {
				$failed++;
			}

			Space_Rules::flush( $owner_id );
		}

		if ( 0 < $failed ) {
			$this->log_event(
				'space.grant.refund_failed',
				array(
					'owner_id'  => 0,
					'result'    => 'error',
					'order_num' => $order_num,
					'detail'    => array(
						'error'    => $error,
						'attempts' => $attempts,
						'rows'     => $failed,
					),
				)
			);
		}

		return array( 'rows' => count( $rows ), 'attempts' => $attempts );
	}

	/**
	 * Decides which wallet (if any) a refunded order must be credited to.
	 *
	 * @param array<string,mixed> $data Normalised order.
	 * @return array<string,mixed>
	 */
	private function wallet_refund_plan( array $data ): array {
		$channel = isset( $data['channel'] ) ? sanitize_key( (string) $data['channel'] ) : '';

		if ( 'balance' === $channel ) {
			return array(
				'kind'    => 'balance',
				'channel' => $channel,
				'amount'  => max( 0.0, (float) ( $data['price'] ?? 0 ) ),
			);
		}

		if ( 'points' === $channel ) {
			$amount = isset( $data['points'] ) ? max( 0, (int) $data['points'] ) : 0;

			if ( 0 >= $amount ) {
				$amount = (int) round( max( 0.0, (float) ( $data['price'] ?? 0 ) ) );
			}

			return array( 'kind' => 'points', 'channel' => $channel, 'amount' => $amount );
		}

		// Alipay / WeChat were refunded outside the plugin; only release the grant.
		return array( 'kind' => 'external', 'channel' => $channel, 'amount' => 0 );
	}

	/**
	 * Whether the theme already logged this refund in the wallet history.
	 *
	 * The theme keeps the newest 50 records without a unique key, so this guards
	 * the crash window between the wallet write and the grant update.
	 *
	 * @param int    $user_id   User id.
	 * @param string $order_num Order number.
	 * @param string $kind      balance|points.
	 * @return bool
	 */
	private function wallet_refund_recorded( int $user_id, string $order_num, string $kind ): bool {
		if ( 0 >= $user_id || '' === $order_num || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		$key = 'balance' === $kind ? 'balance_record' : 'points_record';

		// Zibll stores these histories through its own meta router (an option
		// row), so plain get_user_meta() returns nothing on a real site.
		$records = function_exists( 'zib_get_user_meta' )
			? zib_get_user_meta( $user_id, $key, true )
			: get_user_meta( $user_id, $key, true );

		if ( ! is_array( $records ) ) {
			return false;
		}

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$record_num  = isset( $record['order_num'] ) ? (string) $record['order_num'] : '';
			$record_type = isset( $record['type'] ) ? (string) $record['type'] : '';
			$record_val  = isset( $record['value'] ) ? (float) $record['value'] : 0.0;

			if ( $record_num === $order_num && 'space_expand_refund' === $record_type && 0 < $record_val ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Re-tries wallet compensation for grants stuck in refund_pending.
	 *
	 * @param int $limit Max orders per run.
	 * @return int Orders handled.
	 */
	public function retry_refunds( int $limit = 20 ): int {
		if ( ! $this->has_table() ) {
			return 0;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$limit     = max( 1, min( 100, $limit ) );
		$table     = $this->table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - 60 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_num, MAX(order_id) AS order_id FROM {$table} WHERE state = 'refund_pending' AND attempts < 5 AND updated_at <= %s GROUP BY order_num ORDER BY MIN(id) ASC LIMIT %d",
				$threshold,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return 0;
		}

		$handled = 0;

		foreach ( $rows as $row ) {
			$order_num = isset( $row['order_num'] ) ? (string) $row['order_num'] : '';
			$order_id  = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;

			if ( '' === $order_num ) {
				continue;
			}

			// Claim the order before touching the wallet: two cron workers can
			// select the same refund_pending rows, but only one may credit it.
			$claimed = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET updated_at = %s WHERE order_num = %s AND state = 'refund_pending' AND updated_at <= %s",
					Space_Rules::now(),
					$order_num,
					$threshold
				)
			);

			if ( 0 >= $claimed ) {
				continue;
			}

			$handled++;
			$order = $this->load_order( $order_id, $order_num );

			if ( null === $order ) {
				$failure = $this->fail_refund( $order_num, 'order_missing' );

				$this->log_event(
					'space.grant.refund_deferred',
					array(
						'owner_id'  => 0,
						'result'    => 'error',
						'order_num' => $order_num,
						'detail'    => array(
							'error'    => 'order_missing',
							'attempts' => (int) ( $failure['attempts'] ?? 0 ),
						),
					)
				);

				continue;
			}

			$result = $this->settle_refund( $order, $order_num );

			if ( ! empty( $result['ok'] ) ) {
				$this->log_event(
					'space.grant.refunded',
					array(
						'owner_id'  => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
						'result'    => 'ok',
						'order_num' => $order_num,
						'detail'    => array(
							'retry'   => true,
							'channel' => (string) ( $result['channel'] ?? '' ),
							'amount'  => $result['amount'] ?? 0,
							'kind'    => (string) ( $result['kind'] ?? '' ),
						),
					)
				);

				continue;
			}

			$this->log_event(
				'space.grant.refund_deferred',
				array(
					'owner_id'  => 0,
					'result'    => 'error',
					'order_num' => $order_num,
					'detail'    => array(
						'retry'    => true,
						'error'    => (string) ( $result['error'] ?? 'unknown' ),
						'attempts' => (int) ( $result['attempts'] ?? 0 ),
					),
				)
			);
		}

		return $handled;
	}

	/**
	 * Loads a theme order by primary key, falling back to order number.
	 *
	 * @param int    $order_id  Order id (may be 0 on legacy rows).
	 * @param string $order_num Order number.
	 * @return array<string,mixed>|null
	 */
	private function load_order( int $order_id, string $order_num ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		$orders = $wpdb->prefix . 'zibpay_order';
		$order  = null;

		if ( 0 < $order_id ) {
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, order_num, order_type, user_id, order_price, pay_type, product_id, other, pay_detail FROM {$orders} WHERE id = %d",
					$order_id
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $order ) && '' !== $order_num ) {
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, order_num, order_type, user_id, order_price, pay_type, product_id, other, pay_detail FROM {$orders} WHERE order_num = %s LIMIT 1",
					$order_num
				),
				ARRAY_A
			);
		}

		return is_array( $order ) ? $order : null;
	}

	/**
	 * Paid-without-grant sweep with a success-only daily watermark.
	 *
	 * The old implementation wrote the watermark before running, so a failure
	 * (locked order table, missing grants table, partial heal) postponed the next
	 * attempt by a full day. Now every attempt claims a 15 minute lease first, a
	 * success stores "next attempt in 24 hours", and a failure backs off through
	 * RECONCILE_BACKOFF. Alerts are rate limited to one per day so a persistent
	 * error cannot flood the event log.
	 *
	 * @return void
	 */
	private function maybe_reconcile(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$state = $this->reconcile_state();
		$now   = time();

		if ( 0 < $state['next_attempt'] && $state['next_attempt'] > $now ) {
			return;
		}

		// Claim the run before touching the order table. If this process dies the
		// lease expires in 15 minutes instead of blocking the sweep for a day.
		$state['last_attempt'] = $now;
		$state['next_attempt'] = $now + 900;
		$this->save_reconcile_state( $state );

		try {
			$result = $this->reconcile( true, 100 );
		} catch ( \Throwable $e ) {
			$result = array(
				'ready'   => false,
				'error'   => $e->getMessage(),
				'missing' => 0,
				'healed'  => 0,
				'more'    => false,
			);
		}

		$ready   = ! empty( $result['ready'] );
		$missing = (int) ( $result['missing'] ?? 0 );
		$healed  = (int) ( $result['healed'] ?? 0 );
		$more    = ! empty( $result['more'] );

		if ( $ready && $missing <= $healed && ! $more ) {
			$state['last_success'] = $now;
			$state['failures']     = 0;
			$state['next_attempt'] = $now + DAY_IN_SECONDS;
			$this->save_reconcile_state( $state );

			return;
		}

		// The page was full and every row was healed: keep draining without
		// treating this as an error or waiting for the daily window.
		if ( $ready && $missing <= $healed && $more ) {
			$state['next_attempt'] = $now + 900;
			$this->save_reconcile_state( $state );

			return;
		}

		$state['failures']++;
		$state['next_attempt'] = $now + self::reconcile_delay( (int) $state['failures'] );

		$alert = 0 === $state['last_alert'] || $now - $state['last_alert'] >= DAY_IN_SECONDS;

		if ( $alert ) {
			$state['last_alert'] = $now;
		}

		$this->save_reconcile_state( $state );

		if ( ! $alert ) {
			return;
		}

		$this->log_event(
			'space.reconcile.alert',
			array(
				'owner_id' => 0,
				'result'   => $ready ? 'warn' : 'error',
				'detail'   => array(
					'missing'      => $missing,
					'healed'       => $healed,
					'more'         => $more,
					'error'        => (string) ( $result['error'] ?? '' ),
					'failures'     => (int) $state['failures'],
					'next_attempt' => (int) $state['next_attempt'],
					'auto'         => true,
				),
			)
		);
	}

	/**
	 * Reads the automatic reconciliation schedule.
	 *
	 * A scalar option written by 1.23 and earlier is treated as the last attempt
	 * due 24 hours later, so upgrading does not immediately replay a sweep.
	 *
	 * @return array{last_attempt:int,last_success:int,next_attempt:int,failures:int,last_alert:int}
	 */
	public function reconcile_state(): array {
		$state = array(
			'last_attempt' => 0,
			'last_success' => 0,
			'next_attempt' => 0,
			'failures'     => 0,
			'last_alert'   => 0,
		);

		if ( ! function_exists( 'get_option' ) ) {
			return $state;
		}

		$raw = get_option( self::RECONCILE_OPTION, 0 );

		if ( is_array( $raw ) ) {
			foreach ( $state as $key => $unused ) {
				if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
					$state[ $key ] = max( 0, (int) $raw[ $key ] );
				}
			}

			return $state;
		}

		if ( is_numeric( $raw ) && 0 < (int) $raw ) {
			$state['last_attempt'] = (int) $raw;
			$state['next_attempt'] = (int) $raw + DAY_IN_SECONDS;
		}

		return $state;
	}

	/**
	 * Persists the automatic reconciliation schedule.
	 *
	 * @param array<string,int> $state State array.
	 * @return void
	 */
	private function save_reconcile_state( array $state ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		update_option(
			self::RECONCILE_OPTION,
			array(
				'last_attempt' => max( 0, (int) ( $state['last_attempt'] ?? 0 ) ),
				'last_success' => max( 0, (int) ( $state['last_success'] ?? 0 ) ),
				'next_attempt' => max( 0, (int) ( $state['next_attempt'] ?? 0 ) ),
				'failures'     => max( 0, (int) ( $state['failures'] ?? 0 ) ),
				'last_alert'   => max( 0, (int) ( $state['last_alert'] ?? 0 ) ),
			),
			false
		);
	}

	/**
	 * Backoff for one failure count.
	 *
	 * @param int $failures Consecutive failure count (1-based).
	 * @return int Seconds.
	 */
	private static function reconcile_delay( int $failures ): int {
		$index = max( 0, min( count( self::RECONCILE_BACKOFF ) - 1, $failures - 1 ) );

		return (int) self::RECONCILE_BACKOFF[ $index ];
	}

	/**
	 * Flips active grants whose expiry passed to expired.
	 *
	 * Only the state column is flipped: granted_bytes() already filters on
	 * expires_at, so a broken cron can never inflate a quota.
	 *
	 * @return int Affected rows.
	 */
	public function sweep_expired(): int {
		if ( ! $this->has_table() ) {
			return 0;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$table = $this->table();
		$now   = Space_Rules::now();

		$where = "state = 'active' AND expires_at IS NOT NULL AND expires_at <> '0000-00-00 00:00:00' AND expires_at <= %s";

		$owners = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT owner_id FROM {$table} WHERE {$where}", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where contains a fixed %s; $now supplies it.

		if ( ! is_array( $owners ) || array() === $owners ) {
			return 0;
		}

		$rows = (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET state = 'expired', updated_at = %s WHERE {$where}", $now, $now ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one literal %s plus the fixed %s inside $where; both receive $now.
		);

		if ( 0 >= $rows ) {
			return 0;
		}

		foreach ( $owners as $owner_id ) {
			Space_Rules::flush( (int) $owner_id );
		}

		$this->log_event(
			'space.grant.expired',
			array(
				'owner_id' => 0,
				'result'   => 'ok',
				'detail'   => array( 'count' => $rows ),
			)
		);

		return $rows;
	}

	/**
	 * Re-tries pending grants whose order finally got paid.
	 *
	 * @param int $limit Max rows per run.
	 * @return int Rows handled.
	 */
	public function retry_pending( int $limit = 20 ): int {
		if ( ! $this->has_table() ) {
			return 0;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$limit = max( 1, min( 100, $limit ) );
		$table = $this->table();
		$orders = $wpdb->prefix . 'zibpay_order';

		// UTC, one minute old: never use SQL NOW() (site timezone differs).
		$threshold = gmdate( 'Y-m-d H:i:s', time() - 60 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, owner_id, order_id, order_num, bytes, attempts FROM {$table} WHERE state = 'pending' AND attempts < 5 AND updated_at <= %s ORDER BY id ASC LIMIT %d",
				$threshold,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return 0;
		}

		$handled = 0;

		foreach ( $rows as $row ) {
			$handled++;

			$grant_id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$owner_id  = isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0;
			$order_num = isset( $row['order_num'] ) ? (string) $row['order_num'] : '';
			$attempts  = ( isset( $row['attempts'] ) ? (int) $row['attempts'] : 0 ) + 1;
			$now       = Space_Rules::now();

			$status = '';

			if ( '' !== $order_num ) {
				$status = (string) $wpdb->get_var(
					$wpdb->prepare( "SELECT status FROM {$orders} WHERE order_num = %s LIMIT 1", $order_num )
				);
			}

			if ( '1' === $status ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET state = 'active', attempts = %d, last_error = '', updated_at = %s WHERE id = %d AND state = 'pending'",
						$attempts,
						$now,
						$grant_id
					)
				);

				Space_Rules::flush( $owner_id );

				$this->log_event(
					'space.grant.activated',
					array(
						'owner_id'  => $owner_id,
						'result'    => 'ok',
						'order_num' => $order_num,
						'detail'    => array( 'attempts' => $attempts ),
					)
				);

				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET attempts = %d, last_error = %s, updated_at = %s WHERE id = %d AND state = 'pending'",
					$attempts,
					'order_not_paid',
					$now,
					$grant_id
				)
			);

			// Exactly once, on the attempt that reaches the ceiling.
			if ( 5 === $attempts ) {
				$this->log_event(
					'space.grant.failed',
					array(
						'owner_id'  => $owner_id,
						'result'    => 'error',
						'order_num' => $order_num,
						'detail'    => array(
							'attempts' => $attempts,
							'reason'   => 'order_not_paid',
						),
					)
				);
			}
		}

		return $handled;
	}

	/**
	 * Finds (and optionally heals) paid orders that have no grant.
	 *
	 * @param bool $apply  When false this is a dry run.
	 * @param int  $limit  Max orders per run.
	 * @return array<string,mixed>
	 */
	public function reconcile( bool $apply = false, int $limit = 100 ): array {
		$out = array(
			'missing' => 0,
			'healed'  => 0,
			'dry_run' => ! $apply,
			'items'   => array(),
			'ready'   => $this->has_table(),
			'error'   => '',
			'more'    => false,
		);

		if ( ! $out['ready'] ) {
			return $out;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return $out;
		}

		$table  = $this->table();
		$orders = $wpdb->prefix . 'zibpay_order';
		$limit  = max( 1, min( 500, $limit ) );
		$fetch  = $limit + 1;

		// order_num is created by the theme (MariaDB 11 defaults it to
		// utf8mb4_uca1400_ai_ci) while our column uses the WordPress default
		// collation, so a bare "=" dies with "Illegal mix of collations" and
		// get_results() returns an empty array while last_error carries the
		// message. Pin the comparison collation at runtime (never hard code it:
		// it differs per host) and fall back to a binary compare when unknown.
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
		$collation       = '';

		if ( preg_match( '/COLLATE\s+([A-Za-z0-9_]+)/i', $charset_collate, $matches ) ) {
			$collation = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $matches[1] );
		}

		$join_on = '' !== $collation
			? "g.order_num = o.order_num COLLATE {$collation}"
			: 'g.order_num = BINARY o.order_num';

		// order_type / product_id / pay_type / other are needed by grant_from_order().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.order_num, o.user_id, o.order_price, o.pay_time, o.order_type, o.product_id, o.pay_type, o.other
				FROM {$orders} o
				LEFT JOIN {$table} g ON {$join_on}
				WHERE o.order_type = %s AND o.status = '1' AND g.id IS NULL
				ORDER BY o.id DESC
				LIMIT %d",
				self::ORDER_TYPE,
				$fetch
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// An empty result WITH an error is not "no missing orders": surface it,
		// otherwise the admin reads a broken query as "everything is fine".
		if ( array() === $rows && '' !== (string) $wpdb->last_error ) {
			$out['ready'] = false;
			$out['error'] = (string) $wpdb->last_error;

			$this->log_event(
				'space.reconcile.error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array(
						'collation' => $collation,
						'message'   => $out['error'],
					),
				)
			);

			return $out;
		}

		// Fetch one extra row to tell "exactly this many" from "there are more".
		// Without it a full page looks healed while orders beyond the limit wait
		// for the next daily window.
		$out['more'] = count( $rows ) > $limit;

		if ( $out['more'] ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		$out['missing'] = count( $rows );
		$healed         = 0;

		foreach ( $rows as $row ) {
			// pay_time is stored in SITE LOCAL time by the theme: show it as is,
			// do not run it through another timezone conversion.
			$out['items'][] = array(
				'order_num' => isset( $row['order_num'] ) ? (string) $row['order_num'] : '',
				'user_id'   => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
				'price'     => isset( $row['order_price'] ) ? (float) $row['order_price'] : 0.0,
				'pay_time'  => isset( $row['pay_time'] ) ? (string) $row['pay_time'] : '',
			);

			if ( ! $apply ) {
				continue;
			}

			try {
				// Idempotent by UNIQUE(grant_key): a second pass inserts 0 rows.
				if ( $this->grant_from_order( $row ) ) {
					$healed++;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		$out['healed'] = $healed;

		if ( 0 < $healed ) {
			$this->log_event(
				'space.reconcile.healed',
				array(
					'owner_id' => 0,
					'result'   => 'ok',
					'detail'   => array( 'healed' => $healed ),
				)
			);
		}

		return $out;
	}

	/**
	 * Audits legacy order grants whose order columns were zeroed by the old writer.
	 *
	 * Before 1.24 every grant was inserted with source/channel "points" and
	 * order_id/order_num/price/points set to zero, even for a cash balance order.
	 * The grant key still carries the real order number, so the missing fields can
	 * be rebuilt from the theme order without touching bytes, expiry, state or the
	 * key itself. This is intentionally a manual CLI operation: it mutates
	 * historical ledger rows and must be reviewed with a dry run first.
	 *
	 * Only rows whose grant_key starts with "order:" and whose order_id or
	 * order_num is still empty are considered. Points fallbacks use a different
	 * key and are never rewritten.
	 *
	 * @param bool $apply When false this is a dry run.
	 * @param int  $limit Max grant rows per run.
	 * @return array<string,mixed>
	 */
	public function audit_legacy_order_grants( bool $apply = false, int $limit = 100 ): array {
		$out = array(
			'candidates' => 0,
			'pending'    => 0,
			'filled'     => 0,
			'skipped'    => 0,
			'dry_run'    => ! $apply,
			'ready'      => $this->has_table(),
			'error'      => '',
			'items'      => array(),
		);

		if ( ! $out['ready'] ) {
			return $out;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return $out;
		}

		$table = $this->table();
		$limit = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, owner_id, grant_key, source, channel, order_id, order_num, price, points, state
				FROM {$table}
				WHERE grant_key LIKE %s AND (order_id = 0 OR order_num = '')
				ORDER BY id ASC
				LIMIT %d",
				'order:%',
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		if ( array() === $rows && '' !== (string) $wpdb->last_error ) {
			$out['ready'] = false;
			$out['error'] = (string) $wpdb->last_error;

			$this->log_event(
				'space.grant.audit_error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'message' => $out['error'] ),
				)
			);

			return $out;
		}

		$out['candidates'] = count( $rows );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = $this->audit_legacy_order_grant( $row, $apply );

			$out['items'][] = $item;

			if ( 'filled' === $item['status'] ) {
				$out['filled']++;
			} elseif ( 'pending' === $item['status'] ) {
				$out['pending']++;
			} else {
				$out['skipped']++;
			}
		}

		return $out;
	}

	/**
	 * Inspects or repairs one legacy order grant.
	 *
	 * @param array<string,mixed> $row   Grant row.
	 * @param bool                $apply Write the order fields.
	 * @return array<string,mixed> Audit item.
	 */
	private function audit_legacy_order_grant( array $row, bool $apply ): array {
		$item = array(
			'grant_id'  => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'grant_key' => isset( $row['grant_key'] ) ? (string) $row['grant_key'] : '',
			'owner_id'  => isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0,
			'order_num' => '',
			'order_id'  => 0,
			'source'    => isset( $row['source'] ) ? (string) $row['source'] : '',
			'channel'   => isset( $row['channel'] ) ? (string) $row['channel'] : '',
			'price'     => isset( $row['price'] ) ? (float) $row['price'] : 0.0,
			'points'    => isset( $row['points'] ) ? (int) $row['points'] : 0,
			'status'    => 'skipped',
			'message'   => '',
		);

		$key = $item['grant_key'];

		if ( 0 !== strpos( $key, 'order:' ) ) {
			$item['status']  = 'key_mismatch';
			$item['message'] = 'grant key is not order:{order_num}';

			return $item;
		}

		$order_num      = substr( $key, 6 );
		$item['order_num'] = $order_num;

		if ( '' === $order_num ) {
			$item['status']  = 'key_mismatch';
			$item['message'] = 'empty order number in grant key';

			return $item;
		}

		$stored_num = isset( $row['order_num'] ) ? (string) $row['order_num'] : '';

		if ( '' !== $stored_num && $stored_num !== $order_num ) {
			$item['status']  = 'key_mismatch';
			$item['message'] = 'grant_key and order_num disagree';

			return $item;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			$item['status']  = 'unavailable';
			$item['message'] = 'database unavailable';

			return $item;
		}

		$orders = $wpdb->prefix . 'zibpay_order';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_num, order_type, status, user_id, order_price, pay_type, product_id, other, pay_detail
				FROM {$orders}
				WHERE order_num = %s AND order_type = %s
				LIMIT 2",
				$order_num,
				self::ORDER_TYPE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$item['status']  = 'query_error';
			$item['message'] = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'order query failed';

			return $item;
		}

		if ( array() === $rows ) {
			$item['status']  = 'missing_order';
			$item['message'] = 'order not found';

			return $item;
		}

		if ( 1 < count( $rows ) ) {
			$item['status']  = 'ambiguous_order';
			$item['message'] = 'more than one order has this number';

			return $item;
		}

		$order = $rows[0];

		if ( '1' !== (string) ( $order['status'] ?? '' ) ) {
			$item['status']  = 'order_not_paid';
			$item['message'] = 'order status=' . (string) ( $order['status'] ?? '' );

			return $item;
		}

		if ( (int) ( $order['user_id'] ?? 0 ) !== $item['owner_id'] ) {
			$item['status']  = 'owner_mismatch';
			$item['message'] = 'order owner and grant owner differ';

			return $item;
		}

		if ( 0 !== strpos( (string) ( $order['product_id'] ?? '' ), self::PRODUCT_PREFIX ) ) {
			$item['status']  = 'not_space_product';
			$item['message'] = 'order is not a space product';

			return $item;
		}

		$data = $this->normalise_order( $order );

		if ( null === $data ) {
			$item['status']  = 'invalid_order';
			$item['message'] = 'order could not be normalised';

			return $item;
		}

		$ledger = $this->grant_ledger_fields( $data );

		$item['order_id'] = (int) $ledger['order_id'];
		$item['order_num'] = (string) $ledger['order_num'];
		$item['source']    = (string) $ledger['source'];
		$item['channel']   = (string) $ledger['channel'];
		$item['price']     = (float) $ledger['price'];
		$item['points']    = (int) $ledger['points'];

		$same = (int) ( $row['order_id'] ?? 0 ) === $item['order_id']
			&& (string) ( $row['order_num'] ?? '' ) === $item['order_num']
			&& abs( (float) ( $row['price'] ?? 0 ) - $item['price'] ) < 0.005
			&& (int) ( $row['points'] ?? 0 ) === $item['points']
			&& (string) ( $row['source'] ?? '' ) === $item['source']
			&& (string) ( $row['channel'] ?? '' ) === $item['channel'];

		if ( $same ) {
			$item['status']  = 'current';
			$item['message'] = 'order fields already match';

			return $item;
		}

		if ( ! $apply ) {
			$item['status']  = 'pending';
			$item['message'] = 'dry run; pass --apply to write';

			return $item;
		}

		$table   = $this->table();
		$written = $wpdb->update(
			$table,
			array(
				'order_id'   => $item['order_id'],
				'order_num'  => $item['order_num'],
				'price'      => $item['price'],
				'points'     => $item['points'],
				'source'     => $item['source'],
				'channel'    => $item['channel'],
				'updated_at' => Space_Rules::now(),
			),
			array( 'id' => $item['grant_id'] ),
			array( '%d', '%s', '%f', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $written ) {
			$item['status']  = 'write_failed';
			$item['message'] = (string) $wpdb->last_error;

			return $item;
		}

		if ( 0 === (int) $written ) {
			$item['status']  = 'current';
			$item['message'] = 'another process already updated this row';

			return $item;
		}

		Space_Rules::flush( $item['owner_id'] );

		$this->log_event(
			'space.grant.backfilled',
			array(
				'owner_id'  => $item['owner_id'],
				'result'    => 'ok',
				'order_num' => $item['order_num'],
				'detail'    => array(
					'grant_id' => $item['grant_id'],
					'order_id' => $item['order_id'],
					'source'   => $item['source'],
					'channel'  => $item['channel'],
				),
			)
		);

		$item['status']  = 'filled';
		$item['message'] = 'order fields restored';

		return $item;
	}

	/**
	 * Manually revokes one grant.
	 *
	 * @param int    $grant_id Grant row id.
	 * @param string $reason   Free text, stored in last_error.
	 * @return bool True when a row was moved to revoked.
	 */
	public function revoke( int $grant_id, string $reason = '' ): bool {
		if ( 0 >= $grant_id || ! $this->has_table() ) {
			return false;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, owner_id, bytes, order_num FROM {$table} WHERE id = %d", $grant_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return false;
		}

		$reason = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $reason ) : trim( $reason );
		$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 80 ) : substr( $reason, 0, 80 );

		$rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = 'revoked', last_error = %s, updated_at = %s WHERE id = %d AND state IN ('active','pending')",
				$reason,
				Space_Rules::now(),
				$grant_id
			)
		);

		if ( 0 >= $rows ) {
			return false;
		}

		Space_Rules::flush( isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0 );

		$this->log_event(
			'space.grant.revoked',
			array(
				'owner_id' => isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0,
				'result'   => 'ok',
				'detail'   => array(
					'grant_id' => $grant_id,
					'bytes'    => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
					'reason'   => $reason,
				),
			)
		);

		return true;
	}

	/**
	 * Manual grant from the admin page (1MB .. 1TB, 0..3650 days).
	 *
	 * @param int    $user_id Target user.
	 * @param int    $bytes   Size in bytes.
	 * @param int    $days    Lifetime in days, 0 = permanent.
	 * @param string $note    Free text used as the grant label.
	 * @return array<string,mixed>|WP_Error
	 */
	public function admin_grant( int $user_id, int $bytes, int $days, string $note = '' ) {
		if ( 0 >= $user_id ) {
			return new WP_Error( 'sufm_grant_user', __( '请指定有效的用户。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( ! $this->has_table() ) {
			return new WP_Error( 'sufm_space_not_ready', __( '扩容数据表尚未就绪。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$bytes = max( 1048576, min( 1099511627776, $bytes ) );
		$days  = max( 0, min( 3650, $days ) );

		$note = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $note ) : trim( $note );
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 80 ) : substr( $note, 0, 80 );

		$label = '' !== $note ? $note : __( '管理员补发', 'sakura-imgopt' );

		$suffix = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: uniqid( '', true );

		$grant_key  = 'manual:' . $suffix;
		$expires_at = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * 86400 ) ) : null;
		$now        = Space_Rules::now();

		$meta = function_exists( 'wp_json_encode' )
			? (string) wp_json_encode( array( 'note' => $note, 'days' => $days, 'actor' => get_current_user_id() ) )
			: '';

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new WP_Error( 'sufm_space_not_ready', __( '扩容数据表尚未就绪。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$table   = $this->table();
		$columns = '(owner_id, grant_key, source, channel, bytes, label, expires_at, state, order_id, order_num, price, points, attempts, last_error, meta, created_at, updated_at)';

		if ( null === $expires_at ) {
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table} {$columns} VALUES (%d, %s, 'admin', 'manual', %d, %s, NULL, 'active', 0, '', 0, 0, 0, '', %s, %s, %s)",
				$user_id,
				$grant_key,
				$bytes,
				$label,
				$meta,
				$now,
				$now
			);
		} else {
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table} {$columns} VALUES (%d, %s, 'admin', 'manual', %d, %s, %s, 'active', 0, '', 0, 0, 0, '', %s, %s, %s)",
				$user_id,
				$grant_key,
				$bytes,
				$label,
				$expires_at,
				$meta,
				$now,
				$now
			);
		}

		$inserted = (int) $wpdb->query( $sql );

		if ( 1 !== $inserted ) {
			return new WP_Error( 'sufm_grant_insert', __( '写入授权失败，请重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$grant_id = (int) $wpdb->insert_id;

		Space_Rules::flush( $user_id );

		$this->log_event(
			'space.grant.manual',
			array(
				'owner_id' => $user_id,
				'result'   => 'ok',
				'detail'   => array(
					'grant_id'  => $grant_id,
					'grant_key' => $grant_key,
					'bytes'     => $bytes,
					'days'      => $days,
					'note'      => $note,
				),
			)
		);

		return array(
			'grant_id'    => $grant_id,
			'bytes'       => $bytes,
			'bytes_human' => self::bytes_human( $bytes ),
			'expires_at'  => '' === $expires_at ? '' : self::local_time( $expires_at ),
			'label'       => $label,
		);
	}

	/**
	 * Extends an active grant by N days.
	 *
	 * @param int $grant_id Grant row id.
	 * @param int $days     Days to add (1..3650).
	 * @return array<string,mixed>|WP_Error
	 */
	public function admin_extend( int $grant_id, int $days ) {
		if ( 0 >= $grant_id || ! $this->has_table() ) {
			return new WP_Error( 'sufm_grant_missing', __( '授权记录不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$days = max( 1, min( 3650, $days ) );

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return new WP_Error( 'sufm_space_not_ready', __( '扩容数据表尚未就绪。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, owner_id, expires_at, state FROM {$table} WHERE id = %d", $grant_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'sufm_grant_missing', __( '授权记录不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( 'active' !== (string) $row['state'] ) {
			return new WP_Error( 'sufm_grant_state', __( '只有生效中的授权可以延长。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( null === $row['expires_at'] || '' === (string) $row['expires_at'] ) {
			return new WP_Error( 'sufm_grant_permanent', __( '永久授权无需延长。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$current = (int) strtotime( (string) $row['expires_at'] . ' UTC' );
		$base    = max( $current, time() );
		$expires = gmdate( 'Y-m-d H:i:s', $base + ( $days * 86400 ) );
		$now     = Space_Rules::now();

		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s, updated_at = %s WHERE id = %d AND state = 'active'",
				$expires,
				$now,
				$grant_id
			)
		);

		if ( 0 >= $updated ) {
			return new WP_Error( 'sufm_grant_update', __( '延长失败，请重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$owner_id = isset( $row['owner_id'] ) ? (int) $row['owner_id'] : 0;

		Space_Rules::flush( $owner_id );

		$this->log_event(
			'space.grant.extended',
			array(
				'owner_id' => $owner_id,
				'result'   => 'ok',
				'detail'   => array(
					'grant_id'   => $grant_id,
					'days'       => $days,
					'expires_at' => $expires,
				),
			)
		);

		return array(
			'grant_id'   => $grant_id,
			'days'       => $days,
			'expires_at' => self::local_time( $expires ),
		);
	}

	/**
	 * Minute cron entry: sweep, retry pending grants, retry refunds, reconcile.
	 *
	 * @return void
	 */
	public function tick(): void {
		$start    = microtime( true );
		$swept    = 0;
		$retried  = 0;
		$refunded = 0;

		try {
			$swept = $this->sweep_expired();
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.tick.error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'stage' => 'sweep', 'message' => $e->getMessage() ),
				)
			);
		}

		try {
			$retried = $this->retry_pending( 20 );
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.tick.error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'stage' => 'retry', 'message' => $e->getMessage() ),
				)
			);
		}

		try {
			$refunded = $this->retry_refunds( 20 );
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.tick.error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'stage' => 'refund', 'message' => $e->getMessage() ),
				)
			);
		}

		try {
			$this->maybe_reconcile();
		} catch ( \Throwable $e ) {
			$this->log_event(
				'space.tick.error',
				array(
					'owner_id' => 0,
					'result'   => 'error',
					'detail'   => array( 'stage' => 'reconcile', 'message' => $e->getMessage() ),
				)
			);
		}

		$elapsed = microtime( true ) - $start;

		if ( 5.0 < $elapsed ) {
			$this->log_event(
				'space.tick.slow',
				array(
					'owner_id' => 0,
					'result'   => 'warn',
					'detail'   => array(
						'seconds'  => round( $elapsed, 3 ),
						'expired'  => $swept,
						'retried'  => $retried,
						'refunded' => $refunded,
					),
				)
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Ledger maintenance (T7)                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Thumbnail shown by Zibll's admin order list and user order centre.
	 *
	 * Built through set_url_scheme() so a CLI-created order does not store an
	 * http:// image that the https admin would refuse to load.
	 *
	 * @return string
	 */
	/**
	 * Validates a per-package order icon URL.
	 *
	 * Empty means “use the bundled icon”. Only http(s) is accepted, and the
	 * scheme follows the site URL so a CLI-created order never stores http on
	 * an https shop.
	 *
	 * @param string $url Raw URL from the settings form.
	 * @return string
	 */
	public static function normalize_image( string $url ): string {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url || ! in_array( (string) wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return set_url_scheme( $url, self::site_scheme() );
	}

	/**
	 * Scheme of the public site URL ('http' or 'https').
	 *
	 * @return string
	 */
	private static function site_scheme(): string {
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : ( is_ssl() ? 'https' : 'http' );
	}

	/**
	 * Icon used by every expansion order (shared by cash and points packages).
	 *
	 * @return string
	 */
	public static function order_icon_url(): string {
		$icon = \Nboke\UserFiles\Space_Settings::order_icon();

		return '' !== $icon ? self::normalize_image( $icon ) : self::product_image_url();
	}

	public static function product_image_url(): string {
		/*
		 * Orders can be created from CLI or cron, where is_ssl() is false even
		 * though the site is served over https. Follow the site URL instead so
		 * the stored image never ends up as http:// on an https admin page.
		 */
		return set_url_scheme( SAKURA_TOOLS_URL . 'assets/space-package.svg', self::site_scheme() );
	}

	/**
	 * Wires the two ledger hooks. Called once from Space_Panel::register().
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// ZibPay::refund_order() fires this with the ORDER TABLE primary key (int).
		add_action( 'order_refunded', array( __CLASS__, 'handle_order_refunded' ), 12, 1 );

		// Runs on the plugin's own minute cron; independent from the sale switch.
		add_action( 'sufm_tick', array( __CLASS__, 'handle_tick' ), 20 );

		// Order-centre presentation for this module's order type.
		\Nboke\UserFiles\Space\Zibll_Order_Card::register();
	}

	/**
	 * Hook bridge: order_refunded.
	 *
	 * @param mixed $order_id Order table primary key.
	 * @return void
	 */
	public static function handle_order_refunded( $order_id ): void {
		self::instance()->on_order_refunded( $order_id );
	}

	/**
	 * Hook bridge: sufm_tick.
	 *
	 * @return void
	 */
	public static function handle_tick(): void {
		self::instance()->tick();
	}

	/**
	 * Points snapshot used by the front end dialog and the admin card.
	 *
	 * @param int $user_id User id (0 for a guest).
	 * @return array<string,mixed>
	 */
	public function points_state( int $user_id ): array {
		$settings = Space_Settings::points();

		$per_gb  = isset( $settings['points_per_gb'] ) ? (int) $settings['points_per_gb'] : 0;
		$max_gb  = isset( $settings['max_gb_per_order'] ) ? (float) $settings['max_gb_per_order'] : 10.0;
		$cap_gb  = isset( $settings['daily_cap_gb'] ) ? (float) $settings['daily_cap_gb'] : 20.0;

		$balance = 0;

		if ( 0 < $user_id && function_exists( 'zibpay_get_user_points' ) ) {
			try {
				$balance = (int) zibpay_get_user_points( $user_id );
			} catch ( \Throwable $e ) {
				$balance = 0;
			}
		}

		$used = 0 < $user_id ? ( self::points_used_bytes( $user_id ) / 1073741824 ) : 0.0;

		return array(
			'enabled'      => Space_Settings::points_enabled() && 0 < $per_gb,
			'per_gb'       => $per_gb,
			'max_gb'       => $max_gb,
			'daily_cap_gb' => $cap_gb,
			'used_gb'      => round( (float) $used, 2 ),
			'balance'      => $balance,
			'min_gb'       => 0.5,
		);
	}

	/**
	 * Daily totals across all users (admin card).
	 *
	 * @return array<string,mixed>
	 */
	public function points_today_totals(): array {
		$out = array( 'gb' => 0.0, 'count' => 0 );

		if ( ! $this->has_table() ) {
			return $out;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return $out;
		}

		$table = $this->table();
		$since = gmdate( 'Y-m-d 00:00:00' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(bytes),0) AS total_bytes, COUNT(*) AS total_rows FROM {$table} WHERE source = 'points' AND created_at >= %s",
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $out;
		}

		$out['gb']    = round( ( (int) ( $row['total_bytes'] ?? 0 ) ) / 1073741824, 2 );
		$out['count'] = (int) ( $row['total_rows'] ?? 0 );

		return $out;
	}

	/**
	 * Sum of the bytes that a user redeemed with points today (UTC day).
	 *
	 * Refunded / revoked rows still count against the daily cap on purpose:
	 * the cap protects the points economy, not the storage.
	 *
	 * @param int $user_id User id.
	 * @return int Bytes.
	 */
	public static function points_used_bytes( int $user_id ): int {
		if ( 0 >= $user_id ) {
			return 0;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$store = self::instance();

		if ( ! $store->has_table() ) {
			return 0;
		}

		$table = $wpdb->prefix . 'sufm_space_grants';
		$since = gmdate( 'Y-m-d 00:00:00' );

		return max(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(bytes),0) FROM {$table} WHERE owner_id = %d AND source = 'points' AND created_at >= %s",
					$user_id,
					$since
				)
			)
		);
	}

	/**
	 * Existing grant row by grant_key.
	 *
	 * @param string $grant_key Grant key.
	 * @return array<string,mixed>|null
	 */
	private function grant_by_key( string $grant_key ): ?array {
		if ( '' === $grant_key || ! $this->has_table() ) {
			return null;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		$table = $this->table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, owner_id, bytes, label, state, expires_at, points FROM {$table} WHERE grant_key = %s LIMIT 1", $grant_key ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Normalises a grant row into the redemption response shape.
	 *
	 * @param array<string,mixed> $row     Grant row.
	 * @param int                 $balance Current points balance.
	 * @return array<string,mixed>
	 */
	private function grant_summary( array $row, int $balance ): array {
		$bytes = isset( $row['bytes'] ) ? (int) $row['bytes'] : 0;
		$exp   = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '';

		return array(
			'grant_id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'bytes'       => $bytes,
			'bytes_human' => self::bytes_human( $bytes ),
			'gb'          => round( $bytes / 1073741824, 2 ),
			'cost'        => isset( $row['points'] ) ? (int) $row['points'] : 0,
			'balance'     => $balance,
			'expires_at'  => '' === $exp ? '' : self::local_time( $exp ),
			'duplicate'   => true,
		);
	}

	/**
	 * Redeems points for extra space.
	 *
	 * The points are deducted with the theme's conditional atomic UPDATE helper
	 * (zibpay_update_user_points) — never with update_user_meta().
	 *
	 * @param int    $user_id    User id.
	 * @param float  $gb         Requested gigabytes.
	 * @param string $request_id Client generated idempotency key.
	 * @return array<string,mixed>|WP_Error
	 */
	public function redeem_points( int $user_id, float $gb, string $request_id = '', int $days = 0, ?int $cost_override = null, string $label = '' ) {
		if ( ! Space_Settings::enabled() ) {
			return new WP_Error( 'sufm_space_closed', __( '扩容功能未开启。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		if ( 0 >= $user_id ) {
			return new WP_Error( 'sufm_points_login', __( '请先登录。', 'sakura-imgopt' ), array( 'status' => 401 ) );
		}

		if ( ! $this->has_table() ) {
			return new WP_Error( 'sufm_space_not_ready', __( '扩容数据表尚未就绪。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$state = $this->points_state( $user_id );

		if ( empty( $state['enabled'] ) ) {
			return new WP_Error( 'sufm_points_off', __( '积分兑换未开启。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$gb = round( $gb, 2 );

		if ( 0.5 > $gb || $gb > (float) $state['max_gb'] ) {
			return new WP_Error(
				'sufm_points_range',
				sprintf(
					/* translators: 1: minimum GB, 2: maximum GB per order. */
					__( '单次兑换需在 %1$s GB 到 %2$s GB 之间。', 'sakura-imgopt' ),
					'0.5',
					number_format( (float) $state['max_gb'], 2 )
				),
				array(
					'status' => 400,
					'min_gb' => 0.5,
					'max_gb' => (float) $state['max_gb'],
				)
			);
		}

		// Price the term, not just the size: the old flat rate let a client ask
		// for days=0 and receive a permanent grant for the price of one year.
		$days = $days > 0 ? min( 3650, $days ) : 365;

		$cost = null !== $cost_override && $cost_override > 0
			? (int) $cost_override
			: (int) ceil( $gb * (int) $state['per_gb'] * $days / 365 );

		if ( 0 >= $cost ) {
			return new WP_Error( 'sufm_points_range', __( '兑换数量无效。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( (int) $state['balance'] < $cost ) {
			return new WP_Error(
				'sufm_points_short',
				sprintf(
					/* translators: 1: required points, 2: current balance. */
					__( '积分不足：需要 %1$d，当前 %2$d', 'sakura-imgopt' ),
					$cost,
					(int) $state['balance']
				),
				array(
					'status'  => 400,
					'cost'    => $cost,
					'balance' => (int) $state['balance'],
				)
			);
		}

		if ( ( (float) $state['used_gb'] + $gb ) > (float) $state['daily_cap_gb'] ) {
			return new WP_Error(
				'sufm_points_daily',
				sprintf(
					/* translators: 1: redeemed GB today, 2: daily cap GB. */
					__( '今日兑换已达上限：已兑 %1$s GB / 上限 %2$s GB', 'sakura-imgopt' ),
					number_format( (float) $state['used_gb'], 2 ),
					number_format( (float) $state['daily_cap_gb'], 2 )
				),
				array(
					'status'  => 400,
					'used_gb' => (float) $state['used_gb'],
					'cap_gb'  => (float) $state['daily_cap_gb'],
				)
			);
		}

		$request_id = '' !== $request_id ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $request_id ) : '';

		$suffix = '' !== $request_id
			? md5( $user_id . '|' . $request_id )
			: ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true ) );

		$grant_key = 'points:' . $suffix;

		// Idempotency: the same request_id returns the same grant, no second charge.
		$existing = $this->grant_by_key( $grant_key );

		if ( null !== $existing ) {
			return $this->grant_summary( $existing, (int) $state['balance'] );
		}

		if ( ! function_exists( 'zibpay_update_user_points' ) ) {
			return new WP_Error( 'sufm_points_off', __( '主题积分组件不可用。', 'sakura-imgopt' ), array( 'status' => 503 ) );
		}

		$bytes = (int) round( $gb * 1073741824 );

		$product_title = '' !== $label ? $label : Space_Settings::points_label( $gb, $days );

		$charged   = false;
		$order_id  = 0;
		$order_num = '';

		// Prefer a real theme order so the redemption shows up in both order
		// centres (pay_type=points, order_type=21). Falls back to the plain
		// deduction when the theme's zibpay class is unavailable.
		$order_ready = class_exists( 'ZibPay' ) && function_exists( 'zibpay_points_initiate_pay' );
		$order       = null;

		if ( $order_ready ) {
			try {
				$order = \ZibPay::add_order(
					array(
						'user_id'     => $user_id,
						'product_id'  => 'sufm_space_points',
						'count'       => 1,
						'order_price' => $cost,
						'order_type'  => self::ORDER_TYPE,
						'pay_type'    => 'points',
						'pay_price'   => $cost,
						'pay_detail'  => array( 'points' => $cost ),
						'meta'        => array(
							'order_data' => array(
								'product_title' => $product_title,
								'product_image' => self::order_icon_url(),
								'plan_id'       => '',
								'bytes'         => $bytes,
								'days'          => $days,
								'points'        => $cost,
								'count'         => 1,
								'prices'        => array(
									'pay_price'   => $cost,
									'total_price' => $cost,
									'unit_price'  => $cost,
								),
							),
							'pay_modo'    => 'points',
						),
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				$order = null;
			}
		}

		if ( is_array( $order ) && '' !== (string) ( $order['order_num'] ?? '' ) ) {
			$order_id  = isset( $order['id'] ) ? (int) $order['id'] : 0;
			$order_num = (string) $order['order_num'];

			$paid = zibpay_points_initiate_pay(
				array(
					'user_id'        => $user_id,
					'payment_method' => 'points',
					'order_num'      => $order_num,
					'order_price'    => $cost,
					'desc'           => $product_title,
				)
			);

			if ( ! empty( $paid['error'] ) ) {
				if ( $order_id > 0 ) {
					try {
						\ZibPay::close_order_single( $order_id, 'failed', __( '积分支付失败', 'sakura-imgopt' ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				return new WP_Error( 'sufm_points_failed', isset( $paid['msg'] ) ? (string) $paid['msg'] : __( '积分扣除失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
			}

			$charged = true;
		} else {
			try {
				$charged = (bool) zibpay_update_user_points(
					$user_id,
					array(
						'value' => -$cost,
						'type'  => 'space_expand',
						/* translators: %s: granted GB. */
						'desc'  => sprintf( __( '空间扩容 %s GB', 'sakura-imgopt' ), number_format( $gb, 2 ) ),
					)
				);
			} catch ( \Throwable $e ) {
				$charged = false;
			}
		}

		if ( ! $charged ) {
			return new WP_Error( 'sufm_points_failed', __( '积分扣除失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $days * 86400 ) );
		$now        = Space_Rules::now();
		$label      = sprintf(
			/* translators: %s: granted GB. */
			__( '积分兑换 %s GB', 'sakura-imgopt' ),
			number_format( $gb, 2 )
		);

		$meta = function_exists( 'wp_json_encode' )
			? (string) wp_json_encode(
				array(
					'gb'         => $gb,
					'cost'       => $cost,
					'request_id' => $request_id,
					'actor'      => $user_id,
				)
			)
			: '';

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			$this->refund_points( $user_id, $cost, $gb );

			return new WP_Error( 'sufm_grant_insert', __( '写入授权失败，积分已退回。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$table   = $this->table();
		$columns = '(owner_id, grant_key, source, channel, bytes, label, expires_at, state, order_id, order_num, price, points, attempts, last_error, meta, created_at, updated_at)';

		$inserted = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} {$columns} VALUES (%d, %s, 'points', 'points', %d, %s, %s, 'active', %d, %s, 0, %d, 0, '', %s, %s, %s)",
				$user_id,
				$grant_key,
				$bytes,
				$label,
				$expires_at,
				$order_id,
				$order_num,
				$cost,
				$meta,
				$now,
				$now
			)
		);

		if ( 1 !== $inserted ) {
			// Points already taken: give them back before reporting the failure.
			$this->refund_points( $user_id, $cost, $gb );
			$this->refund_points_order( $order_id );

			return new WP_Error( 'sufm_grant_insert', __( '写入授权失败，积分已退回。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$grant_id = (int) $wpdb->insert_id;

		// Two concurrent redemptions can both pass the pre-check above because
		// the balance and the daily total are read outside any lock. Re-read the
		// total now that the row exists; if the batch pushed the day over the
		// cap, undo this grant and hand the points back.
		$used_after = self::points_used_bytes( $user_id ) / 1073741824;

		if ( $used_after > (float) $state['daily_cap_gb'] + 0.001 ) {
			$wpdb->delete( $table, array( 'id' => $grant_id ), array( '%d' ) );
			$this->refund_points( $user_id, $cost, $gb );
			$this->refund_points_order( $order_id );
			Space_Rules::flush( $user_id );

			return new WP_Error( 'sufm_points_daily', __( '今日兑换已达上限，本次兑换已撤销。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		Space_Rules::flush( $user_id );

		$this->log_event(
			'space.grant.points',
			array(
				'owner_id' => $user_id,
				'result'   => 'ok',
				'detail'   => array(
					'grant_id' => $grant_id,
					'grant_key' => $grant_key,
					'gb'       => $gb,
					'cost'     => $cost,
					'bytes'    => $bytes,
				),
			)
		);

		$after = (int) $state['balance'] - $cost;

		if ( function_exists( 'zibpay_get_user_points' ) ) {
			try {
				$after = (int) zibpay_get_user_points( $user_id );
			} catch ( \Throwable $e ) {
				$after = (int) $state['balance'] - $cost;
			}
		}

		return array(
			'grant_id'    => $grant_id,
			'bytes'       => $bytes,
			'bytes_human' => self::bytes_human( $bytes ),
			'gb'          => $gb,
			'cost'        => $cost,
			'balance'     => $after,
			'expires_at'  => '' === $expires_at ? '' : self::local_time( $expires_at ),
			'duplicate'   => false,
		);
	}

	/**
	 * Gives points back after a failed grant insert.
	 *
	 * @param int   $user_id User id.
	 * @param int   $cost    Points to return.
	 * @param float $gb      Granted GB (for the description).
	 * @return void
	 */
	/**
	 * Redeems a configured points package (points are their own product line).
	 *
	 * The price always comes from the package, never from the client.
	 *
	 * @param int    $user_id    User id.
	 * @param string $package_id Package id from settings.
	 * @param string $request_id Idempotency key.
	 * @return array<string,mixed>|WP_Error
	 */
	public function redeem_package( int $user_id, string $package_id, string $request_id = '' ) {
		$package_id = function_exists( 'sanitize_key' ) ? sanitize_key( $package_id ) : trim( $package_id );

		if ( '' === $package_id ) {
			return new WP_Error( 'sufm_points_package', __( '积分套餐不存在。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$package = null;

		foreach ( Space_Settings::points_packages() as $row ) {
			if ( isset( $row['id'] ) && $package_id === (string) $row['id'] && ! empty( $row['active'] ) ) {
				$package = $row;
				break;
			}
		}

		if ( null === $package ) {
			return new WP_Error( 'sufm_points_package', __( '积分套餐不存在或已下架。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$gb   = (float) ( $package['gb'] ?? 0 );
		$cost = (int) ( $package['points'] ?? 0 );
		$days = (int) ( $package['days'] ?? 365 );
		$max  = (float) ( Space_Settings::points()['max_gb_per_order'] ?? 10 );

		if ( $gb <= 0 || $cost <= 0 ) {
			return new WP_Error( 'sufm_points_package', __( '积分套餐配置不完整。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( $max > 0 && $gb > $max ) {
			return new WP_Error( 'sufm_points_range', __( '该套餐超出单笔兑换上限。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		return $this->redeem_points( $user_id, $gb, $request_id, $days, $cost, (string) ( $package['label'] ?? '' ) );
	}

	/**
	 * Marks the theme order as refunded when the grant could not be stored.
	 *
	 * @param int $order_id Order id (0 when the fallback path was used).
	 * @return void
	 */
	private function refund_points_order( int $order_id ): void {
		if ( $order_id <= 0 || ! class_exists( '\ZibPay' ) ) {
			return;
		}

		try {
			\ZibPay::refund_order( $order_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	private function refund_points( int $user_id, int $cost, float $gb ): void {
		if ( 0 >= $cost || ! function_exists( 'zibpay_update_user_points' ) ) {
			return;
		}

		try {
			zibpay_update_user_points(
				$user_id,
				array(
					'value' => $cost,
					'type'  => 'space_expand_refund',
					/* translators: %s: granted GB. */
					'desc'  => sprintf( __( '空间扩容失败退回 %s GB', 'sakura-imgopt' ), number_format( $gb, 2 ) ),
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
