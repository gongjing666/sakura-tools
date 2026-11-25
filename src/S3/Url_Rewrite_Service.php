<?php
/**
 * Database scan, rewrite and rollback engine for attachment hosts.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every text column that mentions the old host, rewrites matching rows in
 * time-boxed batches, and replays the ledger when the operator asks to roll back.
 *
 * Scanning and execution are resumable: every step persists its cursor in one
 * option, so a browser tab closed mid-run can pick up where it stopped instead of
 * starting over.
 */
final class Url_Rewrite_Service {

	/**
	 * Seconds one scan request may spend before yielding.
	 */
	public const SCAN_SECONDS = 12.0;

	/**
	 * Seconds one run request may spend before yielding.
	 */
	public const RUN_SECONDS = 12.0;

	/**
	 * Rows handled per request.
	 */
	public const BATCH = 50;

	/**
	 * Upper bound for the client-provided batch size.
	 */
	public const MAX_BATCH = 200;

	/**
	 * Groups whose rows are enabled by default.
	 */
	private const DEFAULT_GROUPS = array( 'content', 'theme' );

	/**
	 * Core content tables.
	 */
	private const CONTENT_TABLES = array( 'posts', 'postmeta', 'termmeta', 'terms', 'term_taxonomy', 'comments', 'commentmeta', 'links' );

	/**
	 * Plugin tables; rewriting them requires an explicit opt-in.
	 */
	private const PLUGIN_PREFIXES = array( 'sio_', 'sufm_', 'ldbc_', 'zse_' );

	/**
	 * Columns that must never be touched, whatever they contain.
	 */
	private const DENY_COLUMNS = array( 'user_pass', 'user_activation_key', 'post_password', 'session_tokens' );

	/**
	 * Options whose value defines site identity, permissions or routing.
	 */
	private const DENY_OPTIONS = array(
		'siteurl',
		'home',
		'active_plugins',
		'recently_activated',
		'user_roles',
		'cron',
		'rewrite_rules',
		'sakura_imgopt_url_rewrite_state',
		'sakura_imgopt_url_rewrite_schema',
	);

	/**
	 * Database error raised by the last scan or fetch query.
	 *
	 * @var string
	 */
	private string $query_error = '';

