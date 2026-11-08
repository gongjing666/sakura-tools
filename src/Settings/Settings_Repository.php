<?php
/**
 * Options-backed settings repository.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Settings;

use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Single boundary for plugin settings storage.
 *
 * WordPress still owns the rows and the Settings API still owns form
 * registration/validation; this class owns the option names, defaults and
 * typed reads so image/admin services do not call get_option() directly.
 */
final class Settings_Repository implements Settings_Repository_Interface {

	/**
	 * Option prefix.
	 */
	public const PREFIX = 'sakura_imgopt_';

	/**
	 * Supported source formats.
	 */
	public const SOURCE_FORMATS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'apng' );

	/**
	 * Formats enabled for new sites.
	 *
	 * SVG is opt-in: Imagick with an SVG delegate rasterizes vectors, which can
	 * blow up the file size instead of shrinking it.
	 */
	public const DEFAULT_SOURCE_FORMATS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'apng' );

	/**
	 * Default compression file-size ceiling, in megabytes (0 = unlimited).
	 */
	public const DEFAULT_MAX_FILE_SIZE_MB = 24;

	/**
	 * Default resolution ceiling width.
	 */
	public const DEFAULT_MAX_IMAGE_WIDTH = 3840;

	/**
	 * Default resolution ceiling height.
	 */
	public const DEFAULT_MAX_IMAGE_HEIGHT = 2160;

	/**
	 * Highest supported in-request upload concurrency.
	 */
	public const MAX_UPLOAD_CONCURRENCY = 4;

	/**
	 * Defaults keyed by full option name.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'sakura_imgopt_enable_compression'          => 0,
		'sakura_imgopt_compression_mode'            => 'compress_only',
		'sakura_imgopt_convert_format'              => 'webp',
		'sakura_imgopt_source_formats'              => self::DEFAULT_SOURCE_FORMATS,
		'sakura_imgopt_quality'                     => 82,
		'sakura_imgopt_max_file_size_mb'            => self::DEFAULT_MAX_FILE_SIZE_MB,
		'sakura_imgopt_max_image_width'             => self::DEFAULT_MAX_IMAGE_WIDTH,
		'sakura_imgopt_max_image_height'            => self::DEFAULT_MAX_IMAGE_HEIGHT,
		'sakura_imgopt_backup_enabled'              => 1,
		'sakura_imgopt_backup_retention_days'       => 0,
		'sakura_imgopt_backup_max_mb'               => 0,
		'sakura_imgopt_rename_enabled'              => 0,
		'sakura_imgopt_rename_rule'                 => '{date}-{time}-{rand}',
		'sakura_imgopt_disabled_sizes'              => array(),
		'sakura_imgopt_batch_size'                  => 1,
		'sakura_imgopt_ajax_delay'                  => 500,
		'sakura_imgopt_upload_concurrency'          => 1,
		'sakura_imgopt_delete_data_on_uninstall'    => 0,
	);

	/**
	 * Full option name.
	 *
	 * @param string $key Key with or without prefix.
	 * @return string
	 */
	public function option_name( string $key ): string {
		return str_starts_with( $key, self::PREFIX ) ? $key : self::PREFIX . $key;
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$name = $this->option_name( $key );

		if ( func_num_args() > 1 ) {
			return get_option( $name, $default );
		}

		return get_option( $name, self::DEFAULTS[ $name ] ?? null );
	}

	/**
	 * Persist a setting.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function update( string $key, $value ): bool {
		return (bool) update_option( $this->option_name( $key ), $value, false );
	}

	/**
	 * All known settings, including defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$all = array();
		foreach ( self::DEFAULTS as $name => $default ) {
			$all[ $name ] = get_option( $name, $default );
		}
		return $all;
	}

	/**
	 * Compression master switch.
	 */
	public function compression_enabled(): bool {
		return (bool) $this->get( 'enable_compression' );
	}

	/**
	 * Compression mode.
	 */
	public function compression_mode(): string {
		$mode = (string) $this->get( 'compression_mode' );
		return in_array( $mode, array( 'compress_only', 'compress_convert', 'convert_only' ), true ) ? $mode : 'compress_only';
	}

	/**
	 * Target format.
	 */
	public function convert_format(): string {
		$format = strtolower( (string) $this->get( 'convert_format' ) );
		return in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'webp';
	}

	/**
	 * Allowed source extensions.
	 *
	 * @return array<int,string>
	 */
	public function source_formats(): array {
		$formats = $this->get( 'source_formats' );
		if ( ! is_array( $formats ) ) {
			return self::DEFAULT_SOURCE_FORMATS;
		}

		return array_values( array_intersect( array_map( 'strtolower', $formats ), self::SOURCE_FORMATS ) );
	}

	/**
	 * Compression quality.
	 */
	public function quality(): int {
		return max( 1, min( 100, (int) $this->get( 'quality' ) ) );
	}

	/**
	 * Maximum source file size, in megabytes. 0 disables the check.
	 */
	public function max_file_size_mb(): int {
		return max( 0, min( 1024, (int) $this->get( 'max_file_size_mb' ) ) );
	}

	/**
	 * Maximum image width, in pixels. 0 disables the check.
	 */
	public function max_image_width(): int {
		return max( 0, min( 20000, (int) $this->get( 'max_image_width' ) ) );
	}

	/**
	 * Maximum image height, in pixels. 0 disables the check.
	 */
	public function max_image_height(): int {
		return max( 0, min( 20000, (int) $this->get( 'max_image_height' ) ) );
	}

	/**
	 * Original backup switch.
	 */
	public function backup_enabled(): bool {
		return (bool) $this->get( 'backup_enabled' );
	}

	/**
	 * Rename switch.
	 */
	public function rename_enabled(): bool {
		return (bool) $this->get( 'rename_enabled' );
	}

	/**
	 * Rename rule.
	 */
	public function rename_rule(): string {
		$rule = (string) $this->get( 'rename_rule' );
		return '' === $rule ? '{date}-{time}-{rand}' : $rule;
	}

	/**
	 * Disabled sizes.
	 *
	 * @return array<int,string>
	 */
	public function disabled_sizes(): array {
		$sizes = $this->get( 'disabled_sizes' );
		if ( ! is_array( $sizes ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $sizes ) ) );
	}

	/**
	 * Batch size.
	 */
	public function batch_size(): int {
		return max( 1, min( 20, (int) $this->get( 'batch_size' ) ) );
	}

	/**
	 * Effective in-request upload concurrency (1-4).
	 *
	 * The stored value is only a request; Upload_Concurrency::effective()
	 * downgrades it to 1 when the HTTP stack cannot run parallel requests.
	 */
	public function upload_concurrency(): int {
		return max( 1, min( self::MAX_UPLOAD_CONCURRENCY, (int) $this->get( 'upload_concurrency' ) ) );
	}

	/**
	 * AJAX delay.
	 */
	public function ajax_delay(): int {
		return max( 0, min( 2000, (int) $this->get( 'ajax_delay' ) ) );
	}

	/**
	 * Backup retention days.
	 */
	public function backup_retention_days(): int {
		return max( 0, (int) $this->get( 'backup_retention_days' ) );
	}

	/**
	 * Backup cap in MB.
	 */
	public function backup_max_mb(): int {
		return max( 0, (int) $this->get( 'backup_max_mb' ) );
	}

	/**
	 * Uninstall data switch.
	 */
	public function delete_data_on_uninstall(): bool {
		return 1 === (int) $this->get( 'delete_data_on_uninstall' );
	}
}
