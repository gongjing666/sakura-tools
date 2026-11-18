<?php
/**
 * One answer to "is this object in the active bucket".
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Sync\Feature;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Sync\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The single entry point for "the active bucket is a valid target for this object".
 *
 * R19.4: before this class the question had three answers in three places -
 * a post-meta fingerprint, a per-key SQL lookup, and a wrapper that tried
 * both. Callers that used the fingerprint alone re-uploaded objects that were
 * already sitting in the bucket (a standby bucket switch); callers that used
 * the index alone paid one query per key. Both now ask here, in a fixed order:
 *
 *   1. fingerprint (memory only, no query) - the fast path;
 *   2. sync index (one batched query for the whole set);
 *   3. no.
 *
 * The answer is cached per bucket identity and object key, so a page with
 * twenty images asks the database once. The cache is invalidated by bumping a
 * generation counter, which costs one small set() instead of scanning keys.
 */
final class Bucket_Confirmation {

	/**
	 * Object cache group.
	 */
	public const CACHE_GROUP = 'sakura_imgopt_bucket';

	/**
	 * Seconds a confirmation stays cached.
	 */
	public const CACHE_TTL = 3600;

	/**
	 * Cache key holding the generation counter.
	 */
	private const GENERATION_KEY = 'generation';

	/**
	 * Options of the bucket being asked about.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Attachment sync state.
	 *
	 * @var Sync_Status
	 */
	private Sync_Status $status;

	/**
	 * Sync index, built on first use.
	 *
	 * @var Index_Repository|null
	 */
	private ?Index_Repository $index;

	/**
	 * Answers already given during this request.
	 *
	 * @var array<string,bool>
	 */
	private array $memo = array();

	/**
	 * Fingerprint of the bucket being asked about, computed once.
	 *
	 * @var string|null
	 */
	private ?string $identity = null;

	/**
	 * Constructor.
	 *
	 * @param Options|null          $options Optional options for tests.
	 * @param Sync_Status|null      $status  Optional state store for tests.
	 * @param Index_Repository|null $index   Optional repository for tests.
	 */
	public function __construct( ?Options $options = null, ?Sync_Status $status = null, ?Index_Repository $index = null ) {
		$this->options = $options ?? new Options();
		$this->status  = $status ?? new Sync_Status();
		$this->index   = $index;
	}

	/**
	 * Sync index, built on first use.
	 *
	 * The repository resolves its table names from $wpdb in its constructor, so
	 * building it eagerly would reach for the database on every request that
	 * only ever needed the fingerprint path - including the REST and CLI paths
	 * where $wpdb is not there at all.
	 */
	private function index(): Index_Repository {
		if ( null === $this->index ) {
			$this->index = new Index_Repository();
		}
		return $this->index;
	}

	/**
	 * Whether every object recorded for an attachment is in the active bucket.
	 *
	 * An attachment with no recorded objects answers no: nothing confirms it,
	 * and guessing would keep a dead CDN URL alive.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function confirms( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		if ( $this->fingerprint_matches( $attachment_id ) ) {
			return true;
		}

		$keys = $this->recorded_keys( $attachment_id );
		if ( ! $keys ) {
			return false;
		}

		foreach ( $this->confirms_many( $keys ) as $confirmed ) {
			if ( ! $confirmed ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether one object key is in the active bucket.
	 *
	 * @param string $key Object key.
	 */
	public function confirms_key( string $key ): bool {
		$key = trim( $key );
		if ( '' === $key ) {
			return false;
		}
		$answers = $this->confirms_many( array( $key ) );
		return (bool) ( $answers[ $key ] ?? false );
	}

	/**
	 * Whether each key is in the active bucket, in one batched lookup.
	 *
	 * Recorded keys come in several spellings (object key, path relative to
	 * the uploads directory, absolute path), so every key is expanded to its
	 * candidates before the query and collapsed back afterwards.
	 *
	 * @param array<int,string> $keys Object keys.
	 * @return array<string,bool> Answer per input key.
	 */
	public function confirms_many( array $keys ): array {
		$keys    = Restore_Plan::normalize( $keys );
		$answers = array();
		foreach ( $keys as $key ) {
			$answers[ $key ] = false;
		}
		if ( ! $keys ) {
			return $answers;
		}

		$missing = array();
		foreach ( $keys as $key ) {
			$cached = $this->cached( $key );
			if ( null === $cached ) {
				$missing[] = $key;
				continue;
			}
			$answers[ $key ] = $cached;
		}
		if ( ! $missing || ! $this->index_available() ) {
			return $answers;
		}

		$prefix     = $this->prefix();
		$candidates = array();
		foreach ( $missing as $key ) {
			foreach ( self::key_candidates( $key, $prefix ) as $candidate ) {
				$candidates[ $candidate ][] = $key;
			}
		}

		$rows = $this->index()->find_many( $this->profiles(), array_keys( $candidates ) );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( Sync_Status::SYNCED !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$candidate = (string) ( $row['__key'] ?? '' );
			foreach ( $candidates[ $candidate ] ?? array() as $key ) {
				$answers[ $key ] = true;
			}
		}

		foreach ( $missing as $key ) {
			$this->remember( $key, $answers[ $key ] );
		}

		return $answers;
	}

