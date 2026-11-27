<?php
/**
 * Snapshot storage for URL host rewrites.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the rewrite ledger table and the single in-progress scan state option.
 *
 * The ledger is the rollback contract: one row per column value that was
 * changed, carrying both the previous and the written value. Reverting only
 * touches a row while the column still holds the written value, so a manual
 * edit made after the batch is never overwritten.
 */
final class Url_Rewrite_Repository {

	/**
	 * Schema marker option.
	 */
	public const SCHEMA_OPTION = 'sakura_imgopt_url_rewrite_schema';

	/**
	 * State option.
	 */
	public const STATE_OPTION = 'sakura_imgopt_url_rewrite_state';

	/**
	 * Schema version.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Table suffix, resolved through the site's database prefix.
	 */
	public const TABLE_SUFFIX = 'sio_url_rewrites';

	/**
	 * Ledger row waiting for a rollback.
	 */
	public const STATE_PENDING = 0;

	/**
	 * Ledger row already rolled back.
	 */
	public const STATE_REVERTED = 1;

	/**
	 * Ledger row that could not be rolled back because the column changed.
	 */
	public const STATE_CONFLICT = 2;

	/**
	 * Byte budget for one multi-row ledger insert. Large text columns can add up
	 * quickly, so a batch is split before it approaches max_allowed_packet.
	 */
	private const INSERT_BYTES = 1048576;

