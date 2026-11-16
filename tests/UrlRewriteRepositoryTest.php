<?php
/**
 * Ledger repository tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Repository;

/**
 * Records the statements the repository issues so placeholder order and
 * batch sizes can be asserted without a database.
 */
final class Url_Rewrite_Test_Wpdb {

	/** @var string */
	public $prefix = 't_';

	/** @var string */
	public $options = 't_options';

	/** @var string */
	public $last_error = '';

	/** @var array<int,string> */
	public $queries = array();

	/** @var mixed */
	private $previous = null;

	/** @var bool */
	private $had_previous = false;

	/**
	 * Put this double in place of the real connection.
	 *
	 * @return void
	 */
	public function install(): void {
		$this->had_previous = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']    = $this;
	}

	/**
	 * Restore whatever was there before.
	 *
	 * @return void
	 */
	public function restore(): void {
		if ( $this->had_previous ) {
			$GLOBALS['wpdb'] = $this->previous;
			return;
		}

		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Minimal prepare() that keeps identifiers and literals apart.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values, or a single array of them.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$parts = preg_split( '/(%[idsf])/', (string) $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$index = 0;

		foreach ( (array) $parts as $part ) {
			if ( '%i' === $part ) {
				$out .= '`' . (string) $args[ $index++ ] . '`';
			} elseif ( '%d' === $part ) {
				$out .= (string) (int) $args[ $index++ ];
			} elseif ( '%f' === $part ) {
				$out .= (string) (float) $args[ $index++ ];
			} elseif ( '%s' === $part ) {
				$out .= "'" . (string) $args[ $index++ ] . "'";
			} else {
				$out .= $part;
			}
		}

		return $out;
	}

	/**
	 * Record a statement.
	 *
	 * @param string $sql Statement.
	 * @return int
	 */
	public function query( $sql ) {
		$this->queries[] = (string) $sql;

		return 1;
	}
}

/**
 * The ledger is the only way back from a rewrite, so its statements are
 * checked for placeholder order and for batching.
 */
final class UrlRewriteRepositoryTest extends TestCase {

	/**
	 * Run a callback with the recording connection installed.
	 *
	 * @param callable $callback Receives the double.
	 * @return void
	 */
	private function with_db( callable $callback ): void {
		$db = new Url_Rewrite_Test_Wpdb();
		$db->install();

		try {
			$callback( $db );
		} finally {
			$db->restore();
		}
	}

	public function test_set_states_binds_table_before_state(): void {
		$this->with_db(
			static function ( Url_Rewrite_Test_Wpdb $db ): void {
				$ok = Url_Rewrite_Repository::set_states( array( 7, 9 ), Url_Rewrite_Repository::STATE_REVERTED );

				self::assertTrue( $ok );
				self::assertCount( 1, $db->queries );
				self::assertSame(
					'UPDATE `t_sio_url_rewrites` SET state = 1 WHERE id IN ( 7, 9 )',
					$db->queries[0]
				);
			}
		);
	}

	public function test_set_states_skips_empty_input(): void {
		$this->with_db(
			static function ( Url_Rewrite_Test_Wpdb $db ): void {
				self::assertTrue( Url_Rewrite_Repository::set_states( array(), Url_Rewrite_Repository::STATE_CONFLICT ) );
				self::assertCount( 0, $db->queries );
			}
		);
	}

	public function test_insert_snapshots_uses_one_statement_per_chunk(): void {
		$this->with_db(
			static function ( Url_Rewrite_Test_Wpdb $db ): void {
				$rows = array();

				for ( $i = 1; $i <= 201; $i++ ) {
					$rows[] = array(
						'run_id'      => 'run',
						'from_host'   => 'bf.nboke.com',
						'to_host'     => 'doge.nboke.com',
						'table_name'  => 't_posts',
						'pk_column'   => 'ID',
						'pk_value'    => (string) $i,
						'column_name' => 'post_content',
						'old_value'   => 'https://bf.nboke.com/a.jpg',
						'new_value'   => 'https://doge.nboke.com/a.jpg',
					);
				}

				self::assertSame( 201, Url_Rewrite_Repository::insert_snapshots( $rows ) );
				self::assertCount( 2, $db->queries );
				self::assertStringStartsWith( 'INSERT INTO `t_sio_url_rewrites` ( run_id,', $db->queries[0] );
				self::assertSame( 200, substr_count( $db->queries[0], "( 'run'," ) );
				self::assertSame( 1, substr_count( $db->queries[1], "( 'run'," ) );
			}
		);
	}

	public function test_insert_snapshots_writes_nothing_without_rows(): void {
		$this->with_db(
			static function ( Url_Rewrite_Test_Wpdb $db ): void {
				self::assertSame( 0, Url_Rewrite_Repository::insert_snapshots( array() ) );
				self::assertCount( 0, $db->queries );
			}
		);
	}
}
