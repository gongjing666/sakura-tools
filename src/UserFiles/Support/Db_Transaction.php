<?php
/**
 * Portable transaction depth tracking.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the plugin's own START TRANSACTION / COMMIT / ROLLBACK calls.
 *
 * `SELECT @@in_transaction` is MariaDB-only: on MySQL 8 the variable does not
 * exist, get_var() returns null, and the "do not nest" guard silently
 * disappears. Counting our own statements is portable, needs no privilege and
 * behaves the same on MariaDB, MySQL, Aurora and TiDB.
 */
final class Db_Transaction {

	/**
	 * Open transactions started through this helper.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Start a transaction and remember it.
	 *
	 * @return void
	 */
	public static function begin(): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		++self::$depth;
	}

	/**
	 * Commit the innermost transaction.
	 *
	 * @return void
	 */
	public static function commit(): void {
		global $wpdb;

		if ( self::$depth < 1 ) {
			return;
		}

		$wpdb->query( 'COMMIT' );
		--self::$depth;
	}

	/**
	 * Roll back the innermost transaction.
	 *
	 * @return void
	 */
	public static function rollback(): void {
		global $wpdb;

		if ( self::$depth < 1 ) {
			return;
		}

		$wpdb->query( 'ROLLBACK' );
		--self::$depth;
	}

	/**
	 * How many transactions this request has open.
	 *
	 * @return int
	 */
	public static function depth(): int {
		return self::$depth;
	}

	/**
	 * Forget the counter (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$depth = 0;
	}
}
