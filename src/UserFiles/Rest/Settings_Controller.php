<?php
/**
 * REST surface for the file-space settings cards.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Rest;

use Nboke\UserFiles\Admin\Files_Settings_Payload;
use Nboke\UserFiles\Admin\Settings_Cards;
use Sakura\ImageOptimizer\Admin\App_Manifest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * One write endpoint for both editable cards; the card travels in the body.
 */
final class Settings_Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sufm/v1';

	/**
	 * Route path.
	 */
	public const PATH = '/admin/settings/cards';

	/**
	 * Register the route. Called from rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			self::PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => array(
					'card' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Same gate as the page: allowlist + tab environment + capability.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return App_Manifest::can_view( Files_Settings_Payload::PAGE );
	}

	/**
	 * Save one card and return its fresh data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$card   = sanitize_key( (string) $request->get_param( 'card' ) );
		$fields = $request->get_param( 'fields' );
		$fields = is_array( $fields ) ? $fields : array();

		$result = Settings_Cards::save( $card, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
