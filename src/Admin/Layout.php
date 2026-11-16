<?php
/**
 * Static card placement for the admin screens.
 *
 * Cards sit in a 12-column CSS grid with explicit rows. Each page declares the
 * reading order, and for every card the row, the column it starts in and how
 * many columns it spans. The server writes those onto the card as plain CSS, so
 * the browser paints the final arrangement on its first frame — no drag engine,
 * no absolute positioning, no script.
 *
 * Rows are explicit because the views render cards in code order, which is not
 * the order they are read in; CSS Grid alone would pack by DOM order and leave
 * gaps wherever the two disagree.
 *
 * This replaced the draggable canvas in 1.27. The arrangement below is the one
 * signed off in the test environment (2026-11-14).
 *
 * @package Sakura_ImageOptimizer
 */

declare(strict_types=1);

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Card placement table and the site-wide gap option.
 */
final class Layout {

	/**
	 * Site-wide card gap option.
	 */
	public const GAPS_OPTION = 'sakura_imgopt_layout_gaps';

	/**
	 * Default card gaps in pixels.
	 */
	public const DEFAULT_GAPS = array(
		'gapX' => 20,
		'gapY' => 20,
	);

	/**
	 * Largest gap the settings screen accepts.
	 */
	public const MAX_GAP = 48;

	/**
	 * Card placement, keyed by layout id.
	 *
	 * - order: the sequence cards are read in.
	 * - cards: card id => array( row, column, span ) in the 12-column grid, both
	 *   1-based. Two cards share a row when their columns + spans fit inside 12.
	 *
	 * @var array<string, array{order: array<int, string>, cards: array<string, array{0:int,1:int,2:int}>}>
	 */
	public const PAGES = array(
		's3' => array(
			'order' => array( 'profiles', 'connection', 'behaviour', 'upload-metrics', 'monitoring' ),
			'cards' => array(
				'profiles' => array( 1, 1, 12 ),
				// Connection owns a full row: it is still the tallest card, and a
				// half-width column forced its fields into one long strip.
				'connection' => array( 2, 1, 12 ),
				'behaviour' => array( 3, 1, 6 ),
				'upload-metrics' => array( 3, 7, 6 ),
				'monitoring' => array( 4, 1, 12 ),
			),
		),
		// Single- and two-card screens. Written out so a card added to the view
		// later lands predictably instead of inheriting a half-width default.
		'deletes' => array(
			'order' => array( 'queue' ),
			'cards' => array(
				'queue' => array( 1, 1, 12 ),
			),
		),
		'files-users' => array(
			'order' => array( 'users' ),
			'cards' => array(
				'users' => array( 1, 1, 12 ),
			),
		),
		'files-log' => array(
			'order' => array( 'log' ),
			'cards' => array(
				'log' => array( 1, 1, 12 ),
			),
		),
		'files-settings' => array(
			'order' => array( 'appearance', 'settings' ),
			'cards' => array(
				'appearance' => array( 1, 1, 12 ),
				'settings' => array( 2, 1, 12 ),
			),
		),
		'system-settings' => array(
			'order' => array( 'runtime', 'engine', 'checks', 'extensions', 'layout' ),
			'cards' => array(
				'runtime' => array( 1, 1, 6 ),
				'engine' => array( 1, 7, 6 ),
				'checks' => array( 2, 1, 6 ),
				'extensions' => array( 2, 7, 6 ),
				'layout' => array( 3, 1, 12 ),
			),
		),
		'image' => array(
			'order' => array( 'thumbs', 'rename', 'compress', 'bulk' ),
			'cards' => array(
				'thumbs' => array( 1, 1, 12 ),
				'rename' => array( 2, 1, 6 ),
				'compress' => array( 2, 7, 6 ),
				'bulk' => array( 3, 1, 12 ),
			),
		),
		'thumb' => array(
			'order' => array( 'switches', 'scopes', 'scenes', 'advanced', 'probe', 'sizes', 'stats', 'rollback', 'cache' ),
			'cards' => array(
				'switches' => array( 1, 1, 6 ),
				'scopes' => array( 1, 7, 6 ),
				'scenes' => array( 2, 1, 6 ),
				'advanced' => array( 2, 7, 6 ),
				'probe' => array( 3, 1, 12 ),
				'sizes' => array( 4, 1, 12 ),
				'stats' => array( 5, 1, 6 ),
				'rollback' => array( 5, 7, 6 ),
				'cache' => array( 6, 1, 12 ),
			),
		),
		'backup' => array(
			'order' => array( 'usage', 'policy', 'access' ),
			'cards' => array(
				'usage' => array( 1, 1, 8 ),
				'policy' => array( 1, 9, 4 ),
				'access' => array( 2, 1, 8 ),
			),
		),
		'sync' => array(
			'order' => array( 'ignored', 'tasks', 'health', 'storage', 'notes', 'reconcile', 'logs' ),
			'cards' => array(
				'ignored' => array( 1, 1, 12 ),
				'tasks' => array( 2, 1, 12 ),
				'health' => array( 3, 1, 6 ),
				'storage' => array( 3, 7, 6 ),
				'notes' => array( 4, 1, 12 ),
				'reconcile' => array( 5, 1, 12 ),
				'logs' => array( 6, 1, 12 ),
			),
		),
		'rewrite' => array(
			'order' => array( 'target', 'scan', 'run', 'history' ),
			'cards' => array(
				'target'  => array( 1, 1, 12 ),
				'scan'    => array( 2, 1, 12 ),
				'run'     => array( 3, 1, 12 ),
				'history' => array( 4, 1, 12 ),
			),
		),
		'cost' => array(
			'order' => array( 'demand', 'table', 'notes' ),
			'cards' => array(
				'demand' => array( 1, 1, 12 ),
				'table'  => array( 2, 1, 12 ),
				'notes'  => array( 3, 1, 12 ),
			),
		),
		'files-queue' => array(
			'order' => array( 'execute', 'queue' ),
			'cards' => array(
				'execute' => array( 1, 1, 12 ),
				'queue' => array( 2, 1, 12 ),
			),
		),
		'files-space' => array(
			'order' => array( 'space_diag', 'space_payment', 'space_entry', 'space_editor', 'space_gate', 'space_vip', 'space_level', 'space_plans', 'space_grants', 'space_reconcile' ),
			'cards' => array(
				'space_diag' => array( 1, 1, 12 ),
				'space_payment' => array( 2, 1, 12 ),
				'space_entry' => array( 3, 1, 6 ),
				'space_editor' => array( 3, 7, 6 ),
				'space_gate' => array( 4, 1, 6 ),
				'space_vip' => array( 4, 7, 6 ),
				'space_level' => array( 5, 1, 12 ),
				'space_plans' => array( 6, 1, 12 ),
				'space_grants' => array( 7, 1, 6 ),
				'space_reconcile' => array( 7, 7, 6 ),
			),
		),

	);

