<?php
/**
 * Card component for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\Admin\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * One card, optionally wrapped in its own settings form.
 *
 * The form is inside card__body and owns the fields and the save bar. An empty
 * form array means a read-only card: no form, no hidden markers, no save bar.
 */
final class Card {

	/**
	 * Render a card.
	 *
	 * @param array<string,mixed> $args Card arguments.
	 * @param callable            $body Card body.
	 * @return void
	 */
	public static function render( array $args, callable $body ): void {
		$id    = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$form  = isset( $args['form'] ) && is_array( $args['form'] ) ? $args['form'] : array();
		$save  = isset( $args['save'] ) ? (string) $args['save'] : '';
		$wide  = ! empty( $args['wide'] );
		$save_disabled = ! empty( $args['save_disabled'] );
		$class = isset( $args['class'] ) ? (string) $args['class'] : '';

		if ( '' === $id ) {
			$id = 'card-' . substr( md5( (string) ( $args['title'] ?? '' ) ), 0, 8 );
		}

		$classes = array( 'card' );
		if ( $wide ) {
			$classes[] = 'card--wide';
		}
		if ( '' !== $class ) {
			$classes[] = $class;
		}

		$anchor = isset( $args['anchor'] ) ? sanitize_key( (string) $args['anchor'] ) : '';

		echo '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" data-sakura-card="' . esc_attr( $id ) . '"';

		/*
		 * Placement is resolved on the server so the card paints at its final
		 * size on the first frame: no script, no absolute positioning, no reflow
		 * after load.
		 *
		 * order carries the reading sequence, because a view renders its cards in
		 * code order, which is not always the order the operator reads them in.
		 */
		$style = array();
		$placement = self::placement( $args, $id, $wide );
		if ( null !== $placement ) {
			/*
			 * Placement goes through custom properties, not grid-row/grid-column.
			 *
			 * Inline declarations beat any stylesheet rule, so writing the grid
			 * coordinates inline also beat the mobile media query: below 1100px the
			 * single-column rule lost and cards were squeezed into 1/12 of the
			 * screen. As variables, the stylesheet stays in charge and can drop the
			 * placement when the grid collapses to one column.
			 */
			if ( $placement[0] > 0 ) {
				$style[] = '--g-card-row:' . $placement[0];
			}
			$style[] = '--g-card-col:' . $placement[1];
			$style[] = '--g-card-span:' . $placement[2];
		}

		if ( array() !== $style ) {
			echo ' style="' . esc_attr( implode( ';', $style ) ) . '"';
		}

		if ( '' !== $anchor ) {
			echo ' id="' . esc_attr( $anchor ) . '"';
		}
		echo '>';
		self::head( $args );
		echo '<div class="card__body">';

		if ( ! empty( $form ) ) {
			self::form_open( $form, $id );
		}

		$body();

		if ( ! empty( $form ) ) {
			self::savebar( $save, $save_disabled );
			echo '</form>';
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Column and span for one card.
	 *
	 * Resolution order: an explicit grid_column argument, the placement table for
	 * the current page, then the component default (wide cards span the row).
	 *
	 * @param array<string,mixed> $args Card arguments.
	 * @param string              $id   Card id.
	 * @param bool                $wide Whether the card was declared wide.
	 * @return array{0:int,1:int,2:int}|null Row (0 = let CSS decide), column, span.
	 */
	private static function placement( array $args, string $id, bool $wide ): ?array {
		if ( ! empty( $args['grid_column'] ) && is_array( $args['grid_column'] ) && 2 === count( $args['grid_column'] ) ) {
			$parts = array_values( $args['grid_column'] );

			return array( 0, max( 1, min( 12, (int) $parts[0] ) ), max( 1, min( 12, (int) $parts[1] ) ) );
		}

		$layout = Layout::current_layout();
		if ( '' !== $layout ) {
			$placed = Layout::card( $layout, $id );
			if ( null !== $placed ) {
				return $placed;
			}
		}

		// No table entry: keep the historical default so a new card still lands
		// somewhere sane instead of collapsing to auto placement.
		return $wide ? array( 0, 1, 12 ) : null;
	}

	/**
	 * Card heading.
	 *
	 * @param array<string,mixed> $args Card arguments.
	 * @return void
	 */
	private static function head( array $args ): void {
		$kicker      = isset( $args['kicker'] ) ? (string) $args['kicker'] : '';
		$title       = isset( $args['title'] ) ? (string) $args['title'] : '';
		$description = isset( $args['description'] ) ? (string) $args['description'] : '';
		$actions     = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();

		echo '<header class="card__head' . ( '' !== $kicker ? ' card__head--kicker' : '' ) . '">';
		echo '<div class="card__heading">';
		if ( '' !== $kicker ) {
			echo '<p class="card__kicker">' . esc_html( $kicker ) . '</p>';
		}
		if ( '' !== $title ) {
			echo '<h2 class="card__title">' . esc_html( $title ) . '</h2>';
		}
		if ( '' !== $description ) {
			echo '<p class="card__desc">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		if ( ! empty( $actions ) ) {
			echo '<div class="card__actions">';
			foreach ( $actions as $action ) {
				if ( ! is_array( $action ) || empty( $action['label'] ) ) {
					continue;
				}

				$is_button = ! empty( $action['button'] );
				if ( ! $is_button && empty( $action['url'] ) ) {
					continue;
				}

				$variant = isset( $action['variant'] ) ? sanitize_key( (string) $action['variant'] ) : 'ghost';
				$variant = in_array( $variant, array( 'primary', 'ghost', 'quiet', 'danger' ), true ) ? $variant : 'ghost';
				$action_id = isset( $action['id'] ) ? sanitize_key( (string) $action['id'] ) : '';
				$tag = $is_button ? 'button' : 'a';

				if ( $is_button ) {
					echo '<button type="button" class="btn btn--' . esc_attr( $variant ) . ' btn--small"';
				} else {
					echo '<a class="btn btn--' . esc_attr( $variant ) . ' btn--small" href="' . esc_url( (string) $action['url'] ) . '"';
				}
				if ( '' !== $action_id ) {
					echo ' id="' . esc_attr( $action_id ) . '"';
				}
				if ( $is_button && ! empty( $action['disabled'] ) ) {
					echo ' disabled';
				}
				if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
					foreach ( $action['attrs'] as $attr_name => $attr_value ) {
						$attr_name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $attr_name );
						if ( '' !== $attr_name ) {
							echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"';
						}
					}
				}
				echo '>' . esc_html( (string) $action['label'] ) . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is the literal 'button' or 'a'.
			}
			echo '</div>';
		}

		echo '</header>';
	}

