<?php
namespace Sakura\ImageOptimizer\Backup;

use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Filesystem\File_System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 原图备份 / 一键还原
 *
 * 设计要点：
 *   ① 备份目录   uploads/sakura-imgopt-backup/，内部镜像「年/月/原文件名」结构。
 *   ② 备份时机   handle_upload 删除原图之前复制一份。
 *   ③ 还原方式   原地替换文件 + 原地重算 metadata，绝不新建/删除附件，附件 ID 不变。
 *   ④ 消费策略   还原成功后删除备份与标记；用户若再次压缩则重新备份。
 *
 * postmeta（_sakura_imgopt_backup）结构：
 *   [
 *     'backup_rel'    => 'sakura-imgopt-backup/2026/06/xxx.png', // 相对 uploads
 *     'origin_rel'    => '2026/06/xxx.png',                       // 原图相对路径（还原目标）
 *     'converted_rel' => '2026/06/xxx.avif',                      // 转换后主文件相对路径
 *     'time'          => 'Y-m-d H:i:s',
 *   ]
 */
class Backup {

	/** 备份子目录名（位于 uploads 下） */
	const DIR = 'sakura-imgopt-backup';

	/** 备份信息 meta 名 */
	const META_KEY = '_sakura_imgopt_backup';

	/** 备份目录占用统计的 transient 缓存 key。 */
	const USAGE_CACHE_KEY = 'sakura_imgopt_backup_usage';

	/** Apache 2.2/2.4 双语法拒绝规则；Nginx 不会读取 .htaccess，需在站点配置中另行 deny。 */
	const HTACCESS_RULES = "# Sakura Tools backup directory.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/**
	 * 备份目录绝对路径（不含末尾斜杠）。
	 *
	 * @return string
	 */
	public static function base_dir() {
		$upload = wp_upload_dir();
		return rtrim( $upload['basedir'], '/' ) . '/' . self::DIR;
	}

