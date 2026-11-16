<?php
/**
 * Tests for the URL gate that keeps attachments on the CDN after a bucket switch.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\S3\Upload_URL;
use Sakura\ImageOptimizer\Sync\Feature;
use Sakura\ImageOptimizer\Sync\Schema;

require_once dirname( __DIR__ ) . '/src/Sync/Schema.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Minimal $wpdb double that answers the single-row sync index lookups.
 */
final class StorageBucketSwitchDatabase {

	public string $prefix = 't_';

	/**
	 * Rows keyed by "profile|object-key-sha256-hex".
	 *
	 * @var array<string,string>
	 */
	public array $rows = array();

	public int $queries = 0;

	public function prepare( $sql, ...$args ): string {
		return serialize( array( (string) $sql, $args ) );
	}

	public function get_row( $prepared, $output = ARRAY_A ) {
		++$this->queries;

		list( $sql, $args ) = unserialize( $prepared );

		$lookup = (string) ( $args[0] ?? '' ) . '|' . (string) ( $args[1] ?? '' );
		if ( isset( $this->rows[ $lookup ] ) ) {
			return array( 'status' => $this->rows[ $lookup ] );
		}

		return null;
	}

	public function key( string $profile_id, string $object_key ): string {
		return $profile_id . '|' . bin2hex( hash( 'sha256', $object_key, true ) );
	}
}

final class StorageBucketSwitchTest extends TestCase {

	private const ATTACHMENT = 42;
	private const CDN        = 'https://bf.example.com/wp-content/uploads';
	private const LOCAL      = 'https://www.example.com/wp-content/uploads';
	private const OBJECT_KEY = 'wp-content/uploads/2026/11/a.webp';
	private const OLD_TARGET = 'fingerprint-of-the-previous-bucket';

	private $previous_wpdb;

	private $previous_options;

	private $previous_post_meta;

	private $previous_attached;

	private StorageBucketSwitchDatabase $db;

	private string $file = '';

	protected function setUp(): void {
		$this->previous_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->previous_options    = $GLOBALS['sakura_test_options'] ?? null;
		$this->previous_post_meta  = $GLOBALS['sakura_test_post_meta'] ?? null;
		$this->previous_attached   = $GLOBALS['sakura_test_attached_files'] ?? null;

		$this->db        = new StorageBucketSwitchDatabase();
		$GLOBALS['wpdb'] = $this->db;

		$this->file = (string) tempnam( sys_get_temp_dir(), 'sio-bucket-switch' );

		$GLOBALS['sakura_test_options'] = array(
			Schema::OPTION                  => Schema::VERSION,
			Upload_URL::PREVIOUS_URL_OPTION => self::LOCAL,
		);
		$GLOBALS['sakura_test_post_meta'] = array();
		$GLOBALS['sakura_test_attached_files'] = array( self::ATTACHMENT => $this->file );
	}

	protected function tearDown(): void {
		if ( '' !== $this->file && is_file( $this->file ) ) {
			unlink( $this->file );
		}

		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		$GLOBALS['sakura_test_options']        = $this->previous_options;
		$GLOBALS['sakura_test_post_meta']      = $this->previous_post_meta;
		$GLOBALS['sakura_test_attached_files'] = $this->previous_attached;
	}

	private function options(): Options {
		return new Options(
			array(
				'enabled'           => 'true',
				'provider'          => 'bitiful',
				'bucket'            => 'nboke',
				'endpoint'          => 'https://s3.bitiful.net',
				'key'               => 'test-key',
				'secret'            => 'test-secret',
				'upload_url_path'   => self::CDN,
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => 'prof_new',
				'profiles'          => array(
					'prof_new' => array(
						'id'              => 'prof_new',
						'name'            => 'Bitiful',
						'provider'        => 'bitiful',
						'bucket'          => 'nboke',
						'endpoint'        => 'https://s3.bitiful.net',
						'key'             => 'test-key',
						'secret'          => 'test-secret',
						'upload_path'     => 'wp-content/uploads',
						'upload_url_path' => self::CDN,
					),
				),
			)
		);
	}

	private function storage(): Storage {
		return new Storage( $this->options() );
	}

	private function cdn_url(): string {
		return self::CDN . '/2026/11/a.webp';
	}

