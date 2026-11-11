<?php
/**
 * Shared admin UI components.
 *
 * One place that owns the structural markup for heroes, cards, card forms and
 * save bars. A view describes what a card *is*; it does not repeat the wrapper
 * markup, the nonce or the card-save contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small, dependency-free render API for the admin screens.
 */
final class UI {

	/**
	 * Render a page hero.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $eyebrow Small uppercase kicker.
	 *     @type string $title   Page title.
	 *     @type string $lede    One-paragraph introduction.
	 * }
	 * @return void
	 */
	public static function hero( array $args ): void {
		$eyebrow = isset( $args['eyebrow'] ) ? (string) $args['eyebrow'] : '';
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$lede    = isset( $args['lede'] ) ? (string) $args['lede'] : '';

		echo '<section class="hero">';
		echo '<div class="hero-copy">';
		if ( '' !== $eyebrow ) {
			echo '<p class="eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $lede ) {
			echo '<p class="lede">' . esc_html( $lede ) . '</p>';
		}
		echo '</div></section>';
	}

	/**
	 * Render one card, optionally with its own save form.
	 *
	 * The body callback runs inside .card-body and, when a form is configured,
	 * inside that form. A save bar is appended after the callback so every
	 * editable card gets the same bottom-right action without repeating markup.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string   $card    Card id used by data-sakura-card and markers.
	 *     @type string   $kicker  Small uppercase section label.
	 *     @type string   $title   Card title (may contain inline HTML).
	 *     @type bool     $wide    Span the full card grid.
	 *     @type string   $class   Extra class names.
	 *     @type string   $save    Save button label. Empty means no save bar.
	 *     @type array    $form    {
	 *         @type string $id           Form id.
	 *         @type string $action       Form action. Default options.php.
	 *         @type string $method       Form method. Default post.
	 *         @type string $option_group Option group for the nonce.
	 *         @type string $markers      fields, option_fields or none.
	 *         @type string $option       Option name for option_fields.
	 *     }
	 * }
	 * @param callable            $body Card body renderer.
	 * @return void
	 */
	public static function card( array $args, callable $body ): void {
		$card   = isset( $args['card'] ) ? sanitize_key( (string) $args['card'] ) : '';
		$kicker = isset( $args['kicker'] ) ? (string) $args['kicker'] : '';
		$title  = isset( $args['title'] ) ? (string) $args['title'] : '';
		$wide   = ! empty( $args['wide'] );
		$extra  = isset( $args['class'] ) ? (string) $args['class'] : '';
		$save   = isset( $args['save'] ) ? (string) $args['save'] : '';
		$form   = isset( $args['form'] ) && is_array( $args['form'] ) ? $args['form'] : array();

		$classes = array( 'card' );
		if ( $wide ) {
			$classes[] = 'card--wide';
		}
		if ( '' !== $card ) {
			$classes[] = 'card--' . $card;
		}
		foreach ( preg_split( '/\s+/', trim( $extra ) ) as $class ) {
			if ( '' !== $class ) {
				$classes[] = sanitize_html_class( $class );
			}
		}

		echo '<section class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( '' !== $card ) {
			echo ' data-sakura-card="' . esc_attr( $card ) . '"';
		}
		echo '>';

		echo '<div class="card-head"><div>';
		if ( '' !== $kicker ) {
			echo '<p class="section-kicker">' . esc_html( $kicker ) . '</p>';
		}
		echo '<h2>' . wp_kses_post( $title ) . '</h2>';
		echo '</div></div>';

		echo '<div class="card-body">';

		if ( ! empty( $form ) ) {
			self::form_open( $form, $card );
		}

		$body();

		if ( '' !== $save ) {
			self::savebar( $save );
		}

		if ( ! empty( $form ) ) {
			echo '</form>';
		}

		echo '</div></section>';
	}

	/**
	 * Render a card save bar.
	 *
	 * @param string $label Button label.
	 * @return void
	 */
	public static function savebar( string $label ): void {
		echo '<div class="card-savebar"><button type="submit" class="btn btn--primary">' . esc_html( $label ) . '</button></div>';
	}

	/**
	 * Open a card-local form and print its nonce plus save markers.
	 *
	 * @param array<string,mixed> $form Form configuration.
	 * @param string              $card Card id.
	 * @return void
	 */
	private static function form_open( array $form, string $card ): void {
		$id      = isset( $form['id'] ) ? (string) $form['id'] : 'sakura-card-' . $card;
		$action  = isset( $form['action'] ) ? (string) $form['action'] : 'options.php';
		$method  = isset( $form['method'] ) ? (string) $form['method'] : 'post';
		$group   = isset( $form['option_group'] ) ? (string) $form['option_group'] : '';
		$markers = isset( $form['markers'] ) ? (string) $form['markers'] : 'fields';
		$option  = isset( $form['option'] ) ? (string) $form['option'] : '';

		echo '<form id="' . esc_attr( $id ) . '" method="' . esc_attr( $method ) . '" action="' . esc_attr( $action ) . '">';

		if ( '' !== $group ) {
			echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
			echo '<input type="hidden" name="action" value="update" />';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $group . '-options' ) ) . '" />';
		}

		if ( 'fields' === $markers ) {
			Card_Form::fields( $card );
		} elseif ( 'option_fields' === $markers && '' !== $option ) {
			Card_Form::option_fields( $option, $card );
		}
	}
}
