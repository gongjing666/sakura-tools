<?php
/**
 * Automatic reconciliation scheduling and legacy grant repair tests.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/UserFiles/Services/Space_Rules.php';
require_once dirname( __DIR__, 2 ) . '/src/UserFiles/Services/Space_Store.php';

/**
 * Minimal wpdb double for the space store schedule and audit paths.
 */
final class SpaceReconcileDatabase {

	/** @var string */
	public string $prefix = 't_';

	/** @var string */
	public string $last_error = '';

	/** @var bool */
	public bool $table_exists = true;

	/** @var array<int,array<string,mixed>> */
	public array $orders = array();

	/** @var bool */
	public bool $order_query_error = false;

	/** @var array<int,array<string,mixed>> */
	public array $grants = array();

	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	/**
	 * Keep the SQL readable for the doubles below.
	 *
	 * @param string $sql    SQL.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $sql, ...$args ) {
		return (string) $sql;
	}

	/**
	 * Escape LIKE wildcards the way wpdb does.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Emulate SHOW TABLES.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public function get_var( $sql ) {
		if ( str_contains( (string) $sql, 'SHOW TABLES' ) ) {
			return $this->table_exists ? 't_sufm_space_grants' : null;
		}

		return null;
	}

	/**
	 * Return canned order or grant rows.
	 *
	 * @param string $sql    SQL.
	 * @param mixed  $output Output format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $sql, $output = null ) {
		$sql = (string) $sql;

		if ( str_contains( $sql, 'FROM t_sufm_space_grants' ) ) {
			return array_values(
				array_filter(
					$this->grants,
					static function ( array $row ): bool {
						return 0 === strpos( (string) $row['grant_key'], 'order:' )
							&& ( 0 === (int) $row['order_id'] || '' === (string) $row['order_num'] );
					}
				)
			);
		}

		if ( str_contains( $sql, 'FROM t_zibpay_order' ) ) {
			return $this->order_query_error ? null : $this->orders;
		}

		return array();
	}

	/**
	 * Record an update instead of writing SQL.
	 *
	 * @param string              $table        Table.
	 * @param array<string,mixed> $data         Data.
	 * @param array<string,mixed> $where        Where.
	 * @param mixed               $format       Formats.
	 * @param mixed               $where_format Where formats.
	 * @return int
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array(
			'table' => (string) $table,
			'data'  => $data,
			'where' => $where,
		);

		return 1;
	}

	/**
	 * Collation used by the reconcile join.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}

/**
 * Covers the retry schedule and the order-field repair helper.
 */
final class SpaceStoreReconcileTest extends TestCase {

	/**
	 * Previous option array.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Previous wpdb.
	 *
	 * @var object|null
	 */
	private ?object $previous_wpdb = null;

	/**
	 * Fresh double.
	 *
	 * @var SpaceReconcileDatabase
	 */
	private SpaceReconcileDatabase $db;

	/**
	 * Isolate options and wpdb.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->options       = $GLOBALS['sakura_test_options'] ?? array();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new SpaceReconcileDatabase();
		$GLOBALS['wpdb']     = $this->db;

		// The grants-table probe is memoised for the whole request; a test is
		// one request, so it must not inherit the answer of the previous one.
		Space_Store::forget_grants_table();

		$GLOBALS['sakura_test_options'] = $this->options;
		unset( $GLOBALS['sakura_test_options'][ Space_Store::RECONCILE_OPTION ] );
	}

	/**
	 * Restore globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = $this->options;
		$GLOBALS['wpdb']                = $this->previous_wpdb;
	}

	/**
	 * Invoke a private method.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke( string $method, ...$args ) {
		$ref = new ReflectionMethod( Space_Store::class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( new Space_Store(), ...$args );
	}

	/**
	 * Backoff is 15m, 30m, 1h, 2h, 4h, then every 6h.
	 *
	 * @return void
	 */
	public function test_failure_backoff_series_is_capped(): void {
		$expected = array( 900, 1800, 3600, 7200, 14400, 21600, 21600, 21600 );

		foreach ( $expected as $index => $seconds ) {
			$this->assertSame( $seconds, $this->invoke( 'reconcile_delay', $index + 1 ) );
		}
	}

