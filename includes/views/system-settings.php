<?php
/**
 * System settings view: mount point for the React page.
 *
 * Included from System_Settings_Module::render_system_settings(). The server
 * prints the top bar and the tab strip (Shell::header()) and the first frame;
 * React takes over the children of `.sakura-admin__main` from there and draws
 * the page head and the cards from the bootstrap JSON below.
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

/*
 * The chrome is described once, at the payload's top level for the island, and
 * handed to the server frame as well: Page::render() prints the top bar from
 * `$args['chrome']`, so this is the one place that decides what the printed bar
 * says. Today's chrome carries no status pill, so the output is unchanged; the
 * wiring is what keeps a future chrome.status from appearing on one side only
 * (the hydration contract in docs/开发规则/12-React代码规范.md §12).
 */
$page_args = $payload['page'];

if ( isset( $payload['chrome'] ) && is_array( $payload['chrome'] ) ) {
	$page_args['chrome'] = $payload['chrome'];
}

UI::page(
	$page_args,
	static function () {
		/*
		 * Nothing to print. Page::render() already draws the ghost top bar, the
		 * page head and the boot marker beside the actions; this body exists so the
		 * island has an empty .page-body to fill.
		 */
	}
);
