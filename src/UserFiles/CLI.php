<?php
/**
 * WP-CLI commands for the user file space.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and drive the file-space workers.
 *
 * ## EXAMPLES
 *
 *     wp sufm status
 *     wp sufm tick
 *     wp sufm reconcile
 */
final class CLI {

	/**
	 * Service graph.
	 *
	 * @var array<string, object>|null
	 */
	private static ?array $services = null;

	/**
	 * Return the shared service graph.
	 *
	 * @return array<string, object>
	 */
	private static function services(): array {
		if ( null === self::$services ) {
			self::$services = ( new Module() )->services();
		}

		return self::$services;
	}

	/**
	 * Print schema state, queue depth and outbox backlog.
	 *
	 * @return void
	 */
	public function status(): void {
		global $wpdb;

		Schema::maybe_install();

		$services = self::services();
		$requests = $services['requests'];

		$rows = array(
			'schema'      => (string) get_option( Schema::VERSION_OPTION, '' ),
			'pending'     => (string) $requests->count( 0, Repositories\Requests::PENDING ),
			'approved'    => (string) $requests->count( 0, Repositories\Requests::APPROVED ),
			'queued'      => (string) $requests->count( 0, Repositories\Requests::QUEUED ),
			'executing'   => (string) $requests->count( 0, Repositories\Requests::EXECUTING ),
			'jobs'        => (string) (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'jobs' ) . " WHERE state IN ('queued','running')" ),
			'next_tick'   => (string) (int) wp_next_scheduled( Module::TICK ),
			'outbox_due'  => (string) count( $services['outbox']->due( 100 ) ),
			'tables'      => (string) count( Schema::tables() ),
		);

