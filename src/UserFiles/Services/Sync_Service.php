<?php
/**
 * Media-library reconciliation: align stored sizes and file counts with disk.
 *
 * The scan is keyset-paged over attachment IDs with a per-tick time budget, so
 * a library with tens of thousands of files never blocks a request; the admin
 * UI polls the state while WP-Cron keeps advancing it in the background.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One resumable scan at a time, addressed by the attachment ID cursor.
 */
final class Sync_Service {

	/**
	 * State option (single site-wide scan).
	 */
	public const OPTION = 'sufm_sync_state';

	/**
	 * Attachments inspected per tick.
	 */
	private const BATCH = 200;

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
	 * Constructor.
	 *
	 * @param Files $files Files repository.
	 * @param Quota $quota Quota ledger.
	 */
	public function __construct( Files $files, Quota $quota ) {
		$this->files = $files;
		$this->quota = $quota;
	}

	/**
	 * Current state, with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return wp_parse_args(
			$raw,
			array(
				'state'    => 'idle',
				'cursor'   => 0,
				'total'    => 0,
				'scanned'   => 0,
				'updated'   => 0,
				'owned'     => 0,
				'imported'  => 0,
				'started'  => 0,
				'finished' => 0,
				'last'     => '',
			)
		);
	}

	/**
	 * Start (or resume) a scan.
	 *
	 * @return array<string, mixed>
	 */
	public function start() {
		$state = $this->state();

		if ( 'running' === (string) $state['state'] ) {
			return $state;
		}

		$total = $this->count_attachments();

		$state = array(
			'state'    => 0 === $total ? 'done' : 'running',
			'cursor'   => 0,
			'total'    => $total,
			'scanned'  => 0,
			'updated'  => 0,
			'owned'    => 0,
			'imported' => 0,
			'started'  => time(),
			'finished' => 0 === $total ? time() : 0,
			'last'     => '',
		);

		update_option( self::OPTION, $state, false );

		if ( 0 === $total ) {
			return $this->finish( $state );
		}

		return $state;
	}

