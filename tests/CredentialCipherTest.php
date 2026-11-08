<?php
/**
 * Credential encryption tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Credential_Cipher;
use Sakura\ImageOptimizer\S3\Options;

final class CredentialCipherTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
		if ( ! Credential_Cipher::is_available() ) {
			$this->markTestSkipped( 'Sodium or OpenSSL is required.' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_round_trip_returns_plaintext(): void {
		$cipher = Credential_Cipher::encrypt( 'secret-key-value' );

		$this->assertIsString( $cipher );
		$this->assertTrue( Credential_Cipher::is_encrypted( $cipher ) );
		$this->assertNotSame( 'secret-key-value', $cipher );
		$this->assertSame( 'secret-key-value', Credential_Cipher::decrypt( $cipher ) );
	}

	public function test_plaintext_is_still_readable_for_legacy_installs(): void {
		$this->assertSame( 'legacy-plain', Credential_Cipher::decrypt( 'legacy-plain' ) );
		$this->assertFalse( Credential_Cipher::is_encrypted( 'legacy-plain' ) );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$cipher = Credential_Cipher::encrypt( 'secret-key-value' );
		$this->assertIsString( $cipher );

		$tampered = substr( $cipher, 0, -4 ) . 'AAAA';
		$this->assertFalse( Credential_Cipher::decrypt( $tampered ) );
	}

	public function test_empty_value_stays_empty(): void {
		$this->assertSame( '', Credential_Cipher::encrypt( '' ) );
		$this->assertSame( '', Credential_Cipher::decrypt( '' ) );
	}

	public function test_legacy_option_is_migrated_in_place(): void {
		$GLOBALS['sakura_test_options'][ Options::OPTION ] = array(
			'enabled'  => 'true',
			'provider' => 'cloudflare',
			'bucket'   => 'bucket',
			'endpoint' => 'https://s3.example.com',
			'key'      => 'plain-key',
			'secret'   => 'plain-secret',
		);

		Credential_Cipher::migrate_legacy_option();

		$raw = $GLOBALS['sakura_test_options'][ Options::OPTION ];
		$this->assertTrue( Credential_Cipher::is_encrypted( $raw['key'] ) );
		$this->assertTrue( Credential_Cipher::is_encrypted( $raw['secret'] ) );
		$this->assertSame( 'plain-key', Credential_Cipher::decrypt( $raw['key'] ) );

		$options = new Options();
		$this->assertSame( 'plain-key', $options->get( 'key' ) );
		$this->assertTrue( $options->is_configured() );
	}

	public function test_options_accept_encrypted_credentials(): void {
		$key    = Credential_Cipher::encrypt( 'encrypted-key' );
		$secret = Credential_Cipher::encrypt( 'encrypted-secret' );

		$options = new Options(
			array(
				'enabled'  => 'true',
				'provider' => 'cloudflare',
				'bucket'   => 'bucket',
				'endpoint' => 'https://s3.example.com',
				'key'      => $key,
				'secret'   => $secret,
			)
		);

		$this->assertSame( 'encrypted-key', $options->get( 'key' ) );
		$this->assertSame( 'encrypted-secret', $options->get( 'secret' ) );
		$this->assertFalse( $options->credentials_unreadable() );
		$this->assertTrue( $options->is_configured() );
	}

	public function test_undecryptable_ciphertext_blocks_configuration(): void {
		$options = new Options(
			array(
				'enabled'  => 'true',
				'provider' => 'cloudflare',
				'bucket'   => 'bucket',
				'endpoint' => 'https://s3.example.com',
				'key'      => 'sio1s:not-valid-base64!',
				'secret'   => 'sio1s:not-valid-base64!',
			)
		);

		$this->assertTrue( $options->credentials_unreadable() );
		$this->assertFalse( $options->is_configured() );
		$this->assertContains( 'key', $options->configuration_errors() );
		$this->assertContains( 'secret', $options->configuration_errors() );
	}
}
