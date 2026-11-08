<?php
/**
 * Rolling 14-day rewrite statistics.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Buffers counters for one request and persists them on shutdown.
 */
final class Stats {

	public const OPTION         = Settings::STATS_OPTION;
	public const RETENTION_DAYS = 14;

	/**
	 * Short-lived lock that serialises the read-merge-write of the counters.
	 */
	private const LOCK_OPTION = 'sakura_imgopt_stats_lock';

	/**
	 * Seconds after which a lock left behind by a dying request is taken over.
	 */
	private const LOCK_TTL = 30;

	/**
	 * In-request counters.
	 *
	 * @var array<string,int>
	 */
	private array $buffer = array();

	/**
	 * Whether the shutdown flush is registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Record one decision.
	 *
	 * @param string $kind    rewrite|skip.
	 * @param string $context Context.
	 * @param string $size    Size name.
	 */
	public function record( string $kind, string $context, string $size ): void {
		$key = $kind . '|' . $context . '|' . $size;
		$this->buffer[ $key ] = ( $this->buffer[ $key ] ?? 0 ) + 1;

		if ( ! $this->registered && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( $this, 'flush' ), 99 );
			$this->registered = true;
		}
	}

	/**
	 * Persist buffered counters.
	 */
	public function flush(): void {
		if ( array() === $this->buffer ) {
			return;
		}

		// Two concurrent requests used to read the same counters and write back
		// their own totals, so the slower writer erased the faster one's work.
		// A short option lock (with a stale takeover) makes the read-merge-write
		// sequence exclusive; when the lock cannot be taken the old best-effort
		// path still runs.
		$locked = $this->acquire_lock();

		if ( $locked && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$days   = isset( $stored['days'] ) && is_array( $stored['days'] ) ? $stored['days'] : array();
		$today  = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$bucket = isset( $days[ $today ] ) && is_array( $days[ $today ] ) ? $days[ $today ] : array();

		foreach ( $this->buffer as $key => $count ) {
			$bucket[ $key ] = (int) ( $bucket[ $key ] ?? 0 ) + (int) $count;
		}
		$days[ $today ] = $bucket;

		if ( count( $days ) > self::RETENTION_DAYS ) {
			ksort( $days );
			$days = array_slice( $days, -self::RETENTION_DAYS, null, true );
		}

		update_option(
			self::OPTION,
			array(
				'days'    => $days,
				'updated' => time(),
			),
			false
		);

		$this->buffer = array();

		if ( $locked ) {
			$this->release_lock();
		}
	}

	/**
	 * Take the short-lived stats lock.
	 *
	 * @return bool Whether this request owns the lock.
	 */
	private function acquire_lock(): bool {
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}

		$now   = time();
		$stale = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $stale > 0 && ( $now - $stale ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
		}

		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		usleep( 50000 );

		return (bool) add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Release the stats lock.
	 */
	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Aggregate stored counters.
	 *
	 * @return array{rewrite:int,skip:int,by_size:array<string,int>,by_context:array<string,int>,days:int}
	 */
	public static function summary(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$days   = isset( $stored['days'] ) && is_array( $stored['days'] ) ? $stored['days'] : array();

		$rewrite     = 0;
		$skip        = 0;
		$by_size     = array();
		$by_context  = array();

		foreach ( $days as $bucket ) {
			if ( ! is_array( $bucket ) ) {
				continue;
			}
			foreach ( $bucket as $key => $count ) {
				$parts = explode( '|', (string) $key );
				if ( 3 !== count( $parts ) ) {
					continue;
				}
				[ $kind, $context, $size ] = $parts;
				$count = (int) $count;
				if ( 'rewrite' === $kind ) {
					$rewrite += $count;
					$by_size[ $size ] = ( $by_size[ $size ] ?? 0 ) + $count;
				} else {
					$skip += $count;
				}
				$by_context[ $context ] = ( $by_context[ $context ] ?? 0 ) + $count;
			}
		}

		return array(
			'rewrite'    => $rewrite,
			'skip'       => $skip,
			'by_size'    => $by_size,
			'by_context' => $by_context,
			'days'       => count( $days ),
		);
	}
}
