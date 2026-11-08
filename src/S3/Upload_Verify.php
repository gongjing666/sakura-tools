<?php
/**
 * Upload integrity checks.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for comparing a local file with the object S3 reports.
 *
 * Kept separate from Object_Store so the comparison rules are unit-testable
 * without an S3 client.
 */
final class Upload_Verify {

	/**
	 * Normalize an S3 ETag (quotes, case, whitespace).
	 *
	 * @param string $etag Raw ETag.
	 */
	public static function normalize_etag( string $etag ): string {
		return strtolower( trim( $etag, " \t\n\r\0\x0B\"" ) );
	}

	/**
	 * Multipart ETag: MD5 of the concatenated binary part MD5s, `-N` suffix.
	 *
	 * @param array<int,string> $part_md5s Lowercase hex MD5 per part.
	 */
	public static function multipart_etag( array $part_md5s ): string {
		$binary = '';
		foreach ( $part_md5s as $md5 ) {
			$md5 = strtolower( (string) $md5 );
			if ( 32 !== strlen( $md5 ) || ! ctype_xdigit( $md5 ) ) {
				return '';
			}
			$raw = hex2bin( $md5 );
			if ( false === $raw ) {
				return '';
			}
			$binary .= $raw;
		}

		if ( '' === $binary ) {
			return '';
		}

		return md5( $binary ) . '-' . count( $part_md5s );
	}

	/**
	 * Compare HEAD metadata to the local file.
	 *
	 * @param int    $local_size     Local file size.
	 * @param int    $remote_size    ContentLength from HEAD.
	 * @param string $expected_etag  Expected ETag (MD5 or multipart form).
	 * @param string $remote_etag    ETag from HEAD.
	 * @return string|null Human-readable mismatch, or null when consistent.
	 */
	public static function compare( int $local_size, int $remote_size, string $expected_etag, string $remote_etag ): ?string {
		if ( $local_size !== $remote_size ) {
			return sprintf(
				/* translators: 1: local bytes, 2: remote bytes. */
				__( '上传后校验失败：本地 %1$d 字节，远端 Content-Length 为 %2$d 字节。', 'sakura-imgopt' ),
				$local_size,
				$remote_size
			);
		}

		$expected = self::normalize_etag( $expected_etag );
		$actual   = self::normalize_etag( $remote_etag );

		if ( '' === $actual ) {
			return __( '上传后校验失败：HEAD 未返回 ETag。', 'sakura-imgopt' );
		}

		if ( '' !== $expected && ! hash_equals( $expected, $actual ) ) {
			return sprintf(
				/* translators: 1: expected ETag, 2: remote ETag. */
				__( '上传后校验失败：ETag 不一致（期望 %1$s，远端 %2$s），可能存在截断或代理改写。', 'sakura-imgopt' ),
				$expected,
				$actual
			);
		}

		return null;
	}
}