	/**
	 * Ledger aggregates already read in this request, keyed by limit.
	 *
	 * The rewrite screen asks for the same `runs()` aggregate twice in one page
	 * load — once for the JS config, once for the server-rendered table — and
	 * Query Monitor reports the second SELECT as a duplicate. Every ledger write
	 * below clears the memo, so a scan, a run, a revert or a reset started in the
	 * same request still reads the numbers it just wrote.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private static array $runs_cache = array();

	/**
	 * Fully qualified ledger table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Install the ledger table unless the current schema version is present.
	 */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}

		self::install();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Create or upgrade the ledger table.
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(32) NOT NULL DEFAULT '',
			from_host varchar(191) NOT NULL DEFAULT '',
			to_host varchar(191) NOT NULL DEFAULT '',
			table_name varchar(191) NOT NULL DEFAULT '',
			pk_column varchar(64) NOT NULL DEFAULT '',
			pk_value varchar(191) NOT NULL DEFAULT '',
			column_name varchar(64) NOT NULL DEFAULT '',
			old_value longtext NOT NULL,
			new_value longtext NOT NULL,
			state tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY state (state)
		) $charset;";

		dbDelta( $sql );
	}

	/**
	 * Empty scan state.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'status'       => 'idle',
			'run_id'       => '',
			'from'         => '',
			'to'           => '',
			'path_prefix'  => null,
			'tables'       => array(),
			'table_index'  => 0,
			'column_index' => 0,
			'tasks'        => array(),
			'task_index'   => 0,
			'cursor'       => 0,
			'total_rows'   => 0,
			'done'         => 0,
			'changed'      => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'started_at'   => 0,
			'finished_at'  => 0,
			'updated_at'   => 0,
			'error'        => '',
		);
	}

	/**
	 * Current scan state, always complete.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state(): array {
		$saved = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_replace( self::defaults(), $saved );
	}

	/**
	 * Persist scan state.
	 *
	 * @param array<string,mixed> $state State.
	 */
	public static function save_state( array $state ): void {
		$state['updated_at'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Forget the current scan state; the ledger is intentionally kept.
	 */
	public static function clear_state(): void {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Append one ledger row.
	 *
	 * @param array<string,mixed> $row Column values.
	 * @return int Inserted id, 0 on failure.
	 */
	public static function add_snapshot( array $row ): int {
		global $wpdb;

		$data = array(
			'run_id'      => (string) ( $row['run_id'] ?? '' ),
			'from_host'   => (string) ( $row['from_host'] ?? '' ),
			'to_host'     => (string) ( $row['to_host'] ?? '' ),
			'table_name'  => (string) ( $row['table_name'] ?? '' ),
			'pk_column'   => (string) ( $row['pk_column'] ?? '' ),
			'pk_value'    => (string) ( $row['pk_value'] ?? '' ),
			'column_name' => (string) ( $row['column_name'] ?? '' ),
			'old_value'   => (string) ( $row['old_value'] ?? '' ),
			'new_value'   => (string) ( $row['new_value'] ?? '' ),
			'state'       => self::STATE_PENDING,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		);

		$inserted = $wpdb->insert(
			self::table_name(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		self::flush_runs_cache();

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Append a batch of ledger rows with as few statements as possible.
	 *
	 * Values are escaped here, so the caller must wrap the call in a
	 * transaction when the matching updates need to commit together.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 * @return int Inserted row count, 0 when nothing was written.
	 */
	public static function insert_snapshots( array $rows ): int {
		global $wpdb;

		if ( array() === $rows ) {
			return 0;
		}

		$table  = '`' . str_replace( '`', '', self::table_name() ) . '`';
		$chunks = array();
		$tuples = array();
		$bytes  = 0;

		foreach ( $rows as $row ) {
			$tuple = $wpdb->prepare(
				'( %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s )',
				(string) ( $row['run_id'] ?? '' ),
				(string) ( $row['from_host'] ?? '' ),
				(string) ( $row['to_host'] ?? '' ),
				(string) ( $row['table_name'] ?? '' ),
				(string) ( $row['pk_column'] ?? '' ),
				(string) ( $row['pk_value'] ?? '' ),
				(string) ( $row['column_name'] ?? '' ),
				(string) ( $row['old_value'] ?? '' ),
				(string) ( $row['new_value'] ?? '' ),
				self::STATE_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			);

			$tuple_bytes = strlen( (string) $tuple );

			if ( array() !== $tuples && ( $bytes + $tuple_bytes > self::INSERT_BYTES || count( $tuples ) >= 200 ) ) {
				$chunks[] = $tuples;
				$tuples   = array();
				$bytes    = 0;
			}

			$tuples[] = (string) $tuple;
			$bytes   += $tuple_bytes;
		}

		if ( count( $tuples ) > 0 ) {
			$chunks[] = $tuples;
		}

		$inserted = 0;

		foreach ( $chunks as $chunk ) {
			$sql = 'INSERT INTO ' . $table . ' ( run_id, from_host, to_host, table_name, pk_column, pk_value, column_name, old_value, new_value, state, created_at ) VALUES ' . implode( ', ', $chunk );

			if ( false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) {
				self::flush_runs_cache();

				return 0;
			}

			$inserted += count( $chunk );
		}

		self::flush_runs_cache();

		return $inserted;
	}

	/**
	 * Remove the ledger row of one rewritten value; used when its update failed.
	 *
	 * @param string $run_id      Run id.
	 * @param string $table_name  Rewritten table.
	 * @param string $pk_column   Primary key column.
	 * @param string $pk_value    Primary key value.
	 * @param string $column_name Rewritten column.
	 */
	public static function delete_snapshot_by( string $run_id, string $table_name, string $pk_column, string $pk_value, string $column_name ): void {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			array(
				'run_id'      => $run_id,
				'table_name'  => $table_name,
				'pk_column'   => $pk_column,
				'pk_value'    => $pk_value,
				'column_name' => $column_name,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		self::flush_runs_cache();
	}

	/**
	 * Remove one ledger row; used when the matching update failed.
	 *
	 * @param int $id Ledger id.
	 */
	public static function delete_snapshot( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );

		self::flush_runs_cache();
	}

	/**
	 * Pending ledger rows of one run, ordered by id.
	 *
	 * @param string $run_id   Run id.
	 * @param int    $after_id Last processed ledger id.
	 * @param int    $limit    Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public static function snapshots( string $run_id, int $after_id, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE run_id = %s AND state = %d AND id > %d ORDER BY id ASC LIMIT %d',
				self::table_name(),
				$run_id,
				self::STATE_PENDING,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Set the state of many ledger rows with one statement.
	 *
	 * @param array<int,int> $ids   Ledger ids.
	 * @param int            $state New state.
	 * @return bool True when the rows were updated.
	 */
	public static function set_states( array $ids, int $state ): bool {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return true;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( self::table_name(), $state ), $ids );

		$result = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- %i and %d plus a dynamic %d list; all values are supplied through $args.
				'UPDATE %i SET state = %d WHERE id IN ( ' . $placeholders . ' )',
				...$args
			)
		);

		self::flush_runs_cache();

		return false !== $result;
	}

	/**
	 * Set the state of one ledger row.
	 *
	 * @param int $id    Ledger id.
	 * @param int $state New state.
	 */
	public static function set_state( int $id, int $state ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'state' => $state ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		self::flush_runs_cache();
	}

	/**
	 * Counters for one run.
	 *
	 * @param string $run_id Run id.
	 * @return array{total:int,pending:int,reverted:int,conflicts:int,created_at:string}
	 */
	public static function run_totals( string $run_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total,
					SUM(CASE WHEN state = 0 THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN state = 1 THEN 1 ELSE 0 END) AS reverted,
					SUM(CASE WHEN state = 2 THEN 1 ELSE 0 END) AS conflicts,
					MIN(created_at) AS created_at
				FROM %i WHERE run_id = %s',
				self::table_name(),
				$run_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array( 'total' => 0, 'pending' => 0, 'reverted' => 0, 'conflicts' => 0, 'created_at' => '' );
		}

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'pending'    => (int) ( $row['pending'] ?? 0 ),
			'reverted'   => (int) ( $row['reverted'] ?? 0 ),
			'conflicts'  => (int) ( $row['conflicts'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit Maximum runs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function runs( int $limit = 10 ): array {
		$limit = max( 1, $limit );

		if ( isset( self::$runs_cache[ $limit ] ) ) {
			return self::$runs_cache[ $limit ];
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT run_id, from_host, to_host, COUNT(*) AS total,
					SUM(CASE WHEN state = 0 THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN state = 1 THEN 1 ELSE 0 END) AS reverted,
					SUM(CASE WHEN state = 2 THEN 1 ELSE 0 END) AS conflicts,
					MIN(created_at) AS created_at
				FROM %i
				GROUP BY run_id, from_host, to_host
				ORDER BY MIN(id) DESC
				LIMIT %d',
				self::table_name(),
				$limit
			),
			ARRAY_A
		);

		self::$runs_cache[ $limit ] = is_array( $rows ) ? $rows : array();

		return self::$runs_cache[ $limit ];
	}

	/**
	 * Drop the memoised run aggregates after a ledger write.
	 *
	 * Every method below that inserts, updates or deletes a ledger row must call
	 * this, otherwise the second half of a request would report the numbers from
	 * before its own write.
	 */
	private static function flush_runs_cache(): void {
		self::$runs_cache = array();
	}

	/**
	 * Delete every ledger row of one run.
	 *
	 * @param string $run_id Run id.
	 * @return int Deleted rows.
	 */
	public static function delete_run( string $run_id ): int {
		global $wpdb;

		$deleted = (int) $wpdb->delete( self::table_name(), array( 'run_id' => $run_id ), array( '%s' ) );

		self::flush_runs_cache();

		return $deleted;
	}
}
