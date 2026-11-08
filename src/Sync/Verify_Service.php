<?php
/**
 * Background content verification (ETag vs local MD5).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\S3\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Walks synced rows whose content has not been checked yet.
 *
 * A row is only comparable when the remote ETag is a plain MD5 (single-part
 * upload) and the local file still exists. Multipart ETags (`...-N`) and
 * SSE/re-encoded stores cannot be compared with a local MD5, so they are
 * marked "unverifiable" instead of being reported as mismatches.
 */
final class Verify_Service {

	/**
	 * Rows selected per query.
	 */
	public const BATCH = 200;

	/**
	 * Per-profile cursor option (map profile_id => hex hash).
	 */
	public const CURSOR_OPTION = 'sakura_imgopt_sync_verify_cursor';

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Repository.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $index;

	/**
	 * Constructor.
	 *
	 * @param Options          $options Storage options.
	 * @param Index_Repository $index   Repository.
	 */
	public function __construct( Options $options, Index_Repository $index ) {
		$this->options = $options;
		$this->index   = $index;
	}

	/**
	 * Verify a bounded number of rows for every configured profile.
	 *
	 * @param int         $max_seconds Wall-clock budget.
	 * @param int         $max_files   Optional row cap (0 = budget only).
	 * @param string|null $only_profile Restrict to one profile.
	 * @return array{processed:int,checked:int,mismatched:int,unverifiable:int,done:bool,busy:bool}
	 */
	public function run( int $max_seconds = 20, int $max_files = 0, ?string $only_profile = null ): array {
		global $wpdb;

		$deadline = microtime( true ) + max( 1, $max_seconds );
		$lock     = 'sio_verify_' . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . $wpdb->prefix ), 0, 20 );
		$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) );
		if ( 1 !== $acquired ) {
			return array(
				'processed'    => 0,
				'checked'      => 0,
				'mismatched'   => 0,
				'unverifiable' => 0,
				'done'         => false,
				'busy'         => true,
			);
		}

		$totals = array(
			'processed'    => 0,
			'checked'      => 0,
			'mismatched'   => 0,
			'unverifiable' => 0,
			'done'         => false,
			'busy'         => false,
		);

		try {
			$profiles = null !== $only_profile ? array( $only_profile => true ) : $this->options->profiles();
			foreach ( array_keys( $profiles ) as $profile_id ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$result = $this->run_profile( (string) $profile_id, $deadline, $max_files, $totals['processed'] );
				$totals['processed']    += $result['processed'];
				$totals['checked']      += $result['checked'];
				$totals['mismatched']   += $result['mismatched'];
				$totals['unverifiable'] += $result['unverifiable'];
				$totals['done']          = $result['done'];
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		return $totals;
	}

	/**
	 * Verify rows for one profile until the budget or a batch boundary.
	 *
	 * @param string $profile_id Profile.
	 * @param float  $deadline   Absolute deadline.
	 * @param int    $max_files  Optional cap.
	 * @param int    $already    Rows already processed in this run.
	 * @return array{processed:int,checked:int,mismatched:int,unverifiable:int,done:bool}
	 */
	private function run_profile( string $profile_id, float $deadline, int $max_files, int $already ): array {
		global $wpdb;

		$cursors = $this->cursors();
		$cursor  = (string) ( $cursors[ $profile_id ] ?? '' );
		$out     = array(
			'processed'    => 0,
			'checked'      => 0,
			'mismatched'   => 0,
			'unverifiable' => 0,
			'done'         => false,
		);

		while ( microtime( true ) < $deadline ) {
			if ( $max_files > 0 && ( $already + $out['processed'] ) >= $max_files ) {
				break;
			}

			$rows = $this->rows( $profile_id, $cursor, self::BATCH );
			if ( ! $rows ) {
				$out['done'] = true;
				$cursor      = '';
				break;
			}

			$hit_deadline = false;
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline || ( $max_files > 0 && ( $already + $out['processed'] ) >= $max_files ) ) {
					$hit_deadline = true;
					break;
				}
				++$out['processed'];
				$key  = (string) $row['object_key'];
				$hash = (string) $row['hash_hex'];
				$path = $this->absolute_path( $key, (string) $row['local_path'] );

				if ( ! is_file( $path ) ) {
					// The local copy disappeared after the scan: do not compare,
					// the next scan will settle the row.
					$this->index->record_verification( $profile_id, $key, '', 2 );
					++$out['unverifiable'];
					$cursor = $hash;
					continue;
				}

				$etag = Checksums::normalize_etag( (string) $row['etag'] );


				$md5 = Checksums::hash_file( $path, $deadline );
				if ( '' === $md5 ) {
					// Deadline hit inside a large file: retry this row next tick.
					$hit_deadline = true;
					break;
				}
				if ( false === $md5 ) {
					$this->index->record_verification( $profile_id, $key, '', 2 );
					++$out['unverifiable'];
					$cursor = $hash;
					continue;
				}

				$state = Checksums::is_md5_etag( $etag ) ? ( $etag === $md5 ? 1 : 3 ) : 2;
				$this->index->record_verification( $profile_id, $key, $md5, $state );
				++$out[ 3 === $state ? 'mismatched' : ( 2 === $state ? 'unverifiable' : 'checked' )];
				$cursor = $hash;
			}

			$cursors[ $profile_id ] = $cursor;
			$this->save_cursors( $cursors );

			if ( $hit_deadline ) {
				break;
			}
			if ( count( $rows ) < self::BATCH ) {
				$out['done'] = true;
				$cursors[ $profile_id ] = '';
				$this->save_cursors( $cursors );
				break;
			}
		}

		return $out;
	}

	/**
	 * Fetch the next unverified, size-equal rows in keyset order.
	 *
	 * @param string $profile_id Profile.
	 * @param string $cursor     Hex hash boundary (`''` for the start).
	 * @param int    $limit      Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $profile_id, string $cursor, int $limit ): array {
		global $wpdb;
		$where = "profile_id=%s AND seen_local=1 AND seen_remote=1
			AND local_size=remote_size AND verify_state=0
			AND (status='synced' OR (status='mismatch' AND error_code='sio_etag_mismatch'))";
		$args  = array( $profile_id );
		if ( '' !== $cursor ) {
			$where .= ' AND object_key_hash > UNHEX(%s)';
			$args[] = $cursor;
		}
		$args[] = $limit;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_key,local_path,local_size,remote_size,etag,local_md5,HEX(object_key_hash) AS hash_hex
				FROM {$this->index->table_name()}
				WHERE {$where}
				ORDER BY object_key_hash ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed SQL fragments.
				$args
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolve an index path to an absolute uploads path.
	 *
	 * @param string $key        Object key.
	 * @param string $local_path Stored path (key or uploads-relative).
	 */
	private function absolute_path( string $key, string $local_path ): string {
		$path = '' !== $local_path ? $local_path : $key;
		if ( '' !== $path && '/' === $path[0] ) {
			return $path;
		}
		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		$base    = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' );
		$prefix  = trim( (string) $this->options->upload_path(), '/' );
		if ( '' !== $prefix && 0 === strpos( $path, $prefix . '/' ) ) {
			$path = substr( $path, strlen( $prefix ) + 1 );
		}
		return '' !== $base ? $base . '/' . ltrim( $path, '/' ) : '';
	}

	/**
	 * Read cursor map.
	 *
	 * @return array<string,string>
	 */
	private function cursors(): array {
		$value = get_option( self::CURSOR_OPTION, array() );
		return is_array( $value ) ? array_map( 'strval', $value ) : array();
	}

	/**
	 * Persist cursor map.
	 *
	 * @param array<string,string> $cursors Cursors.
	 */
	private function save_cursors( array $cursors ): void {
		update_option( self::CURSOR_OPTION, $cursors, false );
	}
}
