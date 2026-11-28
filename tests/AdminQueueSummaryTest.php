<?php
/**
 * Admin queue response tests: constant SQL count and summary/items shape.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Rest\Controller;
use PHPUnit\Framework\TestCase;

final class AdminQueueSummaryDatabase {
	public string $prefix = 'test_';
	public string $users  = 'wp_users';
	public array $prepared = array();
	public array $queries  = array();
	public array $results  = array();
	public array $vars     = array();

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
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

final class AdminQueueSummaryTest extends TestCase {
	private $previous_wpdb;
	private AdminQueueSummaryDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new AdminQueueSummaryDatabase();
		$GLOBALS['wpdb']     = $this->db;
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

	private function controller(): Controller {
		$reflection = new ReflectionClass( Controller::class );
		$controller = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'requests' );
		$property->setAccessible( true );
		$property->setValue( $controller, $this->repository() );

		return $controller;
	}

	private function rows( int $count ): array {
		$rows = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = (object) array(
				'id'                => 100 + $i,
				'owner_id'          => 1,
				'status'            => 'pending_review',
				'reason'            => '',
				'created_at'        => '2026-11-27 10:00:00',
				'reviewed_at'       => '',
				'approval_revision' => 0,
				'batch_id'          => 0,
				'owner_name'        => 'Alice',
				'owner_login'       => 'alice',
			);
		}

		return $rows;
	}

	public function test_admin_queue_uses_four_queries_for_one_and_many_rows(): void {
		foreach ( array( 1, 25 ) as $count ) {
			$this->db->queries  = array();
			$this->db->prepared = array();
			$this->db->vars     = array();
			$this->db->results  = array(
				$this->rows( $count ),
				array( (object) array( 'request_id' => 101, 'item_count' => '2', 'bytes' => '4096' ) ),
				array( (object) array( 'status' => 'pending_review', 'total' => (string) $count ) ),
			);

			$response = $this->controller()->admin_queue( new WP_REST_Request( array( 'page' => 1, 'per_page' => 20 ) ) )->get_data();

			$this->assertCount( 4, $this->db->queries );
			$this->assertCount( 2, $this->db->prepared );
			$this->assertCount( $count, $response['rows'] );
			$this->assertSame( array(), $response['rows'][0]['items'] );
			$this->assertSame( 2, $response['rows'][0]['item_count'] );
			$this->assertSame( 4096, $response['rows'][0]['bytes'] );
			$this->assertSame( $count, $response['counts']['pending_review'] );
			$this->assertSame( 0, $response['counts']['approved'] );

			if ( $count > 1 ) {
				$this->assertSame( 0, $response['rows'][1]['item_count'] );
				$this->assertSame( 0, $response['rows'][1]['bytes'] );
			}

			$sql = implode( "\n", $this->db->queries );
			$this->assertStringContainsString( 'GROUP BY request_id', $sql );
			$this->assertStringContainsString( 'GROUP BY status', $sql );
			$this->assertStringNotContainsString( 'manifest_snapshot', $sql );
		}
	}

	public function test_default_describe_request_still_loads_items_for_user_payloads(): void {
		$this->db->results = array(
			array(
				(object) array( 'id' => 1, 'object_type' => 'file', 'object_id' => 9, 'file_name' => 'a.jpg', 'folder_name' => null, 'state' => 'pending', 'result' => '', 'charged_bytes' => 100 ),
				(object) array( 'id' => 2, 'object_type' => 'folder', 'object_id' => 7, 'file_name' => null, 'folder_name' => 'docs', 'state' => 'pending', 'result' => '', 'charged_bytes' => 0 ),
			),
		);
		$this->db->vars = array( '123' );

		$method = new ReflectionMethod( Controller::class, 'describe_request' );
		$method->setAccessible( true );
		$out = $method->invoke(
			$this->controller(),
			(object) array(
				'id'                => 5,
				'owner_id'          => 1,
				'status'            => 'pending_review',
				'reason'            => 'r',
				'created_at'        => 'c',
				'reviewed_at'       => '',
				'approval_revision' => 0,
				'batch_id'          => 0,
				'owner_name'        => 'Alice',
				'owner_login'       => 'alice',
			)
		);

		$this->assertCount( 2, $out['items'] );
		$this->assertSame( 2, $out['item_count'] );
		$this->assertSame( 123, $out['bytes'] );
		$this->assertSame( 'a.jpg', $out['items'][0]['name'] );
	}
}
