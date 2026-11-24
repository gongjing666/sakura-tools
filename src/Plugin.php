<?php
/**
 * Plugin composition root.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Filesystem\File_System_Repository;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\S3\Alerting;
use Sakura\ImageOptimizer\S3\Credential_Cipher;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Prometheus;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\S3\Storage;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Sync_Center_Module;
use Sakura\ImageOptimizer\S3\Delete_Worker;
use Sakura\ImageOptimizer\S3\Upload_Observer;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Attachment_Indexer;
use Sakura\ImageOptimizer\Sync\Verify_Worker;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Sync\Scan_Service;
use Sakura\ImageOptimizer\Sync\Scan_Worker;
use Sakura\ImageOptimizer\Sync\Sync_Calibrator;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Support\Profiler;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin modules and registers their hooks.
 *
 * Modules implement the Module contract and queue their hooks on the shared
 * loader. The loader registers everything once, after all modules have been
 * wired, so the complete hook surface is visible in one place.
 */
final class Plugin {

	/**
	 * Hook registrar for infrastructure hooks.
	 *
	 * @var Hook_Loader
	 */
	private Hook_Loader $hooks;

	/**
	 * Guard against a double boot.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param Hook_Loader|null $hooks Optional hook loader for tests.
	 */
	public function __construct( ?Hook_Loader $hooks = null ) {
		$this->hooks = $hooks ?? new Hook_Loader();
	}

	/**
	 * Boot the plugin.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/*
		 * One timer for the whole boot. Query Monitor's Timing panel is the only
		 * consumer; without it Profiler::measure() is a straight call.
		 */
		Profiler::start( 'boot' );

		Credential_Cipher::migrate_legacy_option();
		Options::migrate_legacy_profile();

