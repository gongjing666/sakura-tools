<?php
/**
 * Lifecycle rules from docs/开发规则/07-S3存储生命周期.md (R1–R12).
 *
 * The rules are enforced in three places that never see each other: Options
 * answers the state machine, Settings_Saver / Profile_Manager refuse the
 * transitions, and the page builds the prompt. These tests pin the parts that
 * can be exercised without WordPress: the freeze predicates, the delete
 * decision table, the shared dialog copy and the srcset candidate drop.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Bucket_Identity;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Manager;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Storage;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\Sync\Feature;
use Sakura\ImageOptimizer\Sync\Schema;

require_once dirname( __DIR__ ) . '/src/Sync/Schema.php';
require_once dirname( __DIR__ ) . '/src/S3/Bucket_Label.php';
require_once dirname( __DIR__ ) . '/src/S3/Providers.php';
require_once dirname( __DIR__ ) . '/src/S3/Sync_Status.php';
require_once dirname( __DIR__ ) . '/src/S3/Synchronous_Uploader.php';
require_once dirname( __DIR__ ) . '/src/S3/Storage_Adapter.php';
require_once dirname( __DIR__ ) . '/src/S3/Storage.php';
require_once dirname( __DIR__ ) . '/src/S3/S3_Storage_Page.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * $wpdb double that answers the per-key sync index lookups Storage makes.
 */
final class S3LifecycleDatabase {

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
		unset( $output );
		++$this->queries;

		list( , $args ) = unserialize( $prepared );

		$lookup = (string) ( $args[0] ?? '' ) . '|' . (string) ( $args[1] ?? '' );

		return isset( $this->rows[ $lookup ] ) ? array( 'status' => $this->rows[ $lookup ] ) : null;
	}

	public function get_results( $prepared, $output = ARRAY_A ) {
		unset( $output );
		++$this->queries;

		list( $sql, $args ) = unserialize( $prepared );

		// The batched form is "target_hash IN (...) AND object_key_hash IN (...)";
		// the bucket identities come first, the hashes after them. Both halves are
		// 64-hex since Schema 1.9.0, so the split cannot look at the values.
		$split   = strpos( (string) $sql, 'object_key_hash IN' );
		$head    = false === $split ? (string) $sql : substr( (string) $sql, 0, $split );
		$count   = substr_count( $head, '%s' );
		$buckets = array_slice( $args, 0, $count );
		$hashes  = array_slice( $args, $count );

		$rows = array();
		foreach ( $hashes as $hash ) {
			foreach ( $buckets as $bucket ) {
				$lookup = $bucket . '|' . $hash;
				if ( ! isset( $this->rows[ $lookup ] ) ) {
					continue;
				}
				$rows[] = array(
					'target_hash'     => $bucket,
					'profile_id'      => 'prof_live',
					// The real column is binary(32), so the driver hands back raw bytes.
					'object_key_hash' => (string) hex2bin( $hash ),
					'status'          => $this->rows[ $lookup ],
				);
			}
		}

		return $rows;
	}

	public function key( string $bucket_hash, string $object_key ): string {
		return $bucket_hash . '|' . bin2hex( hash( 'sha256', $object_key, true ) );
	}
}

final class S3LifecycleTest extends TestCase {

	private const ATTACHMENT = 42;
	private const UPLOAD_DIR = '/tmp/sio-lifecycle-uploads';
	private const LOCAL      = 'https://www.example.com/wp-content/uploads';
	private const CDN        = 'https://bf.example.com/wp-content/uploads';
	private const MAIN_KEY   = 'wp-content/uploads/2026/11/a.webp';
	private const SIZE_KEY   = 'wp-content/uploads/2026/11/a-300x200.webp';

	private $previous_wpdb;
	private $previous_options;
	private $previous_post_meta;
	private $previous_attached;
	private $previous_upload_dir;
	private $previous_baseurl;

	private S3LifecycleDatabase $db;

	/** @var array<int,string> */
	private array $files = array();

