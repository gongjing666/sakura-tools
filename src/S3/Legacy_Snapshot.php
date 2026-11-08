<?php
/**
 * Compatibility snapshot used when the persistent index is disabled.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Sync\Kind;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a small, cached local/remote snapshot without the sync index.
 *
 * This is the rollback path for `sakura_imgopt_sync_index_enabled=0`: it lists
 * at most 25,000 local files and 50,000 remote objects, keeps 300 table rows and
 * caches the result for 30 minutes, matching the pre-1.4 behavior closely
 * enough for an operator to keep working while the index is disabled.
 */
final class Legacy_Snapshot {

	/**
	 * Cache transient.
	 */
	public const TRANSIENT = 'sakura_imgopt_s3_snapshot_compat';

	/**
	 * Cache lifetime.
	 */
	private const TTL = 1800;

	/**
	 * Local file cap.
	 */
	private const LOCAL_LIMIT = 25000;

	/**
	 * Remote object cap.
	 */
	private const CLOUD_LIMIT = 50000;

	/**
	 * Table row cap.
	 */
	private const ROW_LIMIT = 300;

	private Options $options;
	private Storage_Adapter $adapter;

	/**
	 * Constructor.
	 *
	 * @param Options              $options Options.
	 * @param Storage_Adapter|null $adapter Adapter.
	 */
	public function __construct( Options $options, ?Storage_Adapter $adapter = null ) {
		$this->options = $options;
		$this->adapter = $adapter ?? new Storage_Adapter( $options );
	}

	/**
	 * Snapshot (cached).
	 *
	 * @param bool $force Rebuild.
	 * @return array<string,mixed>
	 */
	public function get( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['built_at'] ) ) {
				return $cached;
			}
		}
		$snapshot = $this->build();
		set_transient( self::TRANSIENT, $snapshot, self::TTL );
		return $snapshot;
	}

	/**
	 * Delete the cached snapshot.
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Build a snapshot from one local walk and one remote listing.
	 *
	 * @return array<string,mixed>
	 */
	private function build(): array {
		$prefix = trim( (string) $this->options->upload_path(), '/' );
		$base   = wp_upload_dir( null, false );
		$root   = (string) ( $base['basedir'] ?? '' );

		$local  = array();
		$lbytes = 0;
		if ( '' !== $root && is_dir( $root ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( count( $local ) >= self::LOCAL_LIMIT ) {
					break;
				}
				if ( ! $file->isFile() || $file->isLink() ) {
					continue;
				}
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
				if ( $this->ignored( $relative ) ) {
					continue;
				}
				$key          = ( '' !== $prefix ? $prefix . '/' : '' ) . $relative;
				$local[ $key ] = (int) $file->getSize();
				$lbytes      += (int) $file->getSize();
			}
		}

		$remote  = array();
		$rbytes  = 0;
		$types   = array( 'image' => 0, 'video' => 0, 'archive' => 0, 'other' => 0 );
		$error   = '';
		$listing = $this->adapter->list_objects( $prefix, self::CLOUD_LIMIT );
		if ( is_wp_error( $listing ) ) {
			$error = $listing->get_error_message();
		} else {
			foreach ( (array) ( $listing['objects'] ?? array() ) as $item ) {
				$key = (string) ( $item['key'] ?? '' );
				if ( '' === $key || $this->ignored( $key ) ) {
					continue;
				}
				$remote[ $key ] = (int) ( $item['size'] ?? 0 );
				$rbytes        += (int) ( $item['size'] ?? 0 );
				$kind           = Kind::of( $key );
				$types[ $kind ] = (int) ( $types[ $kind ] ?? 0 ) + 1;
			}
		}

		$counts = array( 'synced' => 0, 'pending' => 0, 'remote' => 0, 'mismatch' => 0 );
		$bytes  = array( 'synced' => 0, 'pending' => 0, 'remote' => 0, 'mismatch' => 0 );
		$keys   = array_unique( array_merge( array_keys( $local ), array_keys( $remote ) ) );
		sort( $keys, SORT_STRING );

		$rows = array();
		foreach ( $keys as $key ) {
			$has_local  = isset( $local[ $key ] );
			$has_remote = isset( $remote[ $key ] );
			$size       = (int) ( $local[ $key ] ?? 0 );
			$cloud      = (int) ( $remote[ $key ] ?? 0 );
			if ( $has_local && $has_remote ) {
				$status = $size === $cloud ? 'synced' : 'mismatch';
			} elseif ( $has_local ) {
				$status = 'pending';
			} else {
				$status = 'remote';
			}
			++$counts[ $status ];
			$bytes[ $status ] += max( $size, $cloud );
			if ( count( $rows ) < self::ROW_LIMIT ) {
				$name  = basename( $key );
				$rows[] = array(
					'key'        => $key,
					'name'       => $name,
					'path'       => $has_local ? $key : $name,
					'type'       => Kind::of( $key ),
					'size'       => $size,
					'cloud_size' => $cloud,
					'mtime'      => 0,
					'status'     => $status,
					'error'      => '',
					'local'      => $has_local,
					'cloud'      => $has_remote,
				);
			}
		}

		return array(
			'local'    => array(
				'files' => count( $local ),
				'bytes' => $lbytes,
			),
			'cloud'    => array(
				'objects' => count( $remote ),
				'bytes'   => $rbytes,
				'types'   => array(
					'image'   => $types['image'],
					'video'   => $types['video'],
					'archive' => $types['archive'],
					'other'   => $types['other'],
					'bytes'   => $rbytes,
				),
			),
			'counts'   => $counts,
			'bytes'    => $bytes,
			'total'    => count( $keys ),
			'rows'     => $rows,
			'error'    => $error,
			'built_at' => time(),
		);
	}

	/**
	 * Whether a relative path is plugin bookkeeping.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	private function ignored( string $relative ): bool {
		$relative = '/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
		return str_contains( $relative, '/sakura-imgopt-backup/' ) || str_contains( $relative, '/.sakura-s3-cache/' );
	}
}
