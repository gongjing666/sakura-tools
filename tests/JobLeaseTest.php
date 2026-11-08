<?php
declare( strict_types=1 );
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Index_Repository;

final class JobLeaseDatabase {
	public array $row;
	public bool $offline = false;
	public function __construct() { $this->row = array( 'id' => 1, 'state' => 'running', 'claim_token' => 'current', 'lease_until' => gmdate( 'Y-m-d H:i:s', time() + 300 ) ); }
	public function prepare( $sql, ...$args ): string { return json_encode( array( $sql, $args ) ); }
	public function query( $prepared ) {
		if ( $this->offline ) { return false; }
		[ $sql, $args ] = json_decode( $prepared, true );
		[ $until, $now, $id, $token ] = $args;
		if ( str_contains( $sql, 'claim_token = %s AND lease_until >= %s' ) && $this->row['id'] === $id && 'running' === $this->row['state'] && $this->row['claim_token'] === $token && $this->row['lease_until'] >= $now ) {
			$this->row['lease_until'] = $until;
			return 1;
		}
		return 0;
	}
	public function get_var( $prepared ) {
		[ $sql, $args ] = json_decode( $prepared, true );
		[ $id, $token, $until ] = $args;
		return $this->row['id'] === $id && 'running' === $this->row['state'] && $this->row['claim_token'] === $token && $this->row['lease_until'] >= $until ? '1' : null;
	}
}

final class JobLeaseTest extends TestCase {
	public function test_expired_or_replaced_claim_cannot_renew_but_live_claim_can(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new JobLeaseDatabase();
		$GLOBALS['wpdb'] = $db;
		$reflection = new ReflectionClass( Index_Repository::class );
		$repository = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'jobs' )->setValue( $repository, 'isolated_jobs' );
		try {
			$this->assertFalse( $repository->job_renew( 1, 'old-token' ) );
			$db->row['lease_until'] = '2000-01-01 00:00:00';
			$this->assertFalse( $repository->job_renew( 1, 'current' ) );
			$this->assertSame( '2000-01-01 00:00:00', $db->row['lease_until'] );
			$db->row['lease_until'] = gmdate( 'Y-m-d H:i:s', time() + 60 );
			$this->assertTrue( $repository->job_renew( 1, 'current' ) );
			$this->assertTrue( $repository->job_renew( 1, 'current' ) );
			$db->offline = true;
			$this->assertFalse( $repository->job_renew( 1, 'current' ) );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
}
