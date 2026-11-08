<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\S3\Client_Factory;
use Sakura\ImageOptimizer\S3\History_Migrator;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || true !== WP_CLI || ! class_exists( 'WP_CLI' ) ) {
	return;
}

/** S3 历史文件迁移命令。 */
class S3_CLI {

	/**
	 * Instance config.
	 *
	 * @var Options|null
	 */
	private ?Options $options = null;

	/**
	 * Upload adapter.
	 *
	 * @var Storage_Adapter|null
	 */
	private ?Storage_Adapter $adapter = null;

	/**
	 * Resolve options lazily.
	 *
	 * @return Options
	 */
	private function options(): Options {
		return $this->options ??= new Options();
	}

	/**
	 * Build a client for this command.
	 *
	 * @return object
	 */
	private function client() {
		return Client_Factory::make( $this->options()->all() );
	}

	/**
	 * Resolve the upload adapter.
	 *
	 * @return Storage_Adapter
	 */
	private function adapter(): Storage_Adapter {
		return $this->adapter ??= new Storage_Adapter( $this->options() );
	}

	/**
	 * Inspect or explicitly reconcile held remote mutations.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list (default) or release.
	 * [--resource=<hash>]
	 * : Resource hash from list.
	 * [--token=<token>]
	 * : Exact reservation token from list.
	 * [--remote-settled]
	 * : Assert all old senders and provider requests are stopped and reconciled.
	 * [--yes]
	 * : Confirm the explicit release.
	 *
	 * @subcommand remote-operations
	 */
	public function remote_operations( $args, $assoc_args ): void {
		$guard = new \Sakura\ImageOptimizer\S3\Remote_Operation_Guard( '' );
		$action = $args[0] ?? 'list';
		if ( 'list' === $action ) {
			\WP_CLI\Utils\format_items( 'table', $guard->pending(), array( 'resource_hash', 'token', 'object_key', 'action', 'state', 'created_at', 'last_error', 'context_json' ) );
			\WP_CLI::line( 'At most 100 held operations shown. Age/HEAD alone never establishes that old requests stopped.' );
			return;
		}
		if ( 'release' !== $action || ! isset( $assoc_args['remote-settled'] ) ) {
			\WP_CLI::error( 'Release requires --remote-settled after stopping old senders and verifying provider-side request completion; a timeout or HEAD check is insufficient.' );
		}
		\WP_CLI::confirm( 'All old senders and provider requests must be stopped and remote state reconciled. Release this exact reservation?', $assoc_args );
		if ( ! $guard->acknowledge_settled( (string) ( $assoc_args['resource'] ?? '' ), (string) ( $assoc_args['token'] ?? '' ) ) ) {
			\WP_CLI::error( 'No matching reservation was released.' );
		}
		\WP_CLI::success( 'Reservation released. Retry only the intended action through Sync Center.' );
	}

	private function require_configuration() {
		if ( ! $this->options()->is_enabled() ) {
			\WP_CLI::error( 'S3 storage is disabled. Enable it in the S3 settings page before uploading.' );
		}
		if ( ! $this->options()->is_configured() ) {
			\WP_CLI::error( 'S3 configuration is incomplete. Save bucket, key, secret and endpoint first.' );
		}
	}