	/**
	 * Site-wide gaps, clamped to the supported range.
	 *
	 * @return array{gapX:int,gapY:int}
	 */
	public static function gaps(): array {
		return self::sanitize_gaps( get_option( self::GAPS_OPTION, self::DEFAULT_GAPS ) );
	}

	/**
	 * Sanitize the site-wide gap option.
	 *
	 * @param mixed $value Raw option value.
	 * @return array{gapX:int,gapY:int}
	 */
	public static function sanitize_gaps( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'gapX' => min( self::MAX_GAP, max( 0, (int) ( $value['gapX'] ?? self::DEFAULT_GAPS['gapX'] ) ) ),
			'gapY' => min( self::MAX_GAP, max( 0, (int) ( $value['gapY'] ?? self::DEFAULT_GAPS['gapY'] ) ) ),
		);
	}

	/**
	 * Layout id of the page being rendered, or '' for screens without cards.
	 *
	 * @return string
	 */
	public static function current_layout(): string {
		$page = Route::current_page();

		return '' !== $page ? self::layout_for_page( $page ) : '';
	}

	/**
	 * Layout id that owns a page id.
	 *
	 * Most pages map to themselves; a few tabs share a module canvas.
	 *
	 * @param string $page Page id.
	 * @return string
	 */
	public static function layout_for_page( string $page ): string {
		$aliases = array(
			'image-rules'        => 'image',
			'image-thumb'        => 'thumb',
			'image-backup'       => 'backup',
			'storage-connection' => 's3',
			'storage-sync'       => 'sync',
			'storage-deletes'    => 'deletes',
			'storage-rewrite'    => 'rewrite',
			'storage-cost'       => 'cost',
			'files-zibll'        => 'files-space',
		);

		return $aliases[ $page ] ?? $page;
	}

	/**
	 * Row, column and span for one card, or null when the default applies.
	 *
	 * @param string $layout Layout id.
	 * @param string $card   Card id.
	 * @return array{0:int,1:int,2:int}|null
	 */
	public static function card( string $layout, string $card ): ?array {
		return self::PAGES[ $layout ]['cards'][ $card ] ?? null;
	}
}
