<?php
/**
 * Database schema for the user file space module.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every sufm_* table and the schema version marker.
 *
 * The blueprint (user-files-design/plugin-blueprint/STRUCTURE.md) fixes the
 * table names and the columns that carry authority: approvals, revisions,
 * fences and the per-artifact cleanup state. Two additional tables are
 * implementation requirements of PROPOSAL.md §11.2/§11.4 and are documented in
 * DECISIONS.md: persistent rate-limit counters (a browser counter can be
 * discarded) and one delete-batch row per confirmed execution.
 */
final class Schema {

	/**
	 * Current schema version.
	 */
	public const VERSION = '1.6.0';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'sufm_schema_version';

	/**
	 * Table suffix list, without the WordPress prefix.
	 *
	 * @return array<int, string>
	 */
	public static function tables(): array {
		return array(
			'media_operations',
			'folders',
			'files',
			'file_tokens',
			'usage',
			'reservations',
			'artifacts',
			'delete_requests',
			'delete_request_items',
			'delete_batches',
			'jobs',
			'events',
			'notifications',
			'rate_limits',
			'space_grants',
		);
	}

	/**
	 * Fully qualified table name.
	 *
	 * @param string $name Short name, e.g. files.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'sufm_' . $name;
	}

	/**
	 * Install or upgrade the schema.
	 *
	 * Version-gated; Upgrade_Guard may replay it once after an in-place update.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or update every table, then record the version.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$statements = self::statements( $charset );

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		// Derived columns (1.5.0) are filled in bounded batches so an upgrade
		// on a large cabinet never turns into one long table scan.
		$remaining = self::backfill_mime_groups();

		if ( $remaining > 0 ) {
			update_option( 'sufm_backfill_pending', $remaining, false );
		} else {
			delete_option( 'sufm_backfill_pending' );
		}

		// Filename bigrams (1.6.0) are the search index; same bounded backfill
		// contract as mime_group, driven by an id watermark.
		$remaining = self::backfill_tokens();

		if ( $remaining > 0 ) {
			update_option( 'sufm_tokens_pending', $remaining, false );
		} else {
			delete_option( 'sufm_tokens_pending' );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Fill mime_group for rows written before the column existed.
	 *
	 * Bounded per run: 20 batches at most, then the minute tick keeps going so
	 * a large cabinet does not block an admin request.
	 *
	 * @return int Rows still missing a group.
	 */
	private static function backfill_mime_groups(): int {
		global $wpdb;

		if ( ! class_exists( __NAMESPACE__ . '\\Repositories\\Files' ) ) {
			return 0;
		}

		for ( $batch = 0; $batch < 20; $batch++ ) {
			if ( 0 === Repositories\Files::backfill_mime_group( 1000 ) ) {
				break;
			}
		}

		$files = self::table( 'files' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$files} WHERE mime_group = '' AND lifecycle = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fill the filename token index for rows written before it existed.
	 *
	 * Watermark based: one bounded batch per call, then the caller loops.
	 *
	 * @return int Rows still waiting for tokens.
	 */
	private static function backfill_tokens(): int {
		global $wpdb;

		if ( ! class_exists( __NAMESPACE__ . '\\Repositories\\Files' ) ) {
			return 0;
		}

		for ( $batch = 0; $batch < 20; $batch++ ) {
			if ( 0 === Repositories\Files::backfill_tokens( 500 ) ) {
				break;
			}
		}

		$files = self::table( 'files' );
		$after = (int) get_option( 'sufm_tokens_after', 0 );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE id > %d", $after ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every CREATE TABLE statement, one per table.
	 *
	 * @param string $charset Collation clause.
	 * @return array<int, string>
	 */
	public static function statements( string $charset ): array {
		$folders     = self::table( 'folders' );
		$files       = self::table( 'files' );
		$tokens      = self::table( 'file_tokens' );
		$usage       = self::table( 'usage' );
		$reservations = self::table( 'reservations' );
		$artifacts   = self::table( 'artifacts' );
		$requests    = self::table( 'delete_requests' );
		$items       = self::table( 'delete_request_items' );
		$batches     = self::table( 'delete_batches' );
		$jobs        = self::table( 'jobs' );
		$events      = self::table( 'events' );
		$notifications = self::table( 'notifications' );
		$rate_limits  = self::table( 'rate_limits' );
		$space_grants = self::table( 'space_grants' );

		$operations = self::table( 'media_operations' );
		return array(
			"CREATE TABLE {$operations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_id bigint(20) unsigned NOT NULL,
				idempotency_key varchar(128) NOT NULL,
				payload_hash varchar(64) NOT NULL,
				result_json longtext NOT NULL,
				notification_status varchar(20) NOT NULL DEFAULT 'pending',
				attempts int(11) unsigned NOT NULL DEFAULT 0,
				next_at datetime NOT NULL,
				last_error text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY actor_key (actor_id,idempotency_key),
				KEY notification_due (notification_status,next_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$folders} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL DEFAULT '',
				name_key varchar(191) NOT NULL DEFAULT '',
				active_slot bigint(20) unsigned NOT NULL DEFAULT 0,
				lifecycle varchar(20) NOT NULL DEFAULT 'active',
				tree_version bigint(20) unsigned NOT NULL DEFAULT 0,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				pending_request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY owner_parent_name (owner_id,parent_id,name_key,active_slot),
				KEY owner_parent_lifecycle (owner_id,parent_id,lifecycle,id)
			) {$charset};",
			"CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				folder_id bigint(20) unsigned NOT NULL DEFAULT 0,
				display_name varchar(191) NOT NULL DEFAULT '',
				mime_group varchar(16) NOT NULL DEFAULT '',
				source varchar(32) NOT NULL DEFAULT 'userfiles',
				lifecycle varchar(20) NOT NULL DEFAULT 'active',
				storage_state varchar(20) NOT NULL DEFAULT 'local',
				charged_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				manifest_version bigint(20) unsigned NOT NULL DEFAULT 0,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				pending_request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY attachment_id (attachment_id),
				KEY owner_folder_lifecycle (owner_id,folder_id,lifecycle,id),
				KEY owner_lifecycle (owner_id,lifecycle,id),
				KEY owner_group_lifecycle (owner_id,lifecycle,mime_group,id)
			) {$charset};",
			"CREATE TABLE {$tokens} (
				file_id bigint(20) unsigned NOT NULL,
				token varchar(16) NOT NULL,
				PRIMARY KEY  (token,file_id),
				KEY file_id (file_id)
			) {$charset};",
			"CREATE TABLE {$usage} (
				owner_id bigint(20) unsigned NOT NULL,
				used_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				reserved_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (owner_id)
			) {$charset};",
			"CREATE TABLE {$reservations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key varchar(191) NOT NULL DEFAULT '',
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				state varchar(20) NOT NULL DEFAULT 'reserved',
				upload_id varchar(64) NOT NULL DEFAULT '',
				lease_until datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				fence bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY state_lease (state,lease_until)
			) {$charset};",
			"CREATE TABLE {$artifacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				file_id bigint(20) unsigned NOT NULL DEFAULT 0,
				generation int(11) unsigned NOT NULL DEFAULT 0,
				artifact_key varchar(191) NOT NULL DEFAULT '',
				kind varchar(32) NOT NULL DEFAULT 'original',
				logical_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				local_path text NOT NULL,
				remote_context text NOT NULL,
				checksum varchar(64) NOT NULL DEFAULT '',
				cleanup_state varchar(20) NOT NULL DEFAULT 'present',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY artifact_key (artifact_key),
				KEY file_id (file_id,generation)
			) {$charset};",
			"CREATE TABLE {$requests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requester_id bigint(20) unsigned NOT NULL DEFAULT 0,
				idempotency_key varchar(191) NOT NULL DEFAULT '',
				payload_hash varchar(64) NOT NULL DEFAULT '',
				request_revision bigint(20) unsigned NOT NULL DEFAULT 1,
				status varchar(32) NOT NULL DEFAULT 'pending_review',
				reason text NOT NULL,
				reference_notice_version varchar(32) NOT NULL DEFAULT '',
				reference_confirmed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				snapshot_hash varchar(64) NOT NULL DEFAULT '',
				reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				approval_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				revoked_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				revocation_reason text NOT NULL,
				executor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				confirmed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY owner_status (owner_id,status,id),
				KEY status_id (status,id)
			) {$charset};",
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(16) NOT NULL DEFAULT 'file',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_version bigint(20) unsigned NOT NULL DEFAULT 0,
				tree_version bigint(20) unsigned NOT NULL DEFAULT 0,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				manifest_version bigint(20) unsigned NOT NULL DEFAULT 0,
				manifest_snapshot longtext NOT NULL,
				item_hash varchar(64) NOT NULL DEFAULT '',
				charged_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				state varchar(32) NOT NULL DEFAULT 'pending',
				result varchar(32) NOT NULL DEFAULT '',
				job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY request_object (request_id,object_type,object_id),
				KEY request_state (request_id,state,id),
				KEY attachment_id (attachment_id)
			) {$charset};",
			"CREATE TABLE {$batches} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approval_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot_hash varchar(64) NOT NULL DEFAULT '',
				state varchar(20) NOT NULL DEFAULT 'queued',
				total_items bigint(20) unsigned NOT NULL DEFAULT 0,
				done_items bigint(20) unsigned NOT NULL DEFAULT 0,
				failed_items bigint(20) unsigned NOT NULL DEFAULT 0,
				executor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				finished_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				stopped_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY request_id (request_id,id),
				KEY state (state,id)
			) {$charset};",
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(32) NOT NULL DEFAULT 'delete_batch',
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
				dedupe_key varchar(191) NOT NULL DEFAULT '',
				context_json longtext NOT NULL,
				payload_json longtext NOT NULL,
				state varchar(20) NOT NULL DEFAULT 'queued',
				fence bigint(20) unsigned NOT NULL DEFAULT 0,
				lease_until datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				attempts int(11) unsigned NOT NULL DEFAULT 0,
				next_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_error text NOT NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY state_next (state,next_at,id)
			) {$charset};",
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approval_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
				operation_id varchar(64) NOT NULL DEFAULT '',
				subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(48) NOT NULL DEFAULT '',
				result varchar(24) NOT NULL DEFAULT '',
				safe_detail text NOT NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY request_id (request_id,id),
				KEY owner_id (owner_id,id)
			) {$charset};",
			"CREATE TABLE {$notifications} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				request_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_revision bigint(20) unsigned NOT NULL DEFAULT 0,
				dedupe_key varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				payload_summary text NOT NULL,
				theme_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
				attempts int(11) unsigned NOT NULL DEFAULT 0,
				next_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				read_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY owner_id (owner_id,id),
				KEY status_next (status,next_at,id)
			) {$charset};",
			"CREATE TABLE {$rate_limits} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				bucket varchar(48) NOT NULL DEFAULT '',
				period_key varchar(16) NOT NULL DEFAULT '',
				count bigint(20) unsigned NOT NULL DEFAULT 0,
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY owner_bucket_period (owner_id,bucket,period_key),
				KEY expires_at (expires_at)
			) {$charset};",
			"CREATE TABLE {$space_grants} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL,
				grant_key varchar(120) NOT NULL,
				source varchar(16) NOT NULL DEFAULT 'purchase',
				channel varchar(24) NOT NULL DEFAULT '',
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(120) NOT NULL DEFAULT '',
				expires_at datetime NULL DEFAULT NULL,
				state varchar(16) NOT NULL DEFAULT 'pending',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_num varchar(64) NOT NULL DEFAULT '',
				price decimal(12,2) NOT NULL DEFAULT 0.00,
				points int(11) unsigned NOT NULL DEFAULT 0,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error varchar(255) NOT NULL DEFAULT '',
				meta longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY grant_key (grant_key),
				KEY owner_state (owner_id,state,expires_at),
				KEY order_lookup (order_id),
				KEY order_num (order_num),
				KEY state_updated (state,updated_at)
			) {$charset};",
		);
	}
}
