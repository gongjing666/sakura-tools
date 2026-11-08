<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Remote_Operation_Guard;

/** Shared durable state for separate guard instances; no WordPress DB or S3. */
final class RemoteGuardDatabase {
	public string $prefix = 'isolated_';
	public array $rows = array();
	public bool $offline = false;
	public function prepare( $sql, ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function query( $prepared ) {
		if ( $this->offline ) { return false; }
		[ $sql, $args ] = json_decode( $prepared, true );
		if ( str_starts_with( $sql, 'INSERT IGNORE' ) ) {
			[ $id, $token, $key, $action ] = $args;
			if ( isset( $this->rows[ $id ] ) ) { return 0; }
			$this->rows[ $id ] = array( 'token' => $token, 'key' => $key, 'action' => $action, 'state' => 'inflight', 'created_at' => $args[4] );
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE' ) ) {
			[ $error, $time, $id, $token ] = $args;
			if ( ( $this->rows[ $id ]['token'] ?? '' ) !== $token ) { return 0; }
			$this->rows[ $id ]['state'] = 'uncertain';
			return 1;
		}
		[ $id, $token ] = $args;
		if ( ( $this->rows[ $id ]['token'] ?? '' ) !== $token ) { return 0; }
		if ( str_contains( $sql, "state = 'inflight'" ) && 'inflight' !== $this->rows[ $id ]['state'] ) { return 0; }
		unset( $this->rows[ $id ] );
		return 1;
	}
}

final class RemoteOperationGuardTest extends TestCase {
	public function test_slow_put_blocks_reclaimed_delete_until_definitive_completion(): void {
		$db = new RemoteGuardDatabase();
		$a = new Remote_Operation_Guard( 'same-target', $db );
		$b = new Remote_Operation_Guard( 'same-target', $db );
		$remote = array();
		$fiber = new Fiber( function () use ( $a, &$remote ) {
			return $a->execute( array( 'image' ), 'upload', function () use ( &$remote ) {
				Fiber::suspend(); // Request in flight beyond queue lease.
				$remote[] = 'old-put';
				return array( 'image' => true );
			} );
		} );
		$fiber->start();
		foreach ( $db->rows as &$row ) { $row['created_at'] = '2000-01-01 00:00:00'; }
		unset( $row );
		$result = $b->execute( array( 'image' ), 'delete', function () use ( &$remote ) { $remote[] = 'delete'; return array( 'image' => true ); } );
		$this->assertSame( 'sio_remote_busy', $result['image']->get_error_code() );
		$this->assertSame( array(), $remote );
		$fiber->resume();
		$this->assertTrue( $fiber->getReturn()['image'] );
		$this->assertTrue( $b->execute( array( 'image' ), 'delete', static fn() => array( 'image' => true ) )['image'] );
		$this->assertSame( array(), $db->rows );
	}

	public function test_uncertain_delete_blocks_replacement_put_and_wrong_acknowledgement(): void {
		$db = new RemoteGuardDatabase();
		$guard = new Remote_Operation_Guard( 'bucket', $db );
		$result = $guard->execute( array( 'image' ), 'delete', static function () { throw new RuntimeException( 'timeout after provider accepted DELETE' ); } );
		$this->assertSame( 'sio_remote_uncertain', $result['image']->get_error_code() );
		$result = ( new Remote_Operation_Guard( 'bucket', $db ) )->execute( array( 'image' ), 'upload', function () { $this->fail( 'Replacement PUT must not be sent.' ); } );
		$this->assertSame( 'sio_remote_busy', $result['image']->get_error_code() );
		$id = array_key_first( $db->rows );
		$token = $db->rows[ $id ]['token'];
		$this->assertFalse( $guard->acknowledge_settled( $id, str_repeat( '0', 64 ) ) );
		$this->assertTrue( $guard->acknowledge_settled( $id, $token ) );
		$this->assertFalse( $guard->acknowledge_settled( $id, $token ) );
	}

	public function test_unknown_put_never_auto_retries_or_unblocks_on_new_instance(): void {
		$db = new RemoteGuardDatabase();
		$calls = 0;
		$dispatch = static function () use ( &$calls ) { ++$calls; return array( 'image' => new WP_Error( 'reset', 'connection lost' ) ); };
		( new Remote_Operation_Guard( 'scope', $db ) )->execute( array( 'image' ), 'upload', $dispatch );
		( new Remote_Operation_Guard( 'scope', $db ) )->execute( array( 'image' ), 'upload', $dispatch );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'uncertain', reset( $db->rows )['state'] );
	}

	public function test_lost_claim_releases_admission_without_sending_and_does_not_resurrect_it(): void {
		$db = new RemoteGuardDatabase();
		$guard = new Remote_Operation_Guard( 'scope', $db );
		$result = $guard->execute( array( 'image' ), 'upload', function () { $this->fail( 'Expired claim must not dispatch.' ); }, static fn() => false );
		$this->assertSame( 'sio_job_claim_lost', $result['image']->get_error_code() );
		$this->assertSame( array(), $db->rows );
	}

	public function test_different_keys_and_targets_progress_but_missing_outcome_stays_held(): void {
		$db = new RemoteGuardDatabase();
		$guard = new Remote_Operation_Guard( 'scope', $db );
		$guard->execute( array( 'a', 'b' ), 'upload', static fn() => array( 'a' => true ) );
		$this->assertCount( 1, $db->rows );
		$this->assertTrue( $guard->execute( array( 'a' ), 'delete', static fn() => array( 'a' => true ) )['a'] );
		$this->assertTrue( ( new Remote_Operation_Guard( 'other-target', $db ) )->execute( array( 'b' ), 'upload', static fn() => array( 'b' => true ) )['b'] );
		$this->assertCount( 1, $db->rows );
	}

	public function test_database_failure_fails_closed_without_network(): void {
		$db = new RemoteGuardDatabase();
		$db->offline = true;
		$result = ( new Remote_Operation_Guard( 'scope', $db ) )->execute( array( 'image' ), 'upload', function () { $this->fail( 'No reservation means no remote mutation.' ); } );
		$this->assertSame( 'sio_remote_busy', $result['image']->get_error_code() );
	}
}
