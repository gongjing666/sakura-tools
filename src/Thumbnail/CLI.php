<?php
/**
 * WP-CLI commands for the thumbnail engine.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * `wp sakura-thumb ...`
 */
final class CLI {

	/**
	 * Probe the provider and store the result.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Ignore the one hour cache.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function probe( array $args, array $assoc_args ): void {
		$force  = ! empty( $assoc_args['force'] );
		$status = ( new Probe() )->run( $force );

		\WP_CLI::line( __( '模式：', 'sakura-imgopt' ) . $status->label() );
		\WP_CLI::line( __( '证据：', 'sakura-imgopt' ) . $status->evidence );
		if ( '' !== $status->sample_url ) {
			\WP_CLI::line( __( '样本：', 'sakura-imgopt' ) . $status->sample_url );
		}
		\WP_CLI::line( __( '有效模式：', 'sakura-imgopt' ) . Settings::effective_mode() );

		if ( $status->is_ok() ) {
			\WP_CLI::success( __( '探测完成。', 'sakura-imgopt' ) );
			return;
		}

		\WP_CLI::warning( __( '探测未成功，URL 引擎不会改写图片。', 'sakura-imgopt' ) );
	}

	/**
	 * Print the current configuration.
	 */
	public function status(): void {
		$opt    = Settings::get();
		$status = Settings::probe_status();
		$mode   = Settings::effective_mode();
		$stats  = Stats::summary();

		\WP_CLI::line( 'enabled         : ' . ( empty( $opt['enabled'] ) ? 'no' : 'yes' ) );
		\WP_CLI::line( 'engine          : ' . (string) $opt['engine'] );
		\WP_CLI::line( 'provider        : ' . ( '' === Settings::provider_id() ? __( '(未配置)', 'sakura-imgopt' ) : Settings::provider_id() ) );
		\WP_CLI::line( 'media host      : ' . ( '' === Settings::media_host() ? __( '(空)', 'sakura-imgopt' ) : Settings::media_host() ) );
		\WP_CLI::line( 'configured mode : ' . (string) $opt['provider_mode'] );
		\WP_CLI::line( 'effective mode  : ' . $mode );
		$cache_backend = Settings::object_cache_backend();
		\WP_CLI::line( 'object cache    : ' . $cache_backend['label'] . ( $cache_backend['group_flush'] ? ' (group flush)' : ' (no group flush)' ) );
		\WP_CLI::line( 'probe           : ' . $status->label() );
		\WP_CLI::line( 'probe evidence  : ' . $status->evidence );
		if ( $status->checked_at > 0 ) {
			$checked = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $status->checked_at ) : gmdate( 'Y-m-d H:i:s', $status->checked_at );
			\WP_CLI::line( 'probe checked   : ' . $checked );
		}
		\WP_CLI::line( 'scopes          : archive=' . (int) $opt['scopes']['archive'] . ' sidebar=' . (int) $opt['scopes']['sidebar'] . ' singular=' . (int) $opt['scopes']['singular'] . ' other=' . (int) $opt['scopes']['other'] );
		\WP_CLI::line( 'stats           : rewrite=' . $stats['rewrite'] . ' skip=' . $stats['skip'] . ' days=' . $stats['days'] );

		foreach ( $opt['sizes'] as $name => $row ) {
			\WP_CLI::line( sprintf( 'size %-13s: %dx%d %s style=%s', $name, (int) $row['width'], (int) $row['height'], (string) $row['mode'], (string) ( $opt['style_map'][ $name ] ?? '' ) ) );
		}
	}

	/**
	 * Switch the rendering engine.
	 *
	 * ## OPTIONS
	 *
	 * <engine>
	 * : url|html|off
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function engine( array $args, array $assoc_args ): void {
		$engine = strtolower( (string) ( $args[0] ?? '' ) );
		if ( ! in_array( $engine, array( 'url', 'html', 'off' ), true ) ) {
			\WP_CLI::error( __( 'engine 必须是 url、html 或 off。', 'sakura-imgopt' ) );
		}

		$opt           = Settings::get();
		$opt['engine'] = $engine;
		update_option( Settings::OPTION, Settings::normalize( $opt ) );
		Settings::reset();
		Decorator::reset();
		Settings::flush_caches();

		/* translators: %s: image engine name. */
		\WP_CLI::success( sprintf( __( 'engine 已切换为 %s。', 'sakura-imgopt' ), $engine ) );
	}

	/**
	 * Enable or disable the thumbnail engine.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on|off
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function toggle( array $args, array $assoc_args ): void {
		$state = strtolower( (string) ( $args[0] ?? '' ) );
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			\WP_CLI::error( __( 'state 必须是 on 或 off。', 'sakura-imgopt' ) );
		}

		$opt            = Settings::get();
		$opt['enabled'] = 'on' === $state ? 1 : 0;
		update_option( Settings::OPTION, Settings::normalize( $opt ) );
		Settings::reset();
		Decorator::reset();
		Settings::flush_caches();

		$label = 'on' === $state ? __( '启用', 'sakura-imgopt' ) : __( '停用', 'sakura-imgopt' );
		/* translators: %s: on/off label. */
		\WP_CLI::success( sprintf( __( '缩略图优化已%s。', 'sakura-imgopt' ), $label ) );
	}

	/**
	 * Clear stored statistics.
	 */
	public function reset_stats(): void {
		update_option( Settings::STATS_OPTION, array(), false );
		\WP_CLI::success( __( '统计已清空。', 'sakura-imgopt' ) );
	}
}
