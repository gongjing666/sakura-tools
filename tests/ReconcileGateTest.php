<?php
/**
 * R18: after the storage target moves, the sync center waits for a full reconcile.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Credential_Cipher;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Manager;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Settings_Saver;

require_once dirname( __DIR__ ) . '/src/S3/Options.php';
require_once dirname( __DIR__ ) . '/src/S3/Profile_Manager.php';
require_once dirname( __DIR__ ) . '/src/S3/Settings_Saver.php';

/**
 * A switch to another bucket leaves the sync center full of numbers that
 * describe the previous bucket: coverage, difference counts and object rows
 * are all keyed by the old profile. Until one full reconciliation finishes
 * against the new target the page must refuse to act on those rows, and that
 * reconciliation is the only way out (R18).
 */
final class ReconcileGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
		$this->seed();
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	/** Nothing is gated on a fresh option. */
	public function test_a_fresh_install_has_no_gate(): void {
		$this->assertNull( ( new Options( array() ) )->reconcile_pending() );
	}

	/** Moving to another bucket raises the gate in the same write. */
	public function test_switching_bucket_raises_the_gate(): void {
		$result = ( new Profile_Manager() )->switch_profile( 'prof_other' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['moved'], 'The caller needs to know the target changed.' );
		$this->assertSame( 'prof_other', $raw['reconcile_pending']['profile_id'] );
		$this->assertSame( 'other-bucket', $raw['reconcile_pending']['bucket'] );
		$this->assertSame( '旧桶', $raw['reconcile_pending']['from'] );
	}

	/**
	 * A label-only edit is not a move (R19.3).
	 *
	 * The space field is the multi-cloud console's name for the bucket, and
	 * nothing but three label renderers reads it. Sealing the sync center over
	 * it would charge the admin a full re-scan for a cosmetic change.
	 */
	public function test_a_space_only_change_does_not_raise_the_gate(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ]['profiles']['prof_live']['space'] = 'renamed-in-console';

		$result = ( new Profile_Manager() )->switch_profile( 'prof_live' );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['moved'], 'Editing the console label must not seal the sync center.' );
		$this->assertSame( array(), $GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'] );
	}

	/**
	 * The region still counts: it is a different entry point to the bucket.
	 */
	public function test_a_region_change_still_raises_the_gate(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ]['profiles']['prof_live']['region'] = 'ap-shanghai';

		$result = ( new Profile_Manager() )->switch_profile( 'prof_live' );

		$this->assertTrue( $result['moved'], 'A new region means the old index numbers were read elsewhere.' );
		$this->assertArrayHasKey( 'profile_id', $GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'] );
	}

	/** Re-clicking the profile already in use changes nothing. */
	public function test_reactivating_the_same_target_keeps_the_gate_down(): void {
		$result = ( new Profile_Manager() )->switch_profile( 'prof_live' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['moved'] );
		$this->assertSame( array(), $raw['reconcile_pending'] );
	}

	/** Credential rotation is a save, not a move. */
	public function test_rotating_credentials_does_not_raise_the_gate(): void {
		$raw = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$raw['key'] = Credential_Cipher::encrypt( 'rotated-key' );
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $raw;

		$options = new Options();

		$this->assertSame( 'live-bucket', $options->all()['bucket'] );
		$this->assertNull( $options->reconcile_pending() );
	}

	/** A finished full reconcile releases the gate. */
	public function test_a_finished_reconcile_releases_the_gate(): void {
		$this->raise();

		Options::clear_reconcile_pending( 'prof_other' );

		$this->assertSame( array(), $GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'] );
	}

	/** A reconcile that ran against the old bucket must not release it. */
	public function test_a_reconcile_for_another_profile_keeps_the_gate(): void {
		$this->raise();
		$pending = $GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'];

		Options::clear_reconcile_pending( 'prof_live' );

		$this->assertSame( $pending, $GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'] );
	}

	/** Clearing when nothing is pending is a no-op, not a write. */
	public function test_clearing_an_absent_gate_writes_nothing(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array( 'bucket' => 'nboke' );

		Options::clear_reconcile_pending( 'prof_other' );

		$this->assertSame( array( 'bucket' => 'nboke' ), $GLOBALS['sakura_test_options'][ Options::OPTION ] );
	}

	/** The accessor fills missing keys so the view never guesses. */
	public function test_the_accessor_fills_missing_keys(): void {
		$pending = ( new Options( array( 'reconcile_pending' => array( 'profile_id' => 'prof_other' ) ) ) )->reconcile_pending();

		$this->assertIsArray( $pending );
		$this->assertSame( 'prof_other', $pending['profile_id'] );
		$this->assertSame( 0, $pending['at'] );
		$this->assertSame( '', $pending['from'] );
		$this->assertSame( '', $pending['bucket'] );
	}

	/** Saving the connection form must not drop the gate. */
	public function test_saving_the_connection_form_keeps_the_gate(): void {
		$pending = array( 'profile_id' => 'prof_other', 'at' => 100, 'bucket' => 'other-bucket', 'from' => '旧桶' );

		$stored = Settings_Saver::keep_reserved_keys(
			array( 'provider' => 'bitiful' ),
			array( 'reconcile_pending' => $pending )
		);

		$this->assertSame( $pending, $stored['reconcile_pending'] );
	}

	/** A fresh install gets an empty gate, not null. */
	public function test_the_gate_defaults_to_an_empty_array(): void {
		$stored = Settings_Saver::keep_reserved_keys( array( 'bucket' => 'nboke' ), array() );

		$this->assertSame( array(), $stored['reconcile_pending'] );
	}

	/** The dialog after a switch names both sides and both follow-up steps. */
	public function test_the_switch_dialog_names_the_old_and_new_buckets(): void {
		$html = S3_Storage_Page::switch_modal_html( '多吉云', '缤纷云', false );

		$this->assertStringContainsString( '多吉云', $html );
		$this->assertStringContainsString( '缤纷云', $html );
		$this->assertStringContainsString( '缩略图地址改写已自动关闭', $html );
		$this->assertStringContainsString( '开始完整核对', $html );
		$this->assertStringContainsString( 'data-sakura-modal-autoshow', $html );
		$this->assertStringNotContainsString( '上传行为', $html, 'Without a binding there is no frozen behaviour to mention.' );
	}

	/** With a binding in place the dialog also says the switches did not move. */
	public function test_the_switch_dialog_mentions_the_frozen_switches(): void {
		$html = S3_Storage_Page::switch_modal_html( '多吉云', '缤纷云', true );

		$this->assertStringContainsString( '上传行为', $html );
	}

	/** A first-ever activation has no previous name to show. */
	public function test_the_switch_dialog_handles_a_first_activation(): void {
		$html = S3_Storage_Page::switch_modal_html( '', '缤纷云', false );

		$this->assertStringContainsString( '已切换到', $html );
		$this->assertStringContainsString( '缤纷云', $html );
	}

	/**
	 * Seed one live profile and a second one pointing at a different bucket.
	 */
	private function seed(): void {
		$key    = Credential_Cipher::encrypt( 'live-key' );
		$secret = Credential_Cipher::encrypt( 'live-secret' );
		$other  = Credential_Cipher::encrypt( 'other-key' );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'            => 'true',
			'provider'           => 'dogecloud',
			'bucket'             => 'live-bucket',
			'endpoint'           => 'https://cos.ap-chengdu.myqcloud.com',
			'region'             => 'ap-chengdu',
			'key'                => $key,
			'secret'             => $secret,
			'no_thumb'           => 'false',
			'no_local_saving'    => 'false',
			'upload_path'        => 'wp-content/uploads',
			'upload_url_path'    => 'https://doge.example.com/wp-content/uploads',
			'object_acl'         => 'public-read',
			'active_profile_id'  => 'prof_live',
			'reconcile_pending'  => array(),
			'profiles'           => array(
				'prof_live'  => array(
					'id'              => 'prof_live',
					'name'            => '旧桶',
					'provider'        => 'dogecloud',
					'bucket'          => 'live-bucket',
					'region'          => 'ap-chengdu',
					'endpoint'        => 'https://cos.ap-chengdu.myqcloud.com',
					'key'             => $key,
					'secret'          => $secret,
					'object_acl'      => 'public-read',
					'upload_path'     => 'wp-content/uploads',
					'upload_url_path' => 'https://doge.example.com/wp-content/uploads',
				),
				'prof_other' => array(
					'id'              => 'prof_other',
					'name'            => '新桶',
					'provider'        => 'bitiful',
					'bucket'          => 'other-bucket',
					'region'          => 'cn-east-1',
					'endpoint'        => 'https://s3.bitiful.net',
					'key'             => $other,
					'secret'          => $other,
					'object_acl'      => 'public-read',
					'upload_path'     => 'wp-content/uploads',
					'upload_url_path' => 'https://bf.example.com/wp-content/uploads',
				),
			),
		);
	}

	/**
	 * Raise the gate as a switch would.
	 */
	private function raise(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ]['reconcile_pending'] = array(
			'profile_id' => 'prof_other',
			'at'         => 100,
			'bucket'     => 'other-bucket',
			'from'       => '旧桶',
		);
	}
}