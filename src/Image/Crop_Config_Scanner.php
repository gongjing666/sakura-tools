<?php
/**
 * Static scanner for WordPress image cropping configuration.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one de-duplicated view of every image size WordPress knows about.
 *
 * Sources, in the order this class documents them:
 * 1. WordPress core (options written by Settings → Media).
 * 2. The active theme (functions.php and every theme PHP file).
 * 3. Active plugins, alphabetically by plugin directory.
 * 4. This plugin's own disabled-size list, which is applied last.
 *
 * Precedence follows WordPress load order: core < theme < plugins, because a
 * later add_image_size() call overwrites an earlier one. Runtime registration
 * wins over static scanning; when the two disagree the row is flagged as a
 * conflict and the reason explains which value is effective.
 *
 * The scan is static: source files are tokenized, never executed. Calls with
 * dynamic size names cannot be resolved and are reported as warnings instead
 * of being guessed.
 */
final class Crop_Config_Scanner {

	/**
	 * Result schema version; stored results with another version are ignored.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Option holding the last scan result.
	 */
	public const OPTION_RESULT = 'sakura_imgopt_crop_scan';

	/**
	 * Option holding every size name the last scan found.
	 */
	public const OPTION_NAMES = 'sakura_imgopt_crop_scan_names';

	/**
	 * Maximum PHP files inspected per source (theme or one plugin).
	 */
	private const MAX_FILES = 1500;

	/**
	 * Maximum bytes read per PHP file.
	 */
	private const MAX_FILE_BYTES = 1048576;

	/**
	 * Wall-clock budget for the whole scan, in seconds.
	 */
	private const TIME_BUDGET = 15.0;

	/**
	 * Directories never scanned.
	 *
	 * @var array<int,string>
	 */
	private const EXCLUDE_DIRS = array( 'vendor', 'node_modules', 'languages', 'tests', '.git', '.github', 'cache' );

	/**
	 * Markers that make a file worth tokenizing.
	 *
	 * @var array<int,string>
	 */
	private const MARKERS = array( 'add_image_size', 'remove_image_size', 'intermediate_image_sizes', '_size_w', '_size_h' );

	/**
	 * Constants in wp-config.php that change image generation.
	 *
	 * @return array<string,string>
	 */
	private static function wp_config_constants(): array {
		return array(
		'IMAGE_EDIT_OVERWRITE' => __( '图像编辑（含手工裁剪/旋转）是否覆盖原图；true 时编辑结果直接替换原文件。', 'sakura-imgopt' ),
		'WP_MEMORY_LIMIT'      => __( 'PHP 内存上限；批量生成缩略图时受该值约束。', 'sakura-imgopt' ),
		'WP_MAX_MEMORY_LIMIT'  => __( '后台高内存上限；图片处理会优先抬升到该值。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Status labels shown in the admin table.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
		'enabled'            => __( '正常生成', 'sakura-imgopt' ),
		'disabled_by_plugin' => __( '已禁止', 'sakura-imgopt' ),
		'removed'            => __( '已被移除', 'sakura-imgopt' ),
		'filtered'           => __( '可能被过滤器移除', 'sakura-imgopt' ),
		'unregistered'       => __( '仅静态发现', 'sakura-imgopt' ),
		'unknown'            => __( '待核实', 'sakura-imgopt' ),
		);
	}

	/**
	 * Run a scan and persist it.
	 *
	 * @return array<string,mixed> Scan result.
	 */
	public function scan_and_store(): array {
		$result = $this->scan();

		update_option( self::OPTION_RESULT, $result, false );
		update_option( self::OPTION_NAMES, array_keys( $result['sizes'] ), false );

		return $result;
	}

	/**
	 * Last stored scan result, or null when absent/outdated.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stored(): ?array {
		$result = get_option( self::OPTION_RESULT, null );
		if ( ! is_array( $result ) || ! isset( $result['schema'] ) || self::SCHEMA_VERSION !== (int) $result['schema'] ) {
			return null;
		}
		if ( ! isset( $result['sizes'] ) || ! is_array( $result['sizes'] ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Size names found by the last scan, sanitized.
	 *
	 * @return array<int,string>
	 */
	public static function stored_names(): array {
		$names = get_option( self::OPTION_NAMES, array() );
		if ( ! is_array( $names ) ) {
			return array();
		}

		$clean = array();
		foreach ( $names as $name ) {
			$name = Size_Catalog::sanitize_name( (string) $name );
			if ( '' !== $name ) {
				$clean[] = $name;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Scan every configured source.
	 *
	 * @return array<string,mixed>
	 */
	public function scan(): array {
		$started  = microtime( true );
		$deadline = $started + self::TIME_BUDGET;
		$warnings = array();

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$warnings[] = array(
				'code'    => 'multisite_scope',
				'message' => __( '当前为多站点网络：本次仅扫描当前站点的 wp-config.php、主题与已启用插件；其他子站的独立裁剪配置不在本次判定范围。', 'sakura-imgopt' ),
				'source'  => __( '网络', 'sakura-imgopt' ),
			);
		}

		$wp_config = $this->scan_wp_config( $warnings );
		$theme     = $this->scan_theme( $warnings, $deadline );
		$plugins   = $this->scan_plugins( $warnings, $deadline );

		$disabled = ( new Settings_Repository() )->disabled_sizes();

		$resolved = self::resolve(
			Size_Catalog::core(),
			Size_Catalog::additional(),
			$theme,
			$plugins,
			$disabled,
			$warnings
		);

		$big_threshold_disabled = in_array( '_big_threshold', $disabled, true );

		$result = array(
			'schema'        => self::SCHEMA_VERSION,
			'generated_at'  => time(),
			'duration_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'wp_config'     => $wp_config,
			'theme'         => $theme,
			'plugins'       => $plugins,
			'big_threshold' => array(
				'disabled'     => $big_threshold_disabled,
				'label'        => __( '2560px 缩放版', 'sakura-imgopt' ),
				'source'       => __( 'WordPress 核心（big_image_size_threshold）', 'sakura-imgopt' ),
				'status'       => $big_threshold_disabled ? 'disabled_by_plugin' : 'enabled',
				'status_label' => $big_threshold_disabled ? self::status_labels()['disabled_by_plugin'] : self::status_labels()['enabled'],
				'reason'       => $big_threshold_disabled
					? __( '已在本插件「禁止 WordPress 裁剪」中勾选；上传超大图时不会生成 2560px 缩放版。', 'sakura-imgopt' )
					: __( '未在本插件中禁止；上传超大图时会按 big_image_size_threshold 生成 2560px 缩放版。', 'sakura-imgopt' ),
			),
			'sizes'         => $resolved['sizes'],
			'summary'       => $resolved['summary'],
			'warnings'      => $resolved['warnings'],
		);

		return $result;
	}

	/**
	 * Parse wp-config.php for image-related constants.
	 *
	 * @param array<int,array<string,string>> $warnings Warnings, by reference.
	 * @return array<string,mixed>
	 */
	private function scan_wp_config( array &$warnings ): array {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);

		$path = '';
		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				$path = $candidate;
				break;
			}
		}

		$constants = array();
		foreach ( self::wp_config_constants() as $name => $effect ) {
			$constants[ $name ] = array(
				'name'    => $name,
				'defined' => false,
				'value'   => null,
				'display' => __( '未定义', 'sakura-imgopt' ),
				'line'    => 0,
				'effect'  => $effect,
			);
		}

		if ( '' === $path ) {
			$warnings[] = array(
				'code'    => 'wp_config_unreadable',
				'message' => __( 'wp-config.php 不存在或不可读，跳过常量扫描；尺寸状态仍按 WordPress 默认值与运行时注册判定。', 'sakura-imgopt' ),
				'source'  => 'wp-config.php',
			);
			return array(
				'path'      => '',
				'readable'  => false,
				'constants' => array_values( $constants ),
			);
		}

		$source = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config read, no HTTP fallback wanted.
		if ( false === $source ) {
			$warnings[] = array(
				'code'    => 'wp_config_unreadable',
				'message' => __( 'wp-config.php 读取失败（权限不足或文件被锁定），已跳过常量扫描。', 'sakura-imgopt' ),
				'source'  => $this->relative_path( $path ),
			);
			return array(
				'path'      => $this->relative_path( $path ),
				'readable'  => false,
				'constants' => array_values( $constants ),
			);
		}

		$tokens = @token_get_all( $source );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}
			if ( 'define' !== strtolower( ltrim( $token[1], '\\' ) ) ) {
				continue;
			}

