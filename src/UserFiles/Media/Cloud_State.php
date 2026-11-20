<?php
/**
 * Read-only "is this file in the bucket" answer for one media row.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies the three independent records into one cloud state.
 *
 * The records are the object keys stored on the attachment, the bucket
 * confirmation for those keys, and the remote-delete queue. Keeping the
 * mapping here (instead of inside the media-list renderer) makes the rule
 * testable without WordPress and keeps the column honest: "待上传" only means
 * "no confirmation", never "the index cannot answer".
 */
final class Cloud_State {

	/** Every recorded object is confirmed in the active bucket. */
	public const SYNCED = 'synced';

	/** Some recorded objects are confirmed, some are not. */
	public const PARTIAL = 'partial';

	/** Recorded objects exist, none confirmed. */
	public const PENDING = 'pending';

	/** No object key was ever recorded for this attachment. */
	public const LOCAL = 'local';

	/** A remote delete is queued or running. */
	public const DELETING = 'deleting';

	/** A remote delete gave up; the object may still exist. */
	public const FAILED = 'failed';

	/** The sync index cannot answer (disabled or not installed). */
	public const UNKNOWN = 'unknown';

	/**
	 * Classify one attachment.
	 *
	 * @param array<int, string>      $objects      Recorded object keys.
	 * @param array<string, bool>     $confirmed    Bucket confirmation per key.
	 * @param array<string, mixed>|null $deletes    Row from Delete_Jobs::states_for().
	 * @param bool                    $can_confirm  Whether confirmation is possible.
	 * @return array{state: string, total: int, confirmed: int, count: int, error: string}
	 */
	public static function classify( array $objects, array $confirmed, ?array $deletes = null, bool $can_confirm = true ): array {
		$keys = array();
		foreach ( $objects as $object ) {
			$object = trim( (string) $object );
			if ( '' !== $object ) {
				$keys[ $object ] = $object;
			}
		}

		$total   = count( $keys );
		$state   = (string) ( $deletes['state'] ?? '' );
		$count   = max( 0, (int) ( $deletes['count'] ?? 0 ) );
		$error   = (string) ( $deletes['error'] ?? ( $deletes['last_error'] ?? '' ) );
		$answer  = array(
			'state'     => self::LOCAL,
			'total'     => $total,
			'confirmed' => 0,
			'count'     => $count,
			'error'     => '',
		);

		// The queue outranks everything: while a delete is queued the object is
		// on its way out, and a dead row means it may still be billable.
		if ( 'dead' === $state ) {
			$answer['state'] = self::FAILED;
			$answer['error'] = $error;

			return $answer;
		}

		if ( 'queued' === $state || 'running' === $state ) {
			$answer['state'] = self::DELETING;

			return $answer;
		}

		if ( 0 === $total ) {
			return $answer;
		}

		if ( ! $can_confirm ) {
			$answer['state'] = self::UNKNOWN;

			return $answer;
		}

		$seen = 0;
		foreach ( $keys as $key ) {
			if ( true === ( $confirmed[ $key ] ?? false ) ) {
				$seen++;
			}
		}

		$answer['confirmed'] = $seen;

		if ( $seen === $total ) {
			$answer['state'] = self::SYNCED;
		} elseif ( $seen > 0 ) {
			$answer['state'] = self::PARTIAL;
		} else {
			$answer['state'] = self::PENDING;
		}

		return $answer;
	}
}
