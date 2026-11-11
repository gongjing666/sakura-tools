<?php
/**
 * Per-user admin card layout storage.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the dashboard layout (order, column span, minimum height) per user.
 * Card gaps are site-wide and live in GAPS_OPTION, not in the per-user layout.
 *
 * The layout is presentation only: cards stay in the DOM and keep their form
 * associations, so a missing or invalid saved layout always falls back to the
 * markup order.
 */
final class Layout implements Module {

	/**
	 * User meta key holding every page layout for the current user.
	 */
	public const META_KEY = '_sakura_imgopt_layout';

	/**
	 * Stored payload version.
	 *
	 * Bumped when the meaning of a stored field changes. 2 = card heights are
	 * minimum heights, so pixel heights written by version 1 are meaningless.
	 * 3 = `top` stores user intent only. 4 = positions are not stored at all:
	 * the board packs every card from the top, so a saved `top` can only leave
	 * a hole behind when a neighbour changes height.
	 */
	public const SCHEMA = 4;

	/**
	 * Nonce action used by the save request.
	 */
	public const NONCE = 'sakura_layout';

	/**
	 * Upper bound for cards in one page layout.
	 */
	public const MAX_CARDS = 40;

	public const MAX_GROUPS = 6;

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
	 * Upper bound for one gap in pixels.
	 */
	public const MAX_GAP = 48;

