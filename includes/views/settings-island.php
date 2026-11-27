<?php
/**
 * Image optimisation island: mount point for the React page.
 *
 * Included from Image_Optimizer_Module::render_page(). The island draws the tab
 * strip, the page head and the cards from the bootstrap JSON below; the server
 * keeps the root node and the menu highlight.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $payload Image_Payload::build() output.
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
 * .sakura-admin, so anything inside it would be replaced on mount.
 */
echo '<script type="application/json" id="sakura-image-bootstrap">';
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
