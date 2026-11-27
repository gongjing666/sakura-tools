<?php
/**
 * The admin page contract: what a page payload must contain, in one place.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises and inspects the payload every allowlisted page returns.
 *
 * The rules live in docs/开发规则/14-后台页面契约.md. This class is the single
 * place that knows them, so the first paint, the page REST route and the
 * contract test cannot drift from three copies of the same list.
 */
final class Payload_Contract {

	/**
	 * Keys every payload must carry.
	 */
	public const REQUIRED = array( 'page', 'head', 'chrome', 'cards', 'strings', 'rest' );

	/**
	 * Top-level keys that are allowed besides the required six.
	 *
	 * Everything else belongs in `sections` (per card) or `data` (page wide).
	 */
	public const OPTIONAL = array( 'version', 'tab', 'alerts', 'notice', 'data', 'sections' );

	/**
	 * Required keys that are missing from a raw payload.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @return array<int,string>
	 */
	public static function missing( array $payload ): array {
		$missing = array();

		foreach ( self::REQUIRED as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Fill what is derivable and cast the shapes the client relies on.
	 *
	 * It deliberately does not invent a payload: a missing `cards` stays an
	 * empty list so the contract test (which inspects the raw builder) fails
	 * instead of the page rendering half a screen.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @param string              $page    Route id.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $payload, string $page ): array {
		$payload['cards']   = self::cards( $payload['cards'] ?? array() );
		$payload['strings'] = isset( $payload['strings'] ) && is_array( $payload['strings'] ) ? $payload['strings'] : array();
		$payload['rest']    = self::rest( isset( $payload['rest'] ) && is_array( $payload['rest'] ) ? $payload['rest'] : array(), $page );

		return $payload;
	}

	/**
	 * Normalise a rest block and always add the refresh endpoint.
	 *
	 * @param array<string,mixed> $rest Rest block.
	 * @param string              $page Route id.
	 * @return array<string,mixed>
	 */
	public static function rest( array $rest, string $page ): array {
		$rest['url']     = isset( $rest['url'] ) ? (string) $rest['url'] : '';
		$rest['nonce']   = isset( $rest['nonce'] ) ? (string) $rest['nonce'] : '';
		$rest['refresh'] = esc_url_raw( rest_url( 'sakura-tools/v1/pages/' . $page ) );

		return $rest;
	}

	/**
	 * The canonical rest block for a page's base route.
	 *
	 * Builders use this instead of assembling url/nonce/refresh three times.
	 *
	 * @param string $url  Absolute base URL of the page's action route.
	 * @param string $page Route id.
	 * @return array<string,mixed>
	 */
	public static function rest_for( string $url, string $page ): array {
		return self::rest(
			array(
				'url'   => esc_url_raw( $url ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			$page
		);
	}

	/**
	 * Cast cards to an ordered list of descriptors, each with an id.
	 *
	 * Associative maps (the old system shape) become a list in their original
	 * order, so a page that still keys cards by id does not lose its order.
	 *
	 * @param mixed $cards Card list or map.
	 * @return array<int,array<string,mixed>>
	 */
	public static function cards( $cards ): array {
		if ( ! is_array( $cards ) ) {
			return array();
		}

		$normalised = array();

		foreach ( $cards as $key => $card ) {
			if ( is_string( $card ) ) {
				$normalised[] = array( 'id' => $card );
				continue;
			}

			if ( ! is_array( $card ) ) {
				continue;
			}

			if ( ! isset( $card['id'] ) && is_string( $key ) ) {
				$card['id'] = $key;
			}

			$normalised[] = $card;
		}

		return $normalised;
	}

	/**
	 * Top-level keys that should have been folded into sections/data.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @return array<int,string>
	 */
	public static function stray_keys( array $payload ): array {
		$allowed = array_merge( self::REQUIRED, self::OPTIONAL );
		$stray   = array();

		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				$stray[] = (string) $key;
			}
		}

		return $stray;
	}
}
