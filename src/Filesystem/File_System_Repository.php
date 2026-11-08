<?php
/**
 * Production filesystem repository.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Filesystem;

use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\File_System;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter from the filesystem contract to the existing static helper.
 */
final class File_System_Repository implements File_System_Interface {

	/**
	 * Write a file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Contents.
	 * @param int    $flags    Flags.
	 * @return bool
	 */
	public function write( string $path, string $contents, int $flags = LOCK_EX ): bool {
		return File_System::write( $path, $contents, $flags );
	}

	/**
	 * Read a file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public function read( string $path ): string|false {
		return File_System::read( $path );
	}

	/**
	 * Copy a file.
	 *
	 * @param string $source      Source.
	 * @param string $destination Destination.
	 * @return bool
	 */
	public function copy( string $source, string $destination ): bool {
		return File_System::copy( $source, $destination );
	}

	/**
	 * Atomically move a file.
	 *
	 * @param string $source      Source.
	 * @param string $destination Destination.
	 * @return bool
	 */
	public function move( string $source, string $destination ): bool {
		return File_System::move( $source, $destination );
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete( string $path ): bool {
		return File_System::delete( $path );
	}

	/**
	 * Remove an empty directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function remove_dir( string $path ): bool {
		return File_System::remove_dir( $path );
	}

	/**
	 * Change permissions.
	 *
	 * @param string $path Absolute path.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	public function chmod( string $path, int $mode ): bool {
		return File_System::chmod( $path, $mode );
	}
}
