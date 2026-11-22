<?php
/**
 * Per-user admin notices for the Zibll module.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * A one-shot notice that survives the current request.
 *
 * Needed because a theme-side exit during the license probe kills the request
 * before any redirect happens: the explanation has to be waiting on the next
 * admin page load. Scoped per user so a shared dashboard does not show one
 * operator's failure to another.
 */
final class Notices {

	/**
	 * Transient prefix; the user id is appended.
	 */
	private const PREFIX = 'sakura_imgopt_zibll_notice_';

	/**
	 * Seconds a notice waits for its owner.
	 */
	private const TTL = 300;

	/**
	 * Store a notice for the current user.
	 *
	 * @param string $tone    error, warning or info.
	 * @param string $message Message.
	 * @return void
	 */
	public static function set( string $tone, string $message ): void {
		$user = self::user_id();

		if ( $user <= 0 || '' === $message || ! function_exists( 'set_transient' ) ) {
			return;
		}

		$tone = in_array( $tone, array( 'error', 'warning', 'success', 'info' ), true ) ? $tone : 'info';

		set_transient( self::PREFIX . $user, array( 'tone' => $tone, 'message' => $message ), self::TTL );
	}

	/**
	 * Read and remove the stored notice.
	 *
	 * @return array{tone:string,message:string}|array{}
	 */
	public static function take(): array {
		$user = self::user_id();

		if ( $user <= 0 || ! function_exists( 'get_transient' ) ) {
			return array();
		}

		$key    = self::PREFIX . $user;
		$notice = get_transient( $key );

		if ( false === $notice || ! is_array( $notice ) ) {
			return array();
		}

		delete_transient( $key );

		if ( ! isset( $notice['message'] ) || '' === (string) $notice['message'] ) {
			return array();
		}

		return array(
			'tone'    => (string) ( $notice['tone'] ?? 'info' ),
			'message' => (string) $notice['message'],
		);
	}

	/**
	 * Load the alert skin before the notice is printed.
	 *
	 * `render()` runs on `admin_notices`, which fires after
	 * `admin_enqueue_scripts` and on screens that do not load the plugin UI
	 * stack. This hook is the last chance to enqueue the styles the alert
	 * needs.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		if ( ! self::pending() || ! class_exists( '\Sakura\ImageOptimizer\Admin\Page_Assets' ) ) {
			return;
		}

		\Sakura\ImageOptimizer\Admin\Page_Assets::enqueue_notice_style();
	}

	/**
	 * Whether a notice is waiting for this user, without consuming it.
	 *
	 * @return bool
	 */
	private static function pending(): bool {
		$user = self::user_id();

		if ( $user <= 0 || ! function_exists( 'get_transient' ) ) {
			return false;
		}

		$notice = get_transient( self::PREFIX . $user );

		return is_array( $notice ) && isset( $notice['message'] ) && '' !== (string) $notice['message'];
	}

	/**
	 * Print a pending notice in the plugin's own alert skin.
	 *
	 * The message is waiting for this page load, but it still has to look like
	 * every other plugin notice: `Feedback::admin_notice_html()` wraps it in the
	 * `.sakura-admin` root so the tokens and alert styles apply, instead of the
	 * bare WordPress `notice notice-*` markup this used to emit.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = self::take();

		if ( array() === $notice ) {
			return;
		}

		$tones = array(
			'error'   => 'bad',
			'warning' => 'warn',
			'success' => 'ok',
			'info'    => 'info',
		);

		$tone = $tones[ (string) $notice['tone'] ] ?? 'info';

		$html = \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html(
			esc_html( (string) $notice['message'] ),
			$tone
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
	}

	/**
	 * Current user id, or 0 outside a signed-in request.
	 *
	 * @return int
	 */
	private static function user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
