<?php
/**
 * The grants-table probe is answered once per request.
 *
 * The space panel renders the quota breakdown (Space_Rules) and the grants
 * list (Space_Store) on one screen; each used to run its own `SHOW TABLES`,
 * which Query Monitor reports as a duplicate query.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/UserFiles/Schema.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Space_Store.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Space_Rules.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Counts the grants-table probes and answers the aggregate reads.
 */
final class SpaceGrantsProbeDatabase {

	/**
	 * Prefix used by every table name.
	 */
	public string $prefix = 't_';

	/**
	 * Whether the grants table exists.
	 */
	public bool $exists = true;

	/**
	 * `SHOW TABLES LIKE` statements executed so far.
	 */
	public int $probes = 0;

	/**
	 * Last error reported to the caller.
	 */
	public string $last_error = '';

	/**
	 * Prepare a statement for the double.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( $query, ...$args ): string {
		return serialize( array( (string) $query, $args ) );
	}

	/**
	 * Escape a LIKE pattern.
	 *
	 * @param string $text Pattern.
	 * @return string
	 */
	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Answer probes and aggregate reads.
	 *
	 * @param string $prepared Prepared statement from `prepare()`.
	 * @return string|int|null
	 */
	public function get_var( $prepared ) {
		list( $query, $args ) = unserialize( $prepared, array( 'allowed_classes' => false ) );

		if ( false !== strpos( (string) $query, 'SHOW TABLES LIKE' ) ) {
			++$this->probes;

			$want = str_replace( '\\', '', isset( $args[0] ) ? (string) $args[0] : '' );

			return ( $this->exists && 't_sufm_space_grants' === $want ) ? 't_sufm_space_grants' : null;
		}

		// SUM(bytes) of active grants.
		return '0';
	}
}

/**
 * The store and the rules engine must share one grants-table probe.
 */
final class SpaceGrantsTableMemoTest extends TestCase {

	/**
	 * Previous $wpdb.
	 *
	 * @var object|null
	 */
	private $previous_wpdb = null;

	/**
	 * Database double.
	 */
	private SpaceGrantsProbeDatabase $db;

	/**
	 * Install the double and forget any memo left by another test.
	 */
	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new SpaceGrantsProbeDatabase();

		$GLOBALS['wpdb'] = $this->db;

		self::forget();
	}

	/**
	 * Restore $wpdb.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;

		self::forget();
	}

	/**
	 * Clear the static memo.
	 */
	private static function forget(): void {
		( new ReflectionClass( Space_Store::class ) )->setStaticPropertyValue( 'grants_table', null );
	}

	/**
	 * The panel and the quota derivation share one SHOW TABLES.
	 */
	public function test_the_store_and_the_rules_engine_share_one_probe(): void {
		$this->assertTrue( ( new Space_Store() )->has_table() );
		$this->assertSame( 0, Space_Rules::granted_bytes( 7 ) );

		$this->assertSame( 1, $this->db->probes, 'Both consumers must share one SHOW TABLES.' );
	}

	/**
	 * A second store instance reuses the answer.
	 */
	public function test_a_second_store_instance_reuses_the_answer(): void {
		$this->assertTrue( Space_Store::grants_table_exists() );
		$this->assertTrue( ( new Space_Store() )->has_table() );

		$this->assertSame( 1, $this->db->probes );
	}

	/**
	 * A table created inside the request is probed again after the installer runs.
	 */
	public function test_an_installed_table_is_probed_again(): void {
		$this->db->exists = false;

		$this->assertFalse( Space_Store::grants_table_exists() );
		$this->assertFalse( ( new Space_Store() )->has_table() );
		$this->assertSame( 1, $this->db->probes, 'A missing table is memoised too.' );

		Space_Store::forget_grants_table();
		$this->db->exists = true;

		$this->assertTrue( ( new Space_Store() )->has_table() );
		$this->assertSame( 2, $this->db->probes );
	}
}
