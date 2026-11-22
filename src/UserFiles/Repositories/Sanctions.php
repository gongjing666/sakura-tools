<?php
/**
 * User sanction rows: the authority every enforcer reads.
 *
 * A sanction is a decision, not an action: the enforcement points
 * (`Uploader::handle()`, `Media_Hooks::guard_media_upload()`, and the login
 * filters) read this table and never write it. That keeps "delete the file"
 * (09-删除与结算) and "punish the uploader" independent: the theme can be
 * swapped without losing the decision, and revoking a sanction never restores
 * content that is already gone.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Repositories;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes and expires the sanction rows.
 *
 * Every reader is lazy about expiry: a row whose `ends_at` has passed is
 * reported as not blocking and is flipped to `expired` on the spot, so a
 * sanction stops applying on the first request after it lapses instead of
 * waiting for the minute tick. The tick only finishes the bookkeeping
 * (notification), never decides whether a user is blocked.
 */
final class Sanctions {

	/**
	 * Notified but otherwise unrestricted; the lightest rung of the ladder.
	 */
	public const TYPE_WARN = 'warn';

	/**
	 * May not add files; may still list and delete what is already there.
	 */
	public const TYPE_UPLOAD_BAN = 'upload_ban';

	/**
	 * May not sign in.
	 */
	public const TYPE_LOGIN_BAN = 'login_ban';

	/**
	 * Both of the above.
	 */
	public const TYPE_FULL_BAN = 'full_ban';

	/**
	 * Only blocks this plugin's upload entry points.
	 */
	public const SCOPE_PLUGIN = 'plugin';

	/**
	 * Also removes `upload_files`, which reaches every upload surface.
	 */
	public const SCOPE_SITE = 'site';

	/**
	 * The sanction currently applies.
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * `ends_at` passed; no enforcer reads it as blocking.
	 */
	public const STATE_EXPIRED = 'expired';

	/**
	 * An administrator withdrew it before it lapsed.
	 */
	public const STATE_REVOKED = 'revoked';

	/**
	 * Enforcement lives entirely in this plugin.
	 */
	public const ENFORCER_NATIVE = 'native';

	/**
	 * A mirror row was written into the theme's user-ban metadata.
	 */
	public const ENFORCER_ZIBLL = 'zibll';

	/**
	 * An extension delivered the decision through `sufm_sanction_enforcers`.
	 */
	public const ENFORCER_FILTER = 'filter';

	/**
	 * Types that stop uploads.
	 *
	 * @return array<int, string>
	 */
	public static function upload_types(): array {
		return array( self::TYPE_UPLOAD_BAN, self::TYPE_FULL_BAN );
	}

	/**
	 * Types that stop sign-in.
	 *
	 * @return array<int, string>
	 */
	public static function login_types(): array {
		return array( self::TYPE_LOGIN_BAN, self::TYPE_FULL_BAN );
	}

