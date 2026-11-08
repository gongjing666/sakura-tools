<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 缩略图控制模块
 *
 * 功能：
 * 1. 按尺寸粒度禁止 WordPress 裁剪（用户自选哪些尺寸不需要）
 * 2. 可选禁止 2560px 缩放版（big_image_size_threshold）
 * 3. 拦截 wp_generate_attachment_metadata，移除被禁的子尺寸
 */
class Thumbnail_Manager implements Module {

	/**
	 * Register bootstrap hooks with the central loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'init', array( $this, 'apply_size_filters' ) );
	}

	/**
	 * 检查开关状态，挂载过滤钩子
	 */
	public function apply_size_filters(): void {
		$disabled = $this->get_disabled_sizes();

		if ( empty( $disabled ) ) {
			return;
		}

		// 1. 过滤 intermediate_image_sizes：只移除用户选中的尺寸
		add_filter( 'intermediate_image_sizes', array( $this, 'filter_sizes' ), PHP_INT_MAX );

		// 2. big_image_size_threshold：独立控制
		if ( in_array( '_big_threshold', $disabled, true ) ) {
			add_filter( 'big_image_size_threshold', '__return_false', PHP_INT_MAX );
		}

		// 3. 拦截元数据生成，移除被禁的子尺寸记录
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'strip_disabled_sizes' ), PHP_INT_MAX, 2 );

		// 4. 兜底：如果禁用了所有尺寸，彻底阻止生成
		static $all_sizes_cache = null;
		if ( null === $all_sizes_cache ) {
			$all_sizes_cache = get_intermediate_image_sizes();
		}
		if ( count( array_intersect( $all_sizes_cache, $disabled ) ) >= count( $all_sizes_cache ) ) {
			add_filter( 'wp_image_make_intermediate_size', array( $this, 'block_intermediate' ), PHP_INT_MAX );
		}
	}

	/**
	 * 获取用户禁用尺寸列表
	 *
	 * @return array 被禁用的尺寸名数组
	 */
	public static function get_disabled_sizes() {
		return ( new Settings_Repository() )->disabled_sizes();
	}

	/**
	 * 过滤中间尺寸列表，移除被禁用的
	 *
	 * @param string[] $sizes
	 * @return string[]
	 */
	public function filter_sizes( $sizes ) {
		$disabled = self::get_disabled_sizes();
		if ( empty( $disabled ) ) {
			return $sizes;
		}
		return array_values( array_diff( $sizes, $disabled ) );
	}

	/**
	 * 从元数据中移除被禁用的子尺寸记录
	 *
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array
	 */
	public function strip_disabled_sizes( $metadata, $attachment_id ) {
		unset( $attachment_id );
		$disabled = self::get_disabled_sizes();
		if ( ! empty( $disabled ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $disabled as $size_name ) {
				unset( $metadata['sizes'][ $size_name ] );
			}
		}
		return $metadata;
	}

	/**
	 * 兜底拦截：禁止中间尺寸文件生成
	 *
	 * @param mixed $result
	 * @return false
	 */
	public function block_intermediate( $result ) {
		unset( $result );
		return false;
	}
}
