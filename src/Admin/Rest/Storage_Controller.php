<?php
/**
 * REST endpoints for the S3 storage island.
 *
 * The screen is an island now, so every click has to come back as JSON. The
 * routes stay thin: the decisions live in Settings_Saver (what a save means),
 * Profile_Actions (what a toggle or a delete means) and Probe_Runner (what a
 * probe means). The admin-post and admin-ajax handlers keep working for the
 * legacy renderer and wrap the very same services.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\Storage_Payload;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Actions;
use Sakura\ImageOptimizer\S3\Probe_Runner;
use Sakura\ImageOptimizer\S3\Save_Refused;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Settings_Saver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the storage routes.
 */
final class Storage_Controller {

	/** Route namespace shared by every island. */
	public const NS = 'sakura-tools/v1';

	/** Cards the save route accepts. */
	public const CARDS = array( 'connection', 'behaviour' );

	/**
	 * Register every route this controller owns.
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/storage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/cards/(?P<card>[a-z]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_card' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'card' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::CARDS,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/profile',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'toggle' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/profile/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'delete' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/probe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'probe' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/probe/profile',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'probe_profile' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/doge-buckets',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'doge_buckets' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback: the whole screen is manage_options.
	 *
	 * @return true|WP_Error
	 */
	public static function can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( '权限不足。', 'sakura-imgopt' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whole bootstrap document; used by the client after a hard reload.
	 *
	 * @return WP_REST_Response
	 */
	public static function read(): WP_REST_Response {
		return rest_ensure_response( ( new Storage_Payload() )->build() );
	}

	/**
	 * Save one card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_card( WP_REST_Request $request ) {
		$card  = (string) $request->get_param( 'card' );
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_body_params();
		}

		$saver = new Settings_Saver( new Options() );

		try {
			$args = $saver->apply( $input, $card );
		} catch ( Save_Refused $refused ) {
			return new WP_Error(
				'sakura_s3_save',
				S3_Storage_Page::error_message( $refused->reason() ),
				array(
					'status' => 400,
					'reason' => $refused->reason(),
					'args'   => $refused->args(),
				)
			);
		}

		$payload = new Storage_Payload();

		return rest_ensure_response(
			array(
				'card'       => $card,
				'saved'      => $args,
				'result'     => array(
					'tone'    => 'ok',
					'kicker'  => 'OBJECT STORAGE / SAVED',
					'title'   => __( '已保存', 'sakura-imgopt' ),
					'message' => self::saved_message( $args ),
				),
				'status'     => $payload->status(),
				'alerts'     => $payload->alerts(),
				'profiles'   => $payload->profiles(),
				'connection' => $payload->connection(),
				'behaviour'  => $payload->behaviour(),
			)
		);
	}

	/**
	 * Turn storage on or off, or move it to another profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function toggle( WP_REST_Request $request ) {
		$profile_id = sanitize_key( (string) ( $request->get_param( 'profile_id' ) ?? '' ) );
		$intent     = sanitize_key( (string) ( $request->get_param( 'intent' ) ?? 'on' ) );
		$result     = Profile_Actions::toggle( $profile_id, 'off' === $intent ? 'off' : 'on' );

		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'sakura_s3_toggle',
				(string) $result['message'],
				array(
					'status' => 400,
					'reason' => (string) $result['code'],
				)
			);
		}

		$payload = new Storage_Payload();

		return rest_ensure_response(
			array(
				'ok'       => true,
				'message'  => '',
				'state'    => (array) $result['state'],
				'switch'   => $result['switch'],
				'status'   => $payload->status(),
				'alerts'   => $payload->alerts(),
				'profiles' => $payload->profiles(),
			)
		);
	}

	/**
	 * Delete one saved profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( WP_REST_Request $request ) {
		$profile_id = sanitize_key( (string) ( $request->get_param( 'profile_id' ) ?? '' ) );
		$result     = Profile_Actions::delete( $profile_id );

		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'sakura_s3_delete',
				(string) $result['message'],
				array(
					'status' => 400,
					'reason' => (string) $result['code'],
				)
			);
		}

		$payload = new Storage_Payload();

		return rest_ensure_response(
			array(
				'ok'       => true,
				'message'  => __( '已删除保存的配置。', 'sakura-imgopt' ),
				'status'   => $payload->status(),
				'alerts'   => $payload->alerts(),
				'profiles' => $payload->profiles(),
				'connection' => $payload->connection(),
			)
		);
	}

	/**
	 * Probe the values currently typed into the form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function probe( WP_REST_Request $request ) {
		$input   = $request->get_json_params();
		$verdict = Probe_Runner::run_form( is_array( $input ) ? $input : array() );
		$http    = (int) ( $verdict['http'] ?? 200 );
		unset( $verdict['http'] );

		if ( $http >= 400 ) {
			return new WP_Error(
				'sakura_s3_probe',
				(string) ( $verdict['message'] ?? '' ),
				array( 'status' => $http )
			);
		}

		return rest_ensure_response( $verdict );
	}

	/**
	 * Probe one saved profile without switching to it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function probe_profile( WP_REST_Request $request ) {
		$profile_id = sanitize_key( (string) ( $request->get_param( 'profile_id' ) ?? '' ) );
		$verdict    = Probe_Runner::run_profile( $profile_id );
		$http       = (int) ( $verdict['http'] ?? 200 );
		unset( $verdict['http'] );

		if ( $http >= 400 ) {
			return new WP_Error(
				'sakura_s3_probe_profile',
				(string) ( $verdict['message'] ?? '' ),
				array( 'status' => $http )
			);
		}

		return rest_ensure_response( $verdict );
	}

	/**
	 * Resolve 多吉云 SDK parameters for a space name.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function doge_buckets( WP_REST_Request $request ) {
		$input  = $request->get_json_params();
		$result = Probe_Runner::doge_buckets( is_array( $input ) ? $input : array() );

		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'sakura_s3_doge',
				(string) $result['message'],
				array( 'status' => (int) $result['http'] )
			);
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'data' => (array) $result['data'],
			)
		);
	}

	/**
	 * Sentence shown after a successful save.
	 *
	 * @param array<string,mixed> $args Result of Settings_Saver::apply().
	 */
	private static function saved_message( array $args ): string {
		$moved   = ! empty( $args['moved'] );
		$created = ! empty( $args['created'] );

		if ( $moved ) {
			return $created
				? __( '已保存为新的存储配置，并指向新的存储位置；请在同步中心重新核对一次。', 'sakura-imgopt' )
				: __( '已更新这份配置，并指向新的存储位置；旧的核对记录已清空，请在同步中心重新核对一次。', 'sakura-imgopt' );
		}

		return $created
			? __( '已保存为新的存储配置。', 'sakura-imgopt' )
			: __( '云存储设置已保存。', 'sakura-imgopt' );
	}
}
