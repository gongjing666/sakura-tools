<?php
/**
 * Settings repository contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical read/write boundary for plugin settings.
 */
interface Settings_Repository_Interface {

	/**
	 * Read one plugin option.
	 *
	 * @param string $key     Option key, with or without the `sakura_imgopt_` prefix.
	 * @param mixed  $default Fallback when the option does not exist.
	 * @return mixed
	 */
	public function get( string $key, $default = null );

	/**
	 * Persist one plugin option.
	 *
	 * @param string $key   Option key, with or without the prefix.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function update( string $key, $value ): bool;

	/**
	 * All known plugin settings, including defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array;

	/**
	 * Full option name for a setting key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function option_name( string $key ): string;

	/**
	 * Compression master switch.
	 */
	public function compression_enabled(): bool;

	/**
	 * Compression mode: compress_only, compress_convert, convert_only.
	 */
	public function compression_mode(): string;

	/**
	 * Target format: webp or avif.
	 */
	public function convert_format(): string;

	/**
	 * Allowed source extensions.
	 *
	 * @return array<int,string>
	 */
	public function source_formats(): array;

	/**
	 * Compression quality (1–100).
	 */
	public function quality(): int;

	/**
	 * Original backup switch.
	 */
	public function backup_enabled(): bool;

	/**
	 * Rename switch.
	 */
	public function rename_enabled(): bool;

	/**
	 * Rename rule.
	 */
	public function rename_rule(): string;

	/**
	 * Disabled intermediate sizes.
	 *
	 * @return array<int,string>
	 */
	public function disabled_sizes(): array;

	/**
	 * Batch size (1–20).
	 */
	public function batch_size(): int;

	/**
	 * Delay between AJAX batches in milliseconds (0–2000).
	 */
	public function ajax_delay(): int;

	/**
	 * Backup retention days (0 = forever).
	 */
	public function backup_retention_days(): int;

	/**
	 * Backup storage cap in MB (0 = unlimited).
	 */
	public function backup_max_mb(): int;

	/**
	 * Whether uninstall should delete persistent data.
	 */
	public function delete_data_on_uninstall(): bool;
}
