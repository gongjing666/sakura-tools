<?php
/**
 * REST endpoints for the monitoring card on the S3 screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\S3\Alerting;
use Sakura\ImageOptimizer\S3\Prometheus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the alert option, fires a test webhook and rotates the metrics token.
 *
 * The old screen posted three admin-post.php forms, each with its own nonce
 * action and check_admin_referer(). The island keeps the same three writes but
 * posts JSON to /wp-json, so the CSRF proof moves to the X-WP-Nonce (wp_rest)
 * header every island request already carries: each write verifies it before
 * touching the option, and permission still comes from manage_options.
 *
 * Nothing here re-implements alerting: Alerting and Prometheus stay the only
 * owners of the option, its sanitizer, dispatch and the read-only token.
 */
final class Storage_Monitoring_Controller {

	/**
	 * Namespace shared by the plugin's REST routes.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Route base for this card.
	 */
	private const BASE = '/storage/monitoring';

	/**
	 * Nonce action the island's X-WP-Nonce header is created with.
	 */
	private const NONCE_ACTION = 'wp_rest';

	/**
	 * Guard against registering the same routes twice on one request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the routes.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		register_rest_route(
			self::NS,
			self::BASE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			self::BASE . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			self::BASE . '/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rotate' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * POST /storage/monitoring.
	 *
	 * Body: { "fields": { enabled, webhook, fail_rate, min_attempts, cooldown } }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$nonce = self::verify_nonce();

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$defaults = Alerting::defaults();

		// Same defaults the admin-post handler used when a field was missing:
		// absent means "keep the documented default", not zero. Alerting::save()
		// still sanitizes and clamps everything below.
		$raw = array(
			'enabled'      => empty( $fields['enabled'] ) ? 0 : 1,
			'webhook'      => isset( $fields['webhook'] ) ? (string) $fields['webhook'] : '',
			'fail_rate'    => isset( $fields['fail_rate'] ) ? absint( $fields['fail_rate'] ) : (int) $defaults['fail_rate'],
			'min_attempts' => isset( $fields['min_attempts'] ) ? absint( $fields['min_attempts'] ) : (int) $defaults['min_attempts'],
			'cooldown'     => isset( $fields['cooldown'] ) ? absint( $fields['cooldown'] ) : (int) $defaults['cooldown'],
		);

		$values = Alerting::save( $raw );

		// Alerting::sanitize() blanks a webhook that is not a public http(s)
		// URL. Report that instead of claiming a clean save that silently threw
		// away the address the operator just typed.
		$dropped = '' !== trim( (string) $raw['webhook'] ) && '' === (string) ( $values['webhook'] ?? '' );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => $dropped
					? __( '告警设置已保存，但 Webhook 地址无效或指向内网，已清空。', 'sakura-imgopt' )
					: __( '告警设置已保存。', 'sakura-imgopt' ),
				'state'   => array(
					'values'          => $values,
					'webhook_dropped' => $dropped,
				),
			)
		);
	}

	/**
	 * POST /storage/monitoring/test.
	 *
	 * No body: like the old handler, this tests the saved configuration, not
	 * the unsaved draft, so a green result always describes what is stored.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test() {
		$nonce = self::verify_nonce();

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$result = Alerting::test();

		return rest_ensure_response(
			array(
				'ok'      => (bool) $result['ok'],
				'message' => (string) $result['message'],
				'state'   => array(),
			)
		);
	}

	/**
	 * POST /storage/monitoring/rotate.
	 *
	 * No body: rotates the read-only metrics token and returns the new URL, so
	 * the island can show the address that is now valid.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rotate() {
		$nonce = self::verify_nonce();

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		Prometheus::rotate();

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'Prometheus 令牌已轮换，请更新抓取配置。', 'sakura-imgopt' ),
				'state'   => array(
					'prometheus' => array(
						'url' => Prometheus::url(),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: only administrators may read or rotate credentials.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return App_Manifest::can_view( 'storage-connection' );
	}

	/**
	 * Verify the X-WP-Nonce header the island sends with every write.
	 *
	 * Cookie authentication already proves who the user is; the nonce proves
	 * the request came from this admin screen, which is exactly what
	 * check_admin_referer() did for the old admin-post forms.
	 *
	 * @return true|WP_Error
	 */
	private static function verify_nonce() {
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WP_NONCE'] ) )
			: '';

		if ( '' !== $nonce && wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return true;
		}

		return new WP_Error(
			'sakura_imgopt_rest_nonce',
			__( '页面已过期，请刷新后重试。', 'sakura-imgopt' ),
			array( 'status' => 403 )
		);
	}
}
