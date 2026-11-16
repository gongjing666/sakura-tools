<?php
/** Persistent admission control for remote object mutations. */
declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * A reservation has no TTL. Neither a dead PHP process nor an expired queue
 * lease proves that an S3 request has stopped. Uncertain reservations require
 * explicit operator reconciliation; HEAD alone is not proof of quiescence.
 */
final class Remote_Operation_Guard {
	private object $db;
	private string $table;
	private string $scope;
	private array $target;

	public function __construct( string $scope, ?object $db = null, array $target = array() ) {
		global $wpdb;
		if ( null !== $db ) {
			$this->db = $db;
		} else {
			// Never join a caller's transaction: its rollback must not erase a sent request.
			if ( ! class_exists( '\\wpdb' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
				throw new \RuntimeException( 'Remote admission requires an independent WordPress database connection.' );
			}
			if ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) ) {
				throw new \RuntimeException( 'Remote admission requires a verified shared ledger; custom db.php routing is unsupported.' );
			}
			$this->db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$this->db->suppress_errors( true );
			if ( false === $this->db->query( 'SET SESSION autocommit = 1' ) || '1' !== (string) $this->db->get_var( 'SELECT @@session.autocommit' ) ) {
				throw new \RuntimeException( 'Remote admission could not enable durable autocommit.' );
			}
			$this->db->prefix = (string) $wpdb->prefix;
		}
		$this->table = $this->db->prefix . 'sio_sync_operations';
		$this->scope = $scope;
		$this->target = $target;
	}

	/** Same physical object must conflict even across saved profile aliases. */
	public static function scope_for( Options $options ): string {
		$config = $options->all();
		$endpoint = strtolower( rtrim( trim( (string) ( $config['endpoint'] ?? '' ) ), '/' ) );
		return hash( 'sha256', ( $endpoint ?: 'aws-s3' ) . "\n" . (string) ( $config['bucket'] ?? '' ) );
	}

	/**
	 * Dispatch only keys reserved by this invocation, preserving file parallelism.
	 * The callback must return one definitive true/error outcome for every key.
	 *
	 * @param string[] $keys Object keys.
	 * @param string $action upload|delete.
	 * @param callable $dispatch Receives reserved keys, returns per-key outcomes.
	 * @param callable|null $check Revalidate queue ownership after admission.
	 * @return array<string,true|\WP_Error>
	 */
	public function execute( array $keys, string $action, callable $dispatch, ?callable $check = null, array $evidence = array() ): array {
		$tokens = array();
		$results = array();
		foreach ( array_unique( $keys ) as $key ) {
			$token = $this->acquire( $key, $action, (array) ( $evidence[ $key ] ?? array() ) );
			if ( is_wp_error( $token ) ) {
				$results[ $key ] = $token;
			} else {
				$tokens[ $key ] = $token;
			}
		}
		if ( ! $tokens ) {
			return $results;
		}

		// No mutation has been sent: an invalid claim can safely release admission.
		try {
			$valid = null === $check ? true : $check();
		} catch ( \Throwable $e ) {
			$valid = new \WP_Error( 'sio_job_claim_lost', $e->getMessage() );
		}
		if ( true !== $valid ) {
			foreach ( $tokens as $key => $token ) {
				$this->release( $key, $token );
				$results[ $key ] = is_wp_error( $valid ) ? $valid : new \WP_Error( 'sio_job_claim_lost', __( '任务租约已失效，未发送远程操作。', 'sakura-imgopt' ) );
			}
			return $results;
		}

		try {
			$outcomes = $dispatch( array_keys( $tokens ) );
		} catch ( \Throwable $e ) {
			$outcomes = array_fill_keys( array_keys( $tokens ), new \WP_Error( 'sio_remote_exception', $e->getMessage() ) );
		}
		foreach ( $tokens as $key => $token ) {
			$outcome = is_array( $outcomes ) ? ( $outcomes[ $key ] ?? null ) : null;
			if ( true === $outcome && $this->release( $key, $token ) ) {
				$results[ $key ] = true;
				continue;
			}
			$message = is_wp_error( $outcome ) ? $outcome->get_error_message() : __( '远程操作未取得可确认的结果。', 'sakura-imgopt' );
			$this->uncertain( $key, $token, $message );
			$results[ $key ] = new \WP_Error( 'sio_remote_uncertain', __( '该对象的远程操作结果待核对，已暂停后续写入；确认旧请求停止后由管理员解除。', 'sakura-imgopt' ) . ' ' . $message );
		}
		return $results;
	}

	/** @return string|\WP_Error */
	private function acquire( string $key, string $action, array $evidence ) {
		$token = bin2hex( random_bytes( 32 ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $this->db->query( $this->db->prepare(
			"INSERT IGNORE INTO {$this->table} (resource_hash, token, object_key, action, state, created_at, updated_at, context_json) VALUES (%s, %s, %s, %s, 'inflight', %s, %s, %s)",
			$this->resource( $key ), $token, $key, $action, $now, $now, json_encode( array( 'target' => $this->target, 'expected' => $evidence ), JSON_UNESCAPED_SLASHES )
		) );
		if ( 1 !== (int) $inserted ) {
			return new \WP_Error( 'sio_remote_busy', __( '该对象有未结束或待核对的远程操作，未发送新的写入。', 'sakura-imgopt' ) );
		}
		return $token;
	}

	private function resource( string $key ): string {
		return hash( 'sha256', $this->scope . "\n" . $key );
	}

	private function release( string $key, string $token ): bool {
		return 1 === (int) $this->db->query( $this->db->prepare(
			"DELETE FROM {$this->table} WHERE resource_hash = %s AND token = %s AND state = 'inflight'",
			$this->resource( $key ), $token
		) );
	}

	private function uncertain( string $key, string $token, string $message ): void {
		$this->db->query( $this->db->prepare(
			"UPDATE {$this->table} SET state = 'uncertain', last_error = %s, updated_at = %s WHERE resource_hash = %s AND token = %s",
			mb_substr( $message, 0, 250 ), gmdate( 'Y-m-d H:i:s' ), $this->resource( $key ), $token
		) );
	}

	/**
	 * Read-only operator inventory; includes crashes left in inflight state.
	 *
	 * @param int $limit  Rows to return, clamped to 1..100.
	 * @param int $offset Rows to skip.
	 * @return array<int,array<string,string>>
	 */
	public function pending( int $limit = 100, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $this->db->get_results(
			$this->db->prepare(
				"SELECT resource_hash, token, object_key, action, state, created_at, updated_at, last_error, context_json FROM {$this->table} ORDER BY created_at, resource_hash LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			'ARRAY_A'
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Read-only count of held reservations.
	 *
	 * @param string $state Optional state: inflight|uncertain.
	 */
	public function count_reservations( string $state = '' ): int {
		$state = strtolower( trim( $state ) );
		if ( '' !== $state && ! in_array( $state, array( 'inflight', 'uncertain' ), true ) ) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM {$this->table}";
		if ( '' !== $state ) {
			$sql = $this->db->prepare( $sql . ' WHERE state = %s', $state );
		}
		return max( 0, (int) $this->db->get_var( $sql ) );
	}

	/**
	 * Advisory read-only count through the request connection, for metrics.
	 *
	 * The guard itself uses a dedicated autocommit connection; this helper is
	 * intentionally only a gauge and must never authorize or release anything.
	 *
	 * @param string $state Optional state: inflight|uncertain.
	 */
	public static function count_held( string $state = '' ): int {
		global $wpdb;
		$state = strtolower( trim( $state ) );
		if ( '' !== $state && ! in_array( $state, array( 'inflight', 'uncertain' ), true ) ) {
			return 0;
		}
		$table = ( is_object( $wpdb ) ? (string) ( $wpdb->prefix ?? '' ) : '' ) . 'sio_sync_operations';
		$sql   = "SELECT COUNT(*) FROM {$table}";
		if ( '' !== $state ) {
			$sql = $wpdb->prepare( $sql . ' WHERE state = %s', $state );
		}
		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	/**
	 * Explicit operator assertion, NOT timeout-based or HEAD-based reclamation.
	 * Caller must establish that all old senders and provider requests settled.
	 */
	public function acknowledge_settled( string $resource_hash, string $token ): bool {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $resource_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return false;
		}
		return 1 === (int) $this->db->query( $this->db->prepare(
			"DELETE FROM {$this->table} WHERE resource_hash = %s AND token = %s",
			$resource_hash, $token
		) );
	}
}
