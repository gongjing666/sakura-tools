<?php
/**
 * Tab gate contract tests.
 *
 * The gate is two-dimensional: environment (theme available) and user capability.
 * These tests only pin the parts that must never regress for the legacy tabs.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers Registry::tab_gate() / tab_meta().
 */
final class TabGateTest extends TestCase {

	/**
	 * Skips when the WordPress runtime (or the plugin autoloader) is missing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( '__' ) || ! function_exists( 'apply_filters' ) ) {
			$this->markTestSkipped( 'WordPress runtime is not available.' );
		}

		if ( ! class_exists( 'Sakura\\ImageOptimizer\\Admin\\Registry' ) ) {
			$this->markTestSkipped( 'Registry class is not autoloadable.' );
		}
	}

	/**
	 * A legacy tab without a gate must stay enabled with an empty note / code.
	 *
	 * @return void
	 */
	public function test_legacy_tab_without_gate_is_enabled(): void {
		$gate = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'settings' );

		$this->assertTrue( $gate['enabled'] );
		$this->assertSame( '', $gate['note'] );
		$this->assertSame( '', $gate['code'] );
		$this->assertSame( 'review', $gate['caps'] );
		$this->assertArrayHasKey( 'capable', $gate );
	}

	/**
	 * An unknown tab must fall through the fast path instead of throwing.
	 *
	 * @return void
	 */
	public function test_unknown_tab_uses_fast_path(): void {
		$gate = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'does-not-exist' );

		$this->assertTrue( $gate['enabled'] );
		$this->assertSame( '', $gate['note'] );
		$this->assertSame( '', $gate['code'] );
	}

	/**
	 * The zibll tab must expose the documented five-key gate contract.
	 *
	 * @return void
	 */
	public function test_zibll_tab_exposes_gate_contract(): void {
		$gate = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'zibll' );

		$this->assertCount( 5, $gate );
		$this->assertArrayHasKey( 'enabled', $gate );
		$this->assertArrayHasKey( 'note', $gate );
		$this->assertArrayHasKey( 'code', $gate );
		$this->assertArrayHasKey( 'caps', $gate );
		$this->assertArrayHasKey( 'capable', $gate );

		$this->assertSame( 'zibll', $gate['code'] );
		$this->assertSame( 'manage', $gate['caps'] );
	}

	/**
	 * Outside a Zibll site the tab must be closed and explain why.
	 *
	 * The assertion is conditional so the test stays valid on a Zibll checkout.
	 *
	 * @return void
	 */
	public function test_zibll_tab_is_closed_outside_zibll(): void {
		if ( ! class_exists( 'Nboke\\UserFiles\\Services\\Zibll_Bridge' ) ) {
			$this->markTestSkipped( 'Zibll_Bridge is not autoloadable.' );
		}

		if ( \Nboke\UserFiles\Services\Zibll_Bridge::instance()->is_zibll_theme() ) {
			$this->markTestSkipped( 'Active theme is Zibll; the closed branch cannot be exercised here.' );
		}

		$gate = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'zibll' );

		$this->assertFalse( $gate['enabled'] );
		$this->assertNotSame( '', $gate['note'] );
	}

	/**
	 * tab_meta() returns the raw definition, including the label.
	 *
	 * @return void
	 */
	public function test_review_only_user_can_open_queue_but_not_the_audit_log(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'                => false,
			'sufm_admin_review_deletions'   => true,
			'sufm_admin_delete_files'       => false,
			'sufm_view_audit'               => false,
		);

		$queue = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'queue' );
		$log   = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'log' );

		$this->assertSame( 'review', $queue['caps'] );
		$this->assertTrue( $queue['capable'] );
		$this->assertSame( 'audit', $log['caps'] );
		$this->assertFalse( $log['capable'] );
	}

	public function test_audit_capability_opens_the_log_tab_without_delete(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'              => false,
			'sufm_admin_review_deletions' => true,
			'sufm_admin_delete_files'     => false,
			'sufm_view_audit'             => true,
		);

		$log = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'log' );

		$this->assertSame( 'audit', $log['caps'] );
		$this->assertTrue( $log['capable'] );
	}

	public function test_direct_delete_alone_no_longer_opens_the_audit_tab(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'          => false,
			'sufm_admin_delete_files' => true,
			'sufm_view_audit'         => false,
		);

		$log = \Sakura\ImageOptimizer\Admin\Registry::tab_gate( 'files', 'log' );

		$this->assertFalse( $log['capable'] );
	}

	public function test_tab_meta_returns_definition(): void {
		$meta = \Sakura\ImageOptimizer\Admin\Registry::tab_meta( 'files', 'zibll' );

		$this->assertArrayHasKey( 'label', $meta );
		$this->assertArrayHasKey( 'gate', $meta );
		$this->assertSame( array(), \Sakura\ImageOptimizer\Admin\Registry::tab_meta( 'files', 'nope' ) );
	}
}