		$width = 12;
		foreach ( $rows as $label => $value ) {
			\WP_CLI::line( str_pad( $label, $width ) . ' ' . $value );
		}
	}

	/**
	 * Run one worker tick synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<n>]
	 * : Maximum job attempts in this tick. Default 40.
	 *
	 * @param array<string, mixed> $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function tick( array $args, array $assoc_args ): void {
		$budget   = isset( $assoc_args['budget'] ) ? max( 1, (int) $assoc_args['budget'] ) : 40;
		$services = self::services();

		Schema::maybe_install();

		$reclaimed = $services['quota']->reclaim_expired();
		$processed = $services['executor']->tick( $budget );
		$sent      = $services['notifier']->deliver_due( 20 );

		\WP_CLI::success( sprintf( 'leases=%d processed=%d notified=%d', $reclaimed, $processed, $sent ) );
	}

	/**
	 * Recompute every owner's used bytes from the file ledger.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		global $wpdb;

		$usage = Schema::table( 'usage' );
		$files = Schema::table( 'files' );

		$owners = $wpdb->get_results( "SELECT owner_id, COALESCE(SUM(charged_bytes),0) AS bytes FROM {$files} WHERE lifecycle <> 'purged' GROUP BY owner_id", ARRAY_A );

		$fixed = 0;
		foreach ( (array) $owners as $row ) {
			$owner_id = (int) $row['owner_id'];
			$bytes    = (int) $row['bytes'];

			$services = self::services();
			$services['quota']->ensure( $owner_id );

			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT used_bytes FROM {$usage} WHERE owner_id = %d", $owner_id ) );
			if ( $current === $bytes ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$usage} SET used_bytes = %d, revision = revision + 1 WHERE owner_id = %d",
					$bytes,
					$owner_id
				)
			);

			$fixed++;
		}

		\WP_CLI::success( sprintf( 'reconciled %d owner(s)', $fixed ) );
	}

	/**
	 * Import FileBird Pro folders and attachment assignments.
	 *
	 * FileBird shares admin folders across users; this plugin keeps folders
	 * per owner, so each attachment's chain is materialised under its author.
	 *
	 * @subcommand import-filebird
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be imported without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sufm import-filebird --dry-run
	 *     wp sufm import-filebird
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function import_filebird( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry = isset( $assoc_args['dry-run'] );
		$fbv = $wpdb->prefix . 'fbv';
		$map = $wpdb->prefix . 'fbv_attachment_folder';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fbv ) );
		if ( ! $exists ) {
			\WP_CLI::error( __( '没有找到 FileBird 数据表（fbv）。', 'sakura-imgopt' ) );
		}

		$services = self::services();
		$folders  = $services['folders'];
		$files    = $services['files'];
		$table    = Schema::table( 'folders' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id,parent,name,created_by FROM {$fbv} ORDER BY parent ASC, ord ASC, id ASC" );
		$rows = is_array( $rows ) ? $rows : array();
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}

		$created = 0;
		$reused  = 0;

		$seen = array();
		$ensure = static function ( int $owner, int $parent, string $name ) use ( &$created, &$reused, &$seen, $folders, $wpdb, $table, $dry ) {
			$key = $owner . '|' . $parent . '|' . $name;
			if ( $dry && isset( $seen[ $key ] ) ) {
				++$reused;
				return $seen[ $key ];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE owner_id=%d AND parent_id=%d AND name=%s AND lifecycle='active' LIMIT 1",
					$owner,
					$parent,
					$name
				)
			);
			if ( $found ) {
				++$reused;
				return (int) $found;
			}
			if ( $dry ) {
				++$created;
				$seen[ $key ] = -1 - count( $seen );
				return $seen[ $key ];
			}
			$folder = $folders->create( $owner, $parent, $name );
			if ( is_wp_error( $folder ) ) {
				\WP_CLI::warning(
					sprintf(
						/* translators: 1: folder name, 2: error message */
						__( '文件夹 %1$s 创建失败：%2$s', 'sakura-imgopt' ),
						$name,
						$folder->get_error_message()
					)
				);
				return 0;
			}
			++$created;
			return (int) $folder->id;
		};

		$chain = static function ( int $owner, int $folder_id ) use ( $by_id, $ensure ) {
			$names = array();
			$guard = 0;
			$cursor = $folder_id;
			while ( $cursor > 0 && isset( $by_id[ $cursor ] ) && $guard++ < 32 ) {
				array_unshift( $names, (string) $by_id[ $cursor ]->name );
				$cursor = (int) $by_id[ $cursor ]->parent;
			}
			$parent = 0;
			foreach ( $names as $raw_name ) {
				$name   = str_replace( array( '\\', '/', ':', '*', '?', '"', '<', '>', '|' ), '·', (string) $raw_name );
				$parent = $ensure( $owner, $parent, $name );
				if ( 0 === $parent ) {
					return 0;
				}
			}
			return $parent;
		};

		// 1) Folders that belong to a real user (created_by > 0) are imported as-is.
		$map_created = array();
		foreach ( $rows as $row ) {
			$owner = (int) $row->created_by;
			if ( $owner <= 0 || ! get_userdata( $owner ) ) {
				continue;
			}
			$parent = (int) $row->parent > 0 ? (int) ( $map_created[ (int) $row->parent ] ?? 0 ) : 0;
			$id     = $ensure( $owner, $parent, (string) $row->name );
			$map_created[ (int) $row->id ] = $id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results( "SELECT folder_id, attachment_id FROM {$map}" );
		$links = is_array( $links ) ? $links : array();

		$assigned = 0;
		$adopted  = 0;
		$skipped  = 0;

		foreach ( $links as $link ) {
			$att  = (int) $link->attachment_id;
			$post = get_post( $att );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				++$skipped;
				continue;
			}

			$owner = (int) $post->post_author;
			if ( $owner <= 0 ) {
				++$skipped;
				continue;
			}

			$folder_id = $chain( $owner, (int) $link->folder_id );
			if ( $folder_id === 0 ) {
				++$skipped;
				continue;
			}

			if ( ! $dry && ! $files->get_by_attachment( $att ) ) {
				if ( ! $services['sync']->adopt( $att ) ) {
					++$skipped;
					continue;
				}
				++$adopted;
			}

			if ( $dry || $folder_id < 0 ) {
				++$assigned;
				continue;
			}

			$row = $files->get_by_attachment( $att );
			if ( ! $row || (int) $row->owner_id !== $owner ) {
				++$skipped;
				continue;
			}

			$wpdb->update(
				Schema::table( 'files' ),
				array(
					'folder_id'  => $folder_id,
					'version'    => (int) $row->version + 1,
					'updated_at' => Support\Time::now(),
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
			++$assigned;
		}

		\WP_CLI::log(
			sprintf(
				/* translators: 1: created, 2: reused, 3: assigned, 4: adopted, 5: skipped */
				__( '文件夹：新建 %1$d，复用 %2$d；附件：归类 %3$d，新纳入 %4$d，跳过 %5$d。', 'sakura-imgopt' ),
				$created,
				$reused,
				$assigned,
				$adopted,
				$skipped
			)
		);

		if ( $dry ) {
			return;
		}

		$services['sync']->start();
		$guard = 0;
		while ( $guard++ < 200 ) {
			$state = $services['sync']->tick( 1500 );
			if ( 'running' !== ( $state['state'] ?? '' ) ) {
				break;
			}
		}

		\WP_CLI::success( __( 'FileBird 数据导入完成。', 'sakura-imgopt' ) );
	}

	/**
	 * Deliver pending theme notifications.
	 *
	 * @param array<string, mixed> $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function notify( array $args, array $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$sent  = self::services()['notifier']->deliver_due( $limit );

		\WP_CLI::success( sprintf( 'delivered %d notification(s)', $sent ) );
	}

	/**
	 * Fill derived columns (mime_group) for rows written before they existed.
	 *
	 * Safe to re-run: it only touches rows whose column is still empty.
	 *
	 * @return void
	 */
	public function backfill(): void {
		$groups = 0;
		$tokens = 0;

		for ( $i = 0; $i < 1000; $i++ ) {
			$done = Repositories\Files::backfill_mime_group( 1000 );
			if ( 0 === $done ) {
				break;
			}
			$groups += $done;
		}

		for ( $i = 0; $i < 1000; $i++ ) {
			$done = Repositories\Files::backfill_tokens( 500 );
			if ( 0 === $done ) {
				break;
			}
			$tokens += $done;
		}

		\WP_CLI::success( sprintf( 'mime_group=%d row(s), tokens=%d row(s)', $groups, $tokens ) );
	}
}