		// In-place updates never fire register_activation_hook(); replay the
		// activation routine when the database still carries an older version.
		Upgrade_Guard::maybe_upgrade(
			SAKURA_TOOLS_VERSION,
			static function (): void {
				Activation::run();
			}
		);

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			\Sakura\ImageOptimizer\Sync\Schema::maybe_install();
		}

		Profiler::lap( 'boot', 'schema' );

		$modules = Profiler::measure( 'modules', fn(): array => $this->build_modules() );
		foreach ( $modules as $module ) {
			$module->register_hooks( $this->hooks );
		}

		Profiler::lap( 'boot', 'hooks queued' );

		if ( is_admin() ) {
			\add_action( 'admin_init', array( \Sakura\ImageOptimizer\Sync\Scan_Worker::class, 'schedule_maintenance' ) );
			\add_action( 'admin_init', array( Action_Worker::class, 'schedule' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SAKURA_TOOLS_INC . 'class-s3-cli.php';
			if ( class_exists( '\WP_CLI' ) ) {
				\WP_CLI::add_command( 'sakura-sync', \Sakura\ImageOptimizer\Sync\CLI::class );
				\WP_CLI::add_command( 'sakura-thumb', \Sakura\ImageOptimizer\Thumbnail\CLI::class );
			}
		}

		// Backup is a legacy class (includes/class-backup.php) and still load-bearing;
		// read the manifest in sakura-tools.php before touching that directory.
		$this->hooks->add_action( 'sakura_imgopt_daily_backup_cleanup', array( Backup::class, 'clean_expired' ) );
		Profiler::measure(
			'register hooks',
			function (): void {
				$this->hooks->register();
			}
		);
		Profiler::stop( 'boot' );
	}

	/**
	 * Build the module list in registration order.
	 *
	 * Admin-only modules are not loaded on front-end or REST requests.
	 *
	 * @return array<int, \Sakura\ImageOptimizer\Contracts\Module>
	 */
	private function build_modules(): array {
		$modules   = array();
		$backup    = new Backup_Repository();
		$stats     = new Stats_Repository();
		$files     = new File_System_Repository();
		$settings  = new Settings_Repository();
		$processor = new Image_Processor( $backup, null, null, $stats, $files, $settings );

		$system_settings = null;
		$image_optimizer = null;

		if ( is_admin() ) {
			$system_settings = new Admin\System_Settings_Module();
			$image_optimizer = new Image_Optimizer_Module( $stats, $backup, $settings );
			$modules[]       = $system_settings;
			$modules[]       = $image_optimizer;

			// Routes and their renderers: the menu, tabs and breadcrumb are
			// derived from the registry, so screens only register a callable.
			Admin\Registry::register_renderer( 'system', 'settings', array( $system_settings, 'render_system_settings' ) );
			Admin\Registry::register_renderer( 'image', 'rules', array( $image_optimizer, 'render_page' ) );
			Admin\Registry::register_renderer( 'image', 'backup', array( $image_optimizer, 'render_backup_page' ) );
			Admin\Registry::register_renderer( 'image', 'stats', array( $image_optimizer, 'render_stats_page' ) );
		}

		$modules[] = new Thumbnail_Manager();
		$modules[] = $processor;

		if ( is_admin() ) {
			$modules[] = new Media_Library_Module( $processor, $backup, $stats, $files, $settings );
			// Card placement is a static table rendered straight into CSS; it
			// registers no hooks and is not a module.
		}

		$thumb_module = new Thumbnail_Optimizer_Module();
		$modules[]    = $thumb_module;

		if ( is_admin() ) {
			Admin\Registry::register_renderer( 'image', 'thumb', array( $thumb_module, 'render_page' ) );
		}

		$s3_options = new Options();
		$s3_adapter = new Storage_Adapter( $s3_options );

		$sync_index   = new Index_Repository();
		$sync_scanner = new Scan_Service( $s3_options, $s3_adapter, $sync_index );
		$sync_worker  = new Scan_Worker( $sync_scanner, $sync_index );
		$modules[]    = $sync_worker;
		$modules[]    = new Sync_Calibrator( $s3_options, $sync_index, $sync_scanner, $sync_worker );
		$modules[]    = new Action_Worker( $s3_options, $s3_adapter, $sync_index );
		$modules[]    = new Alerting();
		$modules[]    = new Prometheus();
		$modules[]    = new Attachment_Indexer( $s3_options, $s3_adapter, $sync_index );
		$modules[]    = new Verify_Worker( $s3_options, $sync_index );

		if ( is_admin() ) {
			$s3_storage  = new S3_Storage_Module();
			$sync_center = new Sync_Center_Module( $s3_options, $s3_adapter, null, $sync_index, $sync_scanner, $sync_worker );

			$modules[] = $s3_storage;
			$modules[] = $sync_center;

			$delete_queue = new S3\Delete_Queue_Panel();
			$modules[]      = $delete_queue;

			$url_rewrite = new S3\Url_Rewrite_Panel();
			$modules[]   = $url_rewrite;

			$cost_panel = new S3\Cost_Panel();
			$modules[]  = $cost_panel;

			Admin\Registry::register_renderer( 'storage', 'connection', array( $s3_storage, 'render' ) );
			Admin\Registry::register_renderer( 'storage', 'sync', array( $sync_center, 'render' ) );
			Admin\Registry::register_renderer( 'storage', 'deletes', array( $delete_queue, 'render' ) );
			Admin\Registry::register_renderer( 'storage', 'rewrite', array( $url_rewrite, 'render' ) );
			Admin\Registry::register_renderer( 'storage', 'cost', array( $cost_panel, 'render' ) );

			$zibll_admin = new Zibll\Admin_Module();
			$modules[]   = $zibll_admin;

			Admin\Registry::register_renderer( 'zibll', 'performance', array( $zibll_admin, 'render' ) );

			// The menu is registered last so every renderer exists by the time
			// WordPress asks for a screen.
			$modules[] = new Admin\Menu();
		}

		$modules[] = new Delete_Worker( $s3_options, $s3_adapter );
		$modules[] = new Upload_Observer( $s3_options );
		$modules[] = new Storage( $s3_options, $s3_adapter );

		// 子比增强：请求旁路、缓存失效与每日复验必须在所有请求里存在。
		$modules[] = new Zibll\Runtime_Module();

		// 后台岛的 REST 路由：/wp-json/ 请求不是 admin 页面，路由不能挂在
		// is_admin() 里的模块上，否则保存永远 404。
		$modules[] = new Admin\Rest\System_Routes();
		$modules[] = new Admin\Rest\Image_Routes();
		$modules[] = new Admin\Rest\Storage_Routes();

		// 用户文件空间：前台、REST 与后台审核页都挂在这个模块上。
		$modules[] = new \Nboke\UserFiles\Module();

		return $modules;
	}
}
