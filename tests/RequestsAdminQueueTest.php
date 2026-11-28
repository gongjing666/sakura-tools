<?php
/**
 * Request admin queue SQL tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Repositories\Requests;
use PHPUnit\Framework\TestCase;

final class RequestsAdminQueueDatabase {
	public string $prefix = 'test_';
	public string $users = 'wp_users';
	public array $prepared = array();
	public array $queries  = array();
	public array $results  = array();
	public array $vars     = array();

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		preg_match_all( '/(?<!%)%[sdf]/', (string) $query, $matches );
		if ( empty( $matches[0] ) ) {
			throw new RuntimeException( 'prepare() called without placeholders.' );
		}

		if ( count( $matches[0] ) !== count( $args ) ) {
			throw new RuntimeException( 'Placeholder/value count mismatch.' );
		}

		$this->prepared[] = array( (string) $query, array_values( $args ) );
		return (string) $query;
	}

	public function get_var( $query ) {
		$this->queries[] = (string) $query;
		return array_shift( $this->vars ) ?? '7';
	}

	public function get_results( $query ): array {
		$this->queries[] = (string) $query;
		return array_shift( $this->results ) ?? array();
	}
}

final class RequestsAdminQueueTest extends TestCase {
	private $previous_wpdb;
	private RequestsAdminQueueDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db = new RequestsAdminQueueDatabase();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		parent::tearDown();
	}

	private function repository(): Requests {
		$reflection = new ReflectionClass( Requests::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	public function test_unbatched_count_without_dynamic_filters_skips_prepare(): void {
		$result = $this->repository()->admin_queue( '', '', 1, 20, true );

		$this->assertSame( 7, $result['total'] );
		$this->assertSame( array(), $result['rows'] );
		$this->assertCount( 1, $this->db->prepared );
		$this->assertStringContainsString( 'batch_id=0', $this->db->queries[0] );
		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $this->db->prepared[0][0] );
		$this->assertCount( 2, $this->db->prepared[0][1] );
	}

	public function test_dynamic_filters_keep_count_and_page_placeholders_paired(): void {
		$this->repository()->admin_queue( 'approved', 'alice', 2, 50, false );

		$this->assertCount( 2, $this->db->prepared );
		$this->assertSame( 4, count( $this->db->prepared[0][1] ) );
		$this->assertSame( 6, count( $this->db->prepared[1][1] ) );
		$this->assertStringContainsString( 'r.status=%s', $this->db->prepared[0][0] );
		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $this->db->prepared[1][0] );
	}

	public function test_summaries_aggregate_item_count_and_file_bytes_in_one_query(): void {
		$this->db->results = array(
			array(
				(object) array( 'request_id' => 11, 'item_count' => '3', 'bytes' => '123' ),
				(object) array( 'request_id' => 12, 'item_count' => '1', 'bytes' => '0' ),
			),
		);

		$out = $this->repository()->summaries( array( 11, 12, 13, 11 ) );

		$this->assertSame( array( 'item_count' => 3, 'bytes' => 123 ), $out[11] );
		$this->assertSame( array( 'item_count' => 1, 'bytes' => 0 ), $out[12] );
		$this->assertSame( array( 'item_count' => 0, 'bytes' => 0 ), $out[13] );
		$this->assertCount( 1, $this->db->queries );
		$this->assertCount( 1, $this->db->prepared );
		$this->assertCount( 3, $this->db->prepared[0][1] );
		$this->assertStringContainsString( 'GROUP BY request_id', $this->db->queries[0] );
		$this->assertStringContainsString( "object_type='file'", $this->db->queries[0] );
		$this->assertStringNotContainsString( 'manifest_snapshot', $this->db->queries[0] );
	}

	public function test_status_counts_group_every_status_in_one_query(): void {
		$this->db->results = array(
			array(
				(object) array( 'status' => 'pending_review', 'total' => '3' ),
				(object) array( 'status' => 'approved', 'total' => '2' ),
			),
		);

		$out = $this->repository()->status_counts();

		$this->assertSame( 3, $out['pending_review'] );
		$this->assertSame( 2, $out['approved'] );
		$this->assertCount( 1, $this->db->queries );
		$this->assertCount( 0, $this->db->prepared );
		$this->assertStringContainsString( 'GROUP BY status', $this->db->queries[0] );
	}
}