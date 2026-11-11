<?php
/**
 * Shared admin UI components.
 *
 * One place that owns the structural markup for page shells, cards, fields and
 * feedback. Views describe content; they do not repeat wrappers, nonces or the
 * card-save contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Card as Card_View;
use Sakura\ImageOptimizer\Admin\UI\Feedback;
use Sakura\ImageOptimizer\Admin\UI\Field as Field_View;
use Sakura\ImageOptimizer\Admin\UI\Page as Page_View;

defined( 'ABSPATH' ) || exit;

/**
 * Small, dependency-free render API for the admin screens.
 */
final class UI {

	/**
	 * Render a page shell.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @param callable            $body Main content.
	 * @return void
	 */
	public static function page( array $args, callable $body ): void {
		Page_View::render( $args, $body );
	}

	/**
	 * Render one card.
	 *
	 * @param array<string,mixed> $args Card arguments.
	 * @param callable            $body Card body.
	 * @return void
	 */
	public static function card( array $args, callable $body ): void {
		Card_View::render( $args, $body );
	}

	/**
	 * Render one field.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 * @return void
	 */
	public static function field( array $args ): void {
		Field_View::render( $args );
	}

	/**
	 * Badge.
	 *
	 * @param string $label Label.
	 * @param string $tone  ok, warn, bad, info or neutral.
	 * @return void
	 */
	public static function badge( string $label, string $tone = 'neutral' ): void {
		Feedback::badge( $label, $tone );
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
		Feedback::alert( $message, $tone, $title );
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
		Feedback::empty_state( $title, $message, $action );
	}

	/**
	 * Inline notice inside a card.
	 *
	 * @param string $message Message.
	 * @param string $tone    info, ok, warn or bad.
	 * @return void
	 */
	public static function notice( string $message, string $tone = 'info' ): void {
		Feedback::notice( $message, $tone );
	}
}
