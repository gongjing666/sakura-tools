<?php
/**
 * Feedback components for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Badges, alerts, inline notices and empty states.
 */
final class Feedback {

	/**
	 * Badge.
	 *
	 * @param string $label Label.
	 * @param string $tone  ok, warn, bad, info or neutral.
	 * @return void
	 */
	public static function badge( string $label, string $tone = 'neutral' ): void {
		$tone = self::tone( $tone );
		echo '<span class="badge badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Page-level alert.
	 *
	 * @param string $message Message.
	 * @param string $tone    info, ok, warn or bad.
	 * @param string $title   Optional title.
	 * @return void
	 */
	public static function alert( string $message, string $tone = 'info', string $title = '' ): void {
		$tone = self::tone( $tone );
		echo '<div class="alert alert--' . esc_attr( $tone ) . '" role="status">';
		if ( '' !== $title ) {
			echo '<strong class="alert__title">' . esc_html( $title ) . '</strong>';
		}
		echo '<div class="alert__body">' . wp_kses_post( $message ) . '</div>';
		echo '</div>';
	}

	/**
	 * Inline notice inside a card.
	 *
	 * @param string $message Message.
	 * @param string $tone    info, ok, warn or bad.
	 * @return void
	 */
	public static function notice( string $message, string $tone = 'info' ): void {
		$tone = self::tone( $tone );
		echo '<div class="notice-inline notice-inline--' . esc_attr( $tone ) . '">' . wp_kses_post( $message ) . '</div>';
	}

	/**
	 * Markup for a WordPress admin notice, skinned as the plugin alert.
	 *
	 * These render through the admin_notices hook on screens that have no
	 * .sakura-admin root (media library, thumbnail screens), so the wrapper
	 * carries its own root class to pick up the tokens and type.
	 *
	 * @param string $message Message (already escaped by the caller or safe HTML).
	 * @param string $tone    info, ok, warn or bad.
	 * @param string $link    Optional action URL.
	 * @param string $label   Optional action label.
	 * @return string
	 */
	public static function admin_notice_html( string $message, string $tone = 'info', string $link = '', string $label = '' ): string {
		$tone  = self::tone( $tone );
		$html  = '<div class="sakura-admin sakura-admin-notice-root"><div class="alert alert--' . esc_attr( $tone ) . '" role="status"><div class="alert__body">' . wp_kses_post( $message );
		if ( '' !== $link && '' !== $label ) {
			$html .= ' <a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div></div></div>';

		return $html;
	}

	/**
	 * Empty state.
	 *
	 * @param string              $title   Title.
	 * @param string              $message Message.
	 * @param array<string,mixed> $action  Optional action: label, url.
	 * @return void
	 */
	public static function empty_state( string $title, string $message, array $action = array() ): void {
		echo '<div class="empty-state">';
		echo '<strong class="empty-state__title">' . esc_html( $title ) . '</strong>';
		echo '<p class="empty-state__message">' . esc_html( $message ) . '</p>';
		if ( ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
			echo '<a class="btn btn--ghost btn--small" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Normalise a tone.
	 *
	 * @param string $tone Raw tone.
	 * @return string
	 */
	private static function tone( string $tone ): string {
		$map = array(
			'ok'       => 'ok',
			'success'  => 'ok',
			'warn'     => 'warn',
			'warning'  => 'warn',
			'bad'      => 'bad',
			'error'    => 'bad',
			'info'     => 'info',
			'neutral'  => 'neutral',
		);

		$tone = sanitize_key( $tone );

		return $map[ $tone ] ?? 'neutral';
	}
}