	/**
	 * 规范并验证相对于 uploads 根目录的路径。
	 *
	 * @param mixed $path
	 * @return string|false
	 */
	public static function normalize_relative_path( $path ) {
		if ( ! is_string( $path ) ) {
			return false;
		}
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
			return false;
		}
		return $path;
	}

	/**
	 * 将已验证的相对路径解析到 uploads 内；拒绝目录穿越和越界符号链接。
	 *
	 * @param mixed $relative_path
	 * @param bool  $require_backup_dir 是否要求路径位于插件备份目录。
	 * @param bool  $must_exist        是否要求目标为已存在的普通文件。
	 * @return string|false
	 */
	public static function resolve_upload_path( $relative_path, $require_backup_dir = false, $must_exist = false ) {
		$relative_path = self::normalize_relative_path( $relative_path );
		if ( false === $relative_path ) {
			return false;
		}
		if ( $require_backup_dir && 0 !== strpos( $relative_path, self::DIR . '/' ) ) {
			return false;
		}

		$upload_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $upload_base ) {
			return false;
		}
		$upload_base = rtrim( wp_normalize_path( $upload_base ), '/' );
		$path        = $upload_base . '/' . $relative_path;

		// 末级本身是符号链接时，后续 copy()/delete() 会穿透到链接目标。
		// must_exist=false 表示“即将创建”，同样必须拒绝。
		if ( is_link( $path ) ) {
			return false;
		}

		$probe       = $must_exist ? $path : dirname( $path );
		while ( ! file_exists( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		$resolved_probe = realpath( $probe );
		$base_prefix    = trailingslashit( $upload_base );
		if ( false === $resolved_probe || 0 !== strpos( trailingslashit( wp_normalize_path( $resolved_probe ) ), $base_prefix ) ) {
			return false;
		}
		if ( ! $must_exist ) {
			return $path;
		}
		if ( ! is_file( $path ) ) {
			return false;
		}
		$resolved_path = realpath( $path );
		if ( false === $resolved_path || 0 !== strpos( trailingslashit( wp_normalize_path( $resolved_path ) ), $base_prefix ) ) {
			return false;
		}
		return $resolved_path;
	}

	/**
	 * 确保备份根目录存在，并写入防直接访问的哨兵文件。
	 *
	 * @return bool
	 */
	public static function ensure_protected_dir() {
		$dir = self::base_dir();
		if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return false;
		}
		$upload_base = realpath( wp_upload_dir()['basedir'] );
		$real_dir    = realpath( $dir );
		if ( false === $upload_base || false === $real_dir ) {
			return false;
		}
		$base_prefix = trailingslashit( rtrim( wp_normalize_path( $upload_base ), '/' ) );
		if ( 0 !== strpos( trailingslashit( wp_normalize_path( $real_dir ) ), $base_prefix ) ) {
			return false;
		}
		$dir = $real_dir;

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) && ! File_System::write( $index, "<?php\n// Silence is golden.\n" ) ) {
			return false;
		}
		$htaccess = $dir . '/.htaccess';
		$existing = file_exists( $htaccess ) ? File_System::read( $htaccess ) : '';
		if ( false === $existing || false === strpos( (string) $existing, 'Require all denied' ) ) {
			if ( ! File_System::write( $htaccess, self::HTACCESS_RULES ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 备份一份原图到备份目录（镜像年/月相对路径）。
	 *
	 * @param mixed $origin_abs 原图绝对路径（即将被删除的转换前文件）
	 * @return string|false      成功返回备份相对路径（相对 uploads），失败返回 false
	 */
	public static function store( $origin_abs ) {
		if ( ! is_string( $origin_abs ) || ! is_file( $origin_abs ) ) {
			return false;
		}
		$origin_abs = realpath( $origin_abs );
		if ( false === $origin_abs ) {
			return false;
		}
		$upload_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $upload_base ) {
			return false;
		}
		$upload_base = rtrim( wp_normalize_path( $upload_base ), '/' );
		$origin_abs  = wp_normalize_path( $origin_abs );
		$base_prefix = trailingslashit( $upload_base );
		if ( 0 !== strpos( $origin_abs, $base_prefix ) ) {
			return false;
		}
		$origin_rel = self::normalize_relative_path( substr( $origin_abs, strlen( $base_prefix ) ) );
		if ( false === $origin_rel || 0 === strpos( $origin_rel, self::DIR . '/' ) ) {
			return false;
		}
		if ( ! self::ensure_protected_dir() ) {
			return false;
		}
		$backup_rel = self::DIR . '/' . $origin_rel;
		$backup_abs = self::resolve_upload_path( $backup_rel, true );
		if ( false === $backup_abs ) {
			return false;
		}
		$backup_parent = dirname( $backup_abs );
		if ( ! is_dir( $backup_parent ) && ! wp_mkdir_p( $backup_parent ) ) {
			return false;
		}
		$backup_abs = self::resolve_upload_path( $backup_rel, true );
		if ( false === $backup_abs || is_link( $backup_abs ) ) {
			return false;
		}
		$backup_existed = file_exists( $backup_abs );
		if ( ! File_System::copy( $origin_abs, $backup_abs ) ) {
			// 复制中断时可能留下半截新文件；仅在覆盖前不存在的目标上清理，
			// 避免误删先前已存在的有效备份。
			if ( ! $backup_existed ) {
				File_System::delete( $backup_abs );
			}
			return false;
		}
		self::flush_usage_cache();
		return $backup_rel;
	}

	/**
	 * 失效备份占用统计缓存。
	 *
	 * 任何会改变备份目录内容的操作（新增 / 删除 / 清空）后都应调用，
	 * 否则「优化统计」页会继续显示 5 分钟内的旧占用数据。
	 */
	public static function flush_usage_cache() {
		delete_transient( self::USAGE_CACHE_KEY );
	}

	/**
	 * 写入/更新附件的备份元数据。
	 *
	 * @param int    $attachment_id
	 * @param string $backup_rel    备份相对路径
	 * @param string $origin_rel    原图相对路径（还原目标）
	 * @param string $converted_rel 转换后主文件相对路径
	 */
	public static function save_meta( $attachment_id, $backup_rel, $origin_rel, $converted_rel ) {
		$backup_rel    = self::normalize_relative_path( $backup_rel );
		$origin_rel    = self::normalize_relative_path( $origin_rel );
		$converted_rel = self::normalize_relative_path( $converted_rel );
		if ( $attachment_id <= 0 || false === $backup_rel || false === $origin_rel || false === $converted_rel || 0 !== strpos( $backup_rel, self::DIR . '/' ) || 0 === strpos( $origin_rel, self::DIR . '/' ) ) {
			return;
		}
		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'backup_rel'    => $backup_rel,
				'origin_rel'    => $origin_rel,
				'converted_rel' => $converted_rel,
				'time'          => current_time( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * 该附件是否有可还原的备份。
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function has_backup( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $meta ) || empty( $meta['backup_rel'] ) ) {
			return false;
		}
		return false !== self::resolve_upload_path( $meta['backup_rel'], true, true );
	}

	/**
	 * 删除某附件的备份文件与元数据标记。
	 *
	 * @param int $attachment_id
	 */
	public static function delete_backup( $attachment_id ) {
		$meta = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( is_array( $meta ) && ! empty( $meta['backup_rel'] ) ) {
			$backup_abs = self::resolve_upload_path( $meta['backup_rel'], true, true );
			if ( false !== $backup_abs ) {
				File_System::delete( $backup_abs );
			}
		}
		delete_post_meta( $attachment_id, self::META_KEY );
		self::flush_usage_cache();
	}

	/**
	 * 统计备份目录的总占用与文件数（忽略 index.php / .htaccess 哨兵文件）。
	 *
	 * 结果缓存 5 分钟，避免每次进后台都递归扫盘。
	 *
	 * @param bool $force 是否跳过缓存强制重算。
	 * @return array ['bytes' => int, 'count' => int]
	 */
	public static function get_usage( $force = false ) {
		$cache_key = self::USAGE_CACHE_KEY;
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$dir   = self::base_dir();
		$bytes = 0;
		$count = 0;

		if ( is_dir( $dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$name = $file->getFilename();
				// 跳过哨兵文件，只统计真正的备份图。
				if ( 'index.php' === $name || '.htaccess' === $name ) {
					continue;
				}
				$bytes += $file->getSize();
				++$count;
			}
		}

		$usage = array(
			'bytes' => $bytes,
			'count' => $count,
		);
		set_transient( $cache_key, $usage, 5 * MINUTE_IN_SECONDS );
		return $usage;
	}

	/**
	 * 清空全部备份：删除备份目录内所有备份文件 + 清理所有附件的备份标记。
	 *
	 * 保留备份根目录与哨兵文件（index.php / .htaccess）。
	 *
	 * @return array ['bytes' => int, 'count' => int] 本次释放的占用与删除文件数。
	 */
	public static function purge_all() {
		$dir   = self::base_dir();
		$freed = 0;
		$count = 0;

		if ( is_dir( $dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $file ) {
				$name = $file->getFilename();
				if ( $file->isDir() ) {
					File_System::remove_dir( $file->getPathname() );
					continue;
				}
				// 保留哨兵文件。
				if ( 'index.php' === $name || '.htaccess' === $name ) {
					continue;
				}
				$freed += $file->getSize();
				if ( File_System::delete( $file->getPathname() ) ) {
					++$count;
				}
			}
		}

		// 清理所有附件残留的备份元数据，避免「meta 说有、文件已没」的脏状态。
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::META_KEY ) );

		// 失效占用缓存。
		self::flush_usage_cache();

		return array(
			'bytes' => $freed,
			'count' => $count,
		);
	}

	/**
	 * 按清理策略删除过期 / 超额备份。
	 *
	 * 策略来源于后台「备份管理」页：
	 *   - sakura_imgopt_backup_retention_days：保留天数（0=不限）。
	 *     备份文件 mtime 早于 now - N 天的，删除。
	 *   - sakura_imgopt_backup_max_mb：容量上限 MB（0=不限）。
	 *     过期清理后若总占用仍超上限，按 mtime 升序（最旧优先，LRU）继续删，直到达标。
	 *
	 * 删除文件的同时清理其对应附件的备份元数据，避免「meta 在、文件没」的脏状态。
	 *
	 * @return array{bytes:int,count:int}
	 */
	public static function clean_expired() {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			return array(
				'bytes' => 0,
				'count' => 0,
			);
		}

		$settings       = new Settings_Repository();
		$retention_days = $settings->backup_retention_days();
		$max_mb         = $settings->backup_max_mb();

		// 收集所有备份文件（跳过哨兵），记录路径 / 大小 / mtime。
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				File_System::remove_dir( $file->getPathname() );
				continue;
			}
			$name = $file->getFilename();
			if ( 'index.php' === $name || '.htaccess' === $name ) {
				continue;
			}
			$files[] = array(
				'path'  => $file->getPathname(),
				'size'  => $file->getSize(),
				'mtime' => $file->getMTime(),
			);
		}

		if ( empty( $files ) ) {
			return array(
				'bytes' => 0,
				'count' => 0,
			);
		}

		// 建立「备份绝对路径 → attachment_id」映射，便于删除文件时同步清 meta。
		$base_dir   = rtrim( wp_upload_dir()['basedir'], '/' );
		$path_to_id = self::build_path_to_id_map();

		$freed   = 0;
		$count   = 0;
		$deleted = array();

		// ① 按保留天数删过期文件。
		if ( $retention_days > 0 ) {
			$cutoff = time() - $retention_days * DAY_IN_SECONDS;
			foreach ( $files as $i => $f ) {
				if ( $f['mtime'] < $cutoff ) {
					if ( File_System::delete( $f['path'] ) ) {
						$freed += $f['size'];
						++$count;
						self::forget_meta_by_path( $f['path'], $path_to_id );
						$deleted[ $i ] = true;
					}
				}
			}
		}

		// ② 按容量上限做 LRU 淘汰（最旧优先），仅在仍超额时执行。
		if ( $max_mb > 0 ) {
			$remaining = array_values(
				array_filter(
					$files,
					function ( $f, $i ) use ( $deleted ) {
						return empty( $deleted[ $i ] );
					},
					ARRAY_FILTER_USE_BOTH
				)
			);

			$total = 0;
			foreach ( $remaining as $f ) {
				$total += $f['size'];
			}

			$limit = $max_mb * MB_IN_BYTES;
			if ( $total > $limit ) {
				// 最旧优先：按 mtime 升序排序。
				usort(
					$remaining,
					function ( $a, $b ) {
						return $a['mtime'] <=> $b['mtime'];
					}
				);
				foreach ( $remaining as $f ) {
					if ( $total <= $limit ) {
						break;
					}
					if ( File_System::delete( $f['path'] ) ) {
						$freed += $f['size'];
						++$count;
						$total -= $f['size'];
						self::forget_meta_by_path( $f['path'], $path_to_id );
					}
				}
			}
		}

		if ( $count > 0 ) {
			self::flush_usage_cache();
		}

		return array(
			'bytes' => $freed,
			'count' => $count,
		);
	}

	/**
	 * 构建「备份绝对路径 → attachment_id」映射。
	 *
		 * @return array<string,int>
	 */
	private static function build_path_to_id_map() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY )
		);
		$map  = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$meta = maybe_unserialize( $row->meta_value );
				if ( is_array( $meta ) && ! empty( $meta['backup_rel'] ) ) {
					$abs = self::resolve_upload_path( $meta['backup_rel'], true, true );
					if ( false !== $abs ) {
						$map[ $abs ] = (int) $row->post_id;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * 删除某备份文件对应的附件备份元数据（若有映射）。
	 *
	 * @param string             $abs        备份文件绝对路径
	 * @param array<string,int>  $path_to_id 路径→附件ID 映射
	 */
	private static function forget_meta_by_path( $abs, $path_to_id ) {
		if ( isset( $path_to_id[ $abs ] ) ) {
			delete_post_meta( $path_to_id[ $abs ], self::META_KEY );
		}
	}
}
