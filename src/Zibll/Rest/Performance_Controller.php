<?php
/**
 * REST surface for the Zibll performance page.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\Zibll\License_Cache;
use Sakura\ImageOptimizer\Zibll\Performance_Payload;
use Sakura\ImageOptimizer\Zibll\Revalidate;
use Sakura\ImageOptimizer\Zibll\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the license-cache card and re-runs the theme check on demand.
 */
final class Performance_Controller {

	/**
	 * Route namespace.
	 */
	private const NS = 'sakura-tools/v1';

	/**
	 * Register both routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/zibll/performance',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/zibll/performance/warm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'warm' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Reuse the page gate so a disabled Zibll tab cannot be written.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return App_Manifest::can_view( 'zibll-performance' );
	}

	/**
	 * Save the cache card through the same sanitizer the option uses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			return new WP_Error(
				'sakura_zibll_fields',
				__( '设置参数不完整。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$fields[ Card_Form::PARTIAL_FIELD ] = 1;
		$fields[ Card_Form::CARD_FIELD ]    = Settings::CARD;

		$value = Settings::sanitize( $fields );

		update_option( Settings::OPTION, $value, false );

		return rest_ensure_response( self::response() );
	}

	/**
	 * Re-run the license check and return fresh card data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function warm( WP_REST_Request $request ) {
		unset( $request );

		if ( ! Settings::enabled() ) {
			return new WP_Error(
				'sakura_zibll_warm_disabled',
				__( '请先启用授权缓存，再重新验证。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$cache = License_Cache::warm( 'rest_manual' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			// A failed re-check changes the cache, so return the fresh sections
			// with the error as a notice. Returning only WP_Error would leave the
			// status card showing the previous, now-invalid verdict.
			$response           = self::response();
			$response['notice'] = array(
				'tone' => 'bad',
				'text' => $cache->get_error_message(),
			);

			return rest_ensure_response( $response );
		}

		Revalidate::reschedule();

		$response           = self::response();
		$response['notice'] = array(
			'tone' => 'ok',
			'text' => __( '验证通过，缓存已刷新。', 'sakura-imgopt' ),
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Fresh sections, head and save notice.
	 *
	 * @return array<string,mixed>
	 */
	private static function response(): array {
		$payload  = ( new Performance_Payload() )->build();
		$sections = array();

		foreach ( $payload['cards'] as $card ) {
			$sections[ $card['id'] ] = $card;
		}

		return array(
			'sections' => $sections,
			'head'     => $payload['head'],
			'notice'   => self::notice(),
		);
	}

	/**
	 * Turn the settings error the sanitizer recorded into one Prompt notice.
	 *
	 * @return array<string,string>
	 */
	private static function notice(): array {
		$errors = function_exists( 'get_settings_errors' ) ? get_settings_errors( Settings::OPTION ) : array();

		if ( ! $errors ) {
			return Performance_Payload::saved_notice();
		}

		$last = end( $errors );
		$map  = array(
			'success' => 'ok',
			'warning' => 'warn',
			'error'   => 'bad',
			'info'    => 'info',
		);
		$type = isset( $last['type'] ) ? (string) $last['type'] : 'info';
		$tone = $map[ $type ] ?? 'info';

		if ( 'bad' === $tone ) {
			$notice = Performance_Payload::failed_notice();
		} elseif ( 'warn' === $tone ) {
			$notice = array(
				'tone'    => 'warn',
				'kicker'  => 'LICENSE / NOTICE',
				'title'   => __( '需要确认', 'sakura-imgopt' ),
				'message' => '',
			);
		} else {
			$notice = Performance_Payload::saved_notice();
		}

		$notice['tone']    = $tone;
		$notice['message'] = isset( $last['message'] ) ? (string) $last['message'] : $notice['message'];

		return $notice;
	}
}
