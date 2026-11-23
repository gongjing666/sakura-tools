<?php
/**
 * System settings view: mount point for the React page.
 *
 * Included from System_Settings_Module::render_system_settings(). The island
 * draws the top bar, the tab strip, the page head and the cards from the
 * bootstrap JSON below; the server keeps the root node, the menu highlight and
 * WordPress' own settings-notice fallback.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $payload System_Payload::build() output.
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

/*
 * The bootstrap lives outside the mount node: React takes over the children of
 * .sakura-admin, so anything inside it would be replaced on hydration.
 */
echo '<script type="application/json" id="sakura-system-bootstrap">';
echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG.
echo '</script>';

UI::page(
	$payload['page'],
	static function () {
		/*
		 * Nothing to print. Page::render() already draws the ghost top bar, the
		 * page head and the boot marker beside the actions; this body exists so the
		 * island has an empty .page-body to fill.
		 */
	}
);
