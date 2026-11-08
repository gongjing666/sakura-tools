<?php
/**
 * History migration state helpers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\History_Migrator;

/**
 * Covers persisted-state normalization used by the UI and CLI.
 */
final class HistoryMigratorTest extends TestCase {

	/**
	 * Reset shimmed options.
	 */
	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	/**
	 * Defaults are an idle state at cursor zero.
	 */
	public function test_defaults(): void {
		$defaults = History_Migrator::defaults();
		$this->assertSame( 'idle', $defaults['status'] );
		$this->assertSame( 0, $defaults['cursor'] );
		$this->assertSame( '', $defaults['last_error'] );
	}

	/**
	 * Unknown keys and invalid values are normalized.
	 */
	public function test_normalize(): void {
		$state = History_Migrator::normalize(
			array(
				'status'    => 'weird',
				'cursor'    => '42',
				'processed' => -5,
				'queued'    => 3,
				'total'     => 100,
				'last_error'=> array( 'x' ),
			)
		);

		$this->assertSame( 'idle', $state['status'] );
		$this->assertSame( 42, $state['cursor'] );
		$this->assertSame( 0, $state['processed'] );
		$this->assertSame( 3, $state['queued'] );
		$this->assertSame( 100, $state['total'] );
		$this->assertSame( '', $state['last_error'] );
	}

	/**
	 * Stored values are read and normalized.
	 */
	public function test_saved_state(): void {
		$GLOBALS['sakura_test_options'][ History_Migrator::STATE_OPTION ] = array(
			'status'    => 'running',
			'cursor'    => 900,
			'processed' => 899,
			'total'     => 100000,
		);

		$state = History_Migrator::saved_state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 900, $state['cursor'] );
		$this->assertSame( 899, $state['processed'] );
		$this->assertSame( 100000, $state['total'] );
		$this->assertSame( 0, $state['queued'] );
	}

	/**
	 * Migration never deletes local files, whatever no_local_saving says.
	 *
	 * H-03: the S3 page promises "迁移始终保留本地文件". The worker used to read
	 * `no_local_saving` here, which deleted each local file right after the
	 * historical copy uploaded — a silent, irreversible move of the whole
	 * library into object storage. This guard keeps the promise testable.
	 */
	public function test_migration_preserves_local_files(): void {
		$this->assertFalse( History_Migrator::deletes_local_after_migration() );
	}
}
