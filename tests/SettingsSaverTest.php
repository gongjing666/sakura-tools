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
	 * A form rendered before the space field existed posts nothing; that must
	 * not erase the name the profile already remembers.
	 */
	public function test_missing_space_keeps_the_stored_name_for_the_same_bucket(): void {
		$editing = array(
			'bucket' => 'doss-v2-328e5d1039000fe8-1258813047',
			'space'  => 'nboke',
		);

		$this->assertSame(
			'nboke',
			Settings_Saver::space_for_save( '', 'doss-v2-328e5d1039000fe8-1258813047', $editing )
		);
	}

	/**
	 * A different bucket is a different target: inheriting the old space name
	 * would label it with a name that describes nothing.
	 */
	public function test_missing_space_is_dropped_when_the_bucket_changed(): void {
		$editing = array(
			'bucket' => 'doss-v2-old',
			'space'  => 'nboke',
		);

		$this->assertSame( '', Settings_Saver::space_for_save( '', 'doss-v2-new', $editing ) );
	}

	public function test_submitted_space_wins(): void {
		$editing = array(
			'bucket' => 'doss-v2-old',
			'space'  => 'nboke',
		);

		$this->assertSame( 'demo', Settings_Saver::space_for_save( 'demo', 'doss-v2-new', $editing ) );
	}

	public function test_missing_space_without_a_matching_profile_stays_empty(): void {
		$this->assertSame( '', Settings_Saver::space_for_save( '', 'doss-v2-new', array() ) );
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
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false )
		);
	}

	/**
	 * Changing the bucket keeps the id: the profile is edited in place, and
	 * save() drops the previous target's reconciliation rows for it.
	 */
	public function test_changing_the_bucket_updates_the_same_profile(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$this->assertSame( 'prof_live', Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false ) );
	}

	/**
	 * The provider and endpoint no longer take part in the decision: loading a
	 * different saved config in the connection card, fixing its endpoint and
	 * saving must not leave a third copy behind.
	 */
	public function test_changing_the_provider_or_endpoint_updates_the_same_profile(): void {
		$profiles = array(
			'prof_live' => array(
				'id'       => 'prof_live',
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
			),
		);

		$this->assertSame( 'prof_live', Settings_Saver::profile_id_for_save( $profiles, 'prof_live', false ) );
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
			Settings_Saver::profile_id_for_save( $profiles, 'prof_live', true )
		);
	}

	/**
	 * A form that posts an id which no longer exists gets a new profile rather
	 * than resurrecting the old one.
	 */
	public function test_unknown_submitted_id_starts_a_new_profile(): void {
		$this->assertNotSame(
			'prof_gone',
			Settings_Saver::profile_id_for_save( array(), 'prof_gone', false )
		);
	}
}