	protected function setUp(): void {
		$this->previous_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->previous_options    = $GLOBALS['sakura_test_options'] ?? null;
		$this->previous_post_meta  = $GLOBALS['sakura_test_post_meta'] ?? null;
		$this->previous_attached   = $GLOBALS['sakura_test_attached_files'] ?? null;
		$this->previous_upload_dir = $GLOBALS['sakura_test_upload_dir'] ?? null;
		$this->previous_baseurl    = $GLOBALS['sakura_test_upload_baseurl'] ?? null;

		$this->db        = new S3LifecycleDatabase();
		$GLOBALS['wpdb'] = $this->db;

		// The confirmation cache survives between tests; a cached "not in
		// the bucket" answer would mask the query the next test asserts on.
		$GLOBALS['sakura_test_cache'] = array();

		if ( ! is_dir( self::UPLOAD_DIR . '/2026/11' ) ) {
			mkdir( self::UPLOAD_DIR . '/2026/11', 0777, true );
		}

		$GLOBALS['sakura_test_upload_dir']     = self::UPLOAD_DIR;
		$GLOBALS['sakura_test_upload_baseurl'] = self::CDN;
		$GLOBALS['sakura_test_options']        = array( Schema::OPTION => Schema::VERSION );
		$GLOBALS['sakura_test_post_meta']      = array();
		$GLOBALS['sakura_test_attached_files'] = array();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();

		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		$GLOBALS['sakura_test_options']        = $this->previous_options;
		$GLOBALS['sakura_test_post_meta']      = $this->previous_post_meta;
		$GLOBALS['sakura_test_attached_files'] = $this->previous_attached;
		$GLOBALS['sakura_test_upload_dir']     = $this->previous_upload_dir;
		$GLOBALS['sakura_test_upload_baseurl'] = $this->previous_baseurl;
	}

	/**
	 * Options array for one live, complete profile.
	 *
	 * @param array<string,mixed> $extra Overrides.
	 * @return array<string,mixed>
	 */
	private function stored( array $extra = array() ): array {
		return array_merge(
			array(
				'enabled'           => 'true',
				'provider'          => 'bitiful',
				'bucket'            => 'nboke',
				'region'            => 'cn-east-1',
				'endpoint'          => 'https://s3.bitiful.net',
				'key'               => 'test-key',
				'secret'            => 'test-secret',
				'object_acl'        => 'public-read',
				'upload_url_path'   => self::CDN,
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => 'prof_live',
				'profiles'          => array(
					'prof_live' => array(
						'id'              => 'prof_live',
						'name'            => '缤纷云 · nboke',
						'provider'        => 'bitiful',
						'bucket'          => 'nboke',
						'region'          => 'cn-east-1',
						'endpoint'        => 'https://s3.bitiful.net',
						'key'             => 'test-key',
						'secret'          => 'test-secret',
						'upload_path'     => 'wp-content/uploads',
						'upload_url_path' => self::CDN,
					),
				),
			),
			$extra
		);
	}

	/**
	 * Binding snapshot matching the stored() profile.
	 *
	 * @param string $no_local_saving Switch the binding was made with.
	 * @return array<string,mixed>
	 */
	private function binding( string $no_local_saving = 'false' ): array {
		return array(
			'profile_id'      => 'prof_live',
			'provider'        => 'bitiful',
			'bucket'          => 'nboke',
			'endpoint'        => 'https://s3.bitiful.net',
			'region'          => 'cn-east-1',
			'upload_path'     => 'wp-content/uploads',
			'no_local_saving' => $no_local_saving,
			'no_thumb'        => 'false',
			'locked_at'       => 1700000000,
			'locked_by'       => 1,
		);
	}

	private function options( array $extra = array() ): Options {
		return new Options( $this->stored( $extra ) );
	}

	/** 64-hex identity of the bucket the default options describe. */
	private function bucket( array $extra = array() ): string {
		return Bucket_Identity::from_options( $this->options( $extra ) )->hash();
	}

	/** Create one local file and return its path. */
	private function touch( string $name ): string {
		$path = self::UPLOAD_DIR . '/2026/11/' . $name;
		file_put_contents( $path, 'x' );
		$this->files[] = $path;

		return $path;
	}

