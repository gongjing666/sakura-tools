<?php
namespace Sakura\ImageOptimizer\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 受控本地文件操作。
 *
 * 所有调用方都先校验路径归属；本类仅负责将底层失败显式返回，
 * 不使用 @ 抑制错误。上传中的同分区移动仍需 rename() 才能保持原子替换。
 */
class File_System {

	/**
	 * 写入本地文件。
	 *
	 * @param string $path
	 * @param string $contents
	 * @param int    $flags
	 * @return bool
	 */
	public static function write( $path, $contents, $flags = LOCK_EX ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local upload cache/sentinel write; WP_Filesystem may require interactive credentials.
		return false !== file_put_contents( $path, $contents, $flags );
	}

	/**
	 * 读取已验证可读的本地文件。
	 *
	 * @param string $path
	 * @return string|false
	 */
	public static function read( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a validated local file, not a remote URL.
		return file_get_contents( $path );
	}

	/**
	 * 复制文件。
	 *
	 * @param string $source
	 * @param string $destination
	 * @return bool
	 */
	public static function copy( $source, $destination ) {
		return copy( $source, $destination );
	}

	/**
	 * 同一文件系统内原子移动文件。
	 *
	 * @param string $source
	 * @param string $destination
	 * @return bool
	 */
	public static function move( $source, $destination ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replacement is required for media files; WP_Filesystem has no atomic equivalent.
		return rename( $source, $destination );
	}

	/**
	 * 原子占位一个唯一路径（同名已存在时追加 -1、-2 …）。
	 *
	 * WordPress 的 wp_unique_filename() 只在「上传时」按原始文件名去重；格式
	 * 转换（PNG → WebP 等）发生在改名之后，同一秒内重复上传同名文件时，
	 * 第二次的转换产物会覆盖第一次，导致两个附件指向同一个物理文件。
	 * 用 'x' 模式创建占位文件是原子的：并发下只有一个进程能抢到同一个名字。
	 *
	 * @param string $path 期望的目标路径。
	 * @return string 已占位的路径；候选全部不可用时返回 ''。
	 */
	public static function claim_unique_path( $path ) {
		$dir = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			return '';
		}

		// wp_is_writable()/wp_basename() only exist inside WordPress; the unit
		// tests call this class directly, so keep a native fallback.
		$writable = function_exists( 'wp_is_writable' ) ? wp_is_writable( $dir ) : is_writable( $dir );

		if ( ! $writable ) {
			return '';
		}

		$name   = function_exists( 'wp_basename' ) ? wp_basename( $path ) : basename( $path );
		$ext    = pathinfo( $name, PATHINFO_EXTENSION );
		$stem   = '' !== $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name;
		$suffix = '' !== $ext ? '.' . $ext : '';

		for ( $i = 0; $i <= 1000; $i++ ) {
			$candidate = 0 === $i ? $path : $dir . '/' . $stem . '-' . $i . $suffix;

			if ( file_exists( $candidate ) || is_link( $candidate ) ) {
				continue;
			}

			$handle = self::open_exclusive( $candidate );
			if ( false !== $handle ) {
				fclose( $handle );
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * 以独占模式创建文件（原子占位）。
	 *
	 * 'x' 模式在文件已存在时返回 false 并产生 E_WARNING —— 这是并发判定的
	 * 正常分支，不应写进日志，因此在这里临时吞掉该警告。
	 *
	 * @param string $path
	 * @return resource|false
	 */
	private static function open_exclusive( $path ) {
		$handle = false;
		$quiet  = static function () {
			return true;
		};

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped suppression of the expected "file exists" warning.
		set_error_handler( $quiet, E_WARNING );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive create is the atomic claim primitive.
			$handle = fopen( $path, 'x' );
		} finally {
			restore_error_handler();
		}

		return $handle;
	}

	/**
	 * 原子落盘到唯一目标名：先占位，再 rename；跨分区时 copy + delete。
	 *
	 * @param string $source      临时文件。
	 * @param string $destination 期望路径。
	 * @return array{ok:bool,path:string}
	 */
	public static function move_to_unique( $source, $destination ) {
		$target = self::claim_unique_path( $destination );

		if ( '' === $target ) {
			return array(
				'ok'   => false,
				'path' => $destination,
			);
		}

		if ( self::move( $source, $target ) ) {
			return array(
				'ok'   => true,
				'path' => $target,
			);
		}

		if ( self::copy( $source, $target ) && self::delete( $source ) ) {
			return array(
				'ok'   => true,
				'path' => $target,
			);
		}

		// 落盘失败：清掉占位，避免留下 0 字节孤儿文件。
		self::delete( $target );

		return array(
			'ok'   => false,
			'path' => $target,
		);
	}

	/**
	 * 删除文件或符号链接。
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function delete( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			return wp_delete_file( $path );
		}

		return self::unlink( $path );
	}

	/**
	 * Native unlink with the expected warning suppressed (unit tests / CLI).
	 *
	 * @param string $path
	 * @return bool
	 */
	private static function unlink( $path ) {
		$quiet = static function () {
			return true;
		};

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped suppression of the expected unlink warning.
		set_error_handler( $quiet, E_WARNING );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback when wp_delete_file() is unavailable.
			$ok = unlink( $path );
		} finally {
			restore_error_handler();
		}

		return $ok || ( ! file_exists( $path ) && ! is_link( $path ) );
	}

	/**
	 * 删除已清空的目录。
	 *
	 * @param string $path
	 * @return bool
	 */
	public static function remove_dir( $path ) {
		if ( ! is_dir( $path ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only an already enumerated empty plugin-owned directory.
		return rmdir( $path );
	}

	/**
	 * 收紧本地敏感缓存的权限。
	 *
	 * @param string $path
	 * @param int    $mode
	 * @return bool
	 */
	public static function chmod( $path, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Sensitive local credential cache must be owner-only.
		return chmod( $path, $mode );
	}
}
