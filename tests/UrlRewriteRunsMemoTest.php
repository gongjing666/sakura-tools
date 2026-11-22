<?php
/**
 * Tests for the per-request memo of the URL rewrite ledger aggregates.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Repository;

require_once dirname( __DIR__ ) . '/src/S3/Url_Rewrite_Repository.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Minimal $wpdb double for the rewrite ledger.
 */
final class UrlRewriteRunsDatabase {

	public string $prefix = 't_';

	/**
	 * Aggregate rows handed back to runs().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = array(
		array(
			'run_id'     => 'r1',
			'from_host'  => 'old.test',
			'to_host'    => 'new.test',
			'total'      => '4',
			'pending'    => '2',
			'reverted'   => '1',
			'conflicts'  => '1',
			'created_at' => '2026-11-22 00:00:00',
		),
	);

	/**
	 * SELECTs that aggregated the ledger.
	 */
	public int $runs_reads = 0;

	public string $last_error = '';

	public function prepare( $sql, ...$args ): string {
		return serialize( array( (string) $sql, $args ) );
	}

	public function get_results( $prepared, $output = ARRAY_A ) {
		unset( $output );

		list( $sql, $args ) = unserialize( $prepared );

		if ( false === strpos( (string) $sql, 'GROUP BY run_id' ) || ! in_array( 't_sio_url_rewrites', (array) $args, true ) ) {
			return array();
		}

		++$this->runs_reads;

		return $this->rows;
	}

	public function delete( $table, $where, $formats = array() ) {
		unset( $table, $where, $formats );

		return 1;
	}

	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
		unset( $table, $data, $where, $formats, $where_formats );

		return 1;
	}

	public function query( $sql ) {
		unset( $sql );

		return 1;
	}
}

final class UrlRewriteRunsMemoTest extends TestCase {

	private $previous_wpdb;

	private UrlRewriteRunsDatabase $db;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		$this->db        = new UrlRewriteRunsDatabase();
		$GLOBALS['wpdb'] = $this->db;

		$this->reset_memo();
	}

	protected function tearDown(): void {
		$this->reset_memo();

		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
	}

	/**
	 * The memo is static, so it outlives one test method; every test starts cold.
	 */
	private function reset_memo(): void {
		( new ReflectionClass( Url_Rewrite_Repository::class ) )->setStaticPropertyValue( 'runs_cache', array() );
	}

	/**
	 * The JS config and the rendered table are both built in one page load;
	 * Query Monitor reported the second aggregate SELECT as a duplicate.
	 */
	public function test_the_config_and_the_render_share_one_ledger_read(): void {
		$first  = Url_Rewrite_Repository::runs();
		$second = Url_Rewrite_Repository::runs();

		$this->assertSame( 1, $this->db->runs_reads );
		$this->assertSame( $first, $second );
	}

	/**
	 * A write in the same request must be visible to the read that follows, or
	 * the "已改写 N 行" counter after a run would keep the old numbers.
	 */
	public function test_a_ledger_write_drops_the_memo(): void {
		Url_Rewrite_Repository::runs();

		Url_Rewrite_Repository::delete_run( 'r1' );

		Url_Rewrite_Repository::runs();

		$this->assertSame( 2, $this->db->runs_reads );
	}

	/**
	 * A rewrite run inserts its snapshots in bulk; that also invalidates.
	 */
	public function test_a_bulk_insert_drops_the_memo(): void {
		Url_Rewrite_Repository::runs();

		$inserted = Url_Rewrite_Repository::insert_snapshots(
			array(
				array(
					'run_id'      => 'r1',
					'from_host'   => 'old.test',
					'to_host'     => 'new.test',
					'table_name'  => 'nb_posts',
					'pk_column'   => 'ID',
					'pk_value'    => '1',
					'column_name' => 'post_content',
					'old_value'   => 'http://old.test/a.jpg',
					'new_value'   => 'http://new.test/a.jpg',
				),
			)
		);

		Url_Rewrite_Repository::runs();

		$this->assertSame( 1, $inserted );
		$this->assertSame( 2, $this->db->runs_reads );
	}

	/**
	 * Rolling a run back only flips the state column, which still moves the
	 * pending and reverted counters.
	 */
	public function test_a_state_change_drops_the_memo(): void {
		Url_Rewrite_Repository::runs();

		Url_Rewrite_Repository::set_states( array( 1, 2 ), Url_Rewrite_Repository::STATE_REVERTED );

		Url_Rewrite_Repository::runs();

		$this->assertSame( 2, $this->db->runs_reads );
	}
}
