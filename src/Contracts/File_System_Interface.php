<?php
/**
 * Local filesystem boundary.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit-failure local file operations used by image/restore services.
 */
interface File_System_Interface {

	/**
	 * Write a file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Contents.
	 * @param int    $flags    file_put_contents flags.
	 * @return bool
	 */
	public function write( string $path, string $contents, int $flags = LOCK_EX ): bool;

	/**
	 * Read a local file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public function read( string $path ): string|false;

	/**
	 * Copy a file.
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 * @return bool
	 */
	public function copy( string $source, string $destination ): bool;

	/**
	 * Atomically move a file within one filesystem.
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 * @return bool
	 */
	public function move( string $source, string $destination ): bool;

	/**
	 * Delete a file or symlink.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete( string $path ): bool;

	/**
	 * Remove an empty directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function remove_dir( string $path ): bool;

	/**
	 * Change permissions.
	 *
	 * @param string $path Absolute path.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	public function chmod( string $path, int $mode ): bool;
}