	/**
	 * Layout shipped as the default for users without a saved layout.
	 *
	 * Only the column span and the starting column are prescribed; heights and
	 * vertical offsets stay at 0 so the canvas flows each card by its content.
	 * The earlier defaults carried pixel heights measured against the clipped
	 * fixed-height model, which under the current min-height semantics would
	 * only leave empty space at the bottom of every card.
	 *
	 * A user's saved meta always wins over this starting point.
	 */
	public const DEFAULT_LAYOUT = array(
		'image' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'thumbs', 'rename', 'compress', 'bulk' ),
					'cards' => array(
						'thumbs'   => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'rename'   => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'compress' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'bulk'     => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'thumb' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'switches', 'advanced', 'probe', 'scenes', 'stats', 'sizes', 'scopes', 'rollback', 'cache' ),
					'cards' => array(
						'switches' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'advanced' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'probe'    => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'scenes'   => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'stats'    => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'sizes'    => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'scopes'   => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'rollback' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'cache'    => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		's3' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'connection', 'behaviour', 'migrate', 'test' ),
					'cards' => array(
						'connection' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'behaviour'  => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'migrate'    => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'test'       => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'sync' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'ignored', 'tasks', 'health', 'storage', 'notes', 'reconcile', 'logs' ),
					'cards' => array(
						'ignored'   => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'tasks'     => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'health'    => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'storage'   => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'notes'     => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'reconcile' => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'logs'      => array( 'span' => 5, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		// The file space is split into four screens, so each tab keeps its own
		// arrangement instead of pushing cards around on a shared canvas.
		'files-queue' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'queue', 'execute' ),
					'cards' => array(
						'queue'   => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'execute' => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'files-users' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'users' ),
					'cards' => array(
						'users' => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'files-settings' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'appearance', 'settings' ),
					'cards' => array(
						'appearance' => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'settings'   => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'files-log' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'log' ),
					'cards' => array(
						'log' => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'files-space' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'space_diag', 'space_gate', 'space_level', 'space_vip', 'space_packages', 'space_points', 'space_grants', 'space_reconcile' ),
					'cards' => array(
						'space_diag'     => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'space_gate'     => array( 'span' => 5, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'space_level'    => array( 'span' => 7, 'minH' => 0, 'h' => 0, 'col' => 6, 'top' => 0 ),
						'space_vip'      => array( 'span' => 5, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'space_packages' => array( 'span' => 7, 'minH' => 0, 'h' => 0, 'col' => 6, 'top' => 0 ),
						'space_points'   => array( 'span' => 5, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'space_grants'   => array( 'span' => 7, 'minH' => 0, 'h' => 0, 'col' => 6, 'top' => 0 ),
						'space_reconcile' => array( 'span' => 7, 'minH' => 0, 'h' => 0, 'col' => 6, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'system-settings' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'runtime', 'engine', 'checks', 'extensions', 'layout' ),
					'cards' => array(
						'runtime'    => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'engine'     => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
						'checks'     => array( 'span' => 12, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'extensions' => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'layout'     => array( 'span' => 6, 'minH' => 0, 'h' => 0, 'col' => 7, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
		'backup' => array(
			'groups' => array(
				'main' => array(
					'order' => array( 'usage', 'policy', 'access' ),
					'cards' => array(
						'usage'  => array( 'span' => 8, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
						'policy' => array( 'span' => 4, 'minH' => 0, 'h' => 0, 'col' => 9, 'top' => 0 ),
						'access' => array( 'span' => 8, 'minH' => 0, 'h' => 0, 'col' => 1, 'top' => 0 ),
					),
				),
			),
			'grid' => 1,
		),
	);

	/**
	 * Register the layout save endpoint.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'wp_ajax_sakura_save_layout', array( $this, 'ajax_save' ) );
	}

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
	 * Saved layouts for the current user, sanitized.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function saved(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = self::raw( $user_id );
		if ( empty( $raw ) ) {
			$raw = self::DEFAULT_LAYOUT;
		} else {
			// Legacy payloads stored absolute heights and offsets from the old
			// clipped-card model. They are cleared once here (a deliberate
			// resize is never touched again) instead of being guessed at on
			// every render, which would undo a card the user just dragged.
			if ( (int) ( $raw['_v'] ?? 1 ) < self::SCHEMA ) {
				$raw = self::migrate( $raw );
				update_user_meta( $user_id, self::META_KEY, $raw );
			}

			// New pages get their default cards even when a layout was saved earlier.
			foreach ( self::DEFAULT_LAYOUT as $page => $definition ) {
				if ( ! isset( $raw[ $page ] ) ) {
					$raw[ $page ] = $definition;
				}
			}

			// Cards added after a layout was saved are appended to the end of the
			// canvas by the engine. The exclusions card belongs at the top of the
			// sync screen, so seed it ahead of the saved order instead of letting
			// it land below the activity log.
			$sync_main = $raw['sync']['groups']['main'] ?? null;

			if ( is_array( $sync_main ) && is_array( $sync_main['cards'] ?? null ) && ! isset( $sync_main['cards']['ignored'] ) ) {
				$order = is_array( $sync_main['order'] ?? null ) ? $sync_main['order'] : array();
				array_unshift( $order, 'ignored' );

				$raw['sync']['groups']['main']['order'] = $order;
				$raw['sync']['groups']['main']['cards'] = array(
					'ignored' => self::DEFAULT_LAYOUT['sync']['groups']['main']['cards']['ignored'],
				) + $sync_main['cards'];

				update_user_meta( $user_id, self::META_KEY, $raw );
			}
		}

		return self::sanitize( $raw );
	}

	/**
	 * Clear heights and offsets left over from the clipped-card model.
	 *
	 * Column span and starting column are kept, so the arrangement still looks
	 * like the user's own; only the measurements are dropped.
	 *
	 * @param array<string, mixed> $raw Raw meta.
	 * @return array<string, mixed>
	 */
	private static function migrate( array $raw ): array {
		$from = (int) ( $raw['_v'] ?? 1 );

		foreach ( $raw as $page => $layout ) {
			if ( '_v' === $page || ! is_array( $layout ) ) {
				continue;
			}

			$groups = isset( $layout['groups'] ) && is_array( $layout['groups'] ) ? $layout['groups'] : array();

			foreach ( $groups as $name => $group ) {
				if ( ! is_array( $group ) || empty( $group['cards'] ) || ! is_array( $group['cards'] ) ) {
					continue;
				}

				foreach ( $group['cards'] as $id => $card ) {
					if ( ! is_array( $card ) ) {
						continue;
					}
					/* Version 1 stored clipped-box heights, meaningless under the
					 * min-height model. Version 2 heights are deliberate user
					 * resizes and are kept. */
					if ( $from < 2 ) {
						$card['h'] = 0;
					}
					$card['top'] = 0;
					$group['cards'][ $id ] = $card;
				}

				$groups[ $name ] = $group;
			}

			$layout['groups'] = $groups;
			$raw[ $page ]     = $layout;
		}

		$raw['_v'] = self::SCHEMA;

		return $raw;
	}

	/**
	 * Raw meta value for one user.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	private static function raw( int $user_id ): array {
		$value = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Normalize every page layout.
	 *
	 * @param array<string, mixed> $raw Raw layouts.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		foreach ( $raw as $page => $layout ) {
			$page = sanitize_key( (string) $page );
			if ( '' === $page || ! is_array( $layout ) ) {
				continue;
			}

			$groups = array();
			$raw_groups = $layout['groups'] ?? array();
			if ( ! is_array( $raw_groups ) || ! $raw_groups ) {
				// Legacy flat shape: treat the page itself as one "main" group.
				$raw_groups = array( 'main' => $layout );
			}

			foreach ( array_slice( $raw_groups, 0, self::MAX_GROUPS, true ) as $name => $group ) {
				$name = self::clean_id( $name );
				if ( '' === $name || ! is_array( $group ) ) {
					continue;
				}

				$order = array();
				foreach ( (array) ( $group['order'] ?? array() ) as $id ) {
					$id = self::clean_id( $id );
					if ( '' !== $id && ! in_array( $id, $order, true ) ) {
						$order[] = $id;
					}
					if ( count( $order ) >= self::MAX_CARDS ) {
						break;
					}
				}

				$cards = array();
				$raw_cards = (array) ( $group['cards'] ?? array() );
				foreach ( array_slice( $raw_cards, 0, self::MAX_CARDS, true ) as $id => $card ) {
					$id = self::clean_id( $id );
					if ( '' === $id || ! is_array( $card ) ) {
						continue;
					}
					$cards[ $id ] = array(
						'span' => min( 12, max( 1, (int) ( $card['span'] ?? 12 ) ) ),
						/* Retired legacy minimum height: kept for old payloads,
						 * never applied as CSS. */
						'minH' => min( 2000, max( 0, (int) ( $card['minH'] ?? 0 ) ) ),
						/* Explicitly resized card height; 0 means content height. */
						'h'    => min( 20000, max( 0, (int) ( $card['h'] ?? 0 ) ) ),
						'col'  => min( 12, max( 0, (int) ( $card['col'] ?? 0 ) ) ),
						'top'  => min( 20000, max( 0, (int) ( $card['top'] ?? 0 ) ) ),
					);
				}

				$groups[ $name ] = array(
					'order' => $order,
					'cards' => $cards,
				);
			}

			$clean[ $page ] = array(
				'groups' => $groups,
				/* Legacy per-page gaps: kept so old payloads still parse; the
				 * UI and CSS read the site-wide GAPS_OPTION instead. */
				'gapX'   => min( 48, max( 0, (int) ( $layout['gapX'] ?? 20 ) ) ),
				'gapY'   => min( 48, max( 0, (int) ( $layout['gapY'] ?? 20 ) ) ),
				'grid'   => empty( $layout['grid'] ) ? 0 : 1,
			);
		}

		return $clean;
	}


	/**
	 * Keep ids lowercase and ASCII-safe.
	 *
	 * @param mixed $value Raw id.
	 * @return string
	 */
	private static function clean_id( $value ): string {
		$id = is_scalar( $value ) ? strtolower( (string) $value ) : '';

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $id );
	}

	/**
	 * Persist one page layout for the current user.
	 */
	public function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		$page = sanitize_key( (string) wp_unslash( $_POST['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( '' === $page ) {
			wp_send_json_error( array( 'message' => __( '未知页面。', 'sakura-imgopt' ) ), 400 );
		}

		$raw    = (string) wp_unslash( $_POST['layout'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$layout = json_decode( $raw, true );
		if ( ! is_array( $layout ) ) {
			wp_send_json_error( array( 'message' => __( '布局数据无法解析。', 'sakura-imgopt' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$all     = self::sanitize( self::raw( $user_id ) );
		$single  = self::sanitize( array( $page => $layout ) );

		if ( empty( $single[ $page ] ) ) {
			wp_send_json_error( array( 'message' => __( '布局数据无效。', 'sakura-imgopt' ) ), 400 );
		}

		$all[ $page ] = $single[ $page ];

		// Stamp the payload so a later load does not mistake this save for the
		// legacy clipped-card layout.
		$all['_v'] = self::SCHEMA;

		update_user_meta( $user_id, self::META_KEY, $all );

		wp_send_json_success( array( 'saved' => true ) );
	}
}
