<?php
/**
 * Page shell for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

use Sakura\ImageOptimizer\Admin\Shell;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the root node, shared chrome, page head and main content area.
 */
final class Page {

	/**
	 * Render one page.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @param callable            $body Main content.
	 * @return void
	 */
	public static function render( array $args, callable $body ): void {
		$id     = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$module = isset( $args['module'] ) ? sanitize_key( (string) $args['module'] ) : '';
		$tab    = isset( $args['tab'] ) ? sanitize_key( (string) $args['tab'] ) : '';
		$chrome = isset( $args['chrome'] ) && is_array( $args['chrome'] ) ? $args['chrome'] : array();

		echo '<div class="sakura-admin"';
		if ( '' !== $id ) {
			echo ' data-sakura-page="' . esc_attr( $id ) . '"';
		}
		echo '>';

		if ( '' !== $module ) {
			Shell::header( $module, $tab, $chrome );
		}

		echo '<main class="sakura-admin__main">';
		self::head( $args );
		echo '<div class="page-body">';
		$body();
		echo '</div>';
		echo '</main>';
		echo '</div>';
	}

	/**
	 * Page heading.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @return void
	 */
	private static function head( array $args ): void {
		$eyebrow = isset( $args['eyebrow'] ) ? (string) $args['eyebrow'] : '';
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$lede    = isset( $args['lede'] ) ? (string) $args['lede'] : '';
		$status  = isset( $args['status'] ) && is_array( $args['status'] ) ? $args['status'] : array();
		$actions = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();

		echo '<header class="page-head">';
		echo '<div class="page-head__copy">';
		if ( '' !== $eyebrow ) {
			echo '<p class="page-head__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}
		if ( '' !== $title ) {
			echo '<h1 id="sakura-page-title">' . esc_html( $title ) . '</h1>';
		}
		if ( '' !== $lede ) {
			echo '<p class="page-head__lede">' . esc_html( $lede ) . '</p>';
		}
		echo '</div>';

		if ( ! empty( $status ) || ! empty( $actions ) ) {
			echo '<aside class="page-head__aside">';
			self::status( $status );
			self::actions( $actions );
			echo '</aside>';
		}

		echo '</header>';
	}

	/**
	 * Small status card.
	 *
	 * @param array<string,mixed> $status Status arguments.
	 * @return void
	 */
	private static function status( array $status ): void {
		if ( empty( $status ) ) {
			return;
		}

		$tones = array( 'ok', 'warn', 'bad', 'neutral' );
		$tone  = isset( $status['tone'] ) ? sanitize_key( (string) $status['tone'] ) : 'neutral';
		$tone  = in_array( $tone, $tones, true ) ? $tone : 'neutral';

		echo '<div class="status-card status-card--' . esc_attr( $tone ) . '">';
		if ( ! empty( $status['label'] ) ) {
			echo '<span class="status-card__label">' . esc_html( (string) $status['label'] ) . '</span>';
		}
		$value_id = isset( $status['id'] ) ? sanitize_key( (string) $status['id'] ) : '';
		$sub_id   = isset( $status['sub_id'] ) ? sanitize_key( (string) $status['sub_id'] ) : '';

		echo '<span class="status-card__line">';
		echo '<strong class="status-card__value"';
		if ( '' !== $value_id ) {
			echo ' id="' . esc_attr( $value_id ) . '"';
		}
		echo '>' . esc_html( (string) ( $status['value'] ?? '' ) ) . '</strong>';
		if ( ! empty( $status['unit'] ) ) {
			echo '<span class="status-card__unit">' . esc_html( (string) $status['unit'] ) . '</span>';
		}
		echo '</span>';
		if ( ! empty( $status['sub'] ) ) {
			echo '<span class="status-card__sub"';
			if ( '' !== $sub_id ) {
				echo ' id="' . esc_attr( $sub_id ) . '"';
			}
			echo '>' . esc_html( (string) $status['sub'] ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Page-level actions.
	 *
	 * @param array<int,mixed> $actions Action list.
	 * @return void
	 */
	private static function actions( array $actions ): void {
		$valid = array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) && ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
				$valid[] = $action;
			}
		}

		if ( empty( $valid ) ) {
			return;
		}

		echo '<div class="page-head__actions">';
		foreach ( $valid as $action ) {
			$variant   = isset( $action['variant'] ) ? sanitize_key( (string) $action['variant'] ) : 'ghost';
			$variant   = in_array( $variant, array( 'primary', 'ghost', 'quiet', 'danger' ), true ) ? $variant : 'ghost';
			$action_id = isset( $action['id'] ) ? sanitize_key( (string) $action['id'] ) : '';

			echo '<a class="btn btn--' . esc_attr( $variant ) . '" href="' . esc_url( (string) $action['url'] ) . '"';
			if ( '' !== $action_id ) {
				echo ' id="' . esc_attr( $action_id ) . '"';
			}
			if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
				foreach ( $action['attrs'] as $attr_name => $attr_value ) {
					$attr_name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $attr_name );
					if ( '' !== $attr_name ) {
						echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"';
					}
				}
			}
			echo '>' . esc_html( (string) $action['label'] ) . '</a>';
		}
		echo '</div>';
	}
}
