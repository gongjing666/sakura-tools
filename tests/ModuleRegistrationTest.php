<?php
/**
 * Tests for the Module contract and centralized hook registration.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Image\Image_Processor;
use Sakura\ImageOptimizer\Modules\Media_Library_Module;
use Sakura\ImageOptimizer\Modules\Thumbnail_Optimizer_Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\Sync\Attachment_Indexer;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\S3\Storage_Module;
use Sakura\ImageOptimizer\Modules\Image_Optimizer_Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Thumbnail\Thumbnail_Manager;

final class ModuleRegistrationTest extends TestCase {

	/** @var object|null Previous $wpdb, restored after each test. */
	private ?object $previous_wpdb = null;

	protected function setUp(): void {
		$GLOBALS['sakura_test_hooks']   = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['sakura_test_options'] = array();

		// Modules that touch the index resolve their table names in the
		// constructor, so $wpdb->prefix has to exist even here.
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			/** @var string */
			public $prefix = 't_';
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	/**
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	private static function module_cases(): array {
		return array(
			// The module only draws its four screens: the menu comes from src/Admin
			// and the options are saved through REST, so there is no admin_init hook
			// left to assert on.
			'settings'        => array( Image_Optimizer_Module::class, 'admin_enqueue_scripts' ),
			'media_library'   => array( Media_Library_Module::class, 'wp_ajax_sakura_process_batch' ),
			'oss_thumbnail'   => array( Thumbnail_Optimizer_Module::class, 'template_redirect' ),
			'thumbnail'       => array( Thumbnail_Manager::class, 'init' ),
			'image_processor' => array( Image_Processor::class, 'wp_handle_upload_prefilter' ),
			// The connection tab saves through REST; the module only keeps the
			// monitoring forms, the sync retry endpoint and the assets hook.
			's3_settings'     => array( Storage_Module::class, 'admin_post_sakura_s3_alerts_save' ),
			// The monthly calibration owns the cron schedule filter; a missing
			// registration would silently stop the dashboard from refreshing.
			'sync_calibrator' => array( \Sakura\ImageOptimizer\Sync\Sync_Calibrator::class, 'cron_schedules' ),
		);
	}

	public function test_modules_implement_the_contract_and_queue_hooks(): void {
		foreach ( self::module_cases() as $case ) {
			[ $class, $expected_hook ] = $case;
			$module                    = new $class();
			$this->assertInstanceOf( Module::class, $module, $class );

			$loader = new Hook_Loader();
			$module->register_hooks( $loader );

			$queued = array_merge(
				array_column( $loader->actions(), 'hook' ),
				array_column( $loader->filters(), 'hook' )
			);
			$this->assertContains( $expected_hook, $queued, $class );
		}
	}

	public function test_s3_storage_only_registers_when_enabled(): void {
		$loader = new Hook_Loader();
		( new Storage( new Options( array() ) ) )->register_hooks( $loader );
		$this->assertSame( array(), $loader->actions() );
		$this->assertSame( array(), $loader->filters() );

		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'enabled'  => 'true',
			'provider' => 'cloudflare',
			'bucket'   => 'example',
			'key'      => 'key',
			'secret'   => 'secret',
			'endpoint' => 'https://s3.example.com',
		);
		$loader = new Hook_Loader();
		( new Storage( new Options( $GLOBALS['sakura_test_options']['s3_options'] ) ) )->register_hooks( $loader );
		$this->assertContains( 'add_attachment', array_column( $loader->actions(), 'hook' ) );
		$this->assertContains( 'wp_update_attachment_metadata', array_column( $loader->filters(), 'hook' ) );
	}

	public function test_attachment_indexer_drops_remote_rows_after_object_deletion(): void {
		$loader = new Hook_Loader();
		$indexer = new Attachment_Indexer( new Options( array() ), new Storage_Adapter( new Options( array() ) ), new Index_Repository() );
		$indexer->register_hooks( $loader );

		$hooks = array_column( $loader->actions(), 'hook' );
		$this->assertContains( 'delete_attachment', $hooks );
		// The delete worker settles the remote copy later; without this the row
		// would keep reporting the object as cloud-only until a full scan.
		$this->assertContains( 'sakura_imgopt_s3_object_deleted', $hooks );
	}

	public function test_constructors_do_not_register_hooks(): void {
		new Image_Optimizer_Module();
		new Media_Library_Module();
		new Thumbnail_Optimizer_Module();
		new Thumbnail_Manager();
		new Image_Processor();
		new Storage_Module();
		new Storage( new Options( array() ) );

		$this->assertSame( array(), $GLOBALS['sakura_test_hooks']['actions'] );
		$this->assertSame( array(), $GLOBALS['sakura_test_hooks']['filters'] );
	}
}