	/**
	 * A scalar option from 1.23 is migrated without an immediate replay.
	 *
	 * @return void
	 */
	public function test_scalar_watermark_is_migrated(): void {
		$last = time() - 120;
		$GLOBALS['sakura_test_options'][ Space_Store::RECONCILE_OPTION ] = $last;

		$state = ( new Space_Store() )->reconcile_state();

		$this->assertSame( $last, $state['last_attempt'] );
		$this->assertSame( $last + DAY_IN_SECONDS, $state['next_attempt'] );
		$this->assertSame( 0, $state['last_success'] );
	}

	/**
	 * A failed sweep stores the first backoff and alerts once.
	 *
	 * @return void
	 */
	public function test_failed_sweep_backs_off_and_alerts_once(): void {
		$this->db->table_exists = false;

		$before = time();
		$this->invoke( 'maybe_reconcile' );
		$state = $GLOBALS['sakura_test_options'][ Space_Store::RECONCILE_OPTION ];

		$this->assertIsArray( $state );
		$this->assertSame( 1, $state['failures'] );
		$this->assertSame( 0, $state['last_success'] );
		$this->assertGreaterThanOrEqual( $before + 900, $state['next_attempt'] );
		$this->assertLessThanOrEqual( time() + 900, $state['next_attempt'] );
		$this->assertGreaterThan( 0, $state['last_alert'] );

		// The lease prevents a second attempt on the same tick.
		$this->invoke( 'maybe_reconcile' );
		$again = $GLOBALS['sakura_test_options'][ Space_Store::RECONCILE_OPTION ];
		$this->assertSame( 1, $again['failures'] );
	}

	/**
	 * A successful sweep resets the failure count and waits a day.
	 *
	 * @return void
	 */
	public function test_successful_sweep_sets_daily_watermark(): void {
		$before = time();
		$this->invoke( 'maybe_reconcile' );
		$state = $GLOBALS['sakura_test_options'][ Space_Store::RECONCILE_OPTION ];

		$this->assertSame( 0, $state['failures'] );
		$this->assertGreaterThanOrEqual( $before, $state['last_success'] );
		$this->assertGreaterThanOrEqual( $before + DAY_IN_SECONDS, $state['next_attempt'] );
	}

