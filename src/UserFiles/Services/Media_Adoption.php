<?php
/**
 * Charge a restored attachment to the user file space ledger.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Repositories\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Adopt one attachment into the ledger and charge its owner.
 *
 * The media library normally adopts on `add_attachment` (Media_Hooks::
 * assign_uploaded()), but a bucket download creates its row through
 * Attachment_Adopter, which deliberately suppresses adoption: that hook fires
 * while the file is still being written and before the author is known. Nothing
 * revisits the attachment afterwards, so it stayed outside every quota and the
 * media list showed it under "unassigned media" for good.
 *
 * The sequence below is the one Media_Hooks::adopt_unmanaged() already uses:
 * let Sync_Service create the row (and its artifact manifest), then charge the
 * recorded bytes. An existing row is returned untouched, so calling twice never
 * charges twice - the same idempotence the media library relies on.
 */
final class Media_Adoption {

	/**
	 * Files repository.
	 *
	 * @var Files
	 */
	private Files $files;

	/**
	 * Ledger.
	 *
	 * @var Quota
	 */
	private Quota $quota;

	/**
	 * Adoption service (creates the row and its manifest).
	 *
	 * @var Sync_Service
	 */
	private Sync_Service $sync;

	/**
	 * Constructor.
	 *
	 * @param Files|null             $files Files repository.
	 * @param Quota|null             $quota Ledger.
	 * @param Sync_Service|null      $sync  Adoption service.
	 */
	public function __construct( ?Files $files = null, ?Quota $quota = null, ?Sync_Service $sync = null ) {
		$this->files = $files ?? new Files();
		$this->quota = $quota ?? new Quota();
		$this->sync  = $sync ?? new Sync_Service( $this->files, $this->quota );
	}

	/**
	 * Adopt one attachment, charging its owner at most once.
	 *
	 * Authorless attachments stay outside the ledger because there is no quota
	 * to charge (`Sync_Service::adopt()` refuses them); so do attachments that
	 * are no longer readable on disk, whose manifest cannot be proven.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int Bytes charged, or 0 when nothing was adopted.
	 */
	public function adopt( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		$existing = $this->files->get_by_attachment( $attachment_id );

		if ( $existing ) {
			return (int) $existing->charged_bytes;
		}

		if ( ! $this->sync->adopt( $attachment_id ) ) {
			return 0;
		}

		$file = $this->files->get_by_attachment( $attachment_id );

		if ( ! $file ) {
			return 0;
		}

		$charged = (int) $file->charged_bytes;

		if ( $charged > 0 ) {
			$this->quota->adjust_used( (int) $file->owner_id, $charged );
		}

		return $charged;
	}
}
