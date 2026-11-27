#!/usr/bin/env php
<?php
/**
 * Dump the chrome description the admin shell renders from.
 *
 * Called by bin/admin-shell-fixture.mjs. The server is the source of the data:
 * the fixture is generated from this output, so a chrome change can never be
 * "fixed" by editing the fixture by hand.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );

require SAKURA_TOOLS_PATH . 'tests/bootstrap.php';
require SAKURA_TOOLS_PATH . 'src/Admin/Route.php';
require SAKURA_TOOLS_PATH . 'src/Admin/Registry.php';
require SAKURA_TOOLS_PATH . 'src/Admin/UI.php';
require SAKURA_TOOLS_PATH . 'src/Admin/Shell.php';

$args = array(
	'status'  => array(
		'text' => __( '环境可用 · PHP 8.2', 'sakura-imgopt' ),
		'tone' => 'ok',
		'id'   => 'sakura-shell-fixture-status',
	),
	'actions' => array(
		array(
			'label' => __( '重新检测', 'sakura-imgopt' ),
			'url'   => 'https://example.test/wp-admin/admin.php?page=sakura-tools',
		),
	),
);

$chrome = \Sakura\ImageOptimizer\Admin\Shell::header_data( 'system', 'settings', $args );

echo wp_json_encode(
	array(
		'module' => 'system',
		'tab'    => 'settings',
		'args'   => $args,
		'chrome' => $chrome,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";
