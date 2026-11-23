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
	 *     @type string $tone        Deprecated: accepted for compatibility, no longer changes the artwork.
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
		echo self::close_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
		echo '</button>';

		// 2026-11-20：所有提示框统一成「删除确认」那一副样子——左上角小鲸鱼、
		// 标题在右、正文跟在标题下。tone 因此不再影响外观（保留参数是为了不
		// 动十几处调用点，图标与色块那套在 V2 里已经删了）。
		echo '<div class="sakura-modal__head">';
		echo '<span class="sakura-modal__mascot" aria-hidden="true">';
		echo self::mascot(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
		echo '</span>';
		echo '<div class="sakura-modal__copy">';

		if ( '' !== $kicker ) {
			echo '<p class="sakura-modal__kicker">' . esc_html( $kicker ) . '</p>';
		}

		echo '<h2 id="' . esc_attr( $title_id ) . '" tabindex="-1" data-sakura-modal-focus>' . esc_html( $title ) . '</h2>';

		if ( '' !== $text ) {
			echo '<p id="' . esc_attr( $desc_id ) . '">' . wp_kses_post( $text ) . '</p>';
		} else {
			echo '<p id="' . esc_attr( $desc_id ) . '" hidden></p>';
		}

		echo '</div></div>';

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller passes escaped markup.

		echo '<div class="sakura-modal__actions" data-align="end">';
		echo '<button type="button" class="btn btn--' . esc_attr( $variant ) . '" data-sakura-modal-close>' . esc_html( $button ) . '</button>';
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * The close glyph in the corner.
	 *
	 * @return string SVG markup.
	 */
	private static function close_icon(): string {
		return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>';
	}

	/**
	 * The little whale from the design draft, shared with the JS confirm dialog.
	 *
	 * Same paths as `assets/ui/js/ui.js` → `mascotSvg()`; keeping the artwork in
	 * one shape means the two dialog implementations never drift apart.
	 *
	 * @return string SVG markup.
	 */
	public static function mascot(): string {
		return '<svg width="46" height="46" viewBox="0 0 46 46" aria-hidden="true" focusable="false">'
			. '<circle cx="23" cy="24" r="17" fill="#ffd9e6"/>'
			. '<path d="M9 15c2 2 4 3.2 6.5 3.4C18 11 28 11 30.5 18.4 33 18.2 35 17 37 15c1.4 2.2 1.8 4.6 1.4 7-2.6-.6-4.8-.2-6.6 1.2.7 6.7-4.4 12.4-11.3 12.4S8.9 30.1 9.6 23.4c-1.8-1.4-4-1.8-6.6-1.2-.4-2.4 0-4.8 1.4-7z" fill="#ffb3d1"/>'
			. '<circle cx="18.4" cy="24" r="1.9" fill="#3a2333"/><circle cx="27.6" cy="24" r="1.9" fill="#3a2333"/>'
			. '<path d="M20.4 28.6c1.7 1.5 3.5 1.5 5.2 0" stroke="#3a2333" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
			. '<circle cx="15.2" cy="27.4" r="2.4" fill="#ff8fb8" opacity=".55"/><circle cx="30.8" cy="27.4" r="2.4" fill="#ff8fb8" opacity=".55"/>'
			. '</svg>';
	}
}