	/**
	 * Insert one sanction.
	 *
	 * `ends_at` is nullable on purpose: NULL is "permanent" and needs no
	 * sentinel, so `ends_at > now` comparisons never have to special-case the
	 * zero datetime. `state` is derived from the window rather than trusted
	 * from the caller, and `scope` is normalised: only an upload ban can be
	 * plugin-scoped, because a login ban that stayed inside this plugin would
	 * be theatre.
	 *
	 * @param array<string, mixed> $data Sanction fields.
	 * @return int Insert id, 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$type  = $this->normalise_type( (string) ( $data['type'] ?? '' ) );
		$owner = (int) ( $data['owner_id'] ?? 0 );

		if ( '' === $type || $owner <= 0 ) {
			return 0;
		}

		$now     = Time::now();
		$starts  = isset( $data['starts_at'] ) && is_string( $data['starts_at'] ) && '' !== $data['starts_at'] ? (string) $data['starts_at'] : $now;
		$ends    = isset( $data['ends_at'] ) && is_string( $data['ends_at'] ) && '' !== $data['ends_at'] ? (string) $data['ends_at'] : null;
		$scope   = $this->normalise_scope( $type, (string) ( $data['scope'] ?? self::SCOPE_PLUGIN ) );
		$enforce = (string) ( $data['enforcer'] ?? self::ENFORCER_NATIVE );

		$ok = $wpdb->insert(
			Schema::table( 'sanctions' ),
			array(
				'blog_id'      => (int) ( $data['blog_id'] ?? get_current_blog_id() ),
				'owner_id'     => $owner,
				'actor_id'     => (int) ( $data['actor_id'] ?? get_current_user_id() ),
				'operation_id' => substr( (string) ( $data['operation_id'] ?? '' ), 0, 64 ),
				'type'         => $type,
				'scope'        => $scope,
				'reason_code'  => substr( (string) ( $data['reason_code'] ?? '' ), 0, 32 ),
				'note'         => mb_substr( (string) ( $data['note'] ?? '' ), 0, 500 ),
				'starts_at'    => $starts,
				'ends_at'      => $ends,
				'state'        => $this->window_state( $starts, $ends ),
				'source'       => substr( (string) ( $data['source'] ?? 'manual' ), 0, 20 ),
				'enforcer'     => substr( $enforce, 0, 20 ),
				'adapter_ref'  => substr( (string) ( $data['adapter_ref'] ?? '' ), 0, 64 ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Sanction id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'sanctions' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * The blocking row for one owner, if any.
	 *
	 * Called on every upload attempt, so it reads one column from at most one
	 * row. Lapsed rows are flipped here rather than left for the tick: a user
	 * whose ban just ended must be able to upload on their next try.
	 *
	 * @param int                $owner_id Owner.
	 * @param array<int, string> $types    Sanction types that count as blocking.
	 * @return object|null
	 */
	public function blocking( int $owner_id, array $types ): ?object {
		global $wpdb;

		if ( $owner_id <= 0 || ! $types ) {
			return null;
		}

		$table = Schema::table( 'sanctions' );
		$now   = Time::now();

		/* A lapsed row is flipped first, before the read. The read below filters
		 * expired rows out anyway, so checking the deadline a second time on the
		 * returned object would never fire — the row simply would not come back,
		 * and `state` would stay `active` until the tick happened to run. Flipping
		 * by predicate keeps the column honest for everyone else reading it (the
		 * users tab badge, an auditor, the ladder count in `count_since()`).
		 *
		 * Deliberately not limited to `$types`: a row that lapsed is over whatever
		 * its type, and leaving a login ban marked active because the caller only
		 * asked about uploads would be the same bug one column over. */
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, updated_at=%s WHERE owner_id=%d AND state=%s AND ends_at IS NOT NULL AND ends_at<=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Schema.
				self::STATE_EXPIRED,
				$now,
				$owner_id,
				self::STATE_ACTIVE,
				$now
			)
		);

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( array( $owner_id, self::STATE_ACTIVE, $now, $now ), array_values( $types ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name comes from Schema; the placeholder list is built from the closed type set in this class.
		$sql = "SELECT * FROM {$table} WHERE owner_id=%d AND state=%s AND starts_at<=%s AND (ends_at IS NULL OR ends_at>%s) AND type IN ({$placeholders}) ORDER BY id DESC LIMIT 1";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );

		return $row ? $row : null;
	}

	/**
	 * Does this owner have a live sanction of one of those types?
	 *
	 * @param int                $owner_id Owner.
	 * @param array<int, string> $types    Types.
	 * @return bool
	 */
	public function blocks( int $owner_id, array $types ): bool {
		return null !== $this->blocking( $owner_id, $types );
	}

	/**
	 * Does this owner still need to be able to sign in?
	 *
	 * @param int $owner_id Owner.
	 * @return bool
	 */
	public function blocks_login( int $owner_id ): bool {
		return $this->blocks( $owner_id, self::login_types() );
	}

	/**
	 * Does this owner still need to be able to upload?
	 *
	 * @param int $owner_id Owner.
	 * @return bool
	 */
	public function blocks_upload( int $owner_id ): bool {
		return $this->blocks( $owner_id, self::upload_types() );
	}

	/**
	 * Live sanctions, newest first, for one owner.
	 *
	 * @param int $owner_id Owner.
	 * @param int $limit    Row cap.
	 * @return array<int, object>
	 */
	public function active_for( int $owner_id, int $limit = 10 ): array {
		global $wpdb;

		$table = Schema::table( 'sanctions' );
		$now   = Time::now();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id=%d AND state=%s AND starts_at<=%s AND (ends_at IS NULL OR ends_at>%s) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				self::STATE_ACTIVE,
				$now,
				$now,
				max( 1, min( 100, $limit ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Live sanctions for a whole page of owners in one query.
	 *
	 * The users tab renders 25 rows; asking per row would be 25 extra queries
	 * on a screen that already batches its aggregates.
	 *
	 * @param array<int, int> $owner_ids Owner ids.
	 * @return array<int, object> Keyed by owner id, one row each.
	 */
	public function active_map( array $owner_ids ): array {
		global $wpdb;

		$owner_ids = array_values( array_filter( array_map( 'intval', $owner_ids ) ) );

		if ( ! $owner_ids ) {
			return array();
		}

		$table        = Schema::table( 'sanctions' );
		$now          = Time::now();
		$placeholders = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$args         = array_merge( array( self::STATE_ACTIVE, $now, $now ), $owner_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from Schema; placeholder list built from the int array above.
		$sql = "SELECT * FROM {$table} WHERE state=%s AND starts_at<=%s AND (ends_at IS NULL OR ends_at>%s) AND owner_id IN ({$placeholders}) ORDER BY id DESC";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$map = array();

		foreach ( (array) $rows as $row ) {
			$owner = (int) $row->owner_id;

			// Newest first, so the first row per owner is the live one.
			if ( ! isset( $map[ $owner ] ) ) {
				$map[ $owner ] = $row;
			}
		}

		return $map;
	}

	/**
	 * How many sanctions this owner received inside a window.
	 *
	 * The ladder counts decisions, not files: one takedown batch that removes
	 * 200 files yields one row, so the count cannot be inflated by file count.
	 *
	 * @param int $owner_id Owner.
	 * @param int $days     Window in days.
	 * @return int
	 */
	public function count_since( int $owner_id, int $days ): int {
		global $wpdb;

		if ( $owner_id <= 0 || $days <= 0 ) {
			return 0;
		}

		$table  = Schema::table( 'sanctions' );
		$cutoff = Time::in( -1 * $days * 86400 );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND created_at>=%s AND state<>%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$cutoff,
				self::STATE_REVOKED
			)
		);
	}

	/**
	 * Flip one lapsed row to `expired`; only the winner reports true.
	 *
	 * Conditional update is the same authority pattern the deletion kernel uses
	 * for settlements: two requests that notice the same expiry cannot both
	 * send the owner a notification.
	 *
	 * @param int $id Sanction id.
	 * @return bool True when this call performed the transition.
	 */
	public function expire( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$table = Schema::table( 'sanctions' );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, updated_at=%s WHERE id=%d AND state=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATE_EXPIRED,
				Time::now(),
				$id,
				self::STATE_ACTIVE
			)
		);

		return is_int( $affected ) && 1 === $affected;
	}

	/**
	 * Withdraw an active sanction.
	 *
	 * `revoked_at` and `revoked_by` are part of the row, not a separate log: an
	 * auditor reading one row must see who undid what and when.
	 *
	 * @param int $id  Sanction id.
	 * @param int $by  Acting user.
	 * @return bool True when this call performed the transition.
	 */
	public function revoke( int $id, int $by ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$table = Schema::table( 'sanctions' );
		$now   = Time::now();

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, revoked_at=%s, revoked_by=%d, updated_at=%s WHERE id=%d AND state=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATE_REVOKED,
				$now,
				$by,
				$now,
				$id,
				self::STATE_ACTIVE
			)
		);

		return is_int( $affected ) && 1 === $affected;
	}

	/**
	 * Stamp the mirror reference once the theme accepted the ban.
	 *
	 * @param int    $id     Sanction id.
	 * @param string $ref    Adapter reference.
	 * @param string $by     Enforcer id.
	 * @return void
	 */
	public function mark_mirrored( int $id, string $ref, string $by ): void {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		$wpdb->update(
			Schema::table( 'sanctions' ),
			array(
				'enforcer'    => substr( $by, 0, 20 ),
				'adapter_ref' => substr( $ref, 0, 64 ),
				'updated_at'  => Time::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Stamp the notification marker.
	 *
	 * @param int $id       Sanction id.
	 * @param int $channel  0 for the notice, 1 for the expiry reminder.
	 * @return void
	 */
	public function mark_notified( int $id, int $channel = 0 ): void {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		$column = 0 === $channel ? 'notified_at' : 'expired_notified_at';

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column name is one of two literals above.
				"UPDATE " . Schema::table( 'sanctions' ) . " SET {$column}=%s, updated_at=%s WHERE id=%d",
				Time::now(),
				Time::now(),
				$id
			)
		);
	}

	/**
	 * Lapsed rows whose ending has not been reported to the owner yet.
	 *
	 * Two shapes qualify, and both matter:
	 *
	 *   - still `active` because nobody has read the row since the deadline
	 *     (`blocking()` flips it while answering an upload attempt);
	 *   - already `expired` because a read flipped it, but the closing notice was
	 *     never sent.
	 *
	 * The second shape is why this cannot filter on `state='active'` alone: every
	 * blocked upload flips the row first, so an active user's ban would be marked
	 * expired by their own upload attempt and the "your sanction ended" message
	 * would never be queued. `expired_notified_at` is the real watermark.
	 *
	 * Bounded so a site that was offline for a month does not scan the whole
	 * table on one request.
	 *
	 * @param int $limit Row cap.
	 * @return array<int, object>
	 */
	public function due_to_expire( int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'sanctions' );
		$zero  = Time::zero();
		$now   = Time::now();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state IN (%s,%s) AND ends_at IS NOT NULL AND ends_at<=%s AND (expired_notified_at=%s OR expired_notified_at IS NULL) ORDER BY ends_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATE_ACTIVE,
				self::STATE_EXPIRED,
				$now,
				$zero,
				max( 1, min( 200, $limit ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Active rows grouped by owner for one operator screen.
	 *
	 * @param int $limit Row cap.
	 * @return array<int, object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'sanctions' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, min( 200, $limit ) )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Is this a known sanction type?
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_type( string $type ): bool {
		return in_array( $type, array( self::TYPE_WARN, self::TYPE_UPLOAD_BAN, self::TYPE_LOGIN_BAN, self::TYPE_FULL_BAN ), true );
	}

	/**
	 * Coerce a submitted type to a known one; empty string when unknown.
	 *
	 * @param string $type Candidate.
	 * @return string
	 */
	private function normalise_type( string $type ): string {
		$type = sanitize_key( $type );

		return self::is_type( $type ) ? $type : '';
	}

	/**
	 * Only an upload ban may be plugin-scoped.
	 *
	 * A login ban that "only applies to this plugin" would stop nothing: the
	 * whole point of that rung is that the person cannot get in at all.
	 *
	 * @param string $type  Sanction type.
	 * @param string $scope Requested scope.
	 * @return string
	 */
	private function normalise_scope( string $type, string $scope ): string {
		if ( self::TYPE_UPLOAD_BAN !== $type ) {
			return self::SCOPE_SITE;
		}

		return self::SCOPE_SITE === $scope ? self::SCOPE_SITE : self::SCOPE_PLUGIN;
	}

	/**
	 * State implied by the sanction window at insert time.
	 *
	 * @param string      $starts Start timestamp.
	 * @param string|null $ends   End timestamp, null for permanent.
	 * @return string
	 */
	private function window_state( string $starts, ?string $ends ): string {
		$now = Time::now();

		if ( $starts > $now ) {
			// Scheduled in the future: still active as a row, but its readers
			// filter on starts_at, so it does not apply yet.
			return self::STATE_ACTIVE;
		}

		if ( null !== $ends && $ends <= $now ) {
			return self::STATE_EXPIRED;
		}

		return self::STATE_ACTIVE;
	}
}