	/**
	 * Object keys one recorded key may correspond to.
	 *
	 * Recorded keys are usually the object key itself, but an install that
	 * scanned while uploads lived at the site root wrote them relative to the
	 * uploads directory, and a path rebuilt from disk can be absolute. Every
	 * form is tried so a key written by any generation still resolves.
	 *
	 * @param string $key    Recorded key or path.
	 * @param string $prefix Normalized upload path, empty at the root.
	 * @return array<int,string>
	 */
	public static function key_candidates( string $key, string $prefix ): array {
		$key        = ltrim( wp_normalize_path( trim( $key ) ), '/' );
		$root       = ltrim( untrailingslashit( wp_normalize_path( ABSPATH ) ), '/' );
		$candidates = array( $key );

		if ( '' !== $root && 0 === strpos( $key, $root . '/' ) ) {
			$candidates[] = ltrim( substr( $key, strlen( $root ) ), '/' );
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $prefix || 0 === strpos( $candidate, $prefix . '/' ) ) {
				continue;
			}
			$candidates[] = $prefix . '/' . $candidate;
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Drop every cached answer.
	 *
	 * Called after a scan finishes, and after an upload or delete succeeds: the
	 * generation counter moves, so entries written under the old one are never
	 * read again. One set() instead of a key scan.
	 */
	public static function flush(): void {
		$generation = wp_cache_get( self::GENERATION_KEY, self::CACHE_GROUP );
		$next       = is_numeric( $generation ) ? (int) $generation + 1 : 2;
		wp_cache_set( self::GENERATION_KEY, $next, self::CACHE_GROUP, 0 );
	}

	/**
	 * Whether the fingerprint in post meta already names this bucket.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function fingerprint_matches( int $attachment_id ): bool {
		return Synchronous_Uploader::target_matches( $this->status->target_hash( $attachment_id ), $this->options );
	}

	/**
	 * Object keys recorded for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,string>
	 */
	private function recorded_keys( int $attachment_id ): array {
		return Restore_Plan::normalize( $this->status->objects( $attachment_id ) );
	}

	/**
	 * Whether the index can answer at all.
	 *
	 * Without the schema option the index tables were never installed, and
	 * querying them would be a wasted, failing round trip per attachment.
	 */
	private function index_available(): bool {
		if ( ! $this->profiles() ) {
			return false;
		}
		// The switch that turns the whole sync index off must also turn off
		// the fallback that reads it; otherwise "index disabled" would still
		// cost a query per page.
		if ( ! Feature::enabled() ) {
			return false;
		}
		return Schema::VERSION === (string) get_option( Schema::OPTION, '' );
	}

	/**
	 * Profiles whose index rows may describe this bucket.
	 *
	 * The active row first, then every other saved row that addresses the same
	 * bucket (R19.7). The index is partitioned by profile id, but a bucket can be
	 * described by more than one row: a standby row added for a failover, or a
	 * row re-created with the same parameters after the original was deleted.
	 * Reading only the active row made those objects look absent - the index
	 * confirmed them yesterday, yet the URL dropped to the site domain.
	 *
	 * Order matters: find_many() keeps the row with the lowest rank, so a
	 * confirmation recorded under the active row wins over a stale one from a
	 * sibling.
	 *
	 * @return array<int,string>
	 */
	private function profiles(): array {
		$active = (string) $this->options->active_profile_id();
		$ranked = '' === $active ? array() : array( $active );

		$identity = Bucket_Identity::from_options( $this->options );
		foreach ( $this->options->profiles() as $id => $profile ) {
			$id = (string) $id;
			if ( '' === $id || in_array( $id, $ranked, true ) || ! is_array( $profile ) ) {
				continue;
			}
			if ( Bucket_Identity::from_profile( $profile, $this->options )->same_as( $identity ) ) {
				$ranked[] = $id;
			}
		}

		return $ranked;
	}

	/**
	 * Normalized upload path used to expand key candidates.
	 */
	private function prefix(): string {
		$path = (string) $this->options->get( 'upload_path', '' );
		return '' === $path || '.' === $path ? '' : untrailingslashit( ltrim( wp_normalize_path( $path ), '/' ) );
	}

	/**
	 * Fingerprint of the bucket being asked about.
	 */
	private function identity(): string {
		if ( null === $this->identity ) {
			$this->identity = Bucket_Identity::from_options( $this->options )->hash();
		}
		return $this->identity;
	}

	/**
	 * Cache key of one answer.
	 *
	 * @param string $key Object key.
	 */
	private function cache_key( string $key ): string {
		$generation = wp_cache_get( self::GENERATION_KEY, self::CACHE_GROUP );
		$generation = is_numeric( $generation ) ? (string) (int) $generation : '1';
		return $generation . ':' . md5( $this->identity() . '|' . $key );
	}

	/**
	 * Cached answer, or null when this key has not been asked about yet.
	 *
	 * @param string $key Object key.
	 */
	private function cached( string $key ): ?bool {
		$memo = $this->identity() . '|' . $key;
		if ( array_key_exists( $memo, $this->memo ) ) {
			return $this->memo[ $memo ];
		}

		// A miss returns false, which is indistinguishable from a cached
		// "not in the bucket" answer; only the by-reference $found flag
		// tells the two apart. Reading the value alone would turn every
		// first question into a permanent no.
		$found = false;
		$value = wp_cache_get( $this->cache_key( $key ), self::CACHE_GROUP, false, $found );
		if ( ! $found || ! is_bool( $value ) ) {
			return null;
		}

		$this->memo[ $memo ] = $value;
		return $value;
	}

	/**
	 * Store one answer.
	 *
	 * @param string $key     Object key.
	 * @param bool   $confirmed Whether the bucket holds it.
	 */
	private function remember( string $key, bool $confirmed ): void {
		$this->memo[ $this->identity() . '|' . $key ] = $confirmed;
		wp_cache_set( $this->cache_key( $key ), $confirmed, self::CACHE_GROUP, self::CACHE_TTL );
	}
}
