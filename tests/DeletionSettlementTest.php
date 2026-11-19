<?php
/**
 * Tests for the single deletion settlement point and the direct-delete gate.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace {
	/**
	 * Ledger double that answers row reads and counts ledger writes.
	 *
	 * Only what settlement touches is implemented: the conditional lifecycle
	 * UPDATE, the quota UPDATE, artifact deletes and transaction statements.
	 * The row flips to purged when the lifecycle write runs, so a second
	 * settlement sees what a real connection would see.
	 */
	final class Deletion_Test_Wpdb {

		/** @var string */
		public $prefix = 't_';

		/** @var array<int,string> */
		public $queries = array();

		/** @var object|null */
		public $row = null;

		/** @var int */
		public $lifecycle_affected = 1;

		/** @var int */
		public $usage_affected = 1;

		/**
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return (string) $query;
		}

		/**
		 * @param string $query Query.
		 * @param string $output Output format.
		 * @param int    $y Row index.
		 * @return object|null
		 */
		public function get_row( $query, $output = OBJECT, $y = 0 ) {
			$this->queries[] = (string) $query;

			return $this->row;
		}

		/**
		 * @param string $query Query.
		 * @return int|bool
		 */
		public function query( $query ) {
			$sql             = (string) $query;
			$this->queries[] = $sql;

			if ( str_contains( $sql, "lifecycle='purged'" ) ) {
				if ( $this->row && $this->lifecycle_affected > 0 ) {
					$this->row->lifecycle     = 'purged';
					$this->row->storage_state = 'deleted';
				}

				return $this->lifecycle_affected;
			}

			if ( str_contains( $sql, 'used_bytes = GREATEST' ) ) {
				return $this->usage_affected;
			}

			return true;
		}

		/**
		 * @param string              $table Table.
		 * @param array<string,mixed> $data Row.
		 * @param array<string,mixed> $where Where.
		 * @param array<int,string>|null $format Formats.
		 * @param array<int,string>|null $where_format Where formats.
		 * @return int|bool
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->queries[] = 'UPDATE ' . $table;

			return 1;
		}

		/**
		 * @param string              $table Table.
		 * @param array<string,mixed> $where Where.
		 * @param array<int,string>|null $format Formats.
		 * @return int|bool
		 */
		public function delete( $table, $where, $format = null ) {
			$this->queries[] = 'DELETE FROM ' . $table;

			return 1;
		}

		/**
		 * @param string $sql Statement.
		 * @return int
		 */
		public function matching( string $needle ): int {
			$count = 0;

			foreach ( $this->queries as $sql ) {
				if ( str_contains( (string) $sql, $needle ) ) {
					++$count;
				}
			}

			return $count;
		}
	}
}

namespace Sakura\ImageOptimizer\Tests {
	use Nboke\UserFiles\Media\Media_Hooks;
	use Nboke\UserFiles\Repositories\Files;
	use Nboke\UserFiles\Services\Deletion;
	use Nboke\UserFiles\Services\Quota;
	use PHPUnit\Framework\TestCase;

	/**
	 * Deletion settlement and the direct-delete gate.
	 */
	final class DeletionSettlementTest extends TestCase {

		/** @var Deletion_Test_Wpdb */
		private $wpdb;

		protected function setUp(): void {
			parent::setUp();

			$this->wpdb         = new \Deletion_Test_Wpdb();
			$GLOBALS['wpdb']    = $this->wpdb;
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'] );

			parent::tearDown();
		}

		/**
		 * Build a ledger row as the repositories hand it over.
		 *
		 * @param string $lifecycle State.
		 * @return object
		 */
		private function file( string $lifecycle = 'active' ): object {
			return (object) array(
				'id'             => 181,
				'attachment_id'  => 322,
				'owner_id'       => 1,
				'charged_bytes'  => 25744,
				'lifecycle'      => $lifecycle,
				'storage_state'  => 'local',
			);
		}

		public function test_active_file_with_both_capabilities_is_deletable(): void {
			$this->assertTrue( Media_Hooks::may_delete_directly( $this->file( 'active' ), true, true ) );
		}

		public function test_file_being_deleted_is_not_deletable(): void {
			$this->assertFalse( Media_Hooks::may_delete_directly( $this->file( 'deleting' ), true, true ) );
		}

		public function test_purged_file_is_not_deletable(): void {
			$this->assertFalse( Media_Hooks::may_delete_directly( $this->file( 'purged' ), true, true ) );
		}

		public function test_missing_delete_capability_blocks(): void {
			$this->assertFalse( Media_Hooks::may_delete_directly( $this->file( 'active' ), false, true ) );
		}

		public function test_missing_delete_post_capability_blocks(): void {
			$this->assertFalse( Media_Hooks::may_delete_directly( $this->file( 'active' ), true, false ) );
		}

		public function test_settle_ignores_unknown_rows(): void {
			$deletion = new Deletion( new Files() );

			$this->assertFalse( $deletion->settle( 0, Deletion::VIA_WORDPRESS ) );
			$this->assertFalse( $deletion->settle( 181, Deletion::VIA_WORDPRESS ) );
			$this->assertSame( 0, $this->wpdb->matching( "lifecycle='purged'" ) );
		}

		public function test_settle_skips_a_row_someone_else_already_purged(): void {
			$this->wpdb->row = $this->file( 'purged' );

			$deletion = new Deletion( new Files(), new Quota() );

			$this->assertFalse( $deletion->settle( 181, Deletion::VIA_PLUGIN ) );
			$this->assertSame( 0, $this->wpdb->matching( 'used_bytes = GREATEST' ) );
		}

		public function test_settle_releases_the_charge_exactly_once(): void {
			$this->wpdb->row = $this->file( 'active' );

			$deletion = new Deletion( new Files(), new Quota() );

			$this->assertTrue( $deletion->settle( 181, Deletion::VIA_WORDPRESS, 1, Deletion::REASON_OPERATOR ) );
			$this->assertSame( 1, $this->wpdb->matching( 'DELETE FROM t_sufm_artifacts' ) );
			$this->assertSame( 1, $this->wpdb->matching( 'used_bytes = GREATEST' ) );
			$this->assertSame( 1, $this->wpdb->matching( 'COMMIT' ) );

			// The row is purged now, so a second settle must not move bytes again.
			$this->assertFalse( $deletion->settle( 181, Deletion::VIA_WORDPRESS, 1, Deletion::REASON_OPERATOR ) );
			$this->assertSame( 1, $this->wpdb->matching( 'used_bytes = GREATEST' ) );
		}

		public function test_losing_the_lifecycle_write_rolls_back_without_charging(): void {
			$this->wpdb->row                = $this->file( 'active' );
			$this->wpdb->lifecycle_affected = 0;

			$deletion = new Deletion( new Files(), new Quota() );

			$this->assertFalse( $deletion->settle( 181, Deletion::VIA_EXECUTOR ) );
			$this->assertSame( 1, $this->wpdb->matching( 'ROLLBACK' ) );
			$this->assertSame( 0, $this->wpdb->matching( 'used_bytes = GREATEST' ) );
		}
	}
}
