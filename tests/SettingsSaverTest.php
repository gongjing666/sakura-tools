<?php
/**
 * Credential normalization tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Settings_Saver;

final class SettingsSaverTest extends TestCase {

	/**
	 * Characters that break `sanitize_text_field()` survive untouched.
	 */
	public function test_upload_concurrency_is_clamped(): void {
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( '' ) );
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( 'many' ) );
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( array( 4 ) ) );
		$this->assertSame( 4, Settings_Saver::sanitize_upload_concurrency( '4' ) );
		$this->assertSame( 4, Settings_Saver::sanitize_upload_concurrency( 9 ) );
	}

	public function test_keeps_literal_characters(): void {
		$this->assertSame( 'a%b<c>d&e', Settings_Saver::clean_credential( '  a%b<c>d&e  ' ) );
	}

	/**
	 * Control characters are removed (they would break signing).
	 */
	public function test_strips_control_characters(): void {
		$this->assertSame( 'abc', Settings_Saver::clean_credential( "a\nb\tc\r" ) );
	}

	/**
	 * Non-ASCII credentials are preserved.
	 */
	public function test_keeps_unicode_and_equals(): void {
		$this->assertSame( '密钥%20+==', Settings_Saver::clean_credential( ' 密钥%20+== ' ) );
	}

	/**
	 * Editing a profile without moving its target keeps the same id, so the
	 * bucket's reconciliation records stay attached to it.
	 */
	public function test_saving_the_same_target_keeps_the_profile_id(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$this->assertSame(
			'prof_live',
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false, 'bitiful', 'text-demo', 'https://s3.bitiful.net' )
		);
	}

	/**
	 * Renaming the bucket is a different target: a new id keeps the old
	 * bucket's counts from showing up under the new name, and lets the old
	 * records be deleted along with the old config.
	 */
	public function test_changing_the_bucket_starts_a_new_profile(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$id = Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false, 'bitiful', 'text-demo-2', 'https://s3.bitiful.net' );

		$this->assertNotSame( 'prof_live', $id );
		$this->assertStringStartsWith( 'prof_', $id );
	}

	public function test_changing_the_provider_or_endpoint_starts_a_new_profile(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$this->assertNotSame(
			'prof_live',
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false, 'cloudflare', 'text-demo', 'https://s3.bitiful.net' )
		);
		$this->assertNotSame(
			'prof_live',
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false, 'bitiful', 'text-demo', 'https://example.com' )
		);
	}

	/**
	 * Saving a copy always writes to a fresh id, even with identical fields.
	 */
	public function test_save_as_new_always_mints_an_id(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$this->assertNotSame(
			'prof_live',
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', true, 'bitiful', 'text-demo', 'https://s3.bitiful.net' )
		);
	}

	/**
	 * A form that posts an id which no longer exists gets a new profile rather
	 * than resurrecting the old one.
	 */
	public function test_unknown_submitted_id_starts_a_new_profile(): void {
		$this->assertNotSame(
			'prof_gone',
			Settings_Saver::profile_id_for_save( array(), 'prof_gone', false, 'bitiful', 'text-demo', '' )
		);
	}
}
