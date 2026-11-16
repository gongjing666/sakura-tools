<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Supported S3-compatible providers. */
final class Providers {

	/**
	 * Provider key => label.
	 *
	 * @note 校验一律走 `Providers::exists()`（只比较标识、不生成标签），
	 *       因此这里的 `__()` 只在后台页面渲染时执行，不会在 `plugins_loaded`
	 *       阶段触发文本域加载（避免 WP 6.7+ 的 `_load_textdomain_just_in_time`）。
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return array(
			'cloudflare' => 'Cloudflare R2',
			'minio'      => 'MinIO',
			'dogecloud'  => __( '多吉云', 'sakura-imgopt' ),
			'aliyun'     => __( '阿里云 OSS', 'sakura-imgopt' ),
			'tencent'    => __( '腾讯云 COS', 'sakura-imgopt' ),
			'qiniu'      => __( '七牛云 KODO', 'sakura-imgopt' ),
			'upyun'      => __( '又拍云 USS', 'sakura-imgopt' ),
			'baidu'      => __( '百度云 BOS', 'sakura-imgopt' ),
			'backblaze'  => 'Backblaze B2',
			'bitiful'    => __( '缤纷云', 'sakura-imgopt' ),
		);
	}

	/**
	 * 目前开放新建配置的 provider key => label。
	 *
	 * 七牛、百度、又拍的 S3 兼容面尚未验证，先不放进新建下拉；已保存的档案
	 * 与 exists() 校验仍保留，切换到旧档案不会因为这里收窄而失效。
	 *
	 * @return array<string,string>
	 */
	public static function selectable(): array {
		$all        = self::all();
		$selectable = array();
		foreach ( array( 'cloudflare', 'minio', 'dogecloud', 'aliyun', 'tencent', 'backblaze', 'bitiful' ) as $provider ) {
			if ( isset( $all[ $provider ] ) ) {
				$selectable[ $provider ] = $all[ $provider ];
			}
		}
		return $selectable;
	}

	/**
	 * 暂时从新建列表中隐藏的 provider。
	 *
	 * @return string[]
	 */
	public static function hidden(): array {
		return array( 'qiniu', 'baidu', 'upyun' );
	}

	/**
	 * 判断 provider 是否受支持。
	 *
	 * 只比较标识，不生成标签，因此不会触发翻译加载；可在 `plugins_loaded`
	 * 阶段安全调用（`Options::is_configured()` 走的就是这里）。
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function exists( string $provider ): bool {
		return in_array(
			$provider,
			array( 'cloudflare', 'minio', 'dogecloud', 'aliyun', 'tencent', 'qiniu', 'upyun', 'baidu', 'backblaze', 'bitiful' ),
			true
		);
	}
}
