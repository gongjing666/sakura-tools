<?php
/**
 * Controller-level regression for negative sanction days.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Notifications;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Rest\Controller;
use Nboke\UserFiles\Services\Sanction_Service;
use PHPUnit\Framework\TestCase;

final class SanctionDaysDatabase {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public array $inserts = array();

	public function insert( $table, $data, $formats = null ): int {
		unset( $formats );
		$this->inserts[] = array( 'table' => (string) $table, 'data' => (array) $data );
		$this->insert_id = 99;

		return 1;
	}

	public function get_row( $query ) {
		unset( $query );

		return null;
	}

	public function prepare( $query, ...$args ): string {
		unset( $args );

		return (string) $query;
	}
}

final class AdminSanctionDaysTest extends TestCase {
	private $previous_wpdb;
	private $previous_can;
	private $previous_user_can;
	private SanctionDaysDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb      = $GLOBALS['wpdb'] ?? null;
		$this->previous_can       = $GLOBALS['sakura_test_can'] ?? null;
		$this->previous_user_can  = $GLOBALS['sakura_test_user_can'] ?? null;
		$this->db                 = new SanctionDaysDatabase();
		$GLOBALS['wpdb']          = $this->db;
		$GLOBALS['sakura_test_can'] = true;
		$GLOBALS['sakura_test_user_can'] = array( 'manage_options' => false );
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		if ( null === $this->previous_can ) {
			unset( $GLOBALS['sakura_test_can'] );
		} else {
			$GLOBALS['sakura_test_can'] = $this->previous_can;
		}

		if ( null === $this->previous_user_can ) {
			unset( $GLOBALS['sakura_test_user_can'] );
		} else {
			$GLOBALS['sakura_test_user_can'] = $this->previous_user_can;
		}

		parent::tearDown();
	}

	private function controller(): Controller {
		$reflection = new ReflectionClass( Controller::class );
		$controller = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'sanctions' );
		$property->setAccessible( true );
		$property->setValue( $controller, new Sanction_Service( new Sanctions(), new Events(), new Notifications() ) );

		return $controller;
	}

	private function call( array $params ) {
		$request = new WP_REST_Request(
			array_merge(
				array(
					'id'          => 2,
					'type'        => Sanctions::TYPE_UPLOAD_BAN,
					'reason_code' => 'copyright',
					'note'        => '',
				),
				$params
			)
		);

		return $this->controller()->admin_sanction_create( $request );
	}

	public function test_negative_days_is_rejected_before_apply_and_creates_nothing(): void {
		$result = $this->call( array( 'days' => -1, 'confirm' => 'target' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sufm_sanction_days', $result->get_error_code() );
		$this->assertCount( 0, $this->db->inserts, 'A negative duration must not reach Sanction_Service::apply().' );
	}

	public function test_large_negative_days_is_also_rejected(): void {
		$result = $this->call( array( 'days' => -999999, 'confirm' => 'target' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sufm_sanction_days', $result->get_error_code() );
		$this->assertCount( 0, $this->db->inserts );
	}

	public function test_zero_days_without_confirmation_still_requires_login_name(): void {
		$result = $this->call( array( 'days' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sufm_sanction_confirm', $result->get_error_code() );
		$this->assertCount( 0, $this->db->inserts );
	}

	public function test_zero_days_with_confirmation_creates_permanent_row(): void {
		$result = $this->call( array( 'days' => 0, 'confirm' => 'target' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->db->inserts );
		$this->assertNull( $this->db->inserts[0]['data']['ends_at'] );
		$this->assertSame( 2, $this->db->inserts[0]['data']['owner_id'] );
	}

	public function test_positive_days_stays_finite_and_needs_no_confirmation(): void {
		$result = $this->call( array( 'days' => 7 ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->db->inserts );
		$this->assertNotNull( $this->db->inserts[0]['data']['ends_at'] );
		$this->assertNotSame( '', $this->db->inserts[0]['data']['ends_at'] );
	}

	public function test_warning_with_zero_days_still_needs_no_confirmation(): void {
		$result = $this->call( array( 'type' => Sanctions::TYPE_WARN, 'days' => 0 ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->db->inserts );
		$this->assertSame( Sanctions::TYPE_WARN, $this->db->inserts[0]['data']['type'] );
	}
}
