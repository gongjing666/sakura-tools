<?php
/**
 * Per-card settings form helpers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Marks a settings form as owning one card of a shared option group.
 *
 * WordPress' options.php saves every option registered for the submitted
 * group. Without a guard, saving one card would blank the options owned by
 * the other cards. A card form therefore posts a card name and a partial
 * marker; pre_update_option filters keep every option the card does not own
 * at its stored value.
 */
final class Card_Form {

	/**
	 * Hidden field holding the card identifier.
	 */
	public const CARD_FIELD = '_sakura_card';

	/**
	 * Hidden field marking a card-scoped submission.
	 */
	public const PARTIAL_FIELD = '_sakura_partial';

	/**
	 * Print the hidden fields for one card form.
	 *
	 * @param string $card Stable card identifier, for example "compress".
	 * @return void
	 */
	public static function fields( string $card ): void {
		printf(
			'<input type="hidden" name="%1$s" value="%2$s" />',
			esc_attr( self::CARD_FIELD ),
			esc_attr( sanitize_key( $card ) )
		);
		printf(
			'<input type="hidden" name="%1$s" value="1" />',
			esc_attr( self::PARTIAL_FIELD )
		);
	}

	/**
	 * Print the hidden fields for a card form that submits one array option.
	 *
	 * The marker must live inside the option array so the option's sanitize
	 * callback receives it (options.php passes only the posted option value).
	 *
	 * @param string $option Option name, for example "sakura_imgopt_oss_thumb".
	 * @param string $card   Stable card identifier.
	 * @return void
	 */
	public static function option_fields( string $option, string $card ): void {
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="%3$s" />',
			esc_attr( $option ),
			esc_attr( self::CARD_FIELD ),
			esc_attr( sanitize_key( $card ) )
		);
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="1" />',
			esc_attr( $option ),
			esc_attr( self::PARTIAL_FIELD )
		);
	}

	/**
	 * Card name posted by the current request.
	 *
	 * @return string Empty when the request is not a card-scoped submission.
	 */
	public static function current_card(): string {
		if ( ! isset( $_POST[ self::CARD_FIELD ] ) || ! is_string( $_POST[ self::CARD_FIELD ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_POST[ self::CARD_FIELD ] ) );
	}

	/**
	 * Whether the current request saves only one card.
	 *
	 * @return bool
	 */
	public static function is_partial(): bool {
		if ( ! isset( $_POST[ self::PARTIAL_FIELD ] ) ) {
			return false;
		}

		$value = wp_unslash( $_POST[ self::PARTIAL_FIELD ] );

		return is_scalar( $value ) && '' !== (string) $value;
	}

	/**
	 * Keep options outside the submitted card untouched.
	 *
	 * @param string                          $group        Settings group, matching settings_fields().
	 * @param array<string, array<int,string>> $card_options Option names keyed by card identifier.
	 * @return void
	 */
	public static function guard_options( string $group, array $card_options ): void {
		if ( ! self::is_partial() ) {
			return;
		}

		$page = isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] )
			? sanitize_key( wp_unslash( $_POST['option_page'] ) )
			: '';
		if ( $page !== $group ) {
			return;
		}

		$card  = self::current_card();
		$owned = isset( $card_options[ $card ] ) && is_array( $card_options[ $card ] )
			? array_map( 'strval', $card_options[ $card ] )
			: array();

		$options = array();
		foreach ( $card_options as $names ) {
			if ( is_array( $names ) ) {
				$options = array_merge( $options, array_map( 'strval', $names ) );
			}
		}

		foreach ( array_unique( $options ) as $option ) {
			add_filter(
				'pre_update_option_' . $option,
				static function ( $value, $old_value ) use ( $option, $owned ) {
					return in_array( $option, $owned, true ) ? $value : $old_value;
				},
				10,
				2
			);
		}
	}
}
