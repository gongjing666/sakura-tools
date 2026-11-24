<?php
/**
 * The sync center island mount.
 *
 * The bootstrap has to print outside the mount point: React replaces every
 * child of the admin main element, so a script tag inside it would disappear
 * before it could be read.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string,mixed> $payload */
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

echo '<script type="application/json" id="sakura-sync-bootstrap">';
echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON inside a script tag; the encode flags escape it.
echo '</script>';

\Sakura\ImageOptimizer\Admin\UI::page(
	$payload['page'],
	static function (): void {
		// Empty on purpose: the React tree fills the body once it mounts.
	}
);