	/**
	 * A full page reports that more orders remain.
	 *
	 * @return void
	 */
	public function test_reconcile_reports_more_rows(): void {
		$this->db->orders = array(
			array(
				'id'          => 2,
				'order_num'   => 'B',
				'user_id'     => 7,
				'order_price' => '30.00',
				'pay_time'    => '2026-01-02 10:00:00',
				'order_type'  => '21',
				'product_id'  => 'sufm_space_1',
				'pay_type'    => 'balance',
				'other'       => '',
			),
			array(
				'id'          => 1,
				'order_num'   => 'A',
				'user_id'     => 7,
				'order_price' => '30.00',
				'pay_time'    => '2026-01-01 10:00:00',
				'order_type'  => '21',
				'product_id'  => 'sufm_space_1',
				'pay_type'    => 'balance',
				'other'       => '',
			),
		);

		$result = ( new Space_Store() )->reconcile( false, 1 );

		$this->assertTrue( $result['ready'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertTrue( $result['more'] );
	}

	/**
	 * A legacy cash grant can be inspected and repaired without touching capacity.
	 *
	 * @return void
	 */
	public function test_legacy_order_grant_is_repaired(): void {
		$this->db->grants = array(
			array(
				'id'        => 9,
				'owner_id'  => 7,
				'grant_key' => 'order:1001',
				'order_id'  => 0,
				'order_num' => '',
				'source'    => 'points',
				'channel'   => 'points',
				'price'     => '0.00',
				'points'    => 0,
			),
		);
		$this->db->orders = array(
			array(
				'id'          => 55,
				'order_num'   => '1001',
				'order_type'  => '21',
				'status'      => '1',
				'user_id'     => 7,
				'order_price' => '30.00',
				'pay_type'    => 'balance',
				'product_id'  => 'sufm_space_1',
				'other'       => json_encode( array( 'bytes' => 1073741824, 'days' => 30, 'label' => '1 GB' ) ),
				'pay_detail'  => '',
			),
		);

		$store = new Space_Store();

		$dry = $store->audit_legacy_order_grants( false, 100 );
		$this->assertSame( 1, $dry['candidates'] );
		$this->assertSame( 1, $dry['pending'] );
		$this->assertSame( 0, $dry['filled'] );
		$this->assertSame( array(), $this->db->updates );
		$this->assertSame( 'pending', $dry['items'][0]['status'] );
		$this->assertSame( 55, $dry['items'][0]['order_id'] );
		$this->assertSame( 'purchase', $dry['items'][0]['source'] );
		$this->assertSame( 'balance', $dry['items'][0]['channel'] );
		$this->assertSame( 30.0, $dry['items'][0]['price'] );

		$applied = $store->audit_legacy_order_grants( true, 100 );
		$this->assertSame( 1, $applied['filled'] );
		$this->assertCount( 1, $this->db->updates );

		$data = $this->db->updates[0]['data'];
		$this->assertSame( 55, $data['order_id'] );
		$this->assertSame( '1001', $data['order_num'] );
		$this->assertSame( 'purchase', $data['source'] );
		$this->assertSame( 'balance', $data['channel'] );
		$this->assertSame( 30.0, $data['price'] );
		$this->assertSame( 0, $data['points'] );
		$this->assertArrayNotHasKey( 'bytes', $data );
		$this->assertArrayNotHasKey( 'expires_at', $data );
		$this->assertArrayNotHasKey( 'state', $data );
		$this->assertArrayNotHasKey( 'grant_key', $data );
	}

	/**
	 * A points fallback has a different key and is never rewritten.
	 *
	 * @return void
	 */
	public function test_points_fallback_is_not_a_candidate(): void {
		$this->db->grants = array(
			array(
				'id'        => 10,
				'owner_id'  => 7,
				'grant_key' => 'points:req-1',
				'order_id'  => 0,
				'order_num' => '',
				'source'    => 'points',
				'channel'   => 'points',
				'price'     => '0.00',
				'points'    => 90,
			),
		);

		$result = ( new Space_Store() )->audit_legacy_order_grants( true, 100 );

		$this->assertSame( 0, $result['candidates'] );
		$this->assertSame( 0, $result['filled'] );
		$this->assertSame( array(), $this->db->updates );
	}

	/**
	 * Missing and refunded orders stay untouched.
	 *
	 * @return void
	 */
	public function test_missing_or_refunded_orders_are_skipped(): void {
		$grant = array(
			'id'        => 11,
			'owner_id'  => 7,
			'grant_key' => 'order:2002',
			'order_id'  => 0,
			'order_num' => '',
			'source'    => 'points',
			'channel'   => 'points',
			'price'     => '0.00',
			'points'    => 0,
		);

		$this->db->grants = array( $grant );

		$missing = ( new Space_Store() )->audit_legacy_order_grants( true, 100 );
		$this->assertSame( 'missing_order', $missing['items'][0]['status'] );

		$this->db->orders = array(
			array(
				'id'          => 56,
				'order_num'   => '2002',
				'order_type'  => '21',
				'status'      => '-2',
				'user_id'     => 7,
				'order_price' => '30.00',
				'pay_type'    => 'balance',
				'product_id'  => 'sufm_space_1',
				'other'       => '',
				'pay_detail'  => '',
			),
		);

		$refunded = ( new Space_Store() )->audit_legacy_order_grants( true, 100 );
		$this->assertSame( 'order_not_paid', $refunded['items'][0]['status'] );
		$this->assertSame( array(), $this->db->updates );
	}

	/**
	 * A failed order lookup is not reported as a missing order.
	 *
	 * @return void
	 */
	public function test_order_query_error_is_reported(): void {
		$this->db->grants = array(
			array(
				'id'        => 13,
				'owner_id'  => 7,
				'grant_key' => 'order:4004',
				'order_id'  => 0,
				'order_num' => '',
				'source'    => 'points',
				'channel'   => 'points',
				'price'     => '0.00',
				'points'    => 0,
			),
		);
		$this->db->order_query_error = true;

		$result = ( new Space_Store() )->audit_legacy_order_grants( true, 100 );

		$this->assertSame( 'query_error', $result['items'][0]['status'] );
		$this->assertSame( array(), $this->db->updates );
	}

	/**
	 * A key/column disagreement is never silently overwritten.
	 *
	 * @return void
	 */
	public function test_key_mismatch_is_skipped(): void {
		$this->db->grants = array(
			array(
				'id'        => 12,
				'owner_id'  => 7,
				'grant_key' => 'order:3003',
				'order_id'  => 0,
				'order_num' => '9999',
				'source'    => 'points',
				'channel'   => 'points',
				'price'     => '0.00',
				'points'    => 0,
			),
		);

		$result = ( new Space_Store() )->audit_legacy_order_grants( true, 100 );

		$this->assertSame( 'key_mismatch', $result['items'][0]['status'] );
		$this->assertSame( array(), $this->db->updates );
	}
}
