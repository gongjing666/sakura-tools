<?php
/**
 * Plugin Name: 樱花工具箱
 * Plugin URI:  https://www.nboke.com
 * Description: 模块化 WordPress 媒体工具箱：图片压缩、缩略图控制、媒体库整理与 S3 云存储，可按需启用。
 * Version:     1.30.0-beta-1
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author:      星晴
 * Text Domain: sakura-imgopt
 * Domain Path: /languages
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── 常量 ──────────────────────────────────────────────
define( 'SAKURA_TOOLS_VERSION', '1.30.0-beta-1' );

// Fallback mount for the space expansion module (see PATCH 26).
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'Nboke\\UserFiles\\Space_Panel' ) ) {
			\Nboke\UserFiles\Space_Panel::register();
		}
	},
	20
);
define( 'SAKURA_TOOLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAKURA_TOOLS_URL', plugin_dir_url( __FILE__ ) );
define( 'SAKURA_TOOLS_INC', SAKURA_TOOLS_PATH . 'includes/' );

// ── 自动加载 ──────────────────────────────────────────
// 全部类都在 src/，按 PSR-4 映射；includes/ 只剩 PHP 视图（views/），不再有类。
// 1.29.0-beta-10~16 把原 includes/class-*.php 的 14 个类分批搬进 src/{主题}/，
// 命名空间与路径一致。原来的 `includes/class-{snake}.php` 回落分支随之删除
// （beta-16）：目录里已经没有任何类文件，留着只会让人以为还有第二套映射。
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Sakura\\ImageOptimizer\\';
		if ( 0 === strncmp( $class_name, 'Nboke\\UserFiles\\', strlen( 'Nboke\\UserFiles\\' ) ) ) {
			$relative = substr( $class_name, strlen( 'Nboke\\UserFiles\\' ) );
			$file     = SAKURA_TOOLS_PATH . 'src/UserFiles/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );

		$src_file = SAKURA_TOOLS_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $src_file ) ) {
			require_once $src_file;
		}
	}
);

// ── 激活 / 停用 ──────────────────────────────────────
register_activation_hook( __FILE__, array( \Sakura\ImageOptimizer\Activation::class, 'run' ) );
register_deactivation_hook( __FILE__, array( \Sakura\ImageOptimizer\Deactivation::class, 'run' ) );

// ── 启动 ──────────────────────────────────────────────
// 文本域在 init 上加载：WP 6.7+ 要求 load_plugin_textdomain() 不早于 init，
// 否则每次请求都会记录 _load_textdomain_just_in_time 警告。
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'sakura-imgopt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		( new \Sakura\ImageOptimizer\Plugin() )->boot();
	}
);

// ── 后台命令面板 ──────────────────────────────────────
// WordPress 6.9 起命令面板是全站后台功能：wp_enqueue_command_palette_assets()
// 挂在 admin_enqueue_scripts 上，无条件把 wp-commands / wp-core-commands 连同
// React、区块组件与数据层的一整套依赖塞进每一个后台页面。插件页面用不到命令
// 面板，因此只在插件自己的页面上卸掉这三个句柄：
//   - 隐藏的批量进度页不走 Registry，单独按 slug 放行；
//   - 其余后台页面保持原样。整站卸载会让区块编辑器失效，因为
//     wp-edit-post 把 wp-commands 声明为依赖，
//     见 wp-includes/script-loader.php 里的 $scripts->add( 'wp-edit-post', …
//   - 后台工具栏的 ⌘K 入口由核心用 wp_script_is( 'wp-core-commands' ) 判定，
//     脚本被卸载后入口自然不输出，不需要另外改菜单。
add_action(
	'admin_enqueue_scripts',
	static function (): void {
		$sakura_page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';

		if ( 'sakura-imgopt-progress' !== $sakura_page && '' === \Sakura\ImageOptimizer\Admin\Route::current_page() ) {
			return;
		}

		wp_dequeue_script( 'wp-core-commands' );
		wp_dequeue_script( 'wp-commands' );
		wp_dequeue_style( 'wp-commands' );
	},
	100
);