			$open = self::next_call_open( $tokens, $i );
			if ( -1 === $open ) {
				continue;
			}

			$parsed = self::read_call_args( $tokens, $open );
			$args   = $parsed[0];
			if ( count( $args ) < 2 ) {
				continue;
			}

			$name = self::arg_string( $args[0] );
			if ( null === $name || ! isset( $constants[ $name ] ) ) {
				continue;
			}

			$constants[ $name ]['defined'] = true;
			$constants[ $name ]['value']   = self::arg_scalar( $args[1] );
			$constants[ $name ]['display'] = self::display_value( $constants[ $name ]['value'] );
			$constants[ $name ]['line']    = (int) $token[2];
		}

		return array(
			'path'      => $this->relative_path( $path ),
			'readable'  => true,
			'constants' => array_values( $constants ),
		);
	}

	/**
	 * Scan the active theme.
	 *
	 * @param array<int,array<string,string>> $warnings Warnings, by reference.
	 * @param float                           $deadline Scan deadline.
	 * @return array<string,mixed>|null
	 */
	private function scan_theme( array &$warnings, float $deadline ): ?array {
		$dir = get_stylesheet_directory();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			$warnings[] = array(
				'code'    => 'theme_dir_missing',
				'message' => __( '无法定位当前主题目录，已跳过主题裁剪配置扫描。', 'sakura-imgopt' ),
				'source'  => __( '主题', 'sakura-imgopt' ),
			);
			return null;
		}

		$theme = wp_get_theme();
		$name  = is_object( $theme ) ? trim( (string) $theme->get( 'Name' ) ) : '';
		if ( '' === $name ) {
			$name = basename( $dir );
		}

		$slug = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : basename( $dir );
		$label = sprintf( /* translators: 1: theme name, 2: theme slug. */ __( '主题：%1$s（%2$s）', 'sakura-imgopt' ), $name, $slug );

		// Child themes load the parent theme's functions.php first and then the
		// child's; scan parent before child so the last static registration wins.
		$roots        = array();
		$template_dir = get_template_directory();
		if ( '' !== $template_dir && is_dir( $template_dir ) && realpath( $template_dir ) !== realpath( $dir ) ) {
			$roots[] = $template_dir;
		}
		$roots[] = $dir;

		$files = array();
		foreach ( $roots as $root ) {
			$root_files = self::collect_php_files( $root, self::MAX_FILES, $warnings, __( '主题', 'sakura-imgopt' ) );
			$root_files = self::functions_first( $root_files, $root );
			foreach ( $root_files as $root_file ) {
				$files[] = array(
					'file' => $root_file,
					'root' => $root,
				);
			}
		}

		$adds      = array();
		$removals  = array();
		$hooks     = array();
		$options   = array();
		$dynamic   = array();
		$scanned   = 0;
		$truncated = false;

		foreach ( $files as $entry ) {
			if ( microtime( true ) > $deadline ) {
				$truncated = true;
				break;
			}

			++$scanned;
			$parsed = $this->scan_php_file( $entry['file'], $entry['root'], $label, $warnings );
			if ( null === $parsed ) {
				continue;
			}

			$adds     = array_merge( $adds, $parsed['adds'] );
			$removals = array_merge( $removals, $parsed['removals'] );
			$hooks    = array_merge( $hooks, $parsed['hooks'] );
			$options  = array_merge( $options, $parsed['options'] );
			$dynamic  = array_merge( $dynamic, $parsed['dynamic'] );
		}

		if ( $truncated ) {
			$warnings[] = array(
				'code'    => 'scan_time_budget',
				'message' => __( '主题扫描超过时间预算，部分文件未检查；结果可能缺少主题注册的尺寸。', 'sakura-imgopt' ),
				'source'  => $label,
			);
		}

		return array(
			'type'          => 'theme',
			'label'         => $label,
			'slug'          => $slug,
			'dir'           => $this->relative_path( $dir ),
			'files_scanned' => $scanned,
			'adds'          => $adds,
			'removals'      => $removals,
			'hooks'         => $hooks,
			'options'       => $options,
			'dynamic'       => $dynamic,
		);
	}

	/**
	 * Scan active plugins.
	 *
	 * @param array<int,array<string,string>> $warnings Warnings, by reference.
	 * @param float                           $deadline Scan deadline.
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_plugins( array &$warnings, float $deadline ): array {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$active = array_values( array_unique( array_map( 'strval', $active ) ) );
		$self   = dirname( __DIR__, 2 );
		$out    = array();

		foreach ( $active as $plugin_rel ) {
			$main = WP_PLUGIN_DIR . '/' . $plugin_rel;
			$dir  = dirname( $main );

			if ( '' === $plugin_rel || ! is_dir( $dir ) ) {
				$warnings[] = array(
					'code'    => 'plugin_dir_missing',
					'message' => sprintf( /* translators: %s: plugin path. */ __( '已启用插件 %1$s 的目录不存在，已跳过。', 'sakura-imgopt' ), $plugin_rel ),
					'source'  => $plugin_rel,
				);
				continue;
			}

			$real_dir = realpath( $dir );
			if ( false !== $real_dir && realpath( $self ) === $real_dir ) {
				// This plugin documents sizes but never registers its own.
				continue;
			}

			if ( microtime( true ) > $deadline ) {
				$warnings[] = array(
					'code'    => 'scan_time_budget',
					'message' => __( '插件扫描超过时间预算，其余已启用插件未检查。', 'sakura-imgopt' ),
					'source'  => __( '插件', 'sakura-imgopt' ),
				);
				break;
			}

			$name  = $this->plugin_name( $main, $plugin_rel );
			$label = sprintf( /* translators: 1: plugin name, 2: plugin path. */ __( '插件：%1$s（%2$s）', 'sakura-imgopt' ), $name, $plugin_rel );

			if ( '.' === dirname( $plugin_rel ) ) {
				// Single-file plugin: only its own file can register sizes.
				$files = is_file( $main ) ? array( $main ) : array();
			} else {
				$files = self::collect_php_files( $dir, self::MAX_FILES, $warnings, $label );
				$files = self::main_file_first( $files, $main );
			}

			$adds      = array();
			$removals  = array();
			$hooks     = array();
			$options   = array();
			$dynamic   = array();
			$scanned   = 0;
			$matched   = 0;

			foreach ( $files as $file ) {
				if ( microtime( true ) > $deadline ) {
					break;
				}

				++$scanned;
				$parsed = $this->scan_php_file( $file, $dir, $label, $warnings );
				if ( null === $parsed ) {
					continue;
				}

				++$matched;
				$adds     = array_merge( $adds, $parsed['adds'] );
				$removals = array_merge( $removals, $parsed['removals'] );
				$hooks    = array_merge( $hooks, $parsed['hooks'] );
				$options  = array_merge( $options, $parsed['options'] );
				$dynamic  = array_merge( $dynamic, $parsed['dynamic'] );
			}

			if ( 0 === $matched ) {
				continue;
			}

			$out[] = array(
				'type'          => 'plugin',
				'label'         => $label,
				'name'          => $name,
				'file'          => $plugin_rel,
				'dir'           => $this->relative_path( $dir ),
				'files_scanned' => $scanned,
				'files_matched' => $matched,
				'adds'          => $adds,
				'removals'      => $removals,
				'hooks'         => $hooks,
				'options'       => $options,
				'dynamic'       => $dynamic,
			);
		}

		return $out;
	}

	/**
	 * Scan one PHP file and return only cropping-related findings.
	 *
	 * @param string                          $file     Absolute file path.
	 * @param string                          $root     Source root used for relative paths.
	 * @param string                          $label    Human readable source label.
	 * @param array<int,array<string,string>> $warnings Warnings, by reference.
	 * @return array<string,array<mixed>>|null
	 */
	private function scan_php_file( string $file, string $root, string $label, array &$warnings ): ?array {
		$size = @filesize( $file );
		if ( false === $size ) {
			return null;
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			$warnings[] = array(
				'code'    => 'file_skipped_too_large',
				'message' => sprintf( /* translators: 1: file path, 2: size in KB. */ __( '%1$s 超过 %2$d KB，已跳过扫描。', 'sakura-imgopt' ), $this->relative_path( $file, $root ), (int) ( self::MAX_FILE_BYTES / 1024 ) ),
				'source'  => $label,
			);
			return null;
		}

		$source = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source tokenization, never executed.
		if ( false === $source ) {
			$warnings[] = array(
				'code'    => 'file_unreadable',
				'message' => sprintf( /* translators: %s: file path. */ __( '%1$s 不可读，已跳过。', 'sakura-imgopt' ), $this->relative_path( $file, $root ) ),
				'source'  => $label,
			);
			return null;
		}

		$needed = false;
		foreach ( self::MARKERS as $marker ) {
			if ( false !== strpos( $source, $marker ) ) {
				$needed = true;
				break;
			}
		}
		if ( ! $needed ) {
			return null;
		}

		$parsed = self::parse_php_source( $source, $this->relative_path( $file, $root ) );
		foreach ( $parsed['dynamic'] as $hit ) {
			$warnings[] = array(
				'code'    => 'dynamic_size_name',
				'message' => sprintf( /* translators: 1: file, 2: line, 3: function. */ __( '%1$s 第 %2$d 行的 %3$s() 使用了动态尺寸名，静态扫描无法判定具体尺寸，需人工核实。', 'sakura-imgopt' ), $hit['file'], (int) $hit['line'], $hit['function'] ),
				'source'  => $label,
			);
		}

		return $parsed;
	}

	/**
	 * Tokenize a PHP source string for image-size calls.
	 *
	 * Pure function: no WordPress APIs, no source execution. Exposed for tests.
	 *
	 * @param string $source PHP source.
	 * @param string $file   Display path recorded on every finding.
	 * @return array<string,array<mixed>>
	 */
	public static function parse_php_source( string $source, string $file ): array {
		$result = array(
			'adds'     => array(),
			'removals' => array(),
			'hooks'    => array(),
			'options'  => array(),
			'dynamic'  => array(),
		);

		$tokens = @token_get_all( $source );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) ) {
				continue;
			}

			$function = strtolower( ltrim( $token[1], '\\' ) );
			if ( str_contains( $function, '\\' ) ) {
				continue;
			}
			if ( ! in_array( $function, array( 'add_image_size', 'remove_image_size', 'add_filter', 'update_option', 'update_site_option' ), true ) ) {
				continue;
			}

			$open = self::next_call_open( $tokens, $i );
			if ( -1 === $open ) {
				continue;
			}

			$parsed = self::read_call_args( $tokens, $open );
			$args   = $parsed[0];
			$line   = (int) $token[2];

			if ( 'add_image_size' === $function ) {
				$name = isset( $args[0] ) ? self::arg_string( $args[0] ) : null;
				if ( null === $name ) {
					$result['dynamic'][] = array(
						'function' => 'add_image_size',
						'file'     => $file,
						'line'     => $line,
					);
					continue;
				}

				$width  = isset( $args[1] ) ? self::arg_int( $args[1] ) : null;
				$height = isset( $args[2] ) ? self::arg_int( $args[2] ) : null;
				$crop   = isset( $args[3] ) ? self::arg_crop( $args[3] ) : false;

				$result['adds'][] = array(
					'name'   => $name,
					'width'  => null === $width ? 0 : $width,
					'height' => null === $height ? 0 : $height,
					'crop'   => $crop,
					'file'   => $file,
					'line'   => $line,
					'dynamic' => null === $width || null === $height,
				);
				continue;
			}

			if ( 'remove_image_size' === $function ) {
				$name = isset( $args[0] ) ? self::arg_string( $args[0] ) : null;
				if ( null === $name ) {
					$result['dynamic'][] = array(
						'function' => 'remove_image_size',
						'file'     => $file,
						'line'     => $line,
					);
					continue;
				}
				$result['removals'][] = array(
					'name' => $name,
					'file' => $file,
					'line' => $line,
				);
				continue;
			}

			if ( 'add_filter' === $function ) {
				$hook = isset( $args[0] ) ? self::arg_string( $args[0] ) : null;
				if ( null !== $hook && in_array( $hook, array( 'intermediate_image_sizes', 'intermediate_image_sizes_advanced' ), true ) ) {
					$result['hooks'][] = array(
						'hook' => $hook,
						'file' => $file,
						'line' => $line,
					);
				}
				continue;
			}

			// update_option()/update_site_option(): changes to core size settings.
			$option = isset( $args[0] ) ? self::arg_string( $args[0] ) : null;
			if ( null === $option || 1 !== preg_match( '/^(thumbnail|medium|medium_large|large)_(size_[wh]|crop)$/', $option ) ) {
				continue;
			}
			$result['options'][] = array(
				'option' => $option,
				'value'  => isset( $args[1] ) ? self::arg_scalar( $args[1] ) : null,
				'file'   => $file,
				'line'   => $line,
			);
		}

		return $result;
	}

	/**
	 * Merge all sources into one row per size.
	 *
	 * Pure function: all inputs are plain arrays. Exposed for tests.
	 *
	 * @param array<string,array{width:int,height:int,crop:bool,label:string,detail:string,source:string}> $core       Core sizes.
	 * @param array<string,array{width:int,height:int,crop:bool}>                                          $additional Runtime additional sizes.
	 * @param array<string,mixed>|null                                                                      $theme      Theme scan result.
	 * @param array<int,array<string,mixed>>                                                                 $plugins    Plugin scan results.
	 * @param array<int,string>                                                                              $disabled   Disabled size names.
	 * @param array<int,array<string,string>>                                                                $warnings   Existing warnings.
	 * @return array{sizes:array<string,array<string,mixed>>,summary:array<string,int>,warnings:array<int,array<string,string>>}
	 */
	public static function resolve( array $core, array $additional, ?array $theme, array $plugins, array $disabled, array $warnings = array() ): array {
		$contributors = array();
		$removals     = array();
		$hooks        = array();

		foreach ( $core as $name => $data ) {
			$contributors[ $name ]['adds'][] = array(
				'type'   => 'core',
				'label'  => __( 'WordPress 核心', 'sakura-imgopt' ),
				'detail' => $data['detail'],
				'width'  => $data['width'],
				'height' => $data['height'],
				'crop'   => $data['crop'],
				'file'   => 'wp_options',
				'line'   => 0,
			);
		}

		// Runtime-only sizes (filters, conditional add_image_size) still get a
		// row, otherwise a live size could be missing from the checklist.
		foreach ( $additional as $name => $unused ) {
			if ( ! isset( $contributors[ $name ] ) ) {
				$contributors[ $name ] = array( 'adds' => array() );
			}
		}

		$origins = array();
		if ( null !== $theme ) {
			$origins[] = $theme;
		}
		usort(
			$plugins,
			static function ( $a, $b ): int {
				return strcmp( (string) $a['label'], (string) $b['label'] );
			}
		);
		foreach ( $plugins as $plugin ) {
			$origins[] = $plugin;
		}

		foreach ( $origins as $origin ) {
			$type  = isset( $origin['type'] ) ? (string) $origin['type'] : 'plugin';
			$label = isset( $origin['label'] ) ? (string) $origin['label'] : '';

			$adds = isset( $origin['adds'] ) && is_array( $origin['adds'] ) ? $origin['adds'] : array();
			foreach ( $adds as $add ) {
				$name = Size_Catalog::sanitize_name( (string) ( $add['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$contributors[ $name ]['adds'][] = array(
					'type'   => $type,
					'label'  => $label,
					'detail' => sprintf( /* translators: 1: file, 2: line. */ __( '%1$s 第 %2$d 行 add_image_size()', 'sakura-imgopt' ), (string) ( $add['file'] ?? '' ), (int) ( $add['line'] ?? 0 ) ),
					'width'  => (int) ( $add['width'] ?? 0 ),
					'height' => (int) ( $add['height'] ?? 0 ),
					'crop'   => (bool) ( $add['crop'] ?? false ),
					'file'   => (string) ( $add['file'] ?? '' ),
					'line'   => (int) ( $add['line'] ?? 0 ),
				);
			}

			$removed = isset( $origin['removals'] ) && is_array( $origin['removals'] ) ? $origin['removals'] : array();
			foreach ( $removed as $remove ) {
				$name = Size_Catalog::sanitize_name( (string) ( $remove['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$removals[ $name ][] = array(
					'label' => $label,
					'file'  => (string) ( $remove['file'] ?? '' ),
					'line'  => (int) ( $remove['line'] ?? 0 ),
				);
			}

			$origin_hooks = isset( $origin['hooks'] ) && is_array( $origin['hooks'] ) ? $origin['hooks'] : array();
			foreach ( $origin_hooks as $hook ) {
				$hooks[] = array(
					'label' => $label,
					'hook'  => (string) ( $hook['hook'] ?? '' ),
					'file'  => (string) ( $hook['file'] ?? '' ),
					'line'  => (int) ( $hook['line'] ?? 0 ),
				);
			}
		}

		// Filters can add or remove sizes at runtime; static scanning cannot see
		// which names a callback touches. Surface that limit instead of guessing.
		if ( ! empty( $hooks ) ) {
			$hook_labels = array();
			foreach ( $hooks as $hook ) {
				if ( ! in_array( $hook['label'], $hook_labels, true ) ) {
					$hook_labels[] = $hook['label'];
				}
			}
			$warnings[] = array(
				'code'    => 'intermediate_image_sizes_filter',
				'message' => sprintf( /* translators: %s: hook labels. */ __( '%s 挂载了 intermediate_image_sizes 相关过滤器；静态扫描无法判定回调增删了哪些尺寸，相关行按「源码注册」与「运行时注册」对比标注。', 'sakura-imgopt' ), implode( '、', $hook_labels ) ),
				'source'  => __( '过滤器', 'sakura-imgopt' ),
			);
		}

		// Runtime registration: core options plus add_image_size() calls.
		$registered = array();
		foreach ( $core as $name => $data ) {
			$registered[ $name ] = array(
				'width'  => (int) $data['width'],
				'height' => (int) $data['height'],
				'crop'   => (bool) $data['crop'],
			);
		}
		foreach ( $additional as $name => $data ) {
			$registered[ $name ] = array(
				'width'  => (int) $data['width'],
				'height' => (int) $data['height'],
				'crop'   => (bool) $data['crop'],
			);
		}

		$core_order = array_keys( $core );
		$names      = array_keys( $contributors );
		usort(
			$names,
			static function ( string $a, string $b ) use ( $core_order ): int {
				$ai = array_search( $a, $core_order, true );
				$bi = array_search( $b, $core_order, true );
				$ai = false === $ai ? PHP_INT_MAX : $ai;
				$bi = false === $bi ? PHP_INT_MAX : $bi;
				return $ai === $bi ? strcmp( $a, $b ) : $ai <=> $bi;
			}
		);

		$sizes   = array();
		$summary = array(
			'total'       => 0,
			'enabled'     => 0,
			'disabled'    => 0,
			'removed'     => 0,
			'unregistered' => 0,
			'filtered'    => 0,
			'unknown'     => 0,
			'conflicts'   => 0,
		);

		foreach ( $names as $name ) {
			$adds = $contributors[ $name ]['adds'];
			$rows = array();
			foreach ( $adds as $add ) {
				$key = implode( '|', array( $add['type'], $add['label'], $add['width'], $add['height'], $add['crop'] ? '1' : '0', $add['file'], $add['line'] ) );
				$rows[ $key ] = array(
					'type'   => $add['type'],
					'label'  => $add['label'],
					'detail' => $add['detail'],
					'file'   => $add['file'],
					'line'   => $add['line'],
				);
			}

			$last_add = null;
			foreach ( $adds as $add ) {
				$last_add = $add;
			}

			$registered_size = $registered[ $name ] ?? null;

			// Dimensions: runtime wins; otherwise the last static registration.
			if ( null !== $registered_size ) {
				$width  = (int) $registered_size['width'];
				$height = (int) $registered_size['height'];
				$crop   = (bool) $registered_size['crop'];
				$runtime_hit = true;
			} elseif ( null !== $last_add ) {
				$width  = (int) $last_add['width'];
				$height = (int) $last_add['height'];
				$crop   = (bool) $last_add['crop'];
				$runtime_hit = false;
			} else {
				$width  = 0;
				$height = 0;
				$crop   = false;
				$runtime_hit = false;
			}

			// Conflict: static sources disagree, or runtime differs from every static add.
			$shapes = array();
			foreach ( $adds as $add ) {
				if ( (int) $add['width'] <= 0 && (int) $add['height'] <= 0 ) {
					continue;
				}
				$shapes[ (int) $add['width'] . 'x' . (int) $add['height'] . ( $add['crop'] ? 'c' : '' ) ] = true;
			}
			$conflict = count( $shapes ) > 1;

			if ( null !== $registered_size && null !== $last_add && ! $conflict ) {
				if ( (int) $last_add['width'] !== (int) $registered_size['width'] || (int) $last_add['height'] !== (int) $registered_size['height'] ) {
					$conflict = true;
				}
			}

			$removed_by = array();
			if ( isset( $removals[ $name ] ) ) {
				$seen = array();
				foreach ( $removals[ $name ] as $item ) {
					if ( isset( $seen[ $item['label'] ] ) ) {
						continue;
					}
					$seen[ $item['label'] ] = true;
					$removed_by[]           = $item['label'];
				}
			}

			$hook_sources = array();
			foreach ( $hooks as $hook ) {
				if ( ! in_array( $hook['label'], $hook_sources, true ) ) {
					$hook_sources[] = $hook['label'];
				}
			}

			if ( in_array( $name, $disabled, true ) ) {
				$status = 'disabled_by_plugin';
				$reason = __( '已在本插件「禁止 WordPress 裁剪」中勾选，WordPress 不会生成该尺寸。', 'sakura-imgopt' );
			} elseif ( $runtime_hit ) {
				$status = 'enabled';
				$reason = empty( $adds )
					? __( '当前请求已注册该尺寸（可能由过滤器或运行时逻辑注册），源码中未找到静态注册调用。', 'sakura-imgopt' )
					: __( '当前请求已注册该尺寸，WordPress 会按上述宽高生成。', 'sakura-imgopt' );
				if ( ! empty( $removed_by ) ) {
					$reason .= __( ' 源码中存在移除调用，但运行时仍注册，以运行时注册为准。', 'sakura-imgopt' );
				}
				if ( $conflict ) {
					$reason .= __( ' 多个来源以不同宽高注册，运行时值已覆盖静态顺序（核心 < 主题 < 插件）。', 'sakura-imgopt' );
				}
			} elseif ( ! empty( $removed_by ) ) {
				$status = 'removed';
				$reason = sprintf( /* translators: %s: source names. */ __( '运行中未注册，源码 %1$s 调用 remove_image_size() 移除了该尺寸。', 'sakura-imgopt' ), implode( '、', $removed_by ) );
			} elseif ( ! empty( $adds ) && ! empty( $hook_sources ) ) {
				$status = 'filtered';
				$reason = sprintf( /* translators: %s: hook sources. */ __( '源码中存在 add_image_size() 调用，但运行中未注册，且 %s 挂载了 intermediate_image_sizes 过滤器；可能被过滤器按名称移除，需人工核实。', 'sakura-imgopt' ), implode( '、', $hook_sources ) );
			} elseif ( ! empty( $adds ) ) {
				$status = 'unregistered';
				$reason = __( '仅在源码中找到注册调用，当前后台请求未注册（可能条件加载或调用时机不同），当前不会生成。', 'sakura-imgopt' );
			} else {
				$status = 'unknown';
				$reason = __( '运行中未注册，也未在源码中定位到注册或移除调用。', 'sakura-imgopt' );
			}

			if ( $conflict ) {
				++$summary['conflicts'];
			}
			++$summary['total'];
			if ( 'disabled_by_plugin' === $status ) {
				++$summary['disabled'];
			} elseif ( 'enabled' === $status ) {
				++$summary['enabled'];
			} else {
				++$summary[ $status ];
			}

			$sizes[ $name ] = array(
				'name'         => $name,
				'width'        => $width,
				'height'       => $height,
				'crop'         => $crop,
				'registered'   => $runtime_hit,
				'sources'      => array_values( $rows ),
				'removed_by'   => $removed_by,
				'filter_hooks' => $hook_sources,
				'conflict'     => $conflict,
				'status'       => $status,
				'status_label' => self::status_labels()[ $status ],
				'reason'       => $reason,
			);
		}

		// Sizes disabled in this plugin can outlive the theme/plugin that
		// registered them; keep them visible so the admin can uncheck them.
		foreach ( $disabled as $disabled_name ) {
			$disabled_name = Size_Catalog::sanitize_name( (string) $disabled_name );
			// The big-image threshold is reported separately; counting it as a
			// thumbnail size would inflate the summary and duplicate the row.
			if ( '' === $disabled_name || '_big_threshold' === $disabled_name || isset( $sizes[ $disabled_name ] ) ) {
				continue;
			}
			++$summary['total'];
			++$summary['disabled'];
			$sizes[ $disabled_name ] = array(
				'name'         => $disabled_name,
				'width'        => 0,
				'height'       => 0,
				'crop'         => false,
				'registered'   => false,
				'sources'      => array(),
				'removed_by'   => array(),
				'filter_hooks' => array(),
				'conflict'     => false,
				'status'       => 'disabled_by_plugin',
				'status_label' => self::status_labels()['disabled_by_plugin'],
				'reason'       => __( '该名称仍在本插件「禁止 WordPress 裁剪」中勾选，但当前扫描未发现任何注册来源，可安全取消勾选。', 'sakura-imgopt' ),
			);
		}

		return array(
			'sizes'    => $sizes,
			'summary'  => $summary,
			'warnings' => self::dedupe_warnings( $warnings ),
		);
	}

	/**
	 * Remove duplicate warnings while preserving first occurrence order.
	 *
	 * @param array<int,array<string,string>> $warnings Warnings.
	 * @return array<int,array<string,string>>
	 */
	private static function dedupe_warnings( array $warnings ): array {
		$seen = array();
		$out  = array();
		foreach ( $warnings as $warning ) {
			$key = ( $warning['code'] ?? '' ) . '|' . ( $warning['source'] ?? '' ) . '|' . ( $warning['message'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $warning;
		}
		return $out;
	}

	/**
	 * Find the next opening parenthesis of a function call.
	 *
	 * @param array<int,mixed> $tokens Tokens.
	 * @param int              $index  Token index of the function name.
	 * @return int Token index of "(", or -1.
	 */
	private static function next_call_open( array $tokens, int $index ): int {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( '(' === $token ) {
				return $i;
			}
			return -1;
		}
		return -1;
	}

	/**
	 * Read call arguments between a "(" and its matching ")".
	 *
	 * @param array<int,mixed> $tokens Tokens.
	 * @param int              $open   Index of "(".
	 * @return array{0:array<int,array<int,mixed>>,1:int} Args and closing index.
	 */
	private static function read_call_args( array $tokens, int $open ): array {
		$args    = array();
		$current = array();
		$depth   = 0;
		$count   = count( $tokens );

		for ( $i = $open + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_string( $token ) ) {
				if ( '(' === $token || '[' === $token || '{' === $token ) {
					++$depth;
					$current[] = $token;
					continue;
				}
				if ( ')' === $token ) {
					if ( 0 === $depth ) {
						$args[] = $current;
						return array( $args, $i );
					}
					--$depth;
					$current[] = $token;
					continue;
				}
				if ( ']' === $token || '}' === $token ) {
					$depth = max( 0, $depth - 1 );
					$current[] = $token;
					continue;
				}
				if ( ',' === $token && 0 === $depth ) {
					$args[]  = $current;
					$current = array();
					continue;
				}
				$current[] = $token;
				continue;
			}

			if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$current[] = $token;
		}

		$args[] = $current;
		return array( $args, $count - 1 );
	}

	/**
	 * Decode a string-literal argument.
	 *
	 * @param array<int,mixed> $tokens Argument tokens.
	 * @return string|null
	 */
	private static function arg_string( array $tokens ): ?string {
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				return null;
			}
			$raw = $token[1];
			if ( strlen( $raw ) < 2 ) {
				return null;
			}
			$inner = substr( $raw, 1, -1 );
			return stripcslashes( $inner );
		}
		return null;
	}

	/**
	 * Decode an integer-literal argument.
	 *
	 * @param array<int,mixed> $tokens Argument tokens.
	 * @return int|null
	 */
	private static function arg_int( array $tokens ): ?int {
		$negative = false;
		foreach ( $tokens as $token ) {
			if ( is_string( $token ) ) {
				if ( '-' === $token ) {
					$negative = true;
				}
				continue;
			}
			if ( T_LNUMBER === $token[0] ) {
				$value = (int) $token[1];
				return $negative ? -$value : $value;
			}
			if ( '-' === $token[0] ) {
				$negative = true;
			}
		}
		return null;
	}

	/**
	 * Decode a scalar argument (string, int, bool) for option values.
	 *
	 * @param array<int,mixed> $tokens Argument tokens.
	 * @return string|int|bool|null
	 */
	private static function arg_scalar( array $tokens ) {
		$negative = false;
		foreach ( $tokens as $token ) {
			if ( is_string( $token ) ) {
				if ( '-' === $token ) {
					$negative = true;
				}
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				return stripcslashes( substr( $token[1], 1, -1 ) );
			}
			if ( T_LNUMBER === $token[0] ) {
				$value = (int) $token[1];
				return $negative ? -$value : $value;
			}
			if ( T_STRING === $token[0] ) {
				$lower = strtolower( $token[1] );
				if ( 'true' === $lower ) {
					return true;
				}
				if ( 'false' === $lower ) {
					return false;
				}
			}
		}
		return null;
	}

	/**
	 * Human readable scalar for the admin UI.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display_value( $value ): string {
		if ( null === $value ) {
			return __( '（表达式，未求值）', 'sakura-imgopt' );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
	}

	/**
	 * Decode a crop argument: bool or array( 'crop' => true ).
	 *
	 * @param array<int,mixed> $tokens Argument tokens.
	 * @return bool
	 */
	private static function arg_crop( array $tokens ): bool {
		$has_array = false;
		$bool      = false;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_ARRAY === $token[0] ) {
				$has_array = true;
				continue;
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( 'true' === strtolower( $token[1] ) ) {
				$bool = true;
			}
		}

		if ( ! $has_array ) {
			return $bool;
		}

		// array( 'crop' => true ) / array( 'crop' => false, ... ).
		$crop_key_seen = false;
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$value = stripcslashes( substr( $token[1], 1, -1 ) );
				if ( 'crop' === $value ) {
					$crop_key_seen = true;
				}
				continue;
			}
			if ( $crop_key_seen && is_array( $token ) && T_STRING === $token[0] ) {
				$lower = strtolower( $token[1] );
				if ( 'true' === $lower ) {
					return true;
				}
				if ( 'false' === $lower ) {
					return false;
				}
			}
		}

		// Non-empty crop array without an explicit flag behaves as crop (core),
		// e.g. array( 'center', 'center' ); an empty array() means no crop.
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_ARRAY, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				return true;
			}
			if ( '(' === $token || ')' === $token ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Collect PHP files below a directory, following no symlinks.
	 *
	 * @param string                          $root     Root directory.
	 * @param int                             $max      Maximum files.
	 * @param array<int,array<string,string>> $warnings Warnings, by reference.
	 * @param string                          $label    Source label for warnings.
	 * @return array<int,string>
	 */
	private static function collect_php_files( string $root, int $max, array &$warnings, string $label ): array {
		$files = array();
		$stack = array( $root );

		while ( ! empty( $stack ) ) {
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Local directory listing.
			if ( false === $entries ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_dir( $path ) ) {
					if ( is_link( $path ) ) {
						continue;
					}
					if ( in_array( strtolower( $entry ), self::EXCLUDE_DIRS, true ) ) {
						continue;
					}
					$stack[] = $path;
					continue;
				}
				if ( '.php' !== strtolower( substr( $entry, -4 ) ) ) {
					continue;
				}
				$files[] = $path;
				if ( count( $files ) >= $max ) {
					$warnings[] = array(
						'code'    => 'file_cap_reached',
						'message' => sprintf( /* translators: 1: source label, 2: max files. */ __( '%1$s 的 PHP 文件超过 %2$d 个，仅扫描前 %2$d 个。', 'sakura-imgopt' ), $label, $max ),
						'source'  => $label,
					);
					return $files;
				}
			}
		}

		return $files;
	}

	/**
	 * Put functions.php first, then sort the rest.
	 *
	 * @param array<int,string> $files Files.
	 * @param string            $root  Theme root.
	 * @return array<int,string>
	 */
	private static function functions_first( array $files, string $root ): array {
		$functions = rtrim( $root, '/\\' ) . '/functions.php';
		usort(
			$files,
			static function ( string $a, string $b ) use ( $functions ): int {
				if ( $a === $functions ) {
					return -1;
				}
				if ( $b === $functions ) {
					return 1;
				}
				return strcmp( $a, $b );
			}
		);
		return $files;
	}

	/**
	 * Put the plugin main file first, then sort the rest.
	 *
	 * @param array<int,string> $files Files.
	 * @param string            $main  Main plugin file.
	 * @return array<int,string>
	 */
	private static function main_file_first( array $files, string $main ): array {
		usort(
			$files,
			static function ( string $a, string $b ) use ( $main ): int {
				if ( $a === $main ) {
					return -1;
				}
				if ( $b === $main ) {
					return 1;
				}
				return strcmp( $a, $b );
			}
		);
		return $files;
	}

	/**
	 * Read the plugin header name, falling back to the directory name.
	 *
	 * @param string $main      Absolute main file.
	 * @param string $plugin_rel Plugin path relative to the plugins dir.
	 * @return string
	 */
	private function plugin_name( string $main, string $plugin_rel ): string {
		$head = @file_get_contents( $main, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local header read.
		if ( is_string( $head ) && preg_match( '/^[\s\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $matches ) ) {
			$name = trim( $matches[1] );
			if ( '' !== $name ) {
				return $name;
			}
		}
		$fallback = basename( $plugin_rel, '.php' );
		return '' === $fallback ? $plugin_rel : $fallback;
	}

	/**
	 * Path relative to ABSPATH, or the absolute path when outside it.
	 *
	 * @param string      $path Absolute path.
	 * @param string|null $root Optional root to relativize against.
	 * @return string
	 */
	private function relative_path( string $path, ?string $root = null ): string {
		$base = null === $root ? ABSPATH : $root;
		$base = rtrim( $base, '/\\' );
		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) + 1 );
		}
		return $path;
	}
}
