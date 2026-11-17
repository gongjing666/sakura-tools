<?php
/**
 * Tests for the ledger charge a restored bucket download performs.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace {
	if ( ! function_exists( 'maybe_unserialize' ) ) {
		function maybe_unserialize( $value ) {
			if ( ! is_string( $value ) ) {
				return $value;
			}
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
			return false !== $decoded || 'b:0;' === $value ? $decoded : $value;
		}
	}
	if ( ! function_exists( 'path_is_absolute' ) ) {
		function path_is_absolute( $path ) {
			return str_starts_with( (string) $path, '/' ) || (bool) preg_match( '/^[A-Za-z]:[\\\\\/]/', (string) $path );
		}
	}
	if ( ! function_exists( 'get_userdata' ) ) {
		function get_userdata( $user_id ) {
			$id = (int) $user_id;
			return $id > 0 ? (object) array( 'ID' => $id, 'user_login' => 'sakura' ) : false;
		}
	}
	if ( ! function_exists( 'get_post_mime_type' ) ) {
		function get_post_mime_type( $post = null ) {
			return 'image/webp';
		}
	}
}

namespace Sakura\ImageOptimizer\Tests {
	use Nboke\UserFiles\Repositories\Files;
	use Nboke\UserFiles\Services\Media_Adoption;
	use Nboke\UserFiles\Services\Quota;
	use Nboke\UserFiles\Services\Sync_Service;
	use PHPUnit\Framework\TestCase;
	use Sakura\ImageOptimizer\S3\Options;
	use Sakura\ImageOptimizer\S3\Storage_Adapter;
	use Sakura\ImageOptimizer\S3\Sync_Actions;
	require_once dirname( __DIR__ ) . '/src/UserFiles/Schema.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Support/Time.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Adoption_Manifest.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Files.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Quota.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Sync_Service.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Media_Adoption.php';
	require_once dirname( __DIR__ ) . '/src/Sync/Action_Worker.php';
	require_once dirname( __DIR__ ) . '/src/S3/Sync_Actions.php';

	/**
	 * A file restored from the bucket reaches the media library through
	 * Attachment_Adopter, which suppresses adoption on purpose. Nothing revisits
	 * the attachment afterwards, so without an explicit charge it belongs to no
	 * owner: no quota, and the media library files it under "unassigned media".
	 */
	final class MediaAdoptionTest extends TestCase {

		private const ATTACHMENT = 321;
		private const OWNER      = 1;
		private const BYTES      = 192994;

		private $previous_db;

		private $previous_posts;

		private $previous_attached;

		private string $dir = '';

		protected function setUp(): void {
			$this->previous_db       = $GLOBALS['wpdb'] ?? null;
			$this->previous_posts    = $GLOBALS['sakura_test_posts'] ?? null;
			$this->previous_attached = $GLOBALS['sakura_test_attached_files'] ?? null;

			$this->dir = sys_get_temp_dir() . '/sio-adopt-' . uniqid( '', true );
			mkdir( $this->dir, 0777, true );
			file_put_contents( $this->dir . '/2026-11-a.webp', str_repeat( 'x', self::BYTES ) );

			$GLOBALS['sakura_test_upload_dir'] = $this->dir;
			$GLOBALS['sakura_test_posts']      = array(
				self::ATTACHMENT => (object) array(
					'ID'          => self::ATTACHMENT,
					'post_type'   => 'attachment',
					'post_author' => self::OWNER,
					'post_title'  => '20261117-081224-EJ2zY',
				),
			);
			$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] = array(
				'_wp_attached_file' => '2026-11-a.webp',
			);
			$GLOBALS['sakura_test_attached_files']                = array(
				self::ATTACHMENT => $this->dir . '/2026-11-a.webp',
			);

			$GLOBALS['wpdb'] = new AdoptionDatabase();
		}

		protected function tearDown(): void {
			if ( is_file( $this->dir . '/2026-11-a.webp' ) ) {
				unlink( $this->dir . '/2026-11-a.webp' );
			}
			if ( is_dir( $this->dir ) ) {
				rmdir( $this->dir );
			}
			unset( $GLOBALS['sakura_test_upload_dir'] );
			unset( $GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] );

			$GLOBALS['wpdb']                       = $this->previous_db;
			$GLOBALS['sakura_test_posts']          = $this->previous_posts;
			$GLOBALS['sakura_test_attached_files'] = $this->previous_attached;
		}

		private function service(): Media_Adoption {
			$files = new Files();
			$quota = new Quota();

			return new Media_Adoption( $files, $quota, new Sync_Service( $files, $quota ) );
		}

		/**
		 * The charge is the disk size of the restored file, booked to its author.
		 */
		public function test_restored_attachment_is_charged_to_its_author(): void {
			$charged = $this->service()->adopt( self::ATTACHMENT );

			$this->assertSame( self::BYTES, $charged );

			$db = $GLOBALS['wpdb'];
			$this->assertSame( self::OWNER, $db->created_owner );
			$this->assertSame( self::BYTES, $db->created_bytes );
			$this->assertSame( self::BYTES, $db->charged_to[ self::OWNER ] ?? 0 );
			$this->assertSame( 'media', $db->created_source );
		}

		/**
		 * Calling twice must not charge twice: the row already exists.
		 */
		public function test_second_adoption_of_the_same_attachment_does_not_charge_again(): void {
			$first  = $this->service()->adopt( self::ATTACHMENT );
			$second = $this->service()->adopt( self::ATTACHMENT );

			$this->assertSame( self::BYTES, $first );
			$this->assertSame( self::BYTES, $second );

			$db = $GLOBALS['wpdb'];
			$this->assertSame( 1, $db->inserts, 'the ledger row must be written once' );
			$this->assertSame( self::BYTES, $db->charged_to[ self::OWNER ] ?? 0, 'the owner must be charged once' );
		}

		/**
		 * An authorless attachment has no quota to charge, and the media lists
		 * already show it that way; adoption must leave it alone.
		 */
		public function test_attachment_without_an_author_is_left_outside_the_ledger(): void {
			$GLOBALS['sakura_test_posts'][ self::ATTACHMENT ]->post_author = 0;

			$this->assertSame( 0, $this->service()->adopt( self::ATTACHMENT ) );

			$db = $GLOBALS['wpdb'];
			$this->assertSame( 0, $db->inserts );
			$this->assertSame( array(), $db->charged_to );
		}

		/**
		 * A file that vanished before adoption cannot be proven, so charging it
		 * would invent bytes that no one can account for.
		 */
		public function test_missing_local_file_is_not_charged(): void {
			unlink( $this->dir . '/2026-11-a.webp' );

			$this->assertSame( 0, $this->service()->adopt( self::ATTACHMENT ) );

			$db = $GLOBALS['wpdb'];
			$this->assertSame( array(), $db->charged_to );
		}

		/**
		 * A non-attachment post (id collision, wrong type) is never adopted.
		 */
		public function test_non_attachment_post_is_refused(): void {
			$GLOBALS['sakura_test_posts'][ self::ATTACHMENT ]->post_type = 'post';

			$this->assertSame( 0, $this->service()->adopt( self::ATTACHMENT ) );
			$this->assertSame( 0, $GLOBALS['wpdb']->inserts );
		}

		/**
		 * Unknown ids short-circuit before any query runs.
		 */
		public function test_zero_attachment_id_is_a_no_op(): void {
			$this->assertSame( 0, $this->service()->adopt( 0 ) );
			$this->assertSame( 0, $GLOBALS['wpdb']->inserts );
		}

		/**
		 * The download path itself must reach this service: the storage module
		 * charges through Sync_Actions, which is the single funnel for the single,
		 * bulk and queued downloads alike.
		 */
		public function test_download_path_charges_the_restored_attachment(): void {
			$options = new Options(
				array(
					'enabled'           => 'true',
					'provider'          => 'bitiful',
					'bucket'            => 'nboke',
					'endpoint'          => 'https://s3.bitiful.net',
					'key'               => 'test-key',
					'secret'            => 'test-secret',
					'upload_url_path'   => 'https://cdn.example.com/wp-content/uploads',
					'upload_path'       => 'wp-content/uploads',
					'active_profile_id' => 'prof_alpha',
				)
			);

			$actions = new Sync_Actions( $options, new Storage_Adapter( $options ) );
			$method  = new \ReflectionMethod( Sync_Actions::class, 'charge_owner' );
			$method->setAccessible( true );
			$method->invoke( $actions, self::ATTACHMENT );

			$this->assertSame( self::BYTES, $GLOBALS['wpdb']->charged_to[ self::OWNER ] ?? 0 );
		}
	}

	/**
	 * Ledger double: stores the `sufm_files` row and records the charges.
	 */
	final class AdoptionDatabase {

		public string $prefix = 'test_';

		public string $last_error = '';

		public string $postmeta = 'test_postmeta';

		public string $posts = 'test_posts';

		public int $inserts = 0;

		public int $created_owner = 0;

		public int $created_bytes = 0;

		public string $created_source = '';

		/** @var array<int,int> */
		public array $charged_to = array();

		/** @var object|null */
		public $row = null;

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			return preg_replace_callback(
				'/%[dsf]/',
				static function ( $match ) use ( &$args ) {
					$arg = array_shift( $args );

					if ( '%d' === $match[0] ) {
						return (string) (int) $arg;
					}
					if ( '%f' === $match[0] ) {
						return (string) (float) $arg;
					}

					return "'" . addslashes( (string) $arg ) . "'";
				},
				(string) $sql
			);
		}

		public function insert( $table, $data, $formats = array() ) {
			$this->inserts++;

			if ( ! str_contains( (string) $table, 'sufm_files' ) ) {
				return 1;
			}

			$this->created_owner  = (int) ( $data['owner_id'] ?? 0 );
			$this->created_bytes  = (int) ( $data['charged_bytes'] ?? 0 );
			$this->created_source = (string) ( $data['source'] ?? '' );

			$this->row = (object) array_merge(
				$data,
				array(
					'id'             => 55,
					'lifecycle'      => 'active',
					'manifest_version' => (int) ( $data['manifest_version'] ?? 0 ),
				)
			);

			return 1;
		}

		public function update( $table, $data, $where, ...$formats ) {
			if ( $this->row && str_contains( (string) $table, 'sufm_files' ) ) {
				foreach ( $data as $key => $value ) {
					$this->row->$key = $value;
				}
			}

			return 1;
		}

		public function delete( $table, $where, $formats = array() ) {
			return 1;
		}

		public function query( $sql ) {
			$sql = (string) $sql;

			if ( str_contains( $sql, 'used_bytes = GREATEST' ) && preg_match( '/\+\s*(-?\d+)/', $sql, $m ) ) {
				// The owner is the last quoted/dumped value appended by prepare();
				// we only keep one ledger row here, so charge the recorded owner.
				$this->charged_to[ $this->created_owner ] = ( $this->charged_to[ $this->created_owner ] ?? 0 ) + (int) $m[1];
			}

			return 1;
		}

		public function get_row( $sql ) {
			return str_contains( (string) $sql, 'sufm_files' ) ? $this->row : null;
		}

		public function get_var( $sql ) {
			return null;
		}

		public function get_results( $sql ) {
			return array();
		}

		public function get_col( $sql ) {
			return array();
		}
	}
}
