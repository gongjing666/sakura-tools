<?php
/** Delete queue lease and claim-token tests. */
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Delete_Jobs;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

final class DeleteLeaseDatabase {
	public string $prefix = 'test_';
	/** @var array<string,mixed> */
	public array $row;
	public bool $renew_query_failed = false;
	public bool $renew_update_noop = false;

	public function __construct() {
		$this->row = array(
			'id'          => 7,
			'state'       => 'queued',
			'attempts'    => 0,
			'claim_token' => '',
			'lease_until' => null,
			'next_at'     => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			'last_error'  => '',
		);
	}

	public function prepare( $sql, ...$args ): string {
		return serialize( array( (string) $sql, $args ) );
	}

	public function query( $prepared ) {
		[ $sql, $args ] = unserialize( $prepared );
		if ( str_contains( $sql, "SET state = 'running'" ) ) {
			[ $token, $lease, , $id, $due_now, $stale, $now ] = $args;
			$queued_due = 'queued' === $this->row['state'] && (string) $this->row['next_at'] <= (string) $due_now;
			$old_row = 'running' === $this->row['state'] && ( null === $this->row['lease_until'] || (string) $this->row['lease_until'] <= (string) $stale );
			$expired = 'running' === $this->row['state'] && ( null === $this->row['lease_until'] || (string) $this->row['lease_until'] <= (string) $now );
			if ( (int) $id !== (int) $this->row['id'] || ( ! $queued_due && ! $old_row && ! $expired ) ) {
				return 0;
			}
			$this->row['state']       = 'running';
			$this->row['claim_token'] = $token;
			$this->row['lease_until'] = $lease;
			return 1;
		}
		if ( str_contains( $sql, 'SET lease_until = %s' ) ) {
			[ $lease, , $id, $token, $now ] = $args;
			if ( $this->renew_query_failed ) {
				return false;
			}
			if ( (int) $id !== (int) $this->row['id'] || 'running' !== $this->row['state'] || $token !== $this->row['claim_token'] || null === $this->row['lease_until'] || (string) $this->row['lease_until'] < (string) $now ) {
				return 0;
			}
			if ( ! $this->renew_update_noop ) {
				$this->row['lease_until'] = $lease;
			}
			return $this->renew_update_noop ? 0 : 1;
		}
		if ( str_contains( $sql, "SET state = 'done'" ) ) {
			[ $resolution, , , $id, $token ] = $args;
			if ( (int) $id !== (int) $this->row['id'] || 'running' !== $this->row['state'] || $token !== $this->row['claim_token'] ) {
				return 0;
			}
			$this->row['state']       = 'done';
			$this->row['resolution']  = $resolution;
			$this->row['completed_at'] = gmdate( 'Y-m-d H:i:s' );
			return 1;
		}
		if ( str_contains( $sql, "SET state = IF(attempts + 1" ) ) {
			[ , , $next, $error, , $id, $token ] = $args;
			if ( (int) $id !== (int) $this->row['id'] || 'running' !== $this->row['state'] || $token !== $this->row['claim_token'] ) {
				return 0;
			}
			++$this->row['attempts'];
			$this->row['state']       = $this->row['attempts'] >= Delete_Jobs::MAX_ATTEMPTS ? 'dead' : 'queued';
			$this->row['claim_token'] = '';
			$this->row['lease_until'] = null;
			$this->row['last_error']  = $error;
			return 1;
		}
		if ( str_contains( $sql, "SET state = 'queued'" ) ) {
			[ , , $id, $token ] = $args;
			if ( (int) $id !== (int) $this->row['id'] || 'running' !== $this->row['state'] || $token !== $this->row['claim_token'] ) {
				return 0;
			}
			$this->row['state']       = 'queued';
			$this->row['claim_token'] = '';
			$this->row['lease_until'] = null;
			return 1;
		}
		return 0;
	}

	public function get_var( $prepared ) {
		[ $sql, $args ] = unserialize( $prepared );
		if ( str_contains( $sql, 'SELECT 1 FROM' ) ) {
			[ $id, $token, $until ] = $args;
			return ( (int) $id === (int) $this->row['id'] && 'running' === $this->row['state'] && $token === $this->row['claim_token'] && null !== $this->row['lease_until'] && (string) $this->row['lease_until'] >= (string) $until ) ? '1' : null;
		}
		if ( str_contains( $sql, 'SELECT attempts' ) ) {
			return $this->row['attempts'];
		}
		return 0;
	}

	public function get_row( $prepared, $output = ARRAY_A ) {
		return array( 'attempts' => $this->row['attempts'], 'state' => $this->row['state'] );
	}
}

final class DeleteLeaseTest extends TestCase {
	private function jobs( DeleteLeaseDatabase $db ): Delete_Jobs {
		$GLOBALS['wpdb'] = $db;
		return new Delete_Jobs();
	}

	public function test_claim_token_and_lease_fence_completion(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db       = new DeleteLeaseDatabase();
		try {
			$jobs  = $this->jobs( $db );
			$db->row['next_at'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
			$this->assertFalse( $jobs->start( 7, 600 ) );
			$db->row['next_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
			$token = $jobs->start( 7, 600 );
			$this->assertIsString( $token );
			$this->assertSame( 64, strlen( $token ) );
			$this->assertFalse( $jobs->complete( 7, 'stale-token' ) );
			$this->assertSame( 'running', $db->row['state'] );
			$this->assertTrue( $jobs->complete( 7, $token ) );
			$this->assertSame( 'done', $db->row['state'] );
			$this->assertSame( 'deleted', $db->row['resolution'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

	public function test_renew_requires_live_token_and_lease(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db       = new DeleteLeaseDatabase();
		try {
			$jobs  = $this->jobs( $db );
			$token = $jobs->start( 7, 600 );
			$this->assertIsString( $token );
			$this->assertFalse( $jobs->renew( 7, 'stale-token' ) );
			$db->row['lease_until'] = '2000-01-01 00:00:00';
			$this->assertFalse( $jobs->renew( 7, $token ) );
			$this->assertSame( '2000-01-01 00:00:00', $db->row['lease_until'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

	public function test_renew_accepts_same_second_noop_but_rejects_database_failure(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db       = new DeleteLeaseDatabase();
		try {
			$jobs  = $this->jobs( $db );
			$token = $jobs->start( 7, 600 );
			$this->assertIsString( $token );
			$db->renew_update_noop = true;
			$this->assertTrue( $jobs->renew( 7, $token ) );
			$db->renew_query_failed = true;
			$this->assertFalse( $jobs->renew( 7, $token ) );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

	public function test_stale_failure_cannot_change_reclaimed_claim(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db       = new DeleteLeaseDatabase();
		try {
			$jobs   = $this->jobs( $db );
			$old    = $jobs->start( 7, 60 );
			$this->assertIsString( $old );
			$db->row['lease_until'] = '2000-01-01 00:00:00';
			$new = $jobs->start( 7, 60 );
			$this->assertIsString( $new );
			$this->assertNotSame( $old, $new );
			$this->assertFalse( $jobs->postpone( 7, $old ) );
			$result = $jobs->fail( 7, 'late worker', $old );
			$this->assertFalse( $result['applied'] );
			$this->assertSame( $new, $db->row['claim_token'] );
			$this->assertTrue( $jobs->postpone( 7, $new ) );
			$this->assertSame( 'queued', $db->row['state'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
}
