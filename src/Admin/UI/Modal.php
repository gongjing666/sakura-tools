<?php
/**
 * Prompt modal: one centered dialog for save results, connection tests and
 * setup nudges.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shared .sakura-modal markup.
 *
 * The dialog reports; it does not gate anything. A prompt that blocks work
 * until answered belongs to the screen that owns the lock (see
 * S3\Config_Notice). Everything here can be dismissed three ways — the X, the
 * acknowledge button, or Escape — and none of them write to the database.
 *
 * The node must be echoed inside the page root so the accent tokens reach it.
 * There is no scrim behind the card: it floats in the middle of the viewport
 * while the page underneath stays scrollable and clickable.
 */
final class Modal {

	/**
	 * Render one prompt.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $id          Element id. Generated when omitted.
	 *     @type string $tone        ok, bad, warn, info or neutral. Drives the icon.
	 *     @type string $kicker      Small uppercase line above the title.
	 *     @type string $title       Heading. Required.
	 *     @type string $message     Body copy. May contain escaped HTML.
	 *     @type string $body        Extra markup under the copy.
	 *     @type string $size        Empty for the 560px card, "compact" for 440px.
	 *     @type string $button      Label of the acknowledge button.
	 *     @type string $variant     Button variant for the acknowledge control.
	 *     @type bool   $autoshow    Open on load. Default true.
	 *     @type string $return_to   CSS selector for the focus target on close.
	 *     @type string $class       Extra classes on the root.
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$title = isset( $args['title'] ) ? (string) $args['title'] : '';
		if ( '' === $title ) {
			return;
		}

		$id     = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : 'sakura-modal-' . substr( md5( $title ), 0, 8 );
		$tone   = self::tone( isset( $args['tone'] ) ? (string) $args['tone'] : 'info' );
		$kicker = isset( $args['kicker'] ) ? (string) $args['kicker'] : '';
		$text   = isset( $args['message'] ) ? (string) $args['message'] : '';
		$body   = isset( $args['body'] ) ? (string) $args['body'] : '';
		$size   = isset( $args['size'] ) && 'compact' === $args['size'] ? 'compact' : '';
		$class  = isset( $args['class'] ) ? (string) $args['class'] : '';
		$button = isset( $args['button'] ) && '' !== (string) $args['button'] ? (string) $args['button'] : __( '知道了', 'sakura-imgopt' );
		$variant = isset( $args['variant'] ) ? sanitize_key( (string) $args['variant'] ) : 'primary';
		if ( ! in_array( $variant, array( 'primary', 'ghost' ), true ) ) {
			$variant = 'primary';
		}
		$autoshow = ! isset( $args['autoshow'] ) || (bool) $args['autoshow'];

		$title_id = $id . '-title';
		$desc_id  = $id . '-desc';

		$classes = 'sakura-modal';
		if ( '' !== $class ) {
			$classes .= ' ' . $class;
		}

		echo '<div class="' . esc_attr( $classes ) . '" id="' . esc_attr( $id ) . '" hidden aria-hidden="true"';
		if ( $autoshow ) {
			echo ' data-sakura-modal-autoshow="1"';
		}
		// Where focus goes on close when the opener no longer exists (a page
		// reload moved it). Scoped to this page root by the caller's selector.
		if ( ! empty( $args['return_to'] ) ) {
			echo ' data-sakura-modal-return="' . esc_attr( (string) $args['return_to'] ) . '"';
		}
		echo '>';
		echo '<div class="sakura-modal__card" role="dialog" aria-labelledby="' . esc_attr( $title_id ) . '" aria-describedby="' . esc_attr( $desc_id ) . '"';
		if ( '' !== $size ) {
			echo ' data-modal-size="' . esc_attr( $size ) . '"';
		}
		echo ' tabindex="-1">';

		echo '<button type="button" class="sakura-modal__close" data-sakura-modal-close aria-label="' . esc_attr__( '关闭', 'sakura-imgopt' ) . '">';
		echo self::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
		echo '</button>';

		echo '<div class="sakura-modal__icon sakura-modal__icon--' . esc_attr( $tone ) . '" aria-hidden="true">';
		echo self::icon( $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
		echo '</div>';

		if ( '' !== $kicker ) {
			echo '<p class="sakura-modal__kicker">' . esc_html( $kicker ) . '</p>';
		}

		echo '<h2 id="' . esc_attr( $title_id ) . '" tabindex="-1" data-sakura-modal-focus>' . esc_html( $title ) . '</h2>';

		if ( '' !== $text ) {
			echo '<p id="' . esc_attr( $desc_id ) . '">' . wp_kses_post( $text ) . '</p>';
		} else {
			echo '<p id="' . esc_attr( $desc_id ) . '" hidden></p>';
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller passes escaped markup.

		echo '<div class="sakura-modal__actions" data-align="end">';
		echo '<button type="button" class="btn btn--' . esc_attr( $variant ) . '" data-sakura-modal-close>' . esc_html( $button ) . '</button>';
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Normalise a tone to one of the icon variants.
	 *
	 * @param string $tone Raw tone.
	 * @return string
	 */
	private static function tone( string $tone ): string {
		$map = array(
			'ok'      => 'ok',
			'success' => 'ok',
			'bad'     => 'bad',
			'error'   => 'bad',
			'warn'    => 'warn',
			'warning' => 'warn',
			'info'    => 'info',
			'neutral' => 'neutral',
		);

		$tone = sanitize_key( $tone );

		return $map[ $tone ] ?? 'info';
	}

	/**
	 * Icon paths. Stroke-only so they inherit currentColor from the icon chip.
	 *
	 * @param string $name ok, bad, warn, info, neutral or close.
	 * @return string
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'ok'      => '<path d="M5 12.5 9.5 17 19 7"/>',
			'bad'     => '<path d="M7 7l10 10M17 7 7 17"/>',
			'warn'    => '<path d="M12 4.5 21 19.5H3L12 4.5Z"/><path d="M12 10v4.2"/><path d="M12 17.1v.1"/>',
			'info'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.4"/><path d="M12 7.9v.1"/>',
			'neutral' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.4 12h7.2"/>',
			'close'   => '<path d="M6 6l12 12M18 6 6 18"/>',
		);

		$path = $paths[ $name ] ?? $paths['info'];
		// The X in the corner is a 16px control; the status icons sit in a 46px
		// chip and read better at 25px.
		$side = 'close' === $name ? 16 : 25;

		return '<svg viewBox="0 0 24 24" width="' . $side . '" height="' . $side . '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}
}
