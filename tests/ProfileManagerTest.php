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

	public function test_active_profile_cannot_be_deleted(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'active_profile_id' => 'prof_live',
			'profiles'          => array( 'prof_live' => array( 'provider' => 'cloudflare' ) ),
		);

		$result = ( new Profile_Manager() )->delete_profile( 'prof_live' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'active', $result['error'] );
	}
}
