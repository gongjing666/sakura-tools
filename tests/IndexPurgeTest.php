<?php
/**
 * Purging a profile must not throw away a bucket another row still names.
 *
 * Schema 1.9.0 partitions the sync index by bucket identity (R19.7), so the
 * rows of one bucket can be shared by several configuration rows: a standby
 * row for a disaster-recovery bucket, or a row rebuilt after a delete. Both
 * callers of purge_index() -- deleting a saved profile and saving a profile
 * whose target moved -- used to delete `WHERE profile_id`, which is now only a
 * label. These tests pin the two halves of the new rule: the per-profile
 * tables always follow the profile id, the per-bucket rows only go when the
 * bucket goes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Bucket_Identity;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Sync\Schema;

require_once dirname( __DIR__ ) . '/src/Sync/Schema.php';
require_once dirname( __DIR__ ) . '/src/S3/Bucket_Identity.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/** Records the statements a repository issues. */
final class IndexPurgeDatabase {

	public string $prefix = 't_';

	/** @var array<int,array{0:string,1:array<int,mixed>}> */
	public array $queries = array();

	public function prepare( $sql, ...$args ): string {
		return serialize( array( (string) $sql, $args ) );
	}

	public function query( $prepared ) {
		$this->queries[] = unserialize( $prepared );

		return 1;
	}

	/** Statements whose SQL contains the given needle. */
	public function matching( string $needle ): array {
		return array_values(
			array_filter(
				$this->queries,
				static fn( array $query ): bool => false !== strpos( $query[0], $needle )
			)
		);
	}
}

final class IndexPurgeTest extends TestCase {

	private const LIVE = 'prof_live';

	private const STANDBY = 'prof_standby';

	private $previous_wpdb;

	private $previous_options;

	private IndexPurgeDatabase $db;

	protected function setUp(): void {
		$this->previous_wpdb    = $GLOBALS['wpdb'] ?? null;
		$this->previous_options = $GLOBALS['sakura_test_options'] ?? null;

		$this->db        = new IndexPurgeDatabase();
		$GLOBALS['wpdb'] = $this->db;

		$GLOBALS['sakura_test_options'] = array(
			Schema::OPTION => Schema::VERSION,
		);
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		$GLOBALS['sakura_test_options'] = $this->previous_options;
	}

	/**
	 * Store two profiles naming the same bucket.
	 *
	 * @param bool $same_bucket Whether the standby row names the same bucket.
	 */
	private function seed( bool $same_bucket ): void {
		$live = array(
			'id'              => self::LIVE,
			'name'            => 'Bitiful',
			'provider'        => 'bitiful',
			'bucket'          => 'nboke',
			'endpoint'        => 'https://s3.bitiful.net',
			'region'          => 'cn-east-1',
			'key'             => 'test-key',
			'secret'          => 'test-secret',
			'upload_path'     => 'wp-content/uploads',
			'upload_url_path' => 'https://bf.example.com/wp-content/uploads',
		);

		$standby          = $live;
		$standby['id']    = self::STANDBY;
		$standby['name']  = 'Standby';
		$standby['bucket'] = $same_bucket ? $live['bucket'] : 'other-bucket';

		$GLOBALS['sakura_test_options']['s3_options'] = array(
			'provider'          => $live['provider'],
			'bucket'            => $live['bucket'],
			'endpoint'          => $live['endpoint'],
			'upload_path'       => $live['upload_path'],
			'active_profile_id' => self::LIVE,
			'profiles'          => array(
				self::LIVE    => $live,
				self::STANDBY => $standby,
			),
		);
	}

	private function bucket(): string {
		return Bucket_Identity::from_options( new Options() )->hash();
	}

	public function test_a_shared_bucket_keeps_its_rows(): void {
		$this->seed( true );

		( new Index_Repository() )->purge_index( self::LIVE );

		$this->assertSame( array(), $this->db->matching( 'sio_sync_index' ), 'The standby row still names this bucket.' );
		$this->assertSame( array(), $this->db->matching( 'sio_sync_counts' ), 'Its counters describe the same objects.' );
		$this->assertCount( 1, $this->db->matching( 'sio_sync_scans' ), 'Scan history belongs to the profile alone.' );
		$this->assertCount( 1, $this->db->matching( 'sio_sync_jobs' ) );
		$this->assertCount( 1, $this->db->matching( 'sio_sync_events' ) );
	}

	public function test_the_last_row_of_a_bucket_takes_the_rows_with_it(): void {
		$this->seed( false );

		( new Index_Repository() )->purge_index( self::LIVE );

		$index = $this->db->matching( 'sio_sync_index' );
		$this->assertCount( 1, $index );
		$this->assertStringContainsString( 'DELETE FROM', $index[0][0] );
		$this->assertSame( array( $this->bucket() ), $index[0][1], 'Rows are keyed by bucket identity, not by profile id.' );
		$this->assertCount( 1, $this->db->matching( 'sio_sync_counts' ) );
	}

	public function test_the_bucket_is_read_from_the_caller_when_the_row_is_already_gone(): void {
		$this->seed( false );
		$bucket = $this->bucket();

		// Deleting a profile removes the row first; the id no longer resolves.
		unset( $GLOBALS['sakura_test_options']['s3_options']['profiles'][ self::LIVE ] );

		( new Index_Repository() )->purge_index( self::LIVE, $bucket );

		$this->assertCount( 1, $this->db->matching( 'sio_sync_index' ), 'The captured identity is what gets deleted.' );
	}

	public function test_the_index_is_partitioned_by_bucket(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Sync/Schema.php' );

		$this->assertSame( '1.9.0', Schema::VERSION );
		$this->assertSame( 1, substr_count( $source, 'UNIQUE KEY target_object (target_hash,object_key_hash)' ) );
		$this->assertSame( 1, substr_count( $source, 'PRIMARY KEY  (target_hash,status)' ) );
		$this->assertSame( 0, substr_count( $source, 'UNIQUE KEY profile_object' ) );
	}
}
