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
	 * The ACL control is gone from the connection card; editing a profile must
	 * keep the private value it already had instead of flipping it on save.
	 */
	public function test_missing_acl_keeps_the_edited_profile_value(): void {
		$this->assertSame(
			'private',
			Settings_Saver::object_acl_for_save( '', array( 'object_acl' => 'private' ), array( 'object_acl' => 'public-read' ) )
		);
	}

	/**
	 * A new profile and a legacy profile without the field both inherit the
	 * active value; only a fresh install falls back to public-read.
	 */
	public function test_missing_acl_falls_back_to_the_active_value(): void {
		$this->assertSame(
			'private',
			Settings_Saver::object_acl_for_save( '', array(), array( 'object_acl' => 'private' ) )
		);
	}

	public function test_invalid_acl_falls_back_to_public_read(): void {
		$this->assertSame(
			'public-read',
			Settings_Saver::object_acl_for_save( 'everyone', array(), array( 'object_acl' => 'invalid' ) )
		);
	}

	public function test_submitted_acl_still_wins(): void {
		$this->assertSame(
			'private',
			Settings_Saver::object_acl_for_save( 'private', array( 'object_acl' => 'public-read' ), array( 'object_acl' => 'public-read' ) )
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
	 * The endpoint no longer takes part in the decision: loading a different
	 * saved config in the connection card, fixing its endpoint and saving must
	 * not leave a third copy behind.
	 */
	public function test_changing_the_endpoint_updates_the_same_profile(): void {
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

	/**
	 * A form with no saved profile behind it has nothing to protect, so the
	 * provider picker stays a plain choice.
	 */
	public function test_a_new_profile_is_not_forced_to_save_as_new(): void {
		$this->assertFalse( Settings_Saver::save_as_new_for_save( false, true, array() ) );
	}

	/**
	 * Keeping the provider is an ordinary edit, including when the bucket or the
	 * endpoint underneath it moves.
	 */
	public function test_same_provider_edits_in_place(): void {
		$editing = array(
			'id'       => 'prof_live',
			'provider' => 'dogecloud',
			'bucket'   => 'space-1234',
		);

		$this->assertFalse( Settings_Saver::save_as_new_for_save( false, false, $editing ) );
	}

	/**
	 * Switching the provider writes a new profile even when the operator never
	 * ticked the box. Without this the save rewrites the connection it was
	 * editing, which is how a second storage account replaces the first.
	 */
	public function test_switching_the_provider_mints_a_new_profile(): void {
		$editing = array(
			'id'       => 'prof_live',
			'provider' => 'dogecloud',
			'bucket'   => 'space-1234',
		);

		$this->assertTrue( Settings_Saver::save_as_new_for_save( false, true, $editing ) );
	}

	/**
	 * The unticked box must not be able to talk that rule back down.
	 */
	public function test_ticking_the_box_still_mints_a_new_profile(): void {
		$editing = array(
			'id'       => 'prof_live',
			'provider' => 'dogecloud',
			'bucket'   => 'space-1234',
		);

		$this->assertTrue( Settings_Saver::save_as_new_for_save( true, false, $editing ) );
	}

	/**
	 * A save that writes some other profile must leave the live mirror byte for
	 * byte as it was: credentials in the option are ciphertext, and copying the
	 * decrypted view back would store the secret in plaintext (then re-encrypt it
	 * under a new nonce on the next request, which looks like a changed key in
	 * every diff).
	 */
	public function test_keeping_the_live_mirror_copies_ciphertext_untouched(): void {
		$raw = array(
			'provider'        => 'dogecloud',
			'bucket'          => 'doss-v2-live',
			'key'             => 'sio1s:live-key-ciphertext',
			'secret'          => 'sio1s:live-secret-ciphertext',
			'upload_url_path' => 'https://doge.example/uploads',
			'profiles'        => array( 'prof_live' => array() ),
		);

		$stored = Settings_Saver::keep_live_mirror(
			array(
				'provider' => 'bitiful',
				'bucket'   => 'nboke',
				'key'      => 'plaintext-key',
				'secret'   => 'plaintext-secret',
			),
			$raw
		);

		$this->assertSame( 'dogecloud', $stored['provider'] );
		$this->assertSame( 'doss-v2-live', $stored['bucket'] );
		$this->assertSame( 'sio1s:live-key-ciphertext', $stored['key'] );
		$this->assertSame( 'sio1s:live-secret-ciphertext', $stored['secret'] );
		$this->assertSame( 'https://doge.example/uploads', $stored['upload_url_path'] );
	}

	/**
	 * Keys the option never had must not appear out of nowhere, and keys that are
	 * not part of the mirror belong to the save that is running.
	 */
	public function test_keeping_the_live_mirror_leaves_other_keys_alone(): void {
		$stored = Settings_Saver::keep_live_mirror(
			array(
				'key'      => 'new-key',
				'enabled'  => 'true',
				'profiles' => array( 'prof_new' => array() ),
			),
			array( 'enabled' => 'false' )
		);

		$this->assertSame( 'new-key', $stored['key'] );
		$this->assertSame( 'true', $stored['enabled'] );
		$this->assertSame( array( 'prof_new' => array() ), $stored['profiles'] );
	}
	/**
	 * The lifecycle keys are not rebuilt by save(): they travel with the option, so
	 * a credential-only save must not silently unbind the site.
	 */
	public function test_reserved_lifecycle_keys_survive_a_rebuild(): void {
		$binding = array(
			'profile_id'      => 'prof_live',
			'provider'        => 'bitiful',
			'bucket'          => 'nboke',
			'no_local_saving' => 'true',
		);

		$stored = Settings_Saver::keep_reserved_keys(
			array( 'provider' => 'bitiful' ),
			array(
				'binding'          => $binding,
				'behaviour_locked' => 'true',
				'retired_targets'  => array( 'oldhash' => array( 'profile_id' => 'prof_old' ) ),
			)
		);

		$this->assertSame( $binding, $stored['binding'] );
		$this->assertSame( 'true', $stored['behaviour_locked'] );
		$this->assertSame( array( 'oldhash' => array( 'profile_id' => 'prof_old' ) ), $stored['retired_targets'] );
		$this->assertSame( 'bitiful', $stored['provider'] );
	}

	/**
	 * A fresh install has none of these keys yet; the defaults must not invent a
	 * binding, and must not leave null in the option either.
	 */
	public function test_reserved_lifecycle_keys_default_to_unbound(): void {
		$stored = Settings_Saver::keep_reserved_keys( array( 'bucket' => 'nboke' ), array() );

		$this->assertSame( array(), $stored['binding'] );
		$this->assertSame( 'false', $stored['behaviour_locked'] );
		$this->assertSame( array(), $stored['retired_targets'] );
	}

	/**
	 * A key the caller already set is the caller's business: the helper only fills
	 * gaps, so it can be composed with the code that clears a binding on delete.
	 */
	public function test_reserved_lifecycle_keys_do_not_overwrite_a_deliberate_clear(): void {
		$stored = Settings_Saver::keep_reserved_keys(
			array( 'binding' => array(), 'behaviour_locked' => 'false' ),
			array( 'binding' => array( 'profile_id' => 'prof_live' ), 'behaviour_locked' => 'true' )
		);

		$this->assertSame( array(), $stored['binding'] );
		$this->assertSame( 'false', $stored['behaviour_locked'] );
	}

	/**
	 * R2: only the false-to-true transition is irreversible, so only that one needs
	 * the acknowledgement.
	 */
	public function test_risk_acknowledgement_is_required_only_when_opening_the_risk(): void {
		unset( $_POST['no_local_saving_ack'] );

		$this->assertTrue( Settings_Saver::risk_ack_missing( 'false', 'true' ), 'Turning the risk on without ack must be refused.' );
		$this->assertFalse( Settings_Saver::risk_ack_missing( 'true', 'true' ), 'Already on: saving again is not a new risk.' );
		$this->assertFalse( Settings_Saver::risk_ack_missing( 'true', 'false' ), 'Turning it off never needs an ack.' );
		$this->assertFalse( Settings_Saver::risk_ack_missing( 'false', 'false' ) );
	}

	/**
	 * The acknowledgement has exactly one accepted value; anything else is a POST
	 * that was not produced by the dialog.
	 */
	public function test_risk_acknowledgement_accepts_only_the_dialog_value(): void {
		$_POST['no_local_saving_ack'] = '1';
		$this->assertFalse( Settings_Saver::risk_ack_missing( 'false', 'true' ) );

		$_POST['no_local_saving_ack'] = 'yes';
		$this->assertTrue( Settings_Saver::risk_ack_missing( 'false', 'true' ) );

		unset( $_POST['no_local_saving_ack'] );
	}

	/**
	 * Nothing may leak between tests: this file only stubs the two switches, but a
	 * leftover ack would make the "refused" case above pass for the wrong reason.
	 */
	protected function tearDown(): void {
		unset( $_POST['no_local_saving_ack'] );
		parent::tearDown();
	}
}