	/**
	 * Start a fresh scan.
	 *
	 * @param string      $from        Old host or URL.
	 * @param string      $to          New host or URL.
	 * @param string|null $path_prefix Attachment path prefix, or null for host-only mode.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( string $from, string $to, ?string $path_prefix = null ) {
		$current = Url_Rewrite_Repository::get_state();

		if ( 'running' === (string) $current['status'] ) {
			return new \WP_Error( 'sio_url_busy', __( '已有改写任务正在执行，请先完成或重置。', 'sakura-imgopt' ) );
		}

		$rewriter = self::rewriter( $from, $to, $path_prefix );
		if ( $rewriter instanceof \WP_Error ) {
			return $rewriter;
		}

		$tables = self::tables();
		if ( array() === $tables ) {
			return new \WP_Error( 'sio_url_no_tables', __( '没有找到可扫描的数据表。', 'sakura-imgopt' ) );
		}

		$state = Url_Rewrite_Repository::defaults();
		$state['status']     = 'scanning';
		$state['run_id']     = bin2hex( random_bytes( 16 ) );
		$state['from']        = $rewriter->from_host();
		$state['to']          = $rewriter->to_host();
		$state['path_prefix'] = $rewriter->path_prefix();
		$state['tables']      = $tables;
		$state['started_at'] = time();

		Url_Rewrite_Repository::save_state( $state );

		return $this->scan_step();
	}

	/**
	 * Continue the scan for at most SCAN_SECONDS.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function scan_step() {
		$state = Url_Rewrite_Repository::get_state();

		if ( 'scanning' !== (string) $state['status'] ) {
			return $this->summary();
		}

		$rewriter = self::rewriter( (string) $state['from'], (string) $state['to'], self::state_prefix( $state ) );
		if ( $rewriter instanceof \WP_Error ) {
			return $rewriter;
		}

		$deadline     = microtime( true ) + self::SCAN_SECONDS;
		$tables       = self::string_list( $state['tables'] );
		$tasks        = self::task_list( $state['tasks'] );
		$table_index  = max( 0, (int) $state['table_index'] );
		$column_index = max( 0, (int) $state['column_index'] );
		$table_total  = count( $tables );

		while ( $table_index < $table_total ) {
			$table   = $tables[ $table_index ];
			$columns = self::columns( $table );
			$pk      = self::primary_key( $columns );

			if ( '' === $pk ) {
				$table_index++;
				$column_index = 0;
				continue;
			}

			$text_columns = self::text_columns( $columns );
			$column_total = count( $text_columns );

			while ( $column_index < $column_total ) {
				if ( microtime( true ) >= $deadline ) {
					$state['table_index']  = $table_index;
					$state['column_index'] = $column_index;
					$state['tasks']        = $tasks;
					Url_Rewrite_Repository::save_state( $state );

					return $this->summary();
				}

				$current_column = (string) $text_columns[ $column_index ];
				$tasks          = array_merge( $tasks, $this->scan_column( $table, $current_column, $pk, $rewriter ) );
				$column_index++;

				if ( '' !== $this->query_error ) {
					$state['tasks']  = $tasks;
					$state['status'] = 'idle';
					$state['error']  = $this->query_error;
					Url_Rewrite_Repository::save_state( $state );

					return new \WP_Error(
						'sio_url_scan_failed',
						sprintf(
							/* translators: 1: table name, 2: column name, 3: database error message. */
							__( '扫描 %1$s.%2$s 时数据库报错：%3$s', 'sakura-imgopt' ),
							$table,
							$current_column,
							$this->query_error
						)
					);
				}
			}

			$table_index++;
			$column_index = 0;
			$state['table_index']  = $table_index;
			$state['column_index'] = 0;
			$state['tasks']        = $tasks;
			Url_Rewrite_Repository::save_state( $state );
		}

		$state['tasks']       = $tasks;
		$state['total_rows']  = self::sum_rows( $tasks );
		$state['status']      = 'ready';
		$state['finished_at'] = time();
		Url_Rewrite_Repository::save_state( $state );

		return $this->summary();
	}

	/**
	 * Execute one time-boxed batch of the selected tasks.
	 *
	 * @param array<int,int> $selection Task indexes chosen by the operator; used when a run starts.
	 * @param int            $batch     Rows per task step.
	 * @param float          $seconds   Time budget.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_step( array $selection, int $batch = 0, float $seconds = 0.0 ) {
		$state  = Url_Rewrite_Repository::get_state();
		$status = (string) $state['status'];

		if ( ! in_array( $status, array( 'ready', 'running' ), true ) ) {
			return new \WP_Error( 'sio_url_not_ready', __( '请先扫描数据库，再执行改写。', 'sakura-imgopt' ) );
		}

		$rewriter = self::rewriter( (string) $state['from'], (string) $state['to'], self::state_prefix( $state ) );
		if ( $rewriter instanceof \WP_Error ) {
			return $rewriter;
		}

		$tasks = self::task_list( $state['tasks'] );

		if ( 'running' !== $status ) {
			if ( array() === $tasks ) {
				return new \WP_Error( 'sio_url_no_tasks', __( '扫描没有发现需要改写的值。', 'sakura-imgopt' ) );
			}

			$selection = self::index_list( $selection );
			$enabled   = false;

			foreach ( $tasks as $index => $task ) {
				$tasks[ $index ]['enabled'] = in_array( $index, $selection, true );
				$enabled                    = $enabled || $tasks[ $index ]['enabled'];
			}

			if ( ! $enabled ) {
				return new \WP_Error( 'sio_url_no_selection', __( '请至少勾选一个要改写的位置。', 'sakura-imgopt' ) );
			}

			$state['status']     = 'running';
			$state['tasks']      = $tasks;
			$state['task_index'] = 0;
			$state['cursor']     = 0;
			$state['done']       = 0;
			$state['changed']    = 0;
			$state['skipped']    = 0;
			$state['failed']     = 0;
			$state['started_at'] = time();
			$state['finished_at'] = 0;
			Url_Rewrite_Repository::save_state( $state );
		}

		$batch   = $batch > 0 ? min( $batch, self::MAX_BATCH ) : self::BATCH;
		$seconds = $seconds > 0.0 ? min( $seconds, 25.0 ) : self::RUN_SECONDS;
		$deadline = microtime( true ) + $seconds;
		$index    = max( 0, (int) $state['task_index'] );
		$cursor     = max( 0, (int) $state['cursor'] );
		$task_total = count( $tasks );

		while ( $index < $task_total ) {
			if ( empty( $tasks[ $index ]['enabled'] ) ) {
				$index++;
				$cursor = 0;
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$task = $tasks[ $index ];
			if ( ! self::valid_task( $task ) ) {
				$tasks[ $index ]['failed'] = (int) ( $tasks[ $index ]['failed'] ?? 0 ) + (int) ( $task['rows'] ?? 0 ) - (int) ( $task['done'] ?? 0 );
				$state['failed']           = (int) $state['failed'] + 1;
				$index++;
				$cursor = 0;
				continue;
			}

			$rows = $this->fetch_rows( $task, $cursor, $batch, $rewriter );

			if ( '' !== $this->query_error ) {
				$state['tasks'] = $tasks;
				Url_Rewrite_Repository::save_state( $state );

				return new \WP_Error(
					'sio_url_query_failed',
					sprintf(
						/* translators: 1: table name, 2: database error message. */
						__( '读取 %1$s 时数据库报错：%2$s', 'sakura-imgopt' ),
						(string) $task['table'],
						$this->query_error
					)
				);
			}

			if ( array() === $rows ) {
				$tasks[ $index ]['done'] = (int) $task['rows'];
				$index++;
				$cursor = 0;
				continue;
			}

			$writes = array();

			foreach ( $rows as $row ) {
				$cursor = (int) $row['pk_value'];
				$value  = (string) $row['column_value'];
				$result = $rewriter->rewrite_value( $value );

				$tasks[ $index ]['done'] = (int) ( $tasks[ $index ]['done'] ?? 0 ) + 1;
				$state['done']           = (int) $state['done'] + 1;

				if ( ! $result['safe'] || $result['value'] === $value ) {
					$tasks[ $index ]['skipped'] = (int) ( $tasks[ $index ]['skipped'] ?? 0 ) + 1;
					$state['skipped']           = (int) $state['skipped'] + 1;
					continue;
				}

				$writes[] = array(
					'pk'  => $cursor,
					'old' => $value,
					'new' => (string) $result['value'],
				);
			}

			$written = $this->write_batch( $writes, $task, (string) $state['run_id'], $rewriter );

			$tasks[ $index ]['changed'] = (int) ( $tasks[ $index ]['changed'] ?? 0 ) + $written['changed'];
			$tasks[ $index ]['failed']  = (int) ( $tasks[ $index ]['failed'] ?? 0 ) + $written['failed'];
			$state['changed']           = (int) $state['changed'] + $written['changed'];
			$state['failed']            = (int) $state['failed'] + $written['failed'];

			if ( '' !== $written['error'] ) {
				$state['tasks'] = $tasks;
				Url_Rewrite_Repository::save_state( $state );

				return new \WP_Error(
					'sio_url_write_failed',
					sprintf(
						/* translators: 1: table name, 2: database error message. */
						__( '写入 %1$s 时数据库报错：%2$s', 'sakura-imgopt' ),
						(string) $task['table'],
						$written['error']
					)
				);
			}

			if ( count( $rows ) < $batch ) {
				$index++;
				$cursor = 0;
			}

			$state['tasks']      = $tasks;
			$state['task_index'] = $index;
			$state['cursor']     = $cursor;
			Url_Rewrite_Repository::save_state( $state );

			if ( $index >= count( $tasks ) ) {
				break;
			}
		}

		if ( $index >= count( $tasks ) ) {
			$state['status']      = 'done';
			$state['task_index']  = $index;
			$state['cursor']      = 0;
			$state['finished_at'] = time();

			// Rows were written around the object cache, so drop it once the run ends.
			wp_cache_flush();
		}

		$state['tasks'] = $tasks;
		Url_Rewrite_Repository::save_state( $state );

		return $this->summary();
	}

	/**
	 * Roll back one time-boxed batch of a run.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $after   Last processed ledger id.
	 * @param int    $batch   Rows per request.
	 * @param float  $seconds Time budget.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function revert_step( string $run_id, int $after = 0, int $batch = 0, float $seconds = 0.0 ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $run_id ) ) {
			return new \WP_Error( 'sio_url_bad_run', __( '改写记录编号无效。', 'sakura-imgopt' ) );
		}

		global $wpdb;

		$batch    = $batch > 0 ? min( $batch, self::MAX_BATCH ) : self::BATCH;
		$seconds  = $seconds > 0.0 ? min( $seconds, 25.0 ) : self::RUN_SECONDS;
		$deadline = microtime( true ) + $seconds;
		$rows     = Url_Rewrite_Repository::snapshots( $run_id, $after, $batch );
		$reverted = 0;
		$conflicts = 0;
		$failed   = 0;
		$last_id  = $after;
		$error    = '';
		$reverted_ids = array();
		$conflict_ids = array();

		// One transaction per request keeps rollback to a single commit while
		// the per-row guards still detect values edited after the rewrite.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $rows as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$last_id = (int) $row['id'];
			$table   = (string) $row['table_name'];
			$column  = (string) $row['column_name'];
			$pk      = (string) $row['pk_column'];
			$pkval   = (string) $row['pk_value'];

			if ( ! self::valid_identifier( $table ) || ! self::valid_identifier( $column ) || ! self::valid_identifier( $pk ) ) {
				$conflict_ids[] = $last_id;
				$conflicts++;
				continue;
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET %i = %s WHERE %i = %s AND %i = %s',
					$table,
					$column,
					(string) $row['old_value'],
					$pk,
					$pkval,
					$column,
					(string) $row['new_value']
				)
			);

			if ( false === $updated ) {
				$error = (string) $wpdb->last_error;
				break;
			}

			if ( (int) $updated > 0 ) {
				$reverted_ids[] = $last_id;
				$reverted++;
			} else {
				$conflict_ids[] = $last_id;
				$conflicts++;
			}
		}

		if ( '' === $error ) {
			$marked = Url_Rewrite_Repository::set_states( $reverted_ids, Url_Rewrite_Repository::STATE_REVERTED )
				&& Url_Rewrite_Repository::set_states( $conflict_ids, Url_Rewrite_Repository::STATE_CONFLICT );

			if ( ! $marked ) {
				$error = (string) $wpdb->last_error;
			}
		}

		if ( '' !== $error ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error(
				'sio_url_revert_failed',
				sprintf(
					/* translators: 1: run id, 2: database error message. */
					__( '回滚记录 %1$s 时数据库报错：%2$s', 'sakura-imgopt' ),
					$run_id,
					$error
				)
			);
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error(
				'sio_url_revert_failed',
				sprintf(
					/* translators: 1: run id, 2: database error message. */
					__( '回滚记录 %1$s 时数据库报错：%2$s', 'sakura-imgopt' ),
					$run_id,
					(string) $wpdb->last_error
				)
			);
		}

		$count     = count( $rows );
		$done      = $count < $batch || $last_id <= $after;

		if ( $done && ( $reverted > 0 || $conflicts > 0 ) ) {
			// Restored values also bypassed the object cache.
			wp_cache_flush();
		}
		$remaining = Url_Rewrite_Repository::run_totals( $run_id );

		return array(
			'runId'     => $run_id,
			'done'      => $done,
			'reverted'  => $reverted,
			'conflicts' => $conflicts,
			'failed'    => $failed,
			'lastId'    => $last_id,
			'pending'   => (int) $remaining['pending'],
			'total'     => (int) $remaining['total'],
			'runs'      => $this->runs_payload(),
		);
	}

	/**
	 * Preview rows for one task.
	 *
	 * @param int $index Task index.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function preview( int $index ) {
		$state = Url_Rewrite_Repository::get_state();
		$tasks = self::task_list( $state['tasks'] );

		if ( ! isset( $tasks[ $index ] ) ) {
			return new \WP_Error( 'sio_url_bad_task', __( '该改写位置不存在，请重新扫描。', 'sakura-imgopt' ) );
		}

		$rewriter = self::rewriter( (string) $state['from'], (string) $state['to'], self::state_prefix( $state ) );
		if ( $rewriter instanceof \WP_Error ) {
			return $rewriter;
		}

		$task = $tasks[ $index ];
		if ( ! self::valid_task( $task ) ) {
			return new \WP_Error( 'sio_url_bad_task', __( '该改写位置无效，请重新扫描。', 'sakura-imgopt' ) );
		}

		global $wpdb;

		$like = '%' . $wpdb->esc_like( $rewriter->from_host() ) . '%';
		$sql  = 'SELECT %i AS pk_value, SUBSTRING(%i, GREATEST(1, LOCATE(%s, %i) - 80), 240) AS sample FROM %i WHERE %i LIKE %s';
		$args = array(
			(string) $task['pk'],
			(string) $task['column'],
			$rewriter->from_host(),
			(string) $task['column'],
			(string) $task['table'],
			(string) $task['column'],
			$like,
		);

		$needles = self::scope_needles( $rewriter );

		if ( $needles ) {
			$sql .= ' AND (' . implode( ' OR ', array_fill( 0, count( $needles ), '%i LIKE %s' ) ) . ')';

			foreach ( $needles as $needle ) {
				$args[] = (string) $task['column'];
				$args[] = $needle;
			}
		}

		if ( '' !== (string) $task['filter_column'] ) {
			$sql   .= ' AND %i = %s';
			$args[] = (string) $task['filter_column'];
			$args[] = (string) $task['filter_value'];
		}

		$sql .= ' ORDER BY %i ASC LIMIT 3';
		$args[] = (string) $task['pk'];

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error(
				'sio_url_query_failed',
				sprintf(
					/* translators: %s: database error message. */
					__( '读取样例时数据库报错：%s', 'sakura-imgopt' ),
					$wpdb->last_error
				)
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$sample = (string) ( $row['sample'] ?? '' );
			$out[]  = array(
				'pk'  => (string) ( $row['pk_value'] ?? '' ),
				'old' => $sample,
				'new' => $rewriter->rewrite_string( $sample ),
			);
		}

		return array(
			'index'   => $index,
			'label'   => (string) $task['label'],
			'rows'    => (int) $task['rows'],
			'samples' => $out,
		);
	}

	/**
	 * Public state for the view and the browser controller.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$state = Url_Rewrite_Repository::get_state();
		$tasks = self::task_list( $state['tasks'] );
		$out   = array();

		foreach ( $tasks as $index => $task ) {
			$out[] = array(
				'index'   => $index,
				'label'   => (string) ( $task['label'] ?? '' ),
				'table'   => (string) ( $task['table'] ?? '' ),
				'column'  => (string) ( $task['column'] ?? '' ),
				'group'   => (string) ( $task['group'] ?? 'other' ),
				'rows'    => (int) ( $task['rows'] ?? 0 ),
				'done'    => (int) ( $task['done'] ?? 0 ),
				'changed' => (int) ( $task['changed'] ?? 0 ),
				'skipped' => (int) ( $task['skipped'] ?? 0 ),
				'failed'  => (int) ( $task['failed'] ?? 0 ),
				'enabled' => ! empty( $task['enabled'] ),
			);
		}

		$total    = (int) $state['total_rows'];
		$done     = (int) $state['done'];
		$status   = (string) $state['status'];
		$selected = 0;
		$checked  = 0;

		foreach ( $out as $task ) {
			if ( empty( $task['enabled'] ) ) {
				continue;
			}

			$selected += (int) $task['rows'];
			$checked  += min( (int) $task['rows'], (int) $task['done'] );
		}

		$basis = $selected > 0 ? $selected : $total;

		return array(
			'status'     => $status,
			'runId'      => (string) $state['run_id'],
			'from'       => (string) $state['from'],
			'to'         => (string) $state['to'],
			'pathPrefix' => (string) ( $state['path_prefix'] ?? '' ),
			'tasks'      => $out,
			'total'      => $total,
			'selected'   => $selected,
			'done'       => $done,
			'changed'    => (int) $state['changed'],
			'skipped'    => (int) $state['skipped'],
			'failed'     => (int) $state['failed'],
			'progress'   => $basis > 0 ? min( 100, (int) round( $checked / $basis * 100 ) ) : ( 'done' === $status ? 100 : 0 ),
			'startedAt'  => (int) $state['started_at'],
			'finishedAt' => (int) $state['finished_at'],
			'runs'       => $this->runs_payload(),
		);
	}

	/**
	 * Ledger runs, ready for the rollback card.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function runs_payload(): array {
		$out = array();

		foreach ( Url_Rewrite_Repository::runs() as $run ) {
			$created = (string) ( $run['created_at'] ?? '' );
			$stamp   = '' !== $created ? strtotime( $created . ' UTC' ) : false;

			$out[] = array(
				'runId'     => (string) ( $run['run_id'] ?? '' ),
				'from'      => (string) ( $run['from_host'] ?? '' ),
				'to'        => (string) ( $run['to_host'] ?? '' ),
				'total'     => (int) ( $run['total'] ?? 0 ),
				'pending'   => (int) ( $run['pending'] ?? 0 ),
				'reverted'  => (int) ( $run['reverted'] ?? 0 ),
				'conflicts' => (int) ( $run['conflicts'] ?? 0 ),
				'createdAt' => false !== $stamp ? wp_date( 'Y-m-d H:i', $stamp ) : $created,
			);
		}

		return $out;
	}

	/**
	 * Forget the current scan; the ledger stays for rollback.
	 *
	 * @return array<string,mixed>
	 */
	public function reset(): array {
		Url_Rewrite_Repository::clear_state();

		return $this->summary();
	}

	/**
	 * Path prefix saved with the scan state, or null for a legacy host-only scan.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private static function state_prefix( array $state ): ?string {
		if ( ! array_key_exists( 'path_prefix', $state ) || null === $state['path_prefix'] ) {
			return null;
		}

		return (string) $state['path_prefix'];
	}

	/**
	 * LIKE needles that narrow a candidate set to the scoped attachment path.
	 *
	 * Two spellings are returned because JSON stores slashes as "\/". Both are
	 * only a prefilter: the exact URL test happens in Link_Rewriter before a
	 * value is written.
	 *
	 * @param Link_Rewriter $rewriter Scoped rewriter.
	 * @return array<int,string>
	 */
	private static function scope_needles( Link_Rewriter $rewriter ): array {
		if ( ! $rewriter->is_scoped() ) {
			return array();
		}

		global $wpdb;

		$host   = $rewriter->from_host();
		$prefix = (string) $rewriter->path_prefix();
		$plain  = $host . '/' . ( '' !== $prefix ? $prefix . '/' : '' );
		$json   = $host . '\\/' . ( '' !== $prefix ? str_replace( '/', '\\/', $prefix ) . '\\/' : '' );

		return array(
			'%' . $wpdb->esc_like( $plain ) . '%',
			'%' . $wpdb->esc_like( $json ) . '%',
		);
	}

	/**
	 * Build the rewriter or explain why the two hosts cannot be used.
	 *
	 * @param string      $from        Old host.
	 * @param string      $to          New host.
	 * @param string|null $path_prefix Attachment path prefix, or null for host-only mode.
	 * @return Link_Rewriter|\WP_Error
	 */
	private static function rewriter( string $from, string $to, ?string $path_prefix = null ) {
		try {
			return new Link_Rewriter( $from, $to, $path_prefix );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'sio_url_hosts', $exception->getMessage() );
		}
	}

	/**
	 * Every table under the site prefix, except the ledger itself.
	 *
	 * @return array<int,string>
	 */
	private static function tables(): array {
		global $wpdb;

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$out    = array();
		$skip   = Url_Rewrite_Repository::table_name();

		foreach ( (array) $tables as $table ) {
			$table = (string) $table;
			if ( '' !== $table && $table !== $skip ) {
				$out[] = $table;
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * Column metadata for one table.
	 *
	 * @param string $table Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( string $table ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The single integer primary key, or an empty string when rows cannot be walked.
	 *
	 * @param array<int,array<string,mixed>> $columns Column metadata.
	 */
	private static function primary_key( array $columns ): string {
		$keys = array();

		foreach ( $columns as $column ) {
			if ( 'PRI' === (string) ( $column['Key'] ?? '' ) ) {
				$keys[] = $column;
			}
		}

		if ( 1 !== count( $keys ) ) {
			return '';
		}

		$type = strtolower( (string) ( $keys[0]['Type'] ?? '' ) );
		if ( ! preg_match( '/^(tiny|small|medium|big)?int(?:\([0-9]+\))?(?: unsigned)?$/', $type ) ) {
			return '';
		}

		return (string) ( $keys[0]['Field'] ?? '' );
	}

	/**
	 * Text-typed columns.
	 *
	 * @param array<int,array<string,mixed>> $columns Column metadata.
	 * @return array<int,string>
	 */
	private static function text_columns( array $columns ): array {
		$out = array();

		foreach ( $columns as $column ) {
			$name = (string) ( $column['Field'] ?? '' );
			$type = strtolower( (string) ( $column['Type'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext)(?:\(|$)/', $type ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Tasks produced by one column.
	 *
	 * @param string       $table   Table.
	 * @param string       $column  Column.
	 * @param string       $pk      Primary key.
	 * @param Link_Rewriter $rewriter Rewriter.
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_column( string $table, string $column, string $pk, Link_Rewriter $rewriter ): array {
		global $wpdb;

		if ( in_array( $column, self::DENY_COLUMNS, true ) || ! self::valid_identifier( $column ) ) {
			return array();
		}

		$like    = '%' . $wpdb->esc_like( $rewriter->from_host() ) . '%';
		$needles = self::scope_needles( $rewriter );

		$this->query_error = '';

		if ( self::is_options_table( $table ) && 'option_value' === $column ) {
			$where = 'option_value LIKE %s';
			$args  = array( $table, $like );

			if ( $needles ) {
				$where .= ' AND (' . implode( ' OR ', array_fill( 0, count( $needles ), 'option_value LIKE %s' ) ) . ')';

				foreach ( $needles as $needle ) {
					$args[] = $needle;
				}
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT option_name, COUNT(*) AS matched FROM %i WHERE ' . $where . ' GROUP BY option_name', $args ),
				ARRAY_A
			);

			if ( '' !== $wpdb->last_error ) {
				$this->query_error = $wpdb->last_error;

				return array();
			}

			$tasks = array();

			foreach ( (array) $rows as $row ) {
				$name  = (string) ( $row['option_name'] ?? '' );
				$count = (int) ( $row['matched'] ?? 0 );

				if ( '' === $name || $count < 1 || in_array( $name, self::DENY_OPTIONS, true ) ) {
					continue;
				}

				$tasks[] = self::make_task( $table, $column, $pk, 'option_name', $name, $count );
			}

			return $tasks;
		}

		$where = '%i LIKE %s';
		$args  = array( $table, $column, $like );

		if ( $needles ) {
			$where .= ' AND (' . implode( ' OR ', array_fill( 0, count( $needles ), '%i LIKE %s' ) ) . ')';

			foreach ( $needles as $needle ) {
				$args[] = $column;
				$args[] = $needle;
			}
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, $args )
		);

		if ( '' !== $wpdb->last_error ) {
			$this->query_error = $wpdb->last_error;

			return array();
		}

		if ( $count < 1 ) {
			return array();
		}

		return array( self::make_task( $table, $column, $pk, '', '', $count ) );
	}

	/**
	 * Normalize one task row.
	 *
	 * @param string $table         Table.
	 * @param string $column        Column.
	 * @param string $pk            Primary key.
	 * @param string $filter_column Optional row filter column.
	 * @param string $filter_value  Optional row filter value.
	 * @param int    $rows          Matching rows.
	 * @return array<string,mixed>
	 */
	private static function make_task( string $table, string $column, string $pk, string $filter_column, string $filter_value, int $rows ): array {
		$group = self::group_for( $table, $filter_value );
		$short = self::short_table( $table );

		return array(
			'table'         => $table,
			'column'        => $column,
			'pk'            => $pk,
			'filter_column' => $filter_column,
			'filter_value'  => $filter_value,
			'label'         => '' !== $filter_value ? $filter_value : $short . '.' . $column,
			'group'         => $group,
			'rows'          => $rows,
			'done'          => 0,
			'changed'       => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'enabled'       => in_array( $group, self::DEFAULT_GROUPS, true ),
		);
	}

	/**
	 * Group a task belongs to.
	 *
	 * @param string $table        Table.
	 * @param string $filter_value Option name when the options table is split by row.
	 */
	private static function group_for( string $table, string $filter_value ): string {
		$short = self::short_table( $table );

		if ( in_array( $short, self::CONTENT_TABLES, true ) ) {
			return 'content';
		}

		if ( in_array( $short, array( 'users', 'usermeta' ), true ) ) {
			return 'users';
		}

		if ( 'options' === $short ) {
			if ( 0 === strpos( $filter_value, 'zibll' ) ) {
				return 'theme';
			}

			if ( 's3_options' === $filter_value || 0 === strpos( $filter_value, 'sakura_imgopt_' ) ) {
				return 'plugin';
			}

			foreach ( self::PLUGIN_PREFIXES as $prefix ) {
				if ( 0 === strpos( $filter_value, $prefix ) ) {
					return 'plugin';
				}
			}

			return 'other';
		}

		foreach ( self::PLUGIN_PREFIXES as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return 'plugin';
			}
		}

		return 'other';
	}

	/**
	 * Table name without the site prefix.
	 *
	 * @param string $table Table.
	 */
	private static function short_table( string $table ): string {
		global $wpdb;

		return 0 === strpos( $table, $wpdb->prefix ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;
	}

	/**
	 * Whether this is the site's options table.
	 *
	 * @param string $table Table.
	 */
	private static function is_options_table( string $table ): bool {
		global $wpdb;

		return $wpdb->prefix . 'options' === $table;
	}

	/**
	 * Store one batch in the ledger and apply its rewrites.
	 *
	 * The ledger insert and the row updates share one transaction, so a request
	 * interrupted halfway cannot leave a snapshot without its rewrite (or the
	 * reverse). When the bulk insert fails, the batch falls back to the
	 * row-by-row path.
	 *
	 * @param array<int,array{pk:int,old:string,new:string}> $writes   Pending rewrites.
	 * @param array<string,mixed>                            $task     Task.
	 * @param string                                         $run_id   Run id.
	 * @param Link_Rewriter                                  $rewriter Rewriter.
	 * @return array{changed:int,failed:int,error:string}
	 */
	private function write_batch( array $writes, array $task, string $run_id, Link_Rewriter $rewriter ): array {
		global $wpdb;

		$out = array(
			'changed' => 0,
			'failed'  => 0,
			'error'   => '',
		);

		if ( array() === $writes ) {
			return $out;
		}

		$snapshots = array();

		foreach ( $writes as $write ) {
			$snapshots[] = array(
				'run_id'      => $run_id,
				'from_host'   => $rewriter->from_host(),
				'to_host'     => $rewriter->to_host(),
				'table_name'  => (string) $task['table'],
				'pk_column'   => (string) $task['pk'],
				'pk_value'    => (string) $write['pk'],
				'column_name' => (string) $task['column'],
				'old_value'   => $write['old'],
				'new_value'   => $write['new'],
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$stored = Url_Rewrite_Repository::insert_snapshots( $snapshots );

		if ( $stored < 1 ) {
			$wpdb->query( 'ROLLBACK' );

			foreach ( $snapshots as $snapshot ) {
				$snapshot_id = Url_Rewrite_Repository::add_snapshot( $snapshot );

				if ( $snapshot_id < 1 ) {
					$out['failed']++;
					continue;
				}

				$updated = self::update_value( $task, (int) $snapshot['pk_value'], (string) $snapshot['new_value'] );

				if ( false === $updated || 0 === $updated ) {
					Url_Rewrite_Repository::delete_snapshot( $snapshot_id );
					$out['failed']++;
					continue;
				}

				$out['changed']++;
			}

			return $out;
		}

		foreach ( $writes as $write ) {
			$updated = self::update_value( $task, (int) $write['pk'], (string) $write['new'] );

			if ( false === $updated || 0 === $updated ) {
				Url_Rewrite_Repository::delete_snapshot_by(
					$run_id,
					(string) $task['table'],
					(string) $task['pk'],
					(string) $write['pk'],
					(string) $task['column']
				);
				$out['failed']++;
				continue;
			}

			$out['changed']++;
		}

		$committed = $wpdb->query( 'COMMIT' );

		if ( false === $committed ) {
			$out['error'] = (string) $wpdb->last_error;
		}

		return $out;
	}

	/**
	 * Walk one task from a cursor.
	 *
	 * @param array<string,mixed> $task   Task.
	 * @param int           $cursor   Last primary key.
	 * @param int           $limit    Batch size.
	 * @param Link_Rewriter $rewriter Host and optional attachment scope.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_rows( array $task, int $cursor, int $limit, Link_Rewriter $rewriter ): array {
		global $wpdb;

		$this->query_error = '';

		$sql  = 'SELECT %i AS pk_value, %i AS column_value FROM %i WHERE %i > %d AND %i LIKE %s';
		$args = array(
			(string) $task['pk'],
			(string) $task['column'],
			(string) $task['table'],
			(string) $task['pk'],
			$cursor,
			(string) $task['column'],
			'%' . $wpdb->esc_like( $rewriter->from_host() ) . '%',
		);

		$needles = self::scope_needles( $rewriter );

		if ( $needles ) {
			$sql .= ' AND (' . implode( ' OR ', array_fill( 0, count( $needles ), '%i LIKE %s' ) ) . ')';

			foreach ( $needles as $needle ) {
				$args[] = (string) $task['column'];
				$args[] = $needle;
			}
		}

		if ( '' !== (string) $task['filter_column'] ) {
			$sql   .= ' AND %i = %s';
			$args[] = (string) $task['filter_column'];
			$args[] = (string) $task['filter_value'];
		}

		$sql   .= ' ORDER BY %i ASC LIMIT %d';
		$args[] = (string) $task['pk'];
		$args[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			$this->query_error = $wpdb->last_error;

			return array();
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write one rewritten value.
	 *
	 * @param array<string,mixed> $task  Task.
	 * @param int                 $pk    Primary key.
	 * @param string              $value New value.
	 * @return int|false Affected rows, or false on error.
	 */
	private static function update_value( array $task, int $pk, string $value ) {
		global $wpdb;

		$where        = array( (string) $task['pk'] => $pk );
		$where_format = array( '%d' );

		if ( '' !== (string) $task['filter_column'] ) {
			$where[ (string) $task['filter_column'] ] = (string) $task['filter_value'];
			$where_format[]                           = '%s';
		}

		return $wpdb->update(
			(string) $task['table'],
			array( (string) $task['column'] => $value ),
			$where,
			array( '%s' ),
			$where_format
		);
	}

	/**
	 * Whether a task still points at a safe table/column/pk triple.
	 *
	 * @param array<string,mixed> $task Task.
	 */
	private static function valid_task( array $task ): bool {
		foreach ( array( 'table', 'column', 'pk' ) as $key ) {
			if ( ! self::valid_identifier( (string) ( $task[ $key ] ?? '' ) ) ) {
				return false;
			}
		}

		$filter = (string) ( $task['filter_column'] ?? '' );

		return '' === $filter || self::valid_identifier( $filter );
	}

	/**
	 * Identifier guard for values that end up in SQL.
	 *
	 * @param string $name Identifier.
	 */
	private static function valid_identifier( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}

	/**
	 * Normalize a list of integers.
	 *
	 * @param array<int,mixed> $values Raw values.
	 * @return array<int,int>
	 */
	private static function index_list( array $values ): array {
		$out = array();

		foreach ( $values as $value ) {
			$value = (int) $value;
			if ( $value >= 0 ) {
				$out[] = $value;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize a string list.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int,string>
	 */
	private static function string_list( $values ): array {
		$out = array();

		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) ) {
				$out[] = (string) $value;
			}
		}

		return array_values(
			array_filter(
				$out,
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Normalize a task list.
	 *
	 * @param mixed $tasks Raw tasks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function task_list( $tasks ): array {
		$out = array();

		foreach ( (array) $tasks as $task ) {
			if ( is_array( $task ) ) {
				$out[] = $task;
			}
		}

		return $out;
	}

	/**
	 * Sum the row counters of a task list.
	 *
	 * @param array<int,array<string,mixed>> $tasks Tasks.
	 */
	private static function sum_rows( array $tasks ): int {
		$total = 0;

		foreach ( $tasks as $task ) {
			$total += (int) ( $task['rows'] ?? 0 );
		}

		return $total;
	}
}
