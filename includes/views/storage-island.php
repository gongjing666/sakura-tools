<?php
/**
 * S3 storage island: mount point for the React page.
 *
 * Included from S3_Storage_Page::render(). The top bar and both tab rows are
 * printed by the server and never redrawn; React replaces the page head and the
 * five cards. This is the only renderer: there is no classic fallback.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $payload Storage_Payload::build() output.
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
 * .sakura-admin__main, so anything inside it would be replaced on mount.
 */
echo '<script type="application/json" id="sakura-storage-bootstrap">';
echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG.
echo '</script>';

UI::page(
	$payload['page'],
	static function () {
		/*
		 * Nothing to print. Page::render() already draws the top bar, the page
		 * head and the boot marker; this body exists so the island has an empty
		 * .page-body to fill.
		 */
	}
);
