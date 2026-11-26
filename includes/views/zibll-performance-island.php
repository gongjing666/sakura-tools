<?php
/**
 * React mount for the Zibll performance page.
 *
 * @package Sakura\ImageOptimizer
 *
 * @var array<string,mixed> $data Performance_Payload::build() output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<script type="application/json" id="sakura-zibll-performance-bootstrap">';
echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG.
echo '</script>';

\Sakura\ImageOptimizer\Admin\UI::page(
	$data['page'],
	static function (): void {
		// Empty on purpose: React fills the page body.
	}
);
