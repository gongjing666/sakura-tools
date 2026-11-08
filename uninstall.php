<?php
/**
 * 樱花工具箱 — uninstall entry point.
 *
 * The persistent-data switch is stored in the
 * `sakura_imgopt_delete_data_on_uninstall` option and defaults to off.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * WordPress includes uninstall.php in a request where the plugin itself was
 * never loaded, so the PSR-4 autoloader (it lives in the main file) is not
 * registered yet. Without this require every plugin class referenced by the
 * uninstaller (currently Nboke\UserFiles\Capabilities) is a fatal error and
 * the site ends up half-uninstalled.
 */
require_once __DIR__ . '/sakura-tools.php';
require_once __DIR__ . '/src/Uninstaller.php';

\Sakura\ImageOptimizer\Uninstaller::run();
