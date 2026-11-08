<?php
/**
 * Original-upload backup step.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the original upload before conversion and makes failures explicit.
 */
final class Upload_Backup {

	/**
	 * Backup repository.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param Backup_Repository_Interface          $backup   Backup repository.
	 * @param File_System_Interface                $files    Filesystem repository.
	 * @param Settings_Repository_Interface|null   $settings Settings repository.
	 */
	public function __construct( Backup_Repository_Interface $backup, File_System_Interface $files, ?Settings_Repository_Interface $settings = null ) {
		$this->backup   = $backup;
		$this->files    = $files;
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * Whether backups are enabled (default on).
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->backup_enabled();
	}

	/**
	 * Back up one uploaded file.
	 *
	 * @param string $file Absolute path.
	 * @return string|false Empty when disabled, false on failure.
	 */
	public function store( string $file ) {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$backup_rel = $this->backup->store( $file );
		if ( false === $backup_rel || '' === $backup_rel ) {
			$this->files->delete( $file );
			return false;
		}

		return $backup_rel;
	}

	/**
	 * Remove a backup copy that is no longer needed.
	 *
	 * Used when the original file was kept as-is (no size gain), so the fresh
	 * backup would only be an orphan duplicate of the untouched original.
	 *
	 * @param string $relative_path Backup-relative path.
	 * @return void
	 */
	public function delete( string $relative_path ): void {
		if ( '' === $relative_path ) {
			return;
		}

		$absolute = $this->backup->resolve_upload_path( $relative_path, true, true );
		if ( false !== $absolute ) {
			$this->files->delete( $absolute );
		}
	}
}
