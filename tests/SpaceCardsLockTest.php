<?php
/**
 * Space card option-lock tests: failure, serial merge and competing writer.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Admin\Space_Cards;
use Nboke\UserFiles\Services\Option_Lock;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Space_Settings;
use PHPUnit\Framework\TestCase;

final class SpaceCardsLockDatabase {
	public string $prefix = 'test_';
	public array $queries = array();
	public array $locks   = array();

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		return (string) $query;
	}

	public function get_var( $query ) {
		$sql             = (string) $query;
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'GET_LOCK' ) ) {
			return array_shift( $this->locks ) ?? '1';
		}

		if ( str_contains( $sql, 'RELEASE_LOCK' ) ) {
			return '1';
		}

		return null;
	}
}

final class SpaceCardsLockTest extends TestCase {
	private $previous_wpdb;
	private SpaceCardsLockDatabase $db;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new SpaceCardsLockDatabase();
		$GLOBALS['wpdb']     = $this->db;

		if ( ! isset( $GLOBALS['sakura_test_options'] ) || ! is_array( $GLOBALS['sakura_test_options'] ) ) {
			$GLOBALS['sakura_test_options'] = array();
		}

		unset( $GLOBALS['sakura_test_options'][ Settings::OPTION ] );
		unset( $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] );
		Space_Settings::flush();
		Option_Lock::release();
	}

	protected function tearDown(): void {
		Option_Lock::release();
		unset( $GLOBALS['sakura_test_options'][ Settings::OPTION ] );
		unset( $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] );
		Space_Settings::flush();

		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		parent::tearDown();
	}

	private function invoke( string $method, array $fields ) {
		$reflection = new ReflectionMethod( Space_Cards::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, $fields );
	}

	private function lock_queries(): array {
		return array_values(
			array_filter(
				$this->db->queries,
				static function ( string $sql ): bool {
					return str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' );
				}
			)
		);
	}

	public function test_lock_failure_aborts_without_writing(): void {
		$this->db->locks                                   = array( '0' );
		$GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] = array( 'enabled' => true );
		Space_Settings::flush();

		$result = $this->invoke( 'save_space', array( 'apply_bonuses' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sufm_space_locked', $result->get_error_code() );
		$this->assertTrue( $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ]['enabled'] );
		$this->assertArrayNotHasKey( 'apply_bonuses', $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] );
		$this->assertSame( '', Option_Lock::held() );
		$this->assertCount( 1, $this->lock_queries() );
		$this->assertStringContainsString( 'GET_LOCK', $this->lock_queries()[0] );
	}

	public function test_serial_partial_merges_keep_both_cards(): void {
		$this->db->locks = array( '1', '1' );

		$this->assertTrue( $this->invoke( 'save_space', array( 'enabled' => 1 ) ) );
		$this->assertTrue( $this->invoke( 'save_space', array( 'apply_bonuses' => 0 ) ) );

		$stored = $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ];
		$this->assertTrue( (bool) $stored['enabled'] );
		$this->assertFalse( (bool) $stored['apply_bonuses'] );
		$this->assertSame( '', Option_Lock::held() );
		$this->assertCount( 4, $this->lock_queries() );
	}

	public function test_competing_writer_is_rejected_between_serial_saves(): void {
		$GLOBALS['sakura_test_options'][ Space_Settings::OPTION ] = array(
			'enabled'       => false,
			'apply_bonuses' => false,
		);
		Space_Settings::flush();
		$this->db->locks = array( '1', '0' );

		$this->assertTrue( $this->invoke( 'save_space', array( 'enabled' => 1 ) ) );
		$result = $this->invoke( 'save_space', array( 'apply_bonuses' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$stored = $GLOBALS['sakura_test_options'][ Space_Settings::OPTION ];
		$this->assertTrue( (bool) $stored['enabled'] );
		$this->assertFalse( (bool) $stored['apply_bonuses'] );
		$this->assertSame( '', Option_Lock::held() );
	}

	public function test_entry_and_editor_cards_share_one_option_without_loss(): void {
		$this->db->locks = array( '1', '1' );

		$this->assertTrue( $this->invoke( 'save_settings', array( 'editor_enabled' => 1 ) ) );
		$this->assertTrue( $this->invoke( 'save_settings', array( 'enabled' => 0 ) ) );

		$stored = $GLOBALS['sakura_test_options'][ Settings::OPTION ];
		$this->assertSame( 1, (int) $stored['editor_enabled'] );
		$this->assertFalse( (bool) $stored['enabled'] );
	}

	public function test_public_save_propagates_lock_error(): void {
		$this->db->locks = array( '0' );

		$result = Space_Cards::save(
			Space_Cards::GATE,
			array(
				'enabled'       => 1,
				'apply_bonuses' => 1,
				'mode'          => 'cap',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sufm_space_locked', $result->get_error_code() );
		$this->assertArrayNotHasKey( Space_Settings::OPTION, $GLOBALS['sakura_test_options'] );
	}

	public function test_lock_names_are_distinct_and_bounded(): void {
		$space = Option_Lock::name( Space_Settings::OPTION );
		$base  = Option_Lock::name( Settings::OPTION );

		$this->assertLessThanOrEqual( 64, strlen( $space ) );
		$this->assertNotSame( $space, $base );
	}
}