	private function local_url(): string {
		return self::LOCAL . '/2026/11/a.webp';
	}

	private function seed( string $status, array $objects ): void {
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] = array(
			Sync_Status::STATUS_META  => $status,
			Sync_Status::TARGET_META  => self::OLD_TARGET,
			Sync_Status::OBJECTS_META => $objects,
		);
	}

	private function stored_target(): string {
		return (string) get_post_meta( self::ATTACHMENT, Sync_Status::TARGET_META, true );
	}

	public function test_bucket_switch_keeps_the_cdn_url_when_the_index_confirms_the_objects(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';

		$this->assertSame( $this->cdn_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
	}

	public function test_index_confirmation_rewrites_the_fingerprint_so_later_requests_skip_the_lookup(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ][ Sync_Status::SYNCED_META ] = 1700000000;

		$storage = $this->storage();
		$this->assertSame( $this->cdn_url(), $storage->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame(
			Synchronous_Uploader::target_hash_for( $this->options() ),
			$this->stored_target(),
			'The fingerprint must be rewritten so the next request answers from post meta.'
		);
		$this->assertSame(
			1700000000,
			(int) get_post_meta( self::ATTACHMENT, Sync_Status::SYNCED_META, true ),
			'Nothing was uploaded again, so the sync time must not move.'
		);

		$this->db->rows    = array();
		$this->db->queries = 0;
		$this->assertSame( $this->cdn_url(), $storage->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries, 'A healed attachment must not query the index again.' );
	}

	public function test_bucket_switch_falls_back_to_the_local_url_without_index_evidence(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );

		$this->assertSame( $this->local_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertGreaterThan( 0, $this->db->queries );
		$this->assertSame( self::OLD_TARGET, $this->stored_target() );
	}

	public function test_mismatched_index_rows_do_not_rescue_the_attachment(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'mismatch';

		$this->assertSame( $this->local_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
	}

	public function test_disabled_sync_index_leaves_the_attachment_on_the_local_url(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';
		$GLOBALS['sakura_test_options'][ Feature::OPTION ]                = '0';

		$this->assertSame( $this->local_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries );
	}

	public function test_uninstalled_index_is_never_queried(): void {
		$this->seed( Sync_Status::SYNCED, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';
		unset( $GLOBALS['sakura_test_options'][ Schema::OPTION ] );

		$this->assertSame( $this->local_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries );
	}

	public function test_relative_recorded_key_is_matched_through_the_upload_path(): void {
		$this->seed( Sync_Status::SYNCED, array( '2026/11/a.webp' ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';

		$this->assertSame( $this->cdn_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
	}

	public function test_absolute_recorded_path_is_reduced_to_its_object_key(): void {
		$absolute = '/' . trim( wp_normalize_path( ABSPATH ), '/' ) . '/' . self::OBJECT_KEY;
		$this->seed( Sync_Status::SYNCED, array( $absolute ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';

		$this->assertSame( $this->cdn_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
	}

	public function test_legacy_attachment_without_a_fingerprint_is_healed_by_the_index(): void {
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] = array(
			Sync_Status::STATUS_META  => Sync_Status::SYNCED,
			Sync_Status::OBJECTS_META => array( self::OBJECT_KEY ),
		);
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';

		$this->assertSame( $this->cdn_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( Synchronous_Uploader::target_hash_for( $this->options() ), $this->stored_target() );
	}

	public function test_pending_attachment_is_not_rescued_by_the_index(): void {
		$this->seed( Sync_Status::PENDING, array( self::OBJECT_KEY ) );
		$this->db->rows[ $this->db->key( 'prof_new', self::OBJECT_KEY ) ] = 'synced';

		$this->assertSame( $this->local_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries );
	}

	public function test_same_bucket_fingerprint_still_wins_without_a_lookup(): void {
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] = array(
			Sync_Status::STATUS_META  => Sync_Status::SYNCED,
			Sync_Status::TARGET_META  => Synchronous_Uploader::target_hash_for( $this->options() ),
			Sync_Status::OBJECTS_META => array( self::OBJECT_KEY ),
		);

		$this->assertSame( $this->cdn_url(), $this->storage()->revert_unsynced_url( $this->cdn_url(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries );
	}
}
