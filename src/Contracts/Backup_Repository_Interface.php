<?php
/**
 * Backup repository contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence boundary for original-image backups.
 *
 * Callers depend on this interface instead of the static Backup class. The
 * production adapter delegates to Backup; tests can provide an in-memory fake.
 */
interface Backup_Repository_Interface {

	/**
	 * Store an original file before it is overwritten or deleted.
	 *
	 * @param string $origin_abs Absolute path below uploads.
	 * @return string|false Backup path relative to uploads, or false.
	 */
	public function store( string $origin_abs ): string|false;

	/**
	 * Persist the backup metadata for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $backup_rel    Backup path relative to uploads.
	 * @param string $origin_rel    Original path relative to uploads.
	 * @param string $converted_rel Converted main file relative to uploads.
	 */
	public function save_meta( int $attachment_id, string $backup_rel, string $origin_rel, string $converted_rel ): void;

	/**
	 * Read backup metadata for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>|null
	 */
	public function get_meta( int $attachment_id ): ?array;

	/**
	 * Whether the attachment still has a readable backup file.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function has_backup( int $attachment_id ): bool;

	/**
	 * Delete the backup file and metadata for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_backup( int $attachment_id ): void;

	/**
	 * Resolve an uploads-relative path while rejecting traversal.
	 *
	 * @param string $relative_path      Uploads-relative path.
	 * @param bool   $require_backup_dir Require the plugin backup directory.
	 * @param bool   $must_exist         Require an existing regular file.
	 * @return string|false
	 */
	public function resolve_upload_path( string $relative_path, bool $require_backup_dir = false, bool $must_exist = false ): string|false;

	/**
	 * Backup directory usage.
	 *
	 * @param bool $force Skip the transient cache.
	 * @return array{bytes:int,count:int}
	 */
	public function get_usage( bool $force = false ): array;

	/**
	 * Delete all backups and their metadata.
	 *
	 * @return array{bytes:int,count:int}
	 */
	public function purge_all(): array;

	/**
	 * Delete expired / over-limit backups.
	 *
	 * @return array{bytes:int,count:int}
	 */
	public function clean_expired(): array;
}
