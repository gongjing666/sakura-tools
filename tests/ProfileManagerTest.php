<?php
/**
 * Saved provider profile switching/deletion tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Credential_Cipher;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Manager;

final class ProfileManagerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_switch_missing_profile_fails(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array( 'provider' => 'cloudflare' );

		$result = ( new Profile_Manager() )->switch_profile( 'prof_missing' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing', $result['error'] );
	}

	public function test_switch_incomplete_profile_fails(): void {
		$key = Credential_Cipher::encrypt( 'key' );
		$this->assertNotFalse( $key );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'provider' => 'cloudflare',
			'profiles' => array(
				'prof_aws' => array(
					'provider' => 'aws',
					'bucket'   => '',
					'endpoint' => '',
					'key'      => $key,
					'secret'   => $key,
				),
			),
		);

		$result = ( new Profile_Manager() )->switch_profile( 'prof_aws' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'incomplete', $result['error'] );
	}

	public function test_switch_copies_config_without_reencrypting_or_changing_policy(): void {
		$key    = Credential_Cipher::encrypt( 'key' );
		$secret = Credential_Cipher::encrypt( 'secret' );
		$this->assertNotFalse( $key );
		$this->assertNotFalse( $secret );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'            => 'false',
			'provider'           => 'cloudflare',
			'bucket'             => 'old',
			'endpoint'           => 'https://old.example.com',
			'key'                => Credential_Cipher::encrypt( 'old-key' ),
			'secret'             => Credential_Cipher::encrypt( 'old-secret' ),
			'no_thumb'           => 'true',
			'no_local_saving'    => 'false',
			'upload_url_path'    => '',
			'active_profile_id'  => '',
			'profiles'           => array(
				'prof_aws' => array(
					'name'            => '备份桶',
					'provider'        => 'aws',
					'bucket'          => 'saved-bucket',
					'region'          => 'us-west-2',
					'endpoint'        => '',
					'key'             => $key,
					'secret'          => $secret,
					'object_acl'      => 'private',
					'upload_path'     => 'wp-content/uploads',
					'upload_url_path' => 'https://cdn.example.com/uploads',
				),
			),
		);

		$result = ( new Profile_Manager() )->switch_profile( 'prof_aws' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'prof_aws', $raw['active_profile_id'] );
		$this->assertSame( 'aws', $raw['provider'] );
		$this->assertSame( 'saved-bucket', $raw['bucket'] );
		$this->assertSame( 'us-west-2', $raw['region'] );
		$this->assertSame( 'private', $raw['object_acl'] );
		$this->assertSame( $key, $raw['key'] );
		$this->assertSame( $secret, $raw['secret'] );
		$this->assertSame( 'false', $raw['enabled'] );
		$this->assertSame( 'true', $raw['no_thumb'] );
		$this->assertSame( 'false', $raw['no_local_saving'] );
	}

	public function test_enabling_a_profile_turns_storage_on(): void {
		$key    = Credential_Cipher::encrypt( 'key' );
		$secret = Credential_Cipher::encrypt( 'secret' );
		$this->assertNotFalse( $key );
		$this->assertNotFalse( $secret );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'false',
			'provider'          => 'cloudflare',
			'active_profile_id' => '',
			'profiles'          => array(
				'prof_aws' => array(
					'provider'        => 'aws',
					'bucket'          => 'saved-bucket',
					'region'          => 'us-west-2',
					'upload_url_path' => 'https://cdn.example.com/uploads',
					'key'             => $key,
					'secret'          => $secret,
				),
			),
		);

		$result = ( new Profile_Manager() )->enable_profile( 'prof_aws' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'true', $raw['enabled'] );
		$this->assertSame( 'prof_aws', $raw['active_profile_id'] );
		$this->assertSame( 'saved-bucket', $raw['bucket'] );
	}

	public function test_disabling_keeps_the_active_profile(): void {
		$key = Credential_Cipher::encrypt( 'key' );
		$this->assertNotFalse( $key );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'true',
			'provider'          => 'aws',
			'bucket'            => 'live-bucket',
			'active_profile_id' => 'prof_live',
			'profiles'          => array(
				'prof_live' => array(
					'provider' => 'aws',
					'bucket'   => 'live-bucket',
					'key'      => $key,
					'secret'   => $key,
				),
			),
		);

		$result = ( new Profile_Manager() )->disable();
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'false', $raw['enabled'] );
		// The selected bucket survives a pause so the next click needs no re-pick.
		$this->assertSame( 'prof_live', $raw['active_profile_id'] );
		$this->assertSame( 'live-bucket', $raw['bucket'] );
	}

	public function test_delete_saved_profile(): void {
		$key = Credential_Cipher::encrypt( 'key' );
		$this->assertNotFalse( $key );

		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'active_profile_id' => 'prof_live',
			'profiles'          => array(
				'prof_live' => array( 'provider' => 'cloudflare', 'key' => $key, 'secret' => $key ),
				'prof_old'  => array( 'provider' => 'minio', 'key' => $key, 'secret' => $key ),
			),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_old' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'prof_old', $raw['profiles'] );
		$this->assertArrayHasKey( 'prof_live', $raw['profiles'] );
	}

	public function test_live_profile_cannot_be_deleted(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'true',
			'active_profile_id' => 'prof_live',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare' ) ),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'delete_active', $result['error'], 'R7: the live profile must be paused before it can be deleted.' );
	}

	public function test_live_bound_profile_reports_delete_bound(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'true',
			'active_profile_id' => 'prof_live',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare' ) ),
			'binding'           => array( 'profile_id' => 'prof_live', 'no_local_saving' => 'false' ),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );

		$this->assertSame( 'delete_bound', $result['error'], 'A bound live profile names the binding, not just the pause.' );
	}

	public function test_paused_unbound_profile_can_be_deleted(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'false',
			'active_profile_id' => 'prof_live',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare' ) ),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );

		$this->assertTrue( $result['success'], 'R7: a paused profile with no binding is a plain delete.' );
	}

	public function test_paused_bound_profile_unbinds_and_clears_the_mirror(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'false',
			'active_profile_id' => 'prof_live',
			'bucket'            => 'live-bucket',
			'endpoint'          => 'https://cdn.example.com',
			'upload_url_path'   => 'https://cdn.example.com/uploads',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare', 'bucket' => 'live-bucket' ) ),
			'binding'           => array( 'profile_id' => 'prof_live', 'no_local_saving' => 'false' ),
			'behaviour_locked'  => 'true',
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );
		$raw    = $GLOBALS['sakura_test_options'][ Options::OPTION ];

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $raw['binding'], 'R8 step 5: deleting the bound profile is the only unbind path.' );
		$this->assertSame( 'false', $raw['behaviour_locked'], 'R11: the last profile leaving releases the behaviour freeze.' );
		$this->assertSame( '', $raw['active_profile_id'] );
		$this->assertSame( 'false', $raw['enabled'] );
		$this->assertSame( '', $raw['bucket'], 'R8 step 6: the live mirror is cleared so a deleted account is unusable.' );
		$this->assertSame( '', $raw['endpoint'] );
	}

	public function test_high_risk_binding_refuses_to_leave_other_profiles(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'false',
			'active_profile_id' => 'prof_live',
			'profiles'          => array(
				'prof_live' => array( 'provider' => 'cloudflare' ),
				'prof_old'  => array( 'provider' => 'minio' ),
			),
			'binding'           => array( 'profile_id' => 'prof_live', 'no_local_saving' => 'true' ),
			'behaviour_locked'  => 'true',
		);

		$manager = new Profile_Manager();

		$this->assertSame( 'pending_profiles', $manager->delete_profile( 'prof_live' )['error'], 'R10: the bound profile goes last.' );

		// The other profiles are the ones that must go first; they are not bound.
		$this->assertTrue( $manager->delete_profile( 'prof_old' )['success'] );

		$raw = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertSame( 'true', $raw['behaviour_locked'], 'Deleting a non-bound profile must not release the freeze.' );
		$this->assertTrue( $manager->delete_profile( 'prof_live' )['success'], 'The bound profile goes last.' );
	}

	public function test_high_risk_binding_allows_delete_when_it_is_the_only_profile(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'           => 'false',
			'active_profile_id' => 'prof_live',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare' ) ),
			'binding'           => array( 'profile_id' => 'prof_live', 'no_local_saving' => 'true' ),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $GLOBALS['sakura_test_options'][ Options::OPTION ]['profiles'] );
	}

	public function test_normal_lock_switch_is_allowed_but_high_risk_is_refused(): void {
		$key = Credential_Cipher::encrypt( 'key' );

		$base = array(
			'enabled'           => 'true',
			'active_profile_id' => 'prof_a',
			'profiles'          => array(
				'prof_a' => array( 'provider' => 'aws', 'bucket' => 'a', 'key' => $key, 'secret' => $key ),
				'prof_b' => array( 'provider' => 'aws', 'bucket' => 'b', 'key' => $key, 'secret' => $key ),
			),
		);

		$normal                          = $base;
		$normal['binding']               = array( 'profile_id' => 'prof_a', 'no_local_saving' => 'false' );
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $normal;
		$this->assertTrue( ( new Profile_Manager() )->switch_profile( 'prof_b' )['success'], 'A normal lock still allows switching: the local copies are on disk.' );

		$risky                                 = $base;
		$risky['binding']                      = array( 'profile_id' => 'prof_a', 'no_local_saving' => 'true' );
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = $risky;
		$this->assertSame( 'locked', ( new Profile_Manager() )->switch_profile( 'prof_b' )['error'], 'A high-risk lock has no second bucket to fall back to.' );
		$this->assertTrue( ( new Profile_Manager() )->switch_profile( 'prof_a' )['success'], 'Re-enabling the bound profile is not a switch.' );
	}
}
