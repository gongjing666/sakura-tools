<?php
/**
 * File-space settings island mount.
 *
 * The bootstrap lives outside the mount point because React replaces every
 * child of the admin main element.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string,mixed> $data Files_Settings_Payload::build() output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<script type="application/json" id="sakura-files-settings-bootstrap">';
echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG.
echo '</script>';

\Sakura\ImageOptimizer\Admin\UI::page(
	$data['page'],
	static function (): void {
		// Empty on purpose: the React tree fills the body once it mounts.
	}
);
