<?php
/**
 * The cost estimator island mount.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string,mixed> $data App_Manifest::payload( 'storage-cost' ) output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

echo '<script type="application/json" id="sakura-cost-bootstrap">';
echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG.
echo '</script>';

\Sakura\ImageOptimizer\Admin\UI::page(
	$data['page'],
	static function (): void {
		// Empty on purpose: the React tree fills the body once it mounts.
	}
);
