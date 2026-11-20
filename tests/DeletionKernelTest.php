<?php
/**
 * Tests for the deletion kernel: ordering, permissions and outcomes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace {
	/**
	 * Ledger double for the kernel.
	 *
	 * Answers the row reads the repositories issue, flips the lifecycle on the
	 * conditional UPDATEs and records every statement so the tests can assert
	 * the order and the presence of each irreversible step.
	 */
	final class Deletion_Kernel_Wpdb {

		/** @var string */
		public $prefix = 't_';

		/** @var int */
		public $insert_id = 0;

		/** @var array<int,string> */
		public $queries = array();

		/** @var array<int,array<string,mixed>> */
		public $inserts = array();

		/** @var object|null */
		public $row = null;

		/** @var int */
		public $claim_affected = 1;

		/** @var int */
		public $settle_affected = 1;

		/**
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$values = array_map( static fn( $value ) => (string) $value, $args );

			return (string) $query . ( $values ? ' -- ' . implode( ',', $values ) : '' );
		}

		/**
		 * @param string $query  Query.
		 * @param string $output Output format.
		 * @param int    $y      Row index.
		 * @return object|null
		 */
		public function get_row( $query, $output = OBJECT, $y = 0 ) {
			unset( $output, $y );

			$this->queries[] = (string) $query;

			return $this->row;
		}

		/**
		 * @param string $query Query.
		 * @return string|null
		 */
		public function get_var( $query ) {
			$this->queries[] = (string) $query;

			return $this->row ? (string) $this->row->lifecycle : null;
		}

		/**
		 * @param string $query Query.
		 * @return int|bool
		 */
		public function query( $query ) {
			$sql             = (string) $query;
			$this->queries[] = $sql;

			if ( str_contains( $sql, "SET lifecycle='deleting'" ) ) {
				if ( $this->row && $this->claim_affected > 0 ) {
					$this->row->lifecycle = 'deleting';
				}

				return $this->claim_affected;
			}

			if ( str_contains( $sql, "SET lifecycle='purged'" ) ) {
				if ( $this->row && $this->settle_affected > 0 ) {
					$this->row->lifecycle     = 'purged';
					$this->row->storage_state = 'deleted';
				}

				return $this->settle_affected;
			}

			if ( str_contains( $sql, 'used_bytes = GREATEST' ) ) {
				return 1;
			}

			return true;
		}

		/**
		 * @param string              $table        Table.
		 * @param array<string,mixed> $data         Row.
		 * @param array<string,mixed> $where        Where.
		 * @param array<int,string>|null $format       Formats.
		 * @param array<int,string>|null $where_format Where formats.
		 * @return int|bool
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			unset( $data, $where, $format, $where_format );

			$this->queries[] = 'UPDATE ' . $table;

			return 1;
		}

		/**
		 * @param string              $table Table.
		 * @param array<string,mixed> $data  Row.
		 * @param array<int,string>|null $format Formats.
		 * @return int|bool
		 */
		public function insert( $table, $data, $format = null ) {
			unset( $format );

			$this->queries[] = 'INSERT INTO ' . $table;
			$this->inserts[] = (array) $data;
			$this->insert_id = count( $this->inserts );

			return 1;
		}

		/**
		 * @param string              $table  Table.
		 * @param array<string,mixed> $where  Where.
		 * @param array<int,string>|null $format Formats.
		 * @return int|bool
		 */
		public function delete( $table, $where, $format = null ) {
			unset( $where, $format );

			$this->queries[] = 'DELETE FROM ' . $table;

			return 1;
		}

		/**
		 * How many recorded statements contain a needle.
		 *
		 * @param string $needle SQL fragment.
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
	use Nboke\UserFiles\Repositories\Events;
	use Nboke\UserFiles\Repositories\Files;
	use Nboke\UserFiles\Services\Deletion;
	use Nboke\UserFiles\Services\Deletion_Context;
	use Nboke\UserFiles\Services\Deletion_Result;
	use Nboke\UserFiles\Services\Quota;
	use PHPUnit\Framework\TestCase;

	/**
	 * The one deletion kernel every plugin-initiated removal goes through.
	 */
	final class DeletionKernelTest extends TestCase {

		/** @var Deletion_Kernel_Wpdb */
		private $wpdb;

		protected function setUp(): void {
			parent::setUp();

			$this->wpdb      = new \Deletion_Kernel_Wpdb();
			$GLOBALS['wpdb'] = $this->wpdb;

			$GLOBALS['sakura_test_posts']    = array(
				322 => (object) array(
					'ID'          => 322,
					'post_type'   => 'attachment',
					'post_author' => 1,
				),
			);
			$GLOBALS['sakura_test_post_meta'] = array();
			$GLOBALS['sakura_test_user_can']  = array();

			unset( $GLOBALS['sakura_test_deleted_attachments'], $GLOBALS['sakura_test_refuse_attachment_delete'] );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'], $GLOBALS['sakura_test_posts'], $GLOBALS['sakura_test_post_meta'], $GLOBALS['sakura_test_user_can'], $GLOBALS['sakura_test_deleted_attachments'], $GLOBALS['sakura_test_refuse_attachment_delete'] );

			parent::tearDown();
		}

		/**
		 * Build a ledger row as the repository hands it over.
		 *
		 * @param string $lifecycle State.
		 * @param int    $request   Pending request id.
		 * @return object
		 */
		private function file( string $lifecycle = 'active', int $request = 0 ): object {
			return (object) array(
				'id'                 => 181,
				'attachment_id'      => 322,
				'owner_id'           => 1,
				'charged_bytes'      => 25744,
				'lifecycle'          => $lifecycle,
				'storage_state'      => 'local',
				'pending_request_id' => $request,
				'version'            => 3,
			);
		}

		/**
		 * Kernel wired to the double.
		 *
		 * @return Deletion
		 */
		private function kernel(): Deletion {
			return new Deletion( new Files(), new Quota(), new Events() );
		}

		public function test_actor_without_delete_capability_is_denied(): void {
			$this->wpdb->row                   = $this->file();
			$GLOBALS['sakura_test_user_can']   = array( 'sufm_admin_delete_files' => false );

			$result = $this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_FULL, 9 )
			);

			$this->assertSame( Deletion_Result::DENIED, $result->first_status() );
			$this->assertTrue( $result->denied() );
			$this->assertSame( 0, $this->wpdb->matching( "SET lifecycle='deleting'" ) );
			$this->assertSame( 0, $this->wpdb->matching( 'DELETE FROM t_sufm_artifacts' ) );
		}

		public function test_actor_without_delete_post_on_this_attachment_is_denied(): void {
			$this->wpdb->row                 = $this->file();
			$GLOBALS['sakura_test_user_can'] = array( 'delete_post' => false );

			$result = $this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_FULL, 9 )
			);

			$this->assertSame( Deletion_Result::DENIED, $result->first_status() );
			$this->assertSame( 0, $this->wpdb->matching( "SET lifecycle='deleting'" ) );
		}

		public function test_a_row_already_deleting_reports_in_progress(): void {
			$this->wpdb->row = $this->file( 'deleting' );

			$result = $this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_TAKEDOWN, 1, Deletion::REASON_MODERATION, false, true, Deletion::VIA_PLUGIN )
			);

			$this->assertSame( Deletion_Result::IN_PROGRESS, $result->first_status() );
			$this->assertSame( array(), (array) ( $GLOBALS['sakura_test_deleted_attachments'] ?? array() ) );
		}

		public function test_a_settled_row_reports_missing(): void {
			$this->wpdb->row = $this->file( 'purged' );

			$result = $this->kernel()->delete( array( 322 ) );

			$this->assertSame( Deletion_Result::MISSING, $result->first_status() );
			$this->assertSame( 0, $this->wpdb->matching( "SET lifecycle='deleting'" ) );

			$audit = end( $this->wpdb->inserts );
			$this->assertSame( 'missing', $audit['result'] );
			$this->assertSame( 'executor:operator', $audit['safe_detail'] );
		}

		public function test_an_unresolvable_target_reports_missing(): void {
			$this->wpdb->row = null;

			$result = $this->kernel()->delete( array( 0 ) );

			$this->assertSame( Deletion_Result::MISSING, $result->first_status() );
			$this->assertSame( 'unresolved', $result->first_error() );
		}

		public function test_a_kept_attachment_reports_partial_and_leaves_the_claim(): void {
			$this->wpdb->row                                 = $this->file();
			$GLOBALS['sakura_test_refuse_attachment_delete'] = true;

			$result = $this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_TAKEDOWN, 1, Deletion::REASON_MODERATION, false, true, Deletion::VIA_PLUGIN )
			);

			$this->assertSame( Deletion_Result::PARTIAL, $result->first_status() );
			$this->assertSame( 'attachment_kept', $result->first_error() );
			$this->assertSame( 'deleting', $this->wpdb->row->lifecycle );
			$this->assertSame( 0, $this->wpdb->matching( "SET lifecycle='purged'" ) );

			$audit = end( $this->wpdb->inserts );
			$this->assertSame( 'partial', $audit['result'] );
			$this->assertSame( 'plugin:moderation', $audit['safe_detail'] );
		}

		public function test_full_delete_removes_attachment_settles_and_audits(): void {
			$this->wpdb->row = $this->file();

			$result = $this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_FULL, 0, Deletion::REASON_OPERATOR, false, false, Deletion::VIA_CLI )
			);

			$this->assertSame( Deletion_Result::DELETED, $result->first_status() );
			$this->assertSame( 1, $result->deleted() );
			$this->assertSame( 'purged', $this->wpdb->row->lifecycle );
			$this->assertSame( 1, $this->wpdb->matching( "SET lifecycle='deleting'" ) );
			$this->assertSame( 1, $this->wpdb->matching( 'DELETE FROM t_sufm_artifacts' ) );
			$this->assertSame( 1, $this->wpdb->matching( "SET lifecycle='purged'" ) );
			$this->assertSame( 1, $this->wpdb->matching( 'used_bytes = GREATEST' ) );
			$this->assertSame( 1, $this->wpdb->matching( 'COMMIT' ) );
			$this->assertSame( 1, $this->wpdb->matching( 'INSERT INTO' ) );

			$audit = end( $this->wpdb->inserts );
			$this->assertSame( 'delete', $audit['action'] );
			$this->assertSame( 'purged', $audit['result'] );
			$this->assertSame( 'cli:operator', $audit['safe_detail'] );
		}

		public function test_the_claim_voids_a_pending_request_item(): void {
			$this->wpdb->row = $this->file( 'active', 77 );

			$this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_FULL, 0, Deletion::REASON_OPERATOR, false, false, Deletion::VIA_CLI )
			);

			$this->assertSame( 1, $this->wpdb->matching( 'attachment_gone' ) );
		}

		public function test_the_executor_keeps_ownership_of_its_own_request_items(): void {
			$this->wpdb->row = $this->file( 'active', 77 );

			$this->kernel()->delete(
				array( 322 ),
				new Deletion_Context( Deletion_Context::MODE_FULL, 0, Deletion::REASON_REQUEST, false, false, Deletion::VIA_EXECUTOR, true )
			);

			$this->assertSame( 0, $this->wpdb->matching( 'attachment_gone' ) );
		}

		public function test_context_defaults_and_via_resolution(): void {
			$default = new Deletion_Context();
			$this->assertSame( Deletion_Context::MODE_FULL, $default->mode );
			$this->assertTrue( $default->removes_attachment() );
			$this->assertSame( Deletion::VIA_EXECUTOR, $default->via() );

			$operator = new Deletion_Context( Deletion_Context::MODE_FULL, 7 );
			$this->assertSame( Deletion::VIA_PLUGIN, $operator->via() );

			$takedown = new Deletion_Context( Deletion_Context::MODE_TAKEDOWN );
			$this->assertTrue( $takedown->notify_owner );
			$this->assertSame( Deletion::VIA_PLUGIN, $takedown->via() );

			$remote = new Deletion_Context( Deletion_Context::MODE_REMOTE_ONLY );
			$this->assertFalse( $remote->removes_attachment() );

			$bogus = new Deletion_Context( 'nonsense' );
			$this->assertSame( Deletion_Context::MODE_FULL, $bogus->mode );
		}

		public function test_result_counts_and_messages(): void {
			$result = new Deletion_Result();
			$result->add( '322', Deletion_Result::DELETED, 181, 322 );
			$result->add( '323', Deletion_Result::MISSING, 182, 323 );

			$counts = $result->counts();
			$this->assertSame( 1, $counts['deleted'] );
			$this->assertSame( 1, $counts['missing'] );
			$this->assertSame( 2, $counts['total'] );
			$this->assertTrue( $result->succeeded() );
			$this->assertStringContainsString( '已删除', $result->message() );

			$denied = new Deletion_Result();
			$denied->add( '324', Deletion_Result::DENIED, 183, 324 );
			$this->assertFalse( $denied->succeeded() );
			$this->assertTrue( $denied->denied() > 0 );
		}
	}
}