	/**
	 * Advance the scan within a time budget.
	 *
	 * @param int $budget_ms Milliseconds this tick may spend.
	 * @return array<string, mixed>
	 */
	public function tick( int $budget_ms = 1500 ): array {
		global $wpdb;

		$state = $this->state();

		if ( 'running' !== (string) $state['state'] ) {
			return $state;
		}

		$deadline = microtime( true ) + max( 200, $budget_ms ) / 1000;
		$cursor   = (int) $state['cursor'];
		$scanned  = (int) $state['scanned'];
		$updated  = (int) $state['updated'];
		$owned    = (int) $state['owned'];
		$imported = (int) $state['imported'];
		$files    = Schema::table( 'files' );

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cursor,
					self::BATCH
				)
			);

			if ( empty( $ids ) ) {
				$state['cursor']  = $cursor;
				$state['scanned']  = $scanned;
				$state['updated']  = $updated;
				$state['owned']    = $owned;
				$state['imported'] = $imported;
				update_option( self::OPTION, $state, false );

				return $this->finish( $state );
			}

			foreach ( $ids as $id ) {
				$cursor = (int) $id;
				$scanned++;

				$file = $this->files->get_by_attachment( $cursor );
				if ( ! $file ) {
					if ( $this->adopt( $cursor ) ) {
						$imported++;
					}
					continue;
				}

				$owned++;
				$actual = $this->actual_bytes( $cursor );
				$charged = (int) $file->charged_bytes;

				if ( $actual !== $charged ) {
					$delta = $actual - $charged;
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$files} SET charged_bytes=%d, version=version+1 WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$actual,
							(int) $file->id
						)
					);
					$this->quota->adjust_used( (int) $file->owner_id, $delta );
					$updated++;
				}
			}

			$state['cursor']   = $cursor;
			$state['scanned']  = $scanned;
			$state['updated']  = $updated;
			$state['owned']    = $owned;
			$state['imported'] = $imported;
			update_option( self::OPTION, $state, false );
		} while ( microtime( true ) < $deadline );

		return $state;
	}

	/**
	 * Close the scan and rewrite ledgers from the recorded sizes.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function finish( array $state ): array {
		global $wpdb;

		$files = Schema::table( 'files' );
		$usage = Schema::table( 'usage' );

		$owners = $wpdb->get_results(
			"SELECT owner_id, SUM(charged_bytes) AS total FROM {$files} WHERE lifecycle<>'purged' GROUP BY owner_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$fixed = 0;
		foreach ( is_array( $owners ) ? $owners : array() as $row ) {
			$owner = (int) $row->owner_id;
			$total = (int) $row->total;
			$this->quota->ensure( $owner );
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$usage} SET used_bytes=%d, revision=revision+1 WHERE owner_id=%d AND used_bytes<>%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$total,
					$owner,
					$total
				)
			);
			if ( $changed ) {
				$fixed++;
			}
		}

		$state['state']    = 'done';
		$state['finished'] = time();
		$state['last']     = sprintf(
			/* translators: 1: attachments scanned, 2: newly counted media files, 3: files updated, 4: ledgers corrected */
			__( '扫描 %1$d 个附件，纳入 %2$d 个媒体库文件，更新 %3$d 个文件，修正 %4$d 个账本', 'sakura-imgopt' ),
			(int) $state['scanned'],
			(int) ( $state['imported'] ?? 0 ),
			(int) $state['updated'],
			$fixed
		);
		update_option( self::OPTION, $state, false );

		return $state;
	}

	/**
	 * Adopt a media-library attachment into the ledger.
	 *
	 * Only attachments with a real author are charged; author-less files stay
	 * outside the ledger because they have no quota to charge against.
	 *
	 * @param int $attachment_id Attachment.
	 * @return bool True when a row was created.
	 */
	public function adopt( int $attachment_id ): bool {
		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$owner = (int) $post->post_author;
		if ( $owner <= 0 || ! get_userdata( $owner ) ) {
			return false;
		}

		$path = get_attached_file( $attachment_id );
		$name = (string) $post->post_title;
		if ( '' === trim( $name ) && is_string( $path ) ) {
			$name = wp_basename( $path );
		}

		$created = $this->files->create(
			array(
				'attachment_id'   => $attachment_id,
				'owner_id'        => $owner,
				'folder_id'       => 0,
				'display_name'    => '' === trim( $name ) ? '#' . $attachment_id : $name,
				'source'          => Files::SOURCE_MEDIA,
				'storage_state'   => 'local',
				'charged_bytes'   => $this->actual_bytes( $attachment_id ),
				'manifest_version' => 0,
			)
		);

		if ( is_wp_error( $created ) ) {
			return false;
		}

		// Record the inventory right away. A row adopted without artifacts could
		// only ever move inside its own account, because cross-user transfer
		// demands a proven manifest — adoption used to create second-class rows.
		$this->record_artifacts( (int) $created->id, $attachment_id, (int) $created->charged_bytes );

		return true;
	}

	/**
	 * Persist a disk inventory for one ledger row, promoting it to a proven manifest.
	 *
	 * Bytes are recomputed from disk rather than trusted from the caller, and the
	 * write only happens when the inventory reproduces the recorded charge exactly.
	 * A drifted row therefore keeps manifest_version 0 and stays non-transferable
	 * instead of becoming movable on a guess.
	 *
	 * @param int $file_id       Ledger row ID.
	 * @param int $attachment_id Attachment.
	 * @param int $charged       Recorded charge in bytes.
	 * @return bool True when artifact rows were written.
	 */
	public function record_artifacts( int $file_id, int $attachment_id, int $charged ): bool {
		global $wpdb;

		if ( $file_id <= 0 || $charged <= 0 ) {
			return false;
		}

		$manifest = ( new Adoption_Manifest() )->build( $attachment_id );
		if ( is_wp_error( $manifest ) || array_sum( array_column( $manifest, 'logical_bytes' ) ) !== $charged ) {
			return false;
		}

		$now  = Time::now();
		$done = 0;

		foreach ( $manifest as $artifact ) {
			$inserted = $wpdb->insert(
				Schema::table( 'artifacts' ),
				array(
					'file_id'        => $file_id,
					'artifact_key'   => 'media:' . $attachment_id . ':' . hash( 'sha256', $artifact['local_path'] ),
					'generation'     => 1,
					'kind'           => $artifact['kind'],
					'logical_bytes'  => $artifact['logical_bytes'],
					'local_path'     => $artifact['local_path'],
					'remote_context' => $artifact['remote_context'],
					'checksum'       => $artifact['checksum'],
					'cleanup_state'  => 'present',
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
			if ( false === $inserted ) {
				return false;
			}
			++$done;
		}

		if ( $done > 0 ) {
			$wpdb->update( Schema::table( 'files' ), array( 'manifest_version' => 1, 'updated_at' => $now ), array( 'id' => $file_id ) );
		}

		return $done > 0;
	}

	/**
	 * Bytes proven to exist on disk for one attachment.
	 *
	 * @param int $attachment_id Attachment.
	 * @return int
	 */
	private function actual_bytes( int $attachment_id ): int {
		$total = 0;

		$original = get_attached_file( $attachment_id );
		if ( is_string( $original ) && '' !== $original && is_file( $original ) ) {
			$total += (int) filesize( $original );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( (string) $original );
			foreach ( $meta['sizes'] as $size ) {
				$name = is_array( $size ) ? (string) ( $size['file'] ?? '' ) : '';
				if ( '' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_file( $path ) ) {
					$total += (int) filesize( $path );
				}
			}
		}

		return $total;
	}

	/**
	 * Count attachments for the progress denominator.
	 *
	 * @return int
	 */
	private function count_attachments(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
