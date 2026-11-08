<?php
/**
 * PHPStan bootstrap：声明插件运行期常量。
 *
 * WP-CLI 与 AWS SDK 的桩在 stubs/phpstan-plugin-dependencies.php 中，
 * 由 phpstan.neon 的 scanFiles 载入。
 */

defined( 'SAKURA_TOOLS_VERSION' ) || define( 'SAKURA_TOOLS_VERSION', '1.3.3' );
defined( 'SAKURA_TOOLS_PATH' ) || define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
defined( 'SAKURA_TOOLS_URL' ) || define( 'SAKURA_TOOLS_URL', 'https://example.test/wp-content/plugins/sakura-tools/' );
defined( 'SAKURA_TOOLS_INC' ) || define( 'SAKURA_TOOLS_INC', SAKURA_TOOLS_PATH . 'includes/' );
