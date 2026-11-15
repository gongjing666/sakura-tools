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
 */
final class Bucket_Label {

	/**
	 * 缩写阈值：短于这个长度的桶名原样显示。
	 */
	private const SHORTEN_ABOVE = 22;

	/**
	 * 缩成一个还能认出首尾的短串。
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function short( string $bucket ): string {
		$bucket = trim( $bucket );
		if ( strlen( $bucket ) <= self::SHORTEN_ABOVE ) {
			return $bucket;
		}

		return substr( $bucket, 0, 8 ) . '…' . substr( $bucket, -4 );
	}

	/**
	 * 展示用的一行：有空间名用空间名，否则用缩写。
	 *
	 * @param string $bucket Bucket name.
	 * @param string $space  Storage space name, empty when the provider has none.
	 */
	public static function display( string $bucket, string $space = '' ): string {
		$space = trim( $space );
		if ( '' !== $space ) {
			return $space;
		}

		return self::short( $bucket );
	}
}
