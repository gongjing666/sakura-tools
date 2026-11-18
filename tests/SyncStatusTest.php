<?php
/**
 * Sync status tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\S3\Sync_Status;

final class SyncStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_post_meta'] = array();
		$GLOBALS['sakura_test_failed_ids'] = array();
	}

	public function test_marks_pending_and_synced_and_records_keys(): void {
		$status = new Sync_Status();
		$status->mark_pending( 10 );
		$this->assertSame( Sync_Status::PENDING, $status->status( 10 ) );

		$status->record_object( 10, 'wp-content/uploads/a.jpg' );
		$status->record_object( 10, 'wp-content/uploads/a.jpg' );
		$status->record_object( 10, 'wp-content/uploads/b.jpg' );
		$status->mark_synced( 10 );

		$this->assertSame( Sync_Status::SYNCED, $status->status( 10 ) );
		$this->assertSame(
			array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/b.jpg' ),
			$status->objects( 10 )
		);
		$this->assertSame( '', $status->error( 10 ) );
	}

	public function test_set_objects_replaces_instead_of_appending(): void {
		$status = new Sync_Status();
		$status->record_object( 12, 'wp-content/uploads/a.webp' );
		$status->record_object( 12, 'wp-content/uploads/a-800x450.webp' );

		$status->set_objects( 12, array( ' wp-content/uploads/a.jpg ', 'wp-content/uploads/a.jpg' ) );

		$this->assertSame( array( 'wp-content/uploads/a.jpg' ), $status->objects( 12 ) );
	}

	public function test_set_objects_with_empty_list_clears_the_meta(): void {
		$status = new Sync_Status();
		$status->record_object( 13, 'wp-content/uploads/a.jpg' );

		$status->set_objects( 13, array() );

		$this->assertSame( array(), $status->objects( 13 ) );
	}

	public function test_enqueue_creates_job_and_failure_state(): void {
		$status = new Sync_Status();
		$status->enqueue( 11, 'wp-content/uploads/a.jpg', ABSPATH . 'tests/a.jpg', 'boom' );

		$this->assertSame( Sync_Status::FAILED, $status->status( 11 ) );
		$this->assertSame( 'boom', $status->error( 11 ) );
		$jobs = $status->jobs( 11 );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'tests/a.jpg', $jobs[ md5( 'wp-content/uploads/a.jpg' ) ]['path'] );
	}

	public function test_fail_job_backoff_and_dead_state(): void {
		$status = new Sync_Status();
		$status->enqueue( 12, 'k', ABSPATH . 'tests/a.jpg', 'boom' );
		$hash = md5( 'k' );

		for ( $i = 0; $i < 8; $i++ ) {
			$status->fail_job( 12, $hash, 'still failing' );
		}

		$this->assertSame( Sync_Status::DEAD, $status->status( 12 ) );
		$this->assertSame( 8, $status->jobs( 12 )[ $hash ]['attempts'] );
	}

	public function test_failed_attachments_and_clear(): void {
		$GLOBALS['sakura_test_failed_ids'] = array( 7, 8 );
		$status = new Sync_Status();
		$this->assertSame( array( 7, 8 ), $status->failed_attachments( 5 ) );

		$status->enqueue( 7, 'k', ABSPATH . 'tests/a.jpg', 'boom' );
		$status->clear( 7 );
		$this->assertSame( '', $status->status( 7 ) );
		$this->assertSame( array(), $status->jobs( 7 ) );
	}

	public function test_synced_state_persists_target_fingerprint_and_clear_removes_it(): void {
		$status = new Sync_Status();
		$status->mark_synced( 14, 'target-a' );

		$this->assertSame( 'target-a', $status->target_hash( 14 ) );
		$status->clear( 14 );
		$this->assertSame( '', $status->target_hash( 14 ) );
	}

	public function test_retarget_only_rewrites_the_fingerprint(): void {
		$status = new Sync_Status();
		$status->mark_synced( 16, 'target-a' );
		$GLOBALS['sakura_test_post_meta'][16][ Sync_Status::SYNCED_META ] = 1700000000;

		$status->retarget( 16, 'target-b' );

		$this->assertSame( 'target-b', $status->target_hash( 16 ) );
		$this->assertSame( Sync_Status::SYNCED, $status->status( 16 ) );
		$this->assertSame( 1700000000, (int) get_post_meta( 16, Sync_Status::SYNCED_META, true ) );
	}

	public function test_retarget_ignores_empty_fingerprints_and_invalid_ids(): void {
		$status = new Sync_Status();
		$status->mark_synced( 17, 'target-a' );

		$status->retarget( 17, '' );
		$status->retarget( 0, 'target-b' );

		$this->assertSame( 'target-a', $status->target_hash( 17 ) );
		$this->assertSame( '', $status->target_hash( 0 ) );
	}

	public function test_synced_state_records_who_confirmed_the_fingerprint(): void {
		$status = new Sync_Status();
		$status->mark_synced( 20, 'target-a', 'prof_new' );

		$confirmation = $status->confirmation( 20 );

		$this->assertSame( 'target-a', $confirmation['hash'] );
		$this->assertSame( 'upload', $confirmation['confirmed_by'] );
		$this->assertSame( 'prof_new', $confirmation['profile_id'] );
		$this->assertGreaterThan( 0, $confirmation['confirmed_at'] );
	}

	public function test_retarget_marks_the_confirmation_as_a_scan(): void {
		$status = new Sync_Status();
		$status->mark_synced( 21, 'target-a', 'prof_new' );
		$GLOBALS['sakura_test_post_meta'][21][ Sync_Status::SYNCED_META ] = 1700000000;

		$status->retarget( 21, 'target-b', 'prof_new' );

		$confirmation = $status->confirmation( 21 );
		$this->assertSame( 'target-b', $confirmation['hash'] );
		$this->assertSame( 'scan', $confirmation['confirmed_by'] );
		$this->assertSame( 1700000000, (int) get_post_meta( 21, Sync_Status::SYNCED_META, true ) );
	}

	public function test_skipping_the_fingerprint_clears_the_confirmation(): void {
		$status = new Sync_Status();
		$status->mark_synced( 22, 'target-a', 'prof_new' );
		$this->assertNotSame( array(), $status->confirmation( 22 ) );

		$status->mark_synced( 22 );

		$this->assertSame( array(), $status->confirmation( 22 ) );
		$this->assertSame( '', $status->target_hash( 22 ) );
	}

	public function test_clear_drops_the_confirmation_with_the_fingerprint(): void {
		$status = new Sync_Status();
		$status->mark_synced( 23, 'target-a', 'prof_new' );

		$status->clear( 23 );

		$this->assertSame( array(), $status->confirmation( 23 ) );
	}

	public function test_lost_local_count_accepts_the_legacy_fingerprint_form(): void {
		$GLOBALS['sakura_test_failed_ids'] = array( 30, 31 );
		$GLOBALS['sakura_test_attached_files'] = array(
			30 => '/tmp/sio-missing-legacy.webp',
			31 => '/tmp/sio-missing-other-bucket.webp',
		);

		$options = new Options(
			array(
				'provider'          => 'bitiful',
				'bucket'            => 'nboke',
				'endpoint'          => 'https://s3.bitiful.net',
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => 'prof_live',
				'profiles'          => array(
					'prof_live'   => array(
						'id'          => 'prof_live',
						'provider'    => 'bitiful',
						'bucket'      => 'nboke',
						'endpoint'    => 'https://s3.bitiful.net',
						'upload_path' => 'wp-content/uploads',
					),
					'prof_legacy' => array(
						'id'          => 'prof_legacy',
						'provider'    => 'bitiful',
						'bucket'      => 'nboke',
						'endpoint'    => 'https://s3.bitiful.net',
						'upload_path' => 'wp-content/uploads',
					),
				),
			)
		);

		$legacy = Synchronous_Uploader::target_hashes_for( $options, 'prof_legacy' );
		$GLOBALS['sakura_test_post_meta'][30] = array(
			Sync_Status::STATUS_META => Sync_Status::SYNCED,
			Sync_Status::TARGET_META => (string) end( $legacy ),
		);
		$GLOBALS['sakura_test_post_meta'][31] = array(
			Sync_Status::STATUS_META => Sync_Status::SYNCED,
			Sync_Status::TARGET_META => 'fingerprint-of-another-bucket',
		);

		$this->assertSame( 1, ( new Sync_Status() )->lost_local_count( $options ) );
	}
	public function test_removed_object_marks_existing_attachment_failed(): void {
		$GLOBALS['sakura_test_posts'][ 15 ] = (object) array( 'ID' => 15 );
		$status = new Sync_Status();
		$status->record_object( 15, 'wp-content/uploads/a.jpg' );
		$status->mark_synced( 15, 'target-a' );
		$status->mark_object_removed( 15, 'wp-content/uploads/a.jpg' );

		$this->assertSame( Sync_Status::FAILED, $status->status( 15 ) );
		$this->assertSame( array(), $status->objects( 15 ) );
		$this->assertStringContainsString( '云端对象已删除', $status->error( 15 ) );
	}

	public function test_label_for_missing_and_known_states(): void {
		$status = new Sync_Status();
		$this->assertSame( '未同步', $status->label( 99 ) );
		$status->mark_pending( 99 );
		$this->assertSame( '等待同步', $status->label( 99 ) );
	}
}
