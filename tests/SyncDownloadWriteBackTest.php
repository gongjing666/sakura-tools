<?php
/**
 * Tests for the attachment bookkeeping a restored download writes back.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Sync_Actions;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Index_Repository;

require_once dirname( __DIR__ ) . '/src/Sync/Action_Worker.php';
require_once dirname( __DIR__ ) . '/src/S3/Sync_Actions.php';

/**
 * A download restores an object that already sits in the active bucket, so the
 * attachment must end up synced. Without that, add_attachment leaves it
 * pending and the URL filter falls back to the local uploads path.
 */
final class SyncDownloadWriteBackTest extends TestCase {

	private const ATTACHMENT = 7;
	private const PROFILE    = 'prof_alpha';
	private const KEY        = 'wp-content/uploads/2026/11/a.webp';

	private $previous_wpdb;

	private $previous_post_meta;

	private $previous_attached;

	private $previous_upload_dir;

	private $previous_upload_baseurl;

	private string $dir = '';

	protected function setUp(): void {
		$this->previous_wpdb           = $GLOBALS['wpdb'] ?? null;
		$this->previous_post_meta      = $GLOBALS['sakura_test_post_meta'] ?? null;
		$this->previous_attached       = $GLOBALS['sakura_test_attached_files'] ?? null;
		$this->previous_upload_dir     = $GLOBALS['sakura_test_upload_dir'] ?? null;
		$this->previous_upload_baseurl = $GLOBALS['sakura_test_upload_baseurl'] ?? null;

		$GLOBALS['wpdb']                    = new Sakura_Test_Wpdb();
		$GLOBALS['sakura_test_post_meta']   = array();
		$GLOBALS['sakura_test_upload_dir']  = '';
		$GLOBALS['sakura_test_upload_baseurl'] = 'https://cdn.example.com/wp-content/uploads';

		$this->dir = sys_get_temp_dir() . '/sio-download-' . uniqid( '', true );
		mkdir( $this->dir . '/2026/11', 0777, true );
		file_put_contents( $this->dir . '/2026/11/a.webp', 'webp-bytes' );

		$GLOBALS['sakura_test_upload_dir']     = $this->dir;
		$GLOBALS['sakura_test_attached_files'] = array( self::ATTACHMENT => $this->dir . '/2026/11/a.webp' );
	}

