<?php
declare( strict_types=1 );
namespace Sakura\ImageOptimizer\Tests;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/src/UserFiles/Schema.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Support/Time.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Files.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Folders.php';

/** Protocol regression: fake lock acquisition can expose an earlier committed deletion. */
final class UserFilesOwnerWriteTest extends TestCase {
	private $previous_db;
	protected function setUp(): void { $this->previous_db = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = new OwnerWriteDatabase(); }
	protected function tearDown(): void { $GLOBALS['wpdb'] = $this->previous_db; }

	public function test_frontend_move_reads_source_and_target_only_after_owner_object_locks(): void {
		$db = $GLOBALS['wpdb'];
		$result = ( new Files() )->move( 7, 1, 2, new Folders() );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 2, $result->folder_id );
		$this->assertLessThan( array_search( 'read_file', $db->trace, true ), array_search( 'lock_folders', $db->trace, true ) );
		$this->assertLessThan( array_search( 'read_folder', $db->trace, true ), array_search( 'lock_folders', $db->trace, true ) );
		$this->assertSame( 1, $db->updates );
		$this->assertContains( 'COMMIT', $db->trace );
	}

	public function test_folder_deleted_before_lock_grant_is_reread_and_move_is_not_written(): void {
		$db = $GLOBALS['wpdb'];
		$db->on_folder_lock = function () use ( $db ): void { $db->folder->lifecycle = 'deleted'; };
		$result = ( new Files() )->move( 7, 1, 2, new Folders() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sufm_folder_missing', $result->get_error_code() );
		$this->assertSame( 0, $db->file->folder_id );
		$this->assertSame( 0, $db->updates );
		$this->assertContains( 'ROLLBACK', $db->trace );
		$this->assertNotContains( 'COMMIT', $db->trace );
	}

	public function test_external_transaction_is_not_committed_or_nested(): void {
		$db = $GLOBALS['wpdb']; $db->in_transaction = true;

		// The guard counts the plugin's own transactions (Db_Transaction), so
		// simulate an outer one the same way the plugin would open it.
		\Nboke\UserFiles\Support\Db_Transaction::begin();
		$db->trace = array();
		$result = ( new Files() )->move( 7, 1, 2, new Folders() );
		\Nboke\UserFiles\Support\Db_Transaction::reset();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sufm_transaction_busy', $result->get_error_code() );
		$this->assertSame( 0, $db->updates );
		$this->assertNotContains( 'START TRANSACTION', $db->trace );
		$this->assertNotContains( 'COMMIT', $db->trace );
	}

	public function test_owner_protocol_reentry_does_not_run_inner_callback(): void {
		$db = $GLOBALS['wpdb']; $folders = new Folders(); $called = false;
		$result = $folders->with_owner_write( 7, function () use ( $folders, &$called ) {
			return $folders->with_owner_write( 7, function () use ( &$called ) { $called = true; return true; } );
		} );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $called );
		$this->assertSame( 1, count( array_keys( $db->trace, 'START TRANSACTION', true ) ) );
		$this->assertContains( 'ROLLBACK', $db->trace );
	}
}

final class OwnerWriteDatabase {
	public string $prefix = 'test_';
	public string $last_error = '';
	public bool $in_transaction = false;
	public array $trace = array();
	public int $updates = 0;
	public $on_folder_lock = null;
	public object $file;
	public object $folder;
	public function __construct() {
		$this->file = (object) array( 'id' => 1, 'attachment_id' => 10, 'owner_id' => 7, 'folder_id' => 0, 'version' => 1, 'lifecycle' => 'active', 'pending_request_id' => 0 );
		$this->folder = (object) array( 'id' => 2, 'owner_id' => 7, 'parent_id' => 0, 'version' => 1, 'tree_version' => 1, 'lifecycle' => 'active', 'pending_request_id' => 0 );
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		return preg_replace_callback( '/%[ds]/', static function ( $match ) use ( &$args ) { $arg = array_shift( $args ); return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'"; }, $sql );
	}
	public function get_var( $sql ) {
		if ( str_contains( $sql, 'information_schema' ) ) { return 'InnoDB'; }
		if ( 'SELECT @@in_transaction' === $sql ) { return (int) $this->in_transaction; }
		return null;
	}
	public function query( $sql ) { $this->trace[] = $sql; return 1; }
	public function get_results( $sql ) {
		if ( str_contains( $sql, 'sufm_files' ) ) { $this->trace[] = 'lock_files'; }
		if ( str_contains( $sql, 'sufm_folders' ) ) { $this->trace[] = 'lock_folders'; if ( $this->on_folder_lock ) { ( $this->on_folder_lock )(); } }
		return array();
	}
	public function get_row( $sql ) {
		if ( str_contains( $sql, 'sufm_usage' ) ) { $this->trace[] = 'lock_usage'; return (object) array( 'owner_id' => 7 ); }
		if ( str_contains( $sql, 'sufm_files' ) ) { $this->trace[] = 'read_file'; return clone $this->file; }
		if ( str_contains( $sql, 'sufm_folders' ) ) { $this->trace[] = 'read_folder'; return 'active' === $this->folder->lifecycle ? clone $this->folder : null; }
		return null;
	}
	public function update( $table, $values, $where, ...$formats ) {
		++$this->updates;
		if ( (int) $where['version'] !== (int) $this->file->version ) { return 0; }
		foreach ( $values as $key => $value ) { $this->file->$key = $value; }
		return 1;
	}
}
