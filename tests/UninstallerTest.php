<?php
/**
 * Uninstall opt-in behavior tests.
 *
 * @package Sakura_Image_Optimizer
 */

use Nboke\UserFiles\Schema;
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Uninstaller;

/**
 * Tests the persistent-data switch without touching WordPress.
 */
final class UninstallerTest extends TestCase {

	/**
	 * The switch defaults to "keep data".
	 */
	public function test_data_is_kept_by_default(): void {
		$GLOBALS['sakura_test_options'] = array();
		$this->assertFalse( $this->should_delete() );
	}

	/**
	 * A non-1 value keeps data.
	 */
	public function test_non_one_value_keeps_data(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_delete_data_on_uninstall'] = 'yes';
		$this->assertFalse( $this->should_delete() );
	}

	/**
	 * An explicit 1 opts in to deletion.
	 */
	public function test_one_deletes_data(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_delete_data_on_uninstall'] = 1;
		$this->assertTrue( $this->should_delete() );
	}

	/**
	 * Monitoring options are deleted on uninstall.
	 */
	public function test_monitoring_options_are_cleaned(): void {
		$options = Uninstaller::monitoring_options();

		$this->assertContains( 'sakura_imgopt_s3_upload_metrics', $options );
		$this->assertContains( 'sakura_imgopt_s3_alerts', $options );
		$this->assertContains( 'sakura_imgopt_s3_prometheus_token', $options );
	}

	/**
	 * Every sufm table from Schema is dropped, including file_tokens.
	 */
	public function test_drop_sufm_tables_uses_schema(): void {
		require_once dirname( __DIR__ ) . '/src/UserFiles/Schema.php';

		$had_previous = array_key_exists( 'wpdb', $GLOBALS );
		$previous     = $GLOBALS['wpdb'] ?? null;
		$db           = new class() {
			/** @var string */
			public $prefix = 't_';

			/** @var array<int,string> */
			public $queries = array();

			/**
			 * @param string $sql SQL statement.
			 * @return bool
			 */
			public function query( $sql ) {
				$this->queries[] = (string) $sql;
				return true;
			}
		};
		$GLOBALS['wpdb'] = $db;

		try {
			$method = new ReflectionMethod( Uninstaller::class, 'drop_sufm_tables' );
			$method->setAccessible( true );
			$method->invoke( null );
		} finally {
			if ( $had_previous ) {
				$GLOBALS['wpdb'] = $previous;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		$this->assertCount( count( Schema::tables() ), $db->queries );
		foreach ( Schema::tables() as $table ) {
			$this->assertContains( "DROP TABLE IF EXISTS `t_sufm_{$table}`", $db->queries );
		}
		$this->assertContains( 'DROP TABLE IF EXISTS `t_sufm_file_tokens`', $db->queries );
	}

	/**
	 * Invoke the private opt-in check.
	 */
	private function should_delete(): bool {
		$method = new ReflectionMethod( Uninstaller::class, 'should_delete_data' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null );
	}
}
