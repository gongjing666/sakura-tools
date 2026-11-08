<?php
/**
 * S3 Options normalization tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Credential_Cipher;
use Sakura\ImageOptimizer\S3\Options;

final class OptionsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_defaults_are_disabled_and_valid(): void {
		$options = new Options();

		$this->assertFalse( $options->is_enabled() );
		$this->assertFalse( $options->is_configured() );
		$this->assertSame( 'public-read', $options->object_acl() );
		$this->assertSame( 'wp-content/uploads', $options->upload_path() );
	}

	public function test_legacy_credentials_enable_sync(): void {
		$options = new Options(
			array(
				'bucket'   => 'legacy-bucket',
				'key'      => 'key',
				'secret'   => 'secret',
				'endpoint' => 'https://s3.example.com',
			)
		);

		$this->assertTrue( $options->is_enabled() );
		$this->assertTrue( $options->is_configured() );
	}

	public function test_endpoint_is_required_for_s3_compatible_providers(): void {
		$options = new Options(
			array(
				'enabled'  => 'true',
				'provider' => 'cloudflare',
				'bucket'   => 'bucket',
				'key'      => 'key',
				'secret'   => 'secret',
			)
		);

		$this->assertFalse( $options->is_configured() );
		$this->assertContains( 'endpoint', $options->configuration_errors() );
	}

	public function test_aws_default_endpoint_may_be_empty(): void {
		$options = new Options(
			array(
				'enabled'  => 'true',
				'provider' => 'aws',
				'bucket'   => 'bucket',
				'key'      => 'key',
				'secret'   => 'secret',
			)
		);

		$this->assertTrue( $options->is_configured() );
	}

	public function test_invalid_provider_is_incomplete(): void {
		$options = new Options(
			array(
				'provider' => 'unknown',
				'bucket'   => 'bucket',
				'key'      => 'key',
				'secret'   => 'secret',
				'endpoint' => 'https://s3.example.com',
			)
		);

		$this->assertFalse( $options->is_configured() );
		$this->assertContains( 'provider', $options->configuration_errors() );
	}

	public function test_upload_path_and_acl_are_normalized(): void {
		$GLOBALS['sakura_test_options']['upload_path'] = '/custom/uploads/';

		$options = new Options( array( 'object_acl' => 'invalid-acl' ) );

		$this->assertSame( 'custom/uploads', $options->upload_path() );
		$this->assertSame( 'public-read', $options->object_acl() );
	}

	public function test_saved_profiles_reports_complete_profiles_without_secrets(): void {
		$key    = Credential_Cipher::encrypt( 'profile-key' );
		$secret = Credential_Cipher::encrypt( 'profile-secret' );
		$this->assertNotFalse( $key );
		$this->assertNotFalse( $secret );

		$options = new Options(
			array(
				'provider'          => 'cloudflare',
				'bucket'            => 'active-bucket',
				'endpoint'          => 'https://active.example.com',
				'key'               => $key,
				'secret'            => $secret,
				'active_profile_id' => 'prof_cloud',
				'profiles'          => array(
					'prof_cloud' => array(
						'name'     => '主站图床',
						'provider' => 'cloudflare',
						'bucket'   => 'saved-bucket',
						'endpoint' => 'https://saved.example.com',
						'key'      => $key,
						'secret'   => $secret,
					),
					'prof_minio' => array(
						'provider' => 'minio',
						'bucket'   => 'minio-bucket',
						'endpoint' => 'https://minio.example.com',
						'key'      => $key,
						'secret'   => $secret,
					),
				),
			)
		);

		$profiles = $options->saved_profiles();

		$this->assertTrue( $profiles['prof_cloud']['saved'] );
		$this->assertTrue( $profiles['prof_cloud']['complete'] );
		$this->assertTrue( $profiles['prof_cloud']['active'] );
		$this->assertSame( 'saved-bucket', $profiles['prof_cloud']['bucket'] );
		$this->assertSame( '主站图床', $profiles['prof_cloud']['name'] );
		$this->assertTrue( $profiles['prof_minio']['complete'] );
		$this->assertArrayNotHasKey( 'key', $profiles['prof_cloud'] );
		$this->assertArrayNotHasKey( 'secret', $profiles['prof_cloud'] );
	}

	public function test_incomplete_profile_is_reported_but_not_switchable(): void {
		$key = Credential_Cipher::encrypt( 'profile-key' );
		$this->assertNotFalse( $key );

		$options = new Options(
			array(
				'profiles' => array(
					'prof_incomplete' => array(
						'provider' => 'cloudflare',
						'bucket'   => '',
						'endpoint' => '',
						'key'      => $key,
						'secret'   => $key,
					),
				),
			)
		);

		$profiles = $options->saved_profiles();

		$this->assertTrue( $profiles['prof_incomplete']['saved'] );
		$this->assertFalse( $profiles['prof_incomplete']['complete'] );
	}

	/**
	 * A saved profile can be materialised as a working options snapshot.
	 *
	 * H-01: queued jobs remember their profile id, so the worker must be able
	 * to build an options object for that profile instead of the currently
	 * active one — otherwise a settings switch replays old jobs into the new
	 * bucket.
	 */
	public function test_profile_options_rebuilds_a_profile_snapshot(): void {
		$key    = Credential_Cipher::encrypt( 'profile-key' );
		$secret = Credential_Cipher::encrypt( 'profile-secret' );
		$this->assertNotFalse( $key );
		$this->assertNotFalse( $secret );

		$options = new Options(
			array(
				'provider'          => 'cloudflare',
				'bucket'            => 'active-bucket',
				'endpoint'          => 'https://active.example.com',
				'key'               => $key,
				'secret'            => $secret,
				'active_profile_id' => 'prof_active',
				'profiles'          => array(
					'prof_old' => array(
						'name'            => '旧图床',
						'provider'        => 'minio',
						'bucket'          => 'old-bucket',
						'region'          => 'cn-east-1',
						'endpoint'        => 'http://127.0.0.1:19010',
						'key'             => $key,
						'secret'          => $secret,
						'object_acl'      => 'private',
						'upload_path'     => 'wp-content/uploads',
						'upload_url_path' => 'https://old.example.com/uploads',
						'saved_at'        => 123,
					),
				),
			)
		);

		$resolved = $options->profile_options( 'prof_old' );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'true', $resolved['enabled'] );
		$this->assertSame( 'prof_old', $resolved['active_profile_id'] );
		$this->assertSame( 'minio', $resolved['provider'] );
		$this->assertSame( 'old-bucket', $resolved['bucket'] );
		// The snapshot keeps ciphertext; the Options constructor decrypts it.
		$this->assertNotSame( 'profile-key', $resolved['key'] );
		$this->assertSame( $key, $resolved['key'] );
		$this->assertNotSame( 'profile-secret', $resolved['secret'] );
		$this->assertSame( $secret, $resolved['secret'] );

		$rebuilt = new Options( $resolved );
		$this->assertTrue( $rebuilt->is_configured() );
		$this->assertSame( 'old-bucket', $rebuilt->all()['bucket'] );
		$this->assertSame( 'profile-key', $rebuilt->all()['key'], 'The rebuilt options decrypt the profile credentials.' );
	}

	/**
	 * Unknown profile ids resolve to null rather than falling back silently.
	 */
	public function test_profile_options_returns_null_for_unknown_profile(): void {
		$options = new Options( array( 'profiles' => array() ) );

		$this->assertNull( $options->profile_options( 'prof_missing' ) );
	}

}