	/**
	 * Open the card form and print the shared save markers.
	 *
	 * @param array<string,mixed> $form Form arguments. inplace = save through fetch
	 *                                  so the page never reloads; only for a card
	 *                                  whose neighbours do not print values derived
	 *                                  from it (see docs/开发规则/03-后台UI.md §4.1).
	 * @param string              $card Card id.
	 * @return void
	 */
	private static function form_open( array $form, string $card ): void {
		$id      = isset( $form['id'] ) && '' !== (string) $form['id'] ? sanitize_key( (string) $form['id'] ) : 'sakura-card-form-' . $card;
		$action  = isset( $form['action'] ) && '' !== (string) $form['action'] ? (string) $form['action'] : admin_url( 'options.php' );
		$method  = isset( $form['method'] ) ? strtoupper( (string) $form['method'] ) : 'POST';
		$method  = in_array( $method, array( 'POST', 'GET' ), true ) ? $method : 'POST';
		$group   = isset( $form['option_group'] ) ? (string) $form['option_group'] : '';
		$markers = isset( $form['markers'] ) ? (string) $form['markers'] : 'fields';
		$marker  = isset( $form['marker_card'] ) && '' !== (string) $form['marker_card'] ? sanitize_key( (string) $form['marker_card'] ) : $card;
		$inplace = ! empty( $form['inplace'] ) && 'POST' === $method;

		echo '<form class="card__form" id="' . esc_attr( $id ) . '" method="' . esc_attr( strtolower( $method ) ) . '" action="' . esc_url( $action ) . '"';
		if ( $inplace ) {
			echo ' data-sakura-inplace="1"';
		}
		if ( ! empty( $form['enctype'] ) ) {
			echo ' enctype="' . esc_attr( (string) $form['enctype'] ) . '"';
		}
		echo '>';

		if ( '' !== $group ) {
			echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
			echo '<input type="hidden" name="action" value="update" />';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $group . '-options' ) ) . '" />';
		}

		if ( 'fields' === $markers ) {
			Card_Form::fields( $marker );
		} elseif ( 'option_fields' === $markers ) {
			Card_Form::option_fields( isset( $form['option'] ) ? (string) $form['option'] : '', $marker );
		}

		if ( ! empty( $form['hidden'] ) && is_array( $form['hidden'] ) ) {
			foreach ( $form['hidden'] as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
		}
	}

	/**
	 * Save row.
	 *
	 * A disabled bar keeps the button in place so the card does not reflow when a
	 * screen freezes it; the label stays readable and the tooltip explains why.
	 *
	 * @param string $label    Button label.
	 * @param bool   $disabled Whether the submit button is disabled.
	 * @return void
	 */
	private static function savebar( string $label, bool $disabled = false ): void {
		echo '<div class="card__savebar">';
		echo '<button type="submit" class="btn btn--primary"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( '' !== $label ? $label : __( '保存更改', 'sakura-imgopt' ) ) . '</button>';
		echo '</div>';
	}
}