	private function resolve_path( $path, $must_be_directory = false ) {
		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		if ( '' === $path || '.' === $path || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}
		$root     = realpath( ABSPATH );
		$absolute = realpath( ABSPATH . $path );
		if ( false === $root || false === $absolute ) {
			return false;
		}
		$prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $absolute . ( is_dir( $absolute ) ? DIRECTORY_SEPARATOR : '' ), $prefix ) ) {
			return false;
		}
		if ( $must_be_directory && ! is_dir( $absolute ) ) {
			return false;
		}
		return array( $absolute, $path );
	}

	private function files( $directory ) {
		$root = realpath( $directory );
		if ( false === $root ) {
			return;
		}
		$flags    = \FilesystemIterator::SKIP_DOTS;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, $flags ),
				static function ( $current ) {
					return ! $current->isLink() && ( $current->isDir() || ( $current->isFile() && $current->isReadable() ) );
				}
			),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				yield $file->getPathname();
			}
		}
	}

	private function object_exists( $key ) {
		try {
			$this->client()->headObject(
				array(
					'Bucket' => (string) $this->options()->get( 'bucket' ),
					'Key'    => $key,
				)
			);
			return true;
		} catch ( \Sakura\Vendor\Aws\S3\Exception\S3Exception $e ) {
			if ( (int) $e->getStatusCode() === 404 || in_array( $e->getAwsErrorCode(), array( 'NoSuchKey', 'NotFound' ), true ) ) {
				return false;
			}
			\WP_CLI::error( 'Unable to check remote object safely: ' . $e->getMessage() );
		}
	}

	/**
	 * 分批同步 WordPress 根目录下的文件夹到 S3。
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : WordPress 根目录下的文件夹，例如 wp-content/uploads。
	 *
	 * [--dry-run]
	 * : 仅预览对象 Key，不上传。
	 *
	 * [--limit=<number>]
	 * : 最多处理的文件数。
	 *
	 * [--skip-existing]
	 * : 目标对象存在时跳过。
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-s3 upload wp-content/uploads --dry-run --limit=20
	 *     wp sakura-s3 upload wp-content/uploads --skip-existing
	 *
	 * @when after_wp_load
	 */
	public function upload( $args, $assoc_args ) {
		$path     = isset( $args[0] ) ? $args[0] : '';
		$resolved = $this->resolve_path( $path, true );
		if ( false === $resolved ) {
			\WP_CLI::error( 'Use a readable subdirectory inside the WordPress root. Root and parent traversal are refused.' );
		}
		$this->require_configuration();
		$dry_run       = isset( $assoc_args['dry-run'] );
		$skip_existing = isset( $assoc_args['skip-existing'] );
		$limit         = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		if ( isset( $assoc_args['limit'] ) && 1 > $limit ) {
			\WP_CLI::error( '--limit must be a positive integer.' );
		}
		$processed = 0;
		$uploaded  = 0;
		$skipped   = 0;
		$failed    = 0;
		foreach ( $this->files( $resolved[0] ) as $filename ) {
			if ( $limit && $processed >= $limit ) {
				break;
			}
			$key = $this->adapter()->key_from_path( $filename );
			if ( false === $key ) {
				++$failed;
				\WP_CLI::warning( 'Skipped path outside configured uploads mapping: ' . $filename );
				continue;
			}
			++$processed;
			if ( $dry_run ) {
				\WP_CLI::line( 'Would upload: ' . $key );
				continue;
			}
			if ( $skip_existing && $this->object_exists( $key ) ) {
				++$skipped;
				\WP_CLI::line( 'Skipped existing: ' . $key );
				continue;
			}
			$result = $this->adapter()->upload_file( $key, $filename, false );
			if ( is_wp_error( $result ) ) {
				++$failed;
				\WP_CLI::warning( 'Failed: ' . $key );
			} else {
				++$uploaded;
				\WP_CLI::line( 'Uploaded: ' . $key );
			}
		}
		if ( $failed ) {
			\WP_CLI::error( sprintf( 'Processed %d: uploaded %d, skipped %d, failed %d.', $processed, $uploaded, $skipped, $failed ) );
		}
		\WP_CLI::success(
			$dry_run
			? sprintf( 'Previewed %d file(s); no changes made.', $processed )
			: sprintf( 'Processed %d: uploaded %d, skipped %d.', $processed, $uploaded, $skipped )
		);
	}

	/**
	 * 上传单个文件。
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : WordPress 根目录下的文件。
	 *
	 * [--key=<key>]
	 * : 自定义远端对象 Key。
	 *
	 * [--dry-run]
	 * : 仅预览。
	 *
	 * [--delete]
	 * : 上传成功后删除本地文件，必须同时提供 --yes。
	 *
	 * [--yes]
	 * : 确认本地删除。
	 *
	 * @subcommand upload-file
	 * @when after_wp_load
	 */
	public function upload_file( $args, $assoc_args ) {
		$resolved = $this->resolve_path( isset( $args[0] ) ? $args[0] : '', false );
		if ( false === $resolved || ! is_file( $resolved[0] ) ) {
			\WP_CLI::error( 'Use a readable file inside the WordPress root.' );
		}
		$this->require_configuration();
		$delete = isset( $assoc_args['delete'] );
		if ( $delete && ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Local deletion requires both --delete and --yes.' );
		}
		$key = isset( $assoc_args['key'] ) ? $this->adapter()->normalize_key( (string) $assoc_args['key'] ) : $this->adapter()->key_from_path( $resolved[0] );
		if ( false === $key ) {
			\WP_CLI::error( 'Invalid object key.' );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( 'Would upload ' . $resolved[1] . ' as ' . $key . ( $delete ? ' and delete local file after success.' : '.' ) );
			return;
		}
		$result = $this->adapter()->upload_file( $key, $resolved[0], $delete );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Uploaded: ' . $key );
	}

	/**
	 * 扫描媒体库并同步上传历史附件。
	 *
	 * 扫描按附件 ID 递增分批，每个附件在当前请求内上传并校验，本地副本
	 * 保留；大库请用本命令，浏览器关闭也能续跑（下次从游标继续）。
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : 每页附件数，默认 500（25–2000）。
	 *
	 * [--seconds=<number>]
	 * : 每批时间预算，默认 20 秒（1–120）。
	 *
	 * [--reset]
	 * : 从 ID 0 重新扫描（否则续跑上次游标）。
	 *
	 * [--limit=<number>]
	 * : 本次最多扫描多少个附件后停止。
	 *
	 * [--once]
	 * : 只跑一批，供系统 cron 调用。
	 *
	 * [--dry-run]
	 * : 只统计可上传的附件，不写状态、不上传。
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-s3 migrate --reset
	 *     wp sakura-s3 migrate --batch-size=1000 --seconds=30
	 *     wp sakura-s3 migrate --once
	 *
	 * @when after_wp_load
	 */
	public function migrate( $args, $assoc_args ) {
		$this->require_configuration();
		$migrator = new History_Migrator( $this->options(), $this->adapter() );
		$batch    = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : History_Migrator::DEFAULT_BATCH;
		$seconds  = isset( $assoc_args['seconds'] ) ? (float) $assoc_args['seconds'] : History_Migrator::DEFAULT_SECONDS;
		$limit    = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$once     = isset( $assoc_args['once'] );
		$dry_run  = isset( $assoc_args['dry-run'] );

		if ( $batch < 25 || $batch > History_Migrator::MAX_BATCH ) {
			\WP_CLI::error( sprintf( '--batch-size must be between 25 and %d.', History_Migrator::MAX_BATCH ) );
		}
		if ( $seconds < 1 || $seconds > 120 ) {
			\WP_CLI::error( '--seconds must be between 1 and 120.' );
		}
		if ( isset( $assoc_args['reset'] ) ) {
			$migrator->reset( ! $dry_run );
		}

		$start_state = $migrator->state();
		$start_at    = (int) $start_state['processed'];
		$remaining   = $limit > 0 ? $limit : PHP_INT_MAX;

		do {
			$result = $migrator->run_batch( false, $batch, $seconds, $dry_run );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::line(
				sprintf(
					'Scanned %1$d/%2$d | uploaded %3$d | skipped %4$d | failed %5$d',
					(int) $result['processed'],
					(int) $result['total'],
					(int) $result['queued'],
					(int) $result['skipped'],
					(int) $result['failed']
				)
			);
			$remaining -= max( 0, (int) $result['processed'] - $start_at );
			$start_at   = (int) $result['processed'];
			if ( $once || $result['done'] || $remaining <= 0 ) {
				break;
			}
		} while ( true );

		\WP_CLI::success(
			sprintf(
				'History scan finished: %1$d attachment(s), uploaded %2$d, skipped %3$d, failed %4$d. Local copies were kept.',
				(int) $result['processed'],
				(int) $result['queued'],
				(int) $result['skipped'],
				(int) $result['failed']
			)
		);
	}

	/**
	 * Inspect or repair the remote delete queue.
	 *
	 * Useful after the bucket of an existing profile changed: jobs created for
	 * the previous target are the ones that could not be removed automatically.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<states>]
	 * : Comma separated states (queued, failed, dead) or "all". Default: failed,dead.
	 *
	 * [--limit=<number>]
	 * : Rows to print or export. Default 50.
	 *
	 * [--export=<path>]
	 * : Write the rows as CSV to this path instead of printing a table.
	 *
	 * [--retry]
	 * : Reset matching rows to queued with a cleared attempt counter.
	 *
	 * [--drop]
	 * : Delete matching rows from the queue.
	 *
	 * [--yes]
	 * : Required with --retry and --drop.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-s3 deletes --list --state=dead
	 *     wp sakura-s3 deletes --export=/tmp/orphans.csv --state=all
	 *     wp sakura-s3 deletes --retry --state=dead --yes
	 *
	 * @subcommand deletes
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function deletes( $args, $assoc_args ): void {
		global $wpdb;

		$jobs_class = '\\Sakura\\ImageOptimizer\\S3\\Delete_Jobs';
		$jobs_class::maybe_install();
		$table = $jobs_class::table_name();

		$states = array( 'failed', 'dead' );
		if ( isset( $assoc_args['state'] ) ) {
			$states = array_map( 'sanitize_key', explode( ',', (string) $assoc_args['state'] ) );
			if ( in_array( 'all', $states, true ) ) {
				$states = array( 'queued', 'failed', 'dead' );
			}
		}
		$states = array_values( array_intersect( $states, array( 'queued', 'failed', 'dead' ) ) );
		if ( array() === $states ) {
			\WP_CLI::error( __( '状态无效：用 queued、failed、dead 或 all。', 'sakura-imgopt' ) );
		}

		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 50;
		$where = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';

		if ( isset( $assoc_args['retry'] ) ) {
			$this->require_yes( $assoc_args, '--retry' );
			$sql     = $wpdb->prepare(
				"UPDATE {$table} SET state = 'queued', attempts = 0, next_at = %s, lease_until = NULL, claim_token = '', last_error = '' WHERE {$where}",
				array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $states )
			);
			$updated = false === $sql ? 0 : (int) $wpdb->query( $sql );
			\WP_CLI::success( sprintf( /* translators: %d: number of queued jobs. */ __( '已重排 %1$d 个任务；下一个 cron tick 会按归档目标重试。', 'sakura-imgopt' ), $updated ) );
			return;
		}

		if ( isset( $assoc_args['drop'] ) ) {
			$this->require_yes( $assoc_args, '--drop' );
			$sql     = $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", $states );
			$deleted = false === $sql ? 0 : (int) $wpdb->query( $sql );
			\WP_CLI::success( sprintf( /* translators: %d: number of queue rows. */ __( '已删除 %1$d 行队列记录。', 'sakura-imgopt' ), $deleted ) );
			return;
		}

		$sql  = $wpdb->prepare(
			"SELECT id, profile_id, state, attempts, object_key, last_error, updated_at FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d",
			array_merge( $states, array( $limit ) )
		);
		$rows = false === $sql ? array() : $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			\WP_CLI::success( __( '队列中没有匹配的记录。', 'sakura-imgopt' ) );
			return;
		}

		if ( isset( $assoc_args['export'] ) ) {
			$path = (string) $assoc_args['export'];
			$fh   = fopen( $path, 'wb' );
			if ( false === $fh ) {
				\WP_CLI::error( sprintf( /* translators: %s: file path. */ __( '无法写入 %s', 'sakura-imgopt' ), $path ) );
			}
			fputcsv( $fh, array( 'id', 'profile_id', 'state', 'attempts', 'object_key', 'last_error', 'updated_at' ) );
			foreach ( $rows as $row ) {
				fputcsv( $fh, array_values( $row ) );
			}
			fclose( $fh );
			\WP_CLI::success( sprintf( /* translators: 1: row count, 2: file path. */ __( '已导出 %1$d 行到 %2$s', 'sakura-imgopt' ), count( $rows ), $path ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'profile_id', 'state', 'attempts', 'object_key', 'last_error', 'updated_at' ) );
	}

	/**
	 * Refuse destructive queue operations without an explicit confirmation flag.
	 *
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 * @param string              $flag       Flag that needs --yes.
	 */
	private function require_yes( array $assoc_args, string $flag ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::error( sprintf( /* translators: %s: command flag. */ __( '%s 需要同时提供 --yes。', 'sakura-imgopt' ), $flag ) );
		}
	}

}

\WP_CLI::add_command( 'sakura-s3', __NAMESPACE__ . '\\S3_CLI', array( 'shortdesc' => 'Sakura S3 object storage commands.' ) );
