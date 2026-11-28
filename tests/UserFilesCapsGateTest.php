<?php
/**
 * Capability-matrix REST gate: must match the admin-post handler.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Rest\Controller;
use PHPUnit\Framework\TestCase;

/**
 * The REST route that saves the matrix used to accept manage_options alone,
 * while Capability_Matrix::save() also requires the review capability.
 */
final class UserFilesCapsGateTest extends TestCase {

	/**
	 * Reset the recorded routes and register the space routes once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_rest_routes'] = array();
		Controller::register_space_routes();
	}

	/**
	 * Drop the fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_can'], $GLOBALS['sakura_test_rest_routes'] );

		parent::tearDown();
	}

	/**
	 * The caps route must point at the review-aware gate.
	 *
	 * @return void
	 */
	public function test_caps_route_uses_the_review_aware_gate(): void {
		$args = $GLOBALS['sakura_test_rest_routes'][ Controller::NS . '/admin/caps' ] ?? array();

		self::assertSame( array( Controller::class, 'space_can_edit_caps' ), $args['permission_callback'] ?? null );
	}

	/**
	 * An administrator who lost the review capability cannot edit the matrix.
	 *
	 * @return void
	 */
	public function test_manage_options_only_is_rejected(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'      => true,
			Capabilities::REVIEW  => false,
		);

		self::assertFalse( Controller::space_can_edit_caps() );
	}

	/**
	 * An administrator who can also review may edit the matrix.
	 *
	 * @return void
	 */
	public function test_manage_options_with_review_is_allowed(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'      => true,
			Capabilities::REVIEW  => true,
		);

		self::assertTrue( Controller::space_can_edit_caps() );
	}

	/**
	 * Review alone is not enough, exactly like the admin-post handler.
	 *
	 * @return void
	 */
	public function test_review_without_manage_options_is_rejected(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'      => false,
			Capabilities::REVIEW  => true,
		);

		self::assertTrue( Controller::space_can_review(), 'The read-only ledger gate still accepts reviewers.' );
		self::assertFalse( Controller::space_can_edit_caps(), 'Saving must match Capability_Matrix::save().' );
	}

	/**
	 * The looser review gate alone would let manage_options-only through.
	 *
	 * This pins why the caps route cannot reuse space_can_review().
	 *
	 * @return void
	 */
	public function test_review_gate_alone_would_be_too_loose(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'      => true,
			Capabilities::REVIEW  => false,
		);

		self::assertTrue( Controller::space_can_review() );
		self::assertFalse( Controller::space_can_edit_caps() );
	}

	/**
	 * Money-moving grant routes keep the strict administrator-only gate.
	 *
	 * @return void
	 */
	public function test_grant_routes_keep_the_manage_only_gate(): void {
		$args = $GLOBALS['sakura_test_rest_routes'][ Controller::NS . '/admin/space/grants' ] ?? array();

		self::assertSame( array( Controller::class, 'space_can_manage' ), $args['permission_callback'] ?? null );
	}

	/**
	 * Every other space route keeps the gate it registered before the caps fix.
	 *
	 * /admin/space/grants is registered twice (GET review, POST manage) and the
	 * recorder keeps the last registration, so the write gate is pinned here and
	 * the read gate is covered by test_review_without_manage_options_is_rejected().
	 *
	 * @return void
	 */
	public function test_other_space_routes_keep_their_registered_gates(): void {
		$expected = array(
			'/space/plans'                               => 'space_can_use',
			'/space/orders'                              => 'space_can_use',
			'/space/orders/(?P<num>[A-Za-z0-9]+)/status' => 'space_can_use',
			'/space/grants'                              => 'space_can_use',
			'/space/redeem'                              => 'space_can_use',
			'/admin/space/grants'                        => 'space_can_manage',
			'/admin/space/reconcile'                     => 'space_can_review',
			'/admin/space/grants/(?P<id>\d+)/revoke'     => 'space_can_manage',
			'/admin/space/grants/(?P<id>\d+)/extend'     => 'space_can_manage',
			'/admin/caps'                                => 'space_can_edit_caps',
		);

		foreach ( $expected as $route => $callback ) {
			$args = $GLOBALS['sakura_test_rest_routes'][ Controller::NS . $route ] ?? array();

			self::assertSame(
				array( Controller::class, $callback ),
				$args['permission_callback'] ?? null,
				'Unexpected permission gate on ' . $route
			);
		}
	}

	/**
	 * The grant ledger read gate now follows user/quota management.
	 *
	 * The route recorder keeps the last registration of a shared path (the POST
	 * manage gate), so the GET callback is pinned directly here; the real HTTP
	 * matrix covers the route binding on demo.
	 *
	 * @return void
	 */
	public function test_grant_ledger_read_gate_requires_manage_users(): void {
		$GLOBALS['sakura_test_can'] = array(
			'manage_options'           => false,
			Capabilities::REVIEW       => true,
			Capabilities::MANAGE_USERS => false,
		);
		self::assertFalse( Controller::space_can_manage_users(), 'REVIEW alone must not read the money ledger.' );

		$GLOBALS['sakura_test_can'] = array(
			'manage_options'           => false,
			Capabilities::MANAGE_USERS => true,
		);
		self::assertTrue( Controller::space_can_manage_users() );

		$GLOBALS['sakura_test_can'] = array(
			'manage_options'           => true,
			Capabilities::MANAGE_USERS => false,
		);
		self::assertFalse( Controller::space_can_manage_users(), 'manage_options alone is not the ledger gate either.' );
	}
}
