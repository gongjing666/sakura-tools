<?php
namespace Sakura\ImageOptimizer\Modules;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Backup_Payload;
use Sakura\ImageOptimizer\Admin\Image_Payload;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\Stats_Payload;
use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 图片优化模块：四个页签（规则 / 缩略图 / 备份 / 统计）的渲染与资源入队。
 *
 * 保存与校验走 REST（`src/Admin/Rest/Image_Controller.php` 与 `Admin\Image_Settings`），
 * 所以这里不再注册 Settings API：没有 `options.php` 表单，`register_setting()`
 * 和它的一整套回调只会在每次 `admin_init` 白跑一遍（1.29.0-beta-6 删除）。
 */
class Image_Optimizer_Module implements Module {

	/**
	 * Stats persistence boundary.
	 *
	 * @var Stats_Repository_Interface
	 */
	private Stats_Repository_Interface $stats;

	/**
	 * Backup persistence boundary.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Constructor.
	 *
	 * @param Stats_Repository_Interface|null  $stats  Stats repository.
	 * @param Backup_Repository_Interface|null $backup Backup repository.
	 */
	public function __construct( ?Stats_Repository_Interface $stats = null, ?Backup_Repository_Interface $backup = null ) {
		$this->stats  = $stats ?? new Stats_Repository();
		$this->backup = $backup ?? new Backup_Repository();
	}

	/**
	 * Register admin hooks with the central loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * 渲染「图片优化 · 规则」页签。
	 *
	 * @return void
	 */
	public function render_page() {
		$payload = ( new Image_Payload() )->build( 'rules' );

		require SAKURA_TOOLS_INC . 'views/settings-island.php';
	}

	/**
	 * 渲染「备份管理」页签。
	 *
	 * @return void
	 */
	public function render_backup_page() {
		$payload = ( new Backup_Payload( $this->backup ) )->build();

		require SAKURA_TOOLS_INC . 'views/settings-island.php';
	}

	/**
	 * 渲染「统计看板」页签。
	 *
	 * @return void
	 */
	public function render_stats_page() {
		$payload = ( new Stats_Payload( $this->stats ) )->build();

		require SAKURA_TOOLS_INC . 'views/settings-island.php';
	}

	/**
	 * Load the shared admin stack on the image screens.
	 *
	 * The shell bundle (admin-app.js) and the page CSS are enqueued by
	 * Page_Assets::enqueue(); this module keeps no bundle of its own.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( ! Registry::on( 'image' ) ) {
			return;
		}

		unset( $hook );
		Assets::enqueue_page();
	}
}
