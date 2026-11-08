<?php
/**
 * Tests for the deferred hook loader.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Support\Hook_Loader;

final class HookLoaderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_hooks'] = array(
			'actions' => array(),
			'filters' => array(),
		);
	}

	public function test_register_forwards_actions_with_priority_and_args(): void {
		$loader = new Hook_Loader();
		$loader->add_action( 'init', 'strlen', 5, 2 );
		$loader->register();

		$this->assertSame(
			array( array( 'init', 'strlen', 5, 2 ) ),
			$GLOBALS['sakura_test_hooks']['actions']
		);
	}

	public function test_register_forwards_filters(): void {
		$loader = new Hook_Loader();
		$loader->add_filter( 'the_content', 'trim', 20, 1 );
		$loader->register();

		$this->assertSame(
			array( array( 'the_content', 'trim', 20, 1 ) ),
			$GLOBALS['sakura_test_hooks']['filters']
		);
	}

	public function test_introspection_keeps_queued_hooks_without_registering(): void {
		$loader = new Hook_Loader();
		$loader->add_action( 'save_post', 'strlen' );

		$this->assertCount( 1, $loader->actions() );
		$this->assertCount( 0, $GLOBALS['sakura_test_hooks']['actions'] );
	}
}
