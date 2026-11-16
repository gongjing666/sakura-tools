<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 把存储桶标识变成人看的那一行。
 *
 * 多吉云控制台里叫「存储空间名称」（nboke、demo），而插件真正要填的是 SDK
 * 参数里的 s3Bucket（doss-v2-328e5d1039000fe8-1258813047）。两串东西毫无
 * 相似之处，直接铺在状态卡和配置卡上既难读、又分不清同一账号下的两个空间。
 * 所以展示层优先用空间名，拿不到空间名时把长串缩成「前缀…后缀」，
 * 完整值始终留在 title 里。
 *
 * 腾讯云 COS 的桶名是「自定义名-APPID」（sakura-clover-1301234331），尾巴
 * 那 10 位数字是腾讯按账号发的、同一个账号下所有桶都一样，跟「这个桶是哪
 * 一个」毫无关系。留着它只会把真正有用的自定义名挤成 sakura-c…4331，所以
 * 先按服务商剥掉这段尾巴，再套用通用缩写。
 */
final class Bucket_Label {

	/**
	 * 缩写阈值：短于这个长度的桶名原样显示。
	 */
	private const SHORTEN_ABOVE = 22;

	/**
	 * 厂商自动追加、同一个账号下所有桶都相同的尾巴。
	 *
	 * 只收确认过格式的厂商：剥错会把本该不同的桶显示成同一个名字。
	 *
	 * @var array<string,string>
	 */
	private const AUTO_SUFFIX = array(
		// 腾讯云 COS：桶名 = 自定义名 + '-' + 10 位 APPID。
		'tencent' => '/-\d{10}$/',
	);

	/**
	 * 去掉厂商自动追加的尾巴，保留自定义部分。
	 *
	 * 配置名、提示这类地方有整行宽度，不需要缩成「前缀…后缀」，但同样不该把
	 * 账号级 APPID 写进去：同一个账号下的每个桶都带着它。
	 *
	 * @param string $bucket   Bucket name.
	 * @param string $provider Provider key, when the caller knows it.
	 */
	public static function strip( string $bucket, string $provider = '' ): string {
		$bucket = trim( $bucket );

		if ( '' === $provider || ! isset( self::AUTO_SUFFIX[ $provider ] ) ) {
			return $bucket;
		}

		$stripped = (string) preg_replace( self::AUTO_SUFFIX[ $provider ], '', $bucket );

		// 整串就是尾巴时保留原值：留个空名字比留着完整的原值更糟。
		return '' !== $stripped ? $stripped : $bucket;
	}

	/**
	 * 缩成一个还能认出首尾的短串。
	 *
	 * @param string $bucket   Bucket name.
	 * @param string $provider Provider key, when the caller knows it.
	 */
	public static function short( string $bucket, string $provider = '' ): string {
		$bucket = self::strip( $bucket, $provider );

		if ( strlen( $bucket ) <= self::SHORTEN_ABOVE ) {
			return $bucket;
		}

		return substr( $bucket, 0, 8 ) . '…' . substr( $bucket, -4 );
	}

	/**
	 * 展示用的一行：有空间名用空间名，否则用缩写。
	 *
	 * @param string $bucket   Bucket name.
	 * @param string $space    Storage space name, empty when the provider has none.
	 * @param string $provider Provider key, when the caller knows it.
	 */
	public static function display( string $bucket, string $space = '', string $provider = '' ): string {
		$space = trim( $space );
		if ( '' !== $space ) {
			return $space;
		}

		return self::short( $bucket, $provider );
	}
}
