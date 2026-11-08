<?php
/**
 * Tests for the Module contract and centralized hook registration.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Image_Processor;
use Sakura\ImageOptimizer\Media_Library_Module;
use Sakura\ImageOptimizer\Thumbnail_Optimizer_Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage;
use Sakura\ImageOptimizer\S3_Storage_Module;
use Sakura\ImageOptimizer\Image_Optimizer_Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Thumbnail_Manager;

final class ModuleRegistrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_hooks']   = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['sakura_test_options'] = array();
	}

	/**
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	private static function module_cases(): array {
		return array(
			// The settings module no longer owns a top-level menu: the unified
			// shell (src/Admin) registers it, so assert on a hook it still queues.
			'settings'        => array( Image_Optimizer_Module::class, 'admin_init' ),
			'media_library'   => array( Media_Library_Module::class, 'wp_ajax_sakura_process_batch' ),
			'oss_thumbnail'   => array( Thumbnail_Optimizer_Module::class, 'template_redirect' ),
			'thumbnail'       => array( Thumbnail_Manager::class, 'init' ),
			'image_processor' => array( Image_Processor::class, 'wp_handle_upload_prefilter' ),
			's3_settings'     => array( S3_Storage_Module::class, 'admin_post_sakura_s3_save' ),
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

	public function test_constructors_do_not_register_hooks(): void {
		new Image_Optimizer_Module();
		new Media_Library_Module();
		new Thumbnail_Optimizer_Module();
		new Thumbnail_Manager();
		new Image_Processor();
		new S3_Storage_Module();
		new Storage( new Options( array() ) );

		$this->assertSame( array(), $GLOBALS['sakura_test_hooks']['actions'] );
		$this->assertSame( array(), $GLOBALS['sakura_test_hooks']['filters'] );
	}
}
