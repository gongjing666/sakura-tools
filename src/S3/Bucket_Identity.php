<?php
/**
 * Bucket identity: the one definition of "which bucket is this".
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * The three tiers of storage fields, and the fingerprint built from the first.
 *
 * R19 exists because the same question - "is this the same bucket" - had five
 * answers in five files with five different field lists, and they disagreed
 * with each other. This class is the only place the field lists are written
 * down:
 *
 * - ADDRESS_FIELDS decides where an object lives. Only these four make
 *   `hash()` change, because only these four make a stored fingerprint stale.
 * - REACH_FIELDS covers connecting and signing. A wrong region means the
 *   bucket cannot be read, but the object URL is unchanged, so it stays out of
 *   the fingerprint and inside the binding freeze.
 * - The display fields (space, upload_url_path, the profile name) are not
 *   listed here at all: nothing that is not in this class may take part in a
 *   bucket comparison. `space` in particular is the multi-cloud console's
 *   label for the bucket, not the bucket, and freezing on it demanded a full
 *   re-scan for a change that moved no object.
 */
final class Bucket_Identity {

	/**
	 * Fields that decide where an object lives, and therefore its fingerprint.
	 */
	public const ADDRESS_FIELDS = array( 'provider', 'bucket', 'endpoint', 'upload_path' );

	/**
	 * Fields that decide whether the bucket can be reached, not what it holds.
	 *
	 * `region` is here rather than in ADDRESS_FIELDS by intent: it changes the
	 * SDK signature and routing (src/S3/Client_Factory.php:137) without moving
	 * a single object, so it must not invalidate stored fingerprints.
	 */
	public const REACH_FIELDS = array( 'region', 'key', 'secret', 'object_acl' );

	/**
	 * Version prefix of the fingerprint input.
	 *
	 * Frozen at `v2`. Changing it, the separator, or the order of
	 * ADDRESS_FIELDS invalidates every hash already written to post meta,
	 * sio_sync_jobs and sio_s3_deletes.
	 */
	public const VERSION = 'v2';

	/**
	 * Address fields, trimmed, in fingerprint order.
	 *
	 * @var array<string,string>
	 */
	private array $fields;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $fields Values keyed by field name.
	 */
	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Identity of the bucket an options snapshot points at.
	 *
	 * @param Options $options Options snapshot.
	 */
	public static function from_options( Options $options ): self {
		$fields = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$fields[ $field ] = (string) $options->get( $field, '' );
		}
		return new self( $fields );
	}

	/**
	 * Identity of one saved profile row.
	 *
	 * Falls back to the row's own value per field, so a profile that overrides
	 * only the bucket still compares by its bucket.
	 *
	 * @param array<string,mixed> $profile Row from Options::profiles().
	 * @param Options             $options Options snapshot used to fill gaps.
	 */
	public static function from_profile( array $profile, Options $options ): self {
		$fields = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$value            = isset( $profile[ $field ] ) && '' !== (string) $profile[ $field ]
				? (string) $profile[ $field ]
				: (string) $options->get( $field, '' );
			$fields[ $field ] = $value;
		}
		return new self( $fields );
	}

	/**
	 * Fingerprint of this identity.
	 *
	 * The legacy form binds a profile row id in place of the version tag; it is
	 * accepted so fingerprints written before the row id was dropped keep
	 * resolving without rewriting post meta for the whole site.
	 *
	 * @param string $profile_id Legacy row id, empty for the current form.
	 */
	public function hash( string $profile_id = '' ): string {
		$parts = array( '' === $profile_id ? self::VERSION : $profile_id );
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$parts[] = $this->fields[ $field ] ?? '';
		}
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Address field values, trimmed.
	 *
	 * @return array<string,string>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Whether two identities name the same bucket.
	 *
	 * @param self $other Other identity.
	 */
	public function same_as( self $other ): bool {
		return $this->fields === $other->fields;
	}

	/**
	 * Address fields that differ between two identities.
	 *
	 * @param self $other Other identity.
	 * @return string[]
	 */
	public function differences( self $other ): array {
		$changed = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( ( $this->fields[ $field ] ?? '' ) !== ( $other->fields[ $field ] ?? '' ) ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}
}
