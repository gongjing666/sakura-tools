<?php
/**
 * Production backup repository.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Backup;

use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter from the repository contract to the existing Backup implementation.
 *
 * Keeping this adapter thin preserves the current on-disk layout, postmeta
 * schema, path validation and cache invalidation while allowing callers to
 * depend on an interface instead of static methods.
 */
final class Backup_Repository implements Backup_Repository_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function store( string $origin_abs ): string|false {
		$result = Backup::store( $origin_abs );
		return is_string( $result ) ? $result : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function save_meta( int $attachment_id, string $backup_rel, string $origin_rel, string $converted_rel ): void {
		Backup::save_meta( $attachment_id, $backup_rel, $origin_rel, $converted_rel );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_meta( int $attachment_id ): ?array {
		$meta = get_post_meta( $attachment_id, Backup::META_KEY, true );
		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_backup( int $attachment_id ): bool {
		return (bool) Backup::has_backup( $attachment_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_backup( int $attachment_id ): void {
		Backup::delete_backup( $attachment_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function resolve_upload_path( string $relative_path, bool $require_backup_dir = false, bool $must_exist = false ): string|false {
		$result = Backup::resolve_upload_path( $relative_path, $require_backup_dir, $must_exist );
		return is_string( $result ) ? $result : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_usage( bool $force = false ): array {
		return (array) Backup::get_usage( $force );
	}

	/**
	 * {@inheritDoc}
	 */
	public function purge_all(): array {
		return (array) Backup::purge_all();
	}

	/**
	 * {@inheritDoc}
	 */
	public function clean_expired(): array {
		return (array) Backup::clean_expired();
	}
}