	protected function tearDown(): void {
		foreach ( array( '/2026/11/a.webp', '/2026/11/a-150x150.webp' ) as $relative ) {
			if ( is_file( $this->dir . $relative ) ) {
				unlink( $this->dir . $relative );
			}
		}
		foreach ( array( '/2026/11', '/2026', '' ) as $relative ) {
			if ( is_dir( $this->dir . $relative ) ) {
				@rmdir( $this->dir . $relative );
			}
		}

		$GLOBALS['wpdb']                    = $this->previous_wpdb;
		$GLOBALS['sakura_test_post_meta']   = $this->previous_post_meta;
		$GLOBALS['sakura_test_attached_files'] = $this->previous_attached;
		$GLOBALS['sakura_test_upload_dir']  = $this->previous_upload_dir;
		$GLOBALS['sakura_test_upload_baseurl'] = $this->previous_upload_baseurl;
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
				'upload_url_path'   => 'https://cdn.example.com/wp-content/uploads',
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => self::PROFILE,
				'profiles'          => array(
					self::PROFILE => array(
						'id'          => self::PROFILE,
						'name'        => 'Bitiful',
						'provider'    => 'bitiful',
						'bucket'      => 'nboke',
						'endpoint'    => 'https://s3.bitiful.net',
						'key'         => 'test-key',
						'secret'      => 'test-secret',
						'upload_path' => 'wp-content/uploads',
					),
				),
			)
		);
	}

	private function worker( Options $options ): Action_Worker {
		return new Action_Worker( $options, new Storage_Adapter( $options ), new Index_Repository() );
	}

	private function write_back( Options $options, string $action, string $key = self::KEY ): void {
		$this->worker( $options )->write_back(
			self::ATTACHMENT,
			$key,
			$action,
			array(
				'options' => $options,
				'adapter' => new Storage_Adapter( $options ),
			),
			Synchronous_Uploader::target_hash_for( $options ),
			self::PROFILE
		);
	}

	/**
	 * A download is proof the object exists in the active bucket.
	 */
	public function test_write_back_marks_a_downloaded_attachment_synced(): void {
		$options = $this->options();
		$status  = new Sync_Status();
		$status->mark_pending( self::ATTACHMENT );

		$this->write_back( $options, 'download' );

		$this->assertSame( Sync_Status::SYNCED, $status->status( self::ATTACHMENT ) );
		$this->assertSame( Synchronous_Uploader::target_hash_for( $options ), $status->target_hash( self::ATTACHMENT ) );
		$this->assertSame( array( self::KEY ), $status->objects( self::ATTACHMENT ) );
	}

	/**
	 * The restored version map must describe the file that was just written.
	 */
	public function test_write_back_records_the_manifest_version_of_the_restored_file(): void {
		$options = $this->options();

		$this->write_back( $options, 'download' );

		$versions = ( new Sync_Status() )->versions( self::ATTACHMENT );
		$expected = filesize( $this->dir . '/2026/11/a.webp' ) . ':' . filemtime( $this->dir . '/2026/11/a.webp' );

		$this->assertSame( $expected, $versions[ self::KEY ] ?? null );
	}

	/**
	 * Restoring one sub-size must not declare the whole attachment uploaded.
	 */
	public function test_write_back_keeps_the_attachment_pending_when_a_sibling_key_is_missing(): void {
		$options = $this->options();
		file_put_contents( $this->dir . '/2026/11/a-150x150.webp', 'thumb-bytes' );
		$GLOBALS['sakura_test_post_meta'][ self::ATTACHMENT ] = array(
			'_wp_attachment_metadata' => array(
				'file'  => '2026/11/a.webp',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'a-150x150.webp' ),
				),
			),
		);

		$status = new Sync_Status();
		$status->mark_pending( self::ATTACHMENT );

		$this->write_back( $options, 'download' );

		$this->assertSame( Sync_Status::PENDING, $status->status( self::ATTACHMENT ) );
		$this->assertSame( array( self::KEY ), $status->objects( self::ATTACHMENT ) );
		$this->assertSame( '', $status->target_hash( self::ATTACHMENT ) );
	}

	/**
	 * Any other action must leave the sync state alone.
	 */
	public function test_write_back_ignores_actions_it_does_not_own(): void {
		$status = new Sync_Status();
		$status->mark_pending( self::ATTACHMENT );

		$this->write_back( $this->options(), 'adopt' );

		$this->assertSame( Sync_Status::PENDING, $status->status( self::ATTACHMENT ) );
		$this->assertSame( array(), $status->objects( self::ATTACHMENT ) );
	}

	/**
	 * Sync_Actions::download() hands the adopted row to the same bookkeeping.
	 */
	public function test_mark_downloaded_stamps_an_adopted_row(): void {
		$options = $this->options();
		$actions = new Sync_Actions( $options, new Storage_Adapter( $options ) );
		$status  = new Sync_Status();
		$status->mark_pending( self::ATTACHMENT );

		$method = new ReflectionMethod( Sync_Actions::class, 'mark_downloaded' );
		$method->setAccessible( true );
		$method->invoke( $actions, self::ATTACHMENT, self::KEY );

		$this->assertSame( Sync_Status::SYNCED, $status->status( self::ATTACHMENT ) );
		$this->assertSame( Synchronous_Uploader::target_hash_for( $options ), $status->target_hash( self::ATTACHMENT ) );
	}

	/**
	 * An unknown attachment id is a no-op rather than a fatal.
	 */
	public function test_mark_downloaded_without_an_attachment_is_a_no_op(): void {
		$options = $this->options();
		$actions = new Sync_Actions( $options, new Storage_Adapter( $options ) );

		$method = new ReflectionMethod( Sync_Actions::class, 'mark_downloaded' );
		$method->setAccessible( true );
		$method->invoke( $actions, 0, self::KEY );

		$this->assertSame( array(), $GLOBALS['sakura_test_post_meta'] );
	}
}