	/**
	 * Describe an attachment with metadata listing both the main file and a sub-size.
	 *
	 * @param bool $with_size Whether the sub-size exists on disk and in metadata.
	 */
	private function attachment( bool $with_size = true ): void {
		$main = $this->touch( 'a.webp' );
		$GLOBALS['sakura_test_attached_files'][ self::ATTACHMENT ] = $main;

		$metadata = array( 'file' => '2026/11/a.webp' );
		if ( $with_size ) {
			$this->touch( 'a-300x200.webp' );
			$metadata['sizes'] = array( 'medium' => array( 'file' => 'a-300x200.webp' ) );
		}
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ]['_wp_attachment_metadata'] = $metadata;
	}

	/**
	 * Record the state an earlier upload left behind.
	 *
	 * @param string $status  Sync status.
	 * @param string $target  Fingerprint stored with the attachment.
	 * @param bool   $keys    Whether the uploaded object keys were recorded.
	 */
	private function seed( string $status, string $target, bool $keys = true ): void {
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ][ Sync_Status::STATUS_META ] = $status;
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ][ Sync_Status::TARGET_META ] = $target;
		if ( $keys ) {
			$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ][ Sync_Status::OBJECTS_META ] = array( self::MAIN_KEY, self::SIZE_KEY );
		}
	}

	private function sources(): array {
		return array(
			300 => array( 'url' => self::CDN . '/2026/11/a-300x200.webp', 'descriptor' => 'w', 'value' => 300 ),
			1024 => array( 'url' => self::CDN . '/2026/11/a.webp', 'descriptor' => 'w', 'value' => 1024 ),
		);
	}

	// ---------------------------------------------------------------- state ---

	public function test_a_site_that_never_uploaded_is_unbound_and_unfrozen(): void {
		$options = new Options( array() );

		$this->assertNull( $options->binding() );
		$this->assertFalse( $options->is_bound() );
		$this->assertFalse( $options->behaviour_frozen(), 'R11 freezes nothing before the first confirmed upload.' );
		$this->assertTrue( $options->binding_allows( array( 'bucket' => 'anything' ) ), 'An unbound site may point anywhere.' );
		$this->assertSame( array(), $options->changed_identity_fields( array( 'bucket' => 'anything' ) ) );
	}

	public function test_the_five_identity_fields_are_frozen_by_a_binding(): void {
		$options = $this->options( array( 'binding' => $this->binding() ) );

		$this->assertTrue( $options->is_bound() );
		$this->assertSame( array(), $options->changed_identity_fields( $this->stored() ) );
	}

	/**
	 * @dataProvider identity_mutations
	 */
	public function test_any_identity_change_is_refused(array $patch, string $field): void {
		$options = $this->options( array( 'binding' => $this->binding() ) );
		$stored  = $this->stored( $patch );

		$this->assertFalse( $options->binding_allows( $stored ), $field . ' must trip R4.' );
		$this->assertSame( array( $field ), $options->changed_identity_fields( $stored ) );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function identity_mutations(): array {
		return array(
			'provider'    => array( array( 'provider' => 'dogecloud' ), 'provider' ),
			'bucket'      => array( array( 'bucket' => 'other-bucket' ), 'bucket' ),
			'endpoint'    => array( array( 'endpoint' => 'https://cos.example.com' ), 'endpoint' ),
			'region'      => array( array( 'region' => 'ap-shanghai' ), 'region' ),
			'upload_path' => array( array( 'upload_path' => 'wp-content/assets' ), 'upload_path' ),
		);
	}

	public function test_fields_the_binding_does_not_freeze_stay_editable(): void {
		$options = $this->options( array( 'binding' => $this->binding() ) );
		$stored  = $this->stored(
			array(
				'key'             => 'rotated-key',
				'secret'          => 'rotated-secret',
				'object_acl'      => 'private',
				'upload_url_path' => 'https://new-cdn.example.com/wp-content/uploads',
				'upload_concurrency' => 8,
			)
		);

		$this->assertTrue( $options->binding_allows( $stored ), 'R4 explicitly does not freeze credentials, ACL or the CDN domain.' );
	}

	public function test_trailing_whitespace_is_not_an_identity_change(): void {
		$options = $this->options( array( 'binding' => $this->binding() ) );
		$stored  = $this->stored( array( 'bucket' => '  nboke  ' ) );

		$this->assertTrue( $options->binding_allows( $stored ) );
	}

	public function test_a_normal_lock_freezes_the_behaviour_switches(): void {
		$options = $this->options(
			array(
				'binding'          => $this->binding( 'false' ),
				'behaviour_locked' => 'true',
				'no_thumb'         => 'true',
				'no_local_saving'  => 'true',
			)
		);

		$this->assertTrue( $options->behaviour_frozen() );
		$this->assertSame(
			array( 'no_thumb' => 'false', 'no_local_saving' => 'false' ),
			$options->behaviour_snapshot(),
			'The snapshot is what the binding was made with, not what the POST put in the option.'
		);
	}

	public function test_the_freeze_survives_unbinding_while_profiles_remain(): void {
		$options = $this->options( array( 'binding' => array(), 'behaviour_locked' => 'true' ) );

		$this->assertFalse( $options->is_bound(), 'Deleting the bound profile clears the binding.' );
		$this->assertTrue( $options->behaviour_frozen(), 'R11: the marker holds until the profile list is empty.' );
	}

	public function test_an_empty_profile_list_is_what_actually_unlocks_the_switches(): void {
		$options = $this->options( array( 'binding' => array(), 'behaviour_locked' => 'true', 'profiles' => array(), 'active_profile_id' => '' ) );

		$this->assertFalse( $options->behaviour_frozen() );
	}

	public function test_lock_binding_records_the_snapshot_once(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $this->stored();

		Options::lock_binding(
			array(
				'provider'        => 'bitiful',
				'bucket'          => 'nboke',
				'endpoint'        => 'https://s3.bitiful.net',
				'region'          => 'cn-east-1',
				'upload_path'     => 'wp-content/uploads',
				'no_local_saving' => 'false',
				'no_thumb'        => 'false',
			),
			'prof_live',
			7
		);

		$stored = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertSame( 'prof_live', $stored['binding']['profile_id'] );
		$this->assertSame( 'true', $stored['behaviour_locked'] );

		// A second upload under a later configuration never rewrites the snapshot.
		Options::lock_binding(
			array( 'provider' => 'dogecloud', 'bucket' => 'other' ),
			'prof_other',
			7
		);

		$again = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertSame( 'bitiful', $again['binding']['provider'] );
		$this->assertSame( 'prof_live', $again['binding']['profile_id'] );
	}

	public function test_lock_binding_repairs_a_cleared_marker_without_rewriting(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $this->stored( array( 'binding' => $this->binding(), 'behaviour_locked' => 'false' ) );

		Options::lock_binding( array( 'provider' => 'other' ), 'prof_other', 1 );

		$stored = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertSame( 'true', $stored['behaviour_locked'] );
		$this->assertSame( 'prof_live', $stored['binding']['profile_id'], 'The snapshot always belongs to the first confirmed upload.' );
	}

	public function test_clear_binding_keeps_the_marker_until_the_list_is_empty(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $this->stored( array( 'binding' => $this->binding(), 'behaviour_locked' => 'true' ) );

		Options::clear_binding();
		$stored = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertSame( array(), $stored['binding'] );
		$this->assertSame( 'true', $stored['behaviour_locked'], 'Other profiles are still there, so the switches stay frozen.' );

		$stored['profiles'] = array();
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $stored;
		Options::clear_binding();

		$this->assertSame( 'false', $GLOBALS['sakura_test_options'][ Options::OPTION ]['behaviour_locked'] );
	}

	// ------------------------------------------------------- delete table ---

	/**
	 * R7 decision table, as the renderer and the server both evaluate it.
	 *
	 * @param bool   $active     Is the active profile.
	 * @param bool   $enabled    Is S3 on.
	 * @param bool   $bound_here Is the bound profile.
	 * @param bool   $risk       Binding ran with no_local_saving.
	 * @param int    $count      Saved profiles.
	 */
	private function refusal( bool $active, bool $enabled, bool $bound_here, bool $risk, int $count ): string {
		$reflection = new ReflectionMethod( S3_Storage_Page::class, 'delete_refusal' );
		$reflection->setAccessible( true );

		return (string) $reflection->invoke(
			null,
			'prof_live',
			$active,
			$enabled,
			$bound_here ? 'prof_live' : '',
			$risk,
			$count
		);
	}

	public function test_a_plain_saved_profile_is_always_deletable(): void {
		$this->assertSame( '', $this->refusal( false, true, false, false, 3 ) );
		$this->assertSame( '', $this->refusal( false, false, false, true, 3 ) );
	}

	public function test_the_live_profile_cannot_be_deleted(): void {
		$this->assertSame( 'delete_active', $this->refusal( true, true, false, false, 3 ) );
		$this->assertSame( 'delete_bound', $this->refusal( true, true, true, false, 3 ) );
	}

	public function test_a_paused_unbound_profile_can_be_deleted(): void {
		$this->assertSame( '', $this->refusal( true, false, false, true, 3 ) );
	}

	public function test_a_high_risk_lock_refuses_to_leave_other_profiles(): void {
		$this->assertSame( 'pending_profiles', $this->refusal( true, false, true, true, 2 ) );
	}

	public function test_a_normal_lock_may_leave_other_profiles_behind(): void {
		$this->assertSame( '', $this->refusal( true, false, true, false, 2 ) );
	}

	public function test_the_last_high_risk_profile_can_go(): void {
		$this->assertSame( '', $this->refusal( true, false, true, true, 1 ) );
	}

	// ------------------------------------------------------------- prompt ---

	/** @return array<string,mixed> */
	private function confirm( int $count, bool $is_unbind, bool $risk, string $name = '' ): array {
		$reflection = new ReflectionMethod( S3_Storage_Page::class, 'delete_confirm' );
		$reflection->setAccessible( true );

		return (array) $reflection->invoke( null, $count, $is_unbind, $risk, $name );
	}

	public function test_a_plain_delete_prompt_asks_for_nothing_extra(): void {
		$confirm = $this->confirm( 0, false, false );

		$this->assertSame( array(), $confirm['items'] );
		$this->assertSame( '', $confirm['require'] );
	}

	public function test_unbinding_warns_that_the_bucket_is_not_touched(): void {
		$confirm = $this->confirm( 0, true, false );

		$this->assertCount( 2, $confirm['items'] );
		$this->assertStringContainsString( '云端对象不会跟着删除', implode( ' ', $confirm['items'] ) );
		$this->assertSame( '', $confirm['require'] );
	}

	public function test_a_high_risk_prompt_names_the_lost_attachments_and_asks_for_the_name(): void {
		$confirm = $this->confirm( 133, true, true, '缤纷云 · nboke' );

		$this->assertCount( 5, $confirm['items'] );
		$this->assertStringContainsString( '133', implode( ' ', $confirm['items'] ) );
		$this->assertStringContainsString( '最后一次机会', implode( ' ', $confirm['items'] ) );
		$this->assertSame( '缤纷云 · nboke', $confirm['require'], 'R10 asks the name back before a permanent delete.' );
	}

	// -------------------------------------------------------------- srcset ---

	private function storage( array $extra = array() ): Storage {
		return new Storage( $this->options( $extra ) );
	}

	public function test_no_thumb_drops_every_candidate(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, Synchronous_Uploader::target_hash_for( $this->options() ) );

		$this->assertSame(
			array(),
			$this->storage( array( 'no_thumb' => 'true' ) )->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ),
			'R12: sub-sizes are never uploaded under no_thumb, so no candidate can resolve.'
		);
	}

	public function test_a_confirmed_attachment_keeps_its_candidates(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, Synchronous_Uploader::target_hash_for( $this->options() ) );

		$this->assertSame(
			$this->sources(),
			$this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ),
			'The active bucket is the source of truth here, so the candidates are left alone.'
		);
	}

	public function test_a_failed_upload_drops_the_candidates_that_point_at_its_own_objects(): void {
		$this->attachment();
		$this->seed( Sync_Status::FAILED, '' );

		$this->assertSame(
			array(),
			$this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ),
			'The local file is the copy that resolves; the candidates point at objects the bucket may not hold.'
		);
	}

	public function test_a_bucket_switch_without_index_evidence_falls_back_to_the_local_copy(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, 'fingerprint-of-the-previous-bucket' );

		$this->assertSame( array(), $this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ) );
	}

	public function test_a_bucket_switch_confirmed_by_the_index_keeps_the_candidates(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, 'fingerprint-of-the-previous-bucket' );
		$this->db->rows[ $this->db->key( $this->bucket(), self::MAIN_KEY ) ] = 'synced';
		$this->db->rows[ $this->db->key( $this->bucket(), self::SIZE_KEY ) ] = 'synced';

		$this->assertSame( $this->sources(), $this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ) );
	}

	public function test_candidates_belonging_to_another_file_are_left_alone(): void {
		$this->attachment();
		$this->seed( Sync_Status::FAILED, '' );

		$foreign = array( 800 => array( 'url' => self::CDN . '/2026/11/other.webp', 'descriptor' => 'w', 'value' => 800 ) );
		$this->assertSame( $foreign, $this->storage()->filter_srcset( $foreign, array(), '', array(), self::ATTACHMENT ) );
	}

	public function test_an_attachment_without_recorded_objects_keeps_its_candidates(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, '', false );

		$this->assertSame(
			$this->sources(),
			$this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ),
			'Nothing was recorded, so the plugin cannot claim the candidates are wrong.'
		);
	}

	public function test_markup_without_an_attachment_is_untouched(): void {
		$this->attachment();
		$this->seed( Sync_Status::FAILED, '' );

		$this->assertSame( $this->sources(), $this->storage()->filter_srcset( $this->sources(), array(), '', array(), 0 ) );
	}

	// ----------------------------------------------------- switch refusal ---

	public function test_a_normal_lock_allows_switching_to_another_profile(): void {
		$this->assertFalse(
			$this->switch_refused( 'false' ),
			'R5: a normal lock still lets the operator move between configurations.'
		);
	}

	public function test_a_high_risk_lock_refuses_switching_away(): void {
		$this->assertTrue( $this->switch_refused( 'true' ), 'R10: the objects only exist in this bucket, so switching would break every URL.' );
	}

	public function test_reenabling_the_bound_profile_is_not_a_switch(): void {
		$this->assertFalse( $this->switch_refused( 'true', 'prof_live' ) );
	}

	/**
	 * Whether activate() would refuse this target under the given lock.
	 *
	 * @param string $no_local_saving Switch the binding was made with.
	 * @param string $target          Profile being switched to.
	 */
	private function switch_refused( string $no_local_saving, string $target = 'prof_other' ): bool {
		$reflection = new ReflectionMethod( Profile_Manager::class, 'switch_refused_by_binding' );
		$reflection->setAccessible( true );

		$options = $this->options(
			array(
				'binding'          => $this->binding( $no_local_saving ),
				'behaviour_locked' => 'true',
			)
		);

		return (bool) $reflection->invoke( null, $options->binding(), $target );
	}

	// ------------------------------------------------------------- feature ---

	public function test_the_index_is_only_asked_when_the_feature_is_on_and_installed(): void {
		$this->attachment();
		$this->seed( Sync_Status::SYNCED, 'fingerprint-of-the-previous-bucket' );

		$this->db->rows[ $this->db->key( $this->bucket(), self::MAIN_KEY ) ] = 'synced';
		$this->db->rows[ $this->db->key( $this->bucket(), self::SIZE_KEY ) ] = 'synced';

		$GLOBALS['sakura_test_options'][ Feature::OPTION ] = '0';
		$this->assertSame( array(), $this->storage()->filter_srcset( $this->sources(), array(), '', array(), self::ATTACHMENT ) );
		$this->assertSame( 0, $this->db->queries, 'A disabled index must not be queried.' );
	}
}
