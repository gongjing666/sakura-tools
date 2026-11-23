<?php
/**
 * REST endpoints for the image screens.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\Backup_Payload;
use Sakura\ImageOptimizer\Admin\Backup_Settings;
use Sakura\ImageOptimizer\Admin\Image_Payload;
use Sakura\ImageOptimizer\Admin\Image_Report;
use Sakura\ImageOptimizer\Admin\Image_Settings;
use Sakura\ImageOptimizer\Admin\Stats_Payload;
use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Backup\Exposure_Probe;
use Sakura\ImageOptimizer\Admin\Thumb_Payload;
use Sakura\ImageOptimizer\Admin\Thumb_Settings;
use Sakura\ImageOptimizer\Image\Crop_Config_Scanner;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the image screens over /wp-json instead of options.php.
 *
 * Each write is scoped to one card, the same boundary guard_options() gave the
 * form path, and every value still runs through the sanitizers WordPress knows
 * from register_setting().
 */
final class Image_Controller {

	/**
	 * Namespace shared by the plugin's REST routes.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Cards the rules tab can save.
	 */
	private const RULES_CARDS = array( 'thumbs', 'rename', 'compress', 'bulk' );

	/**
	 * Register the routes.
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/image/rules',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_rules' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/rules/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'scan_rules' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/rules/cards/(?P<card>[a-z]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_rules_card' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'card' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::RULES_CARDS,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/image/thumb',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_thumb' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/thumb/cards/(?P<card>[a-z]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_thumb_card' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'card' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Thumb_Settings::CARDS,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/image/backup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_backup' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/image/backup/cards/access',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_backup_policy' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		foreach ( array( 'refresh', 'clean', 'purge', 'probe' ) as $backup_action ) {
			register_rest_route(
				self::NS,
				'/image/backup/' . $backup_action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'backup_' . $backup_action ),
					'permission_callback' => array( self::class, 'can_manage' ),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/image/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_stats' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 25,
						'enum'    => Stats_Payload::PER_PAGE,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/image/stats/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'reset_stats' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /image/rules.
	 *
	 * @return WP_REST_Response
	 */
	public static function read_rules(): WP_REST_Response {
		return rest_ensure_response( ( new Image_Payload() )->build( 'rules' ) );
	}

	/**
	 * POST /image/rules/scan — re-read the theme, plugins and wp-config.php.
	 *
	 * @return WP_REST_Response
	 */
	public static function scan_rules(): WP_REST_Response {
		( new Crop_Config_Scanner() )->scan_and_store();
		$scan = Crop_Config_Scanner::stored();

		if ( null === $scan ) {
			return new WP_REST_Response(
				array( 'message' => __( '扫描结果保存失败，请检查数据库写入权限。', 'sakura-imgopt' ) ),
				500
			);
		}

		$settings = new Settings_Repository();

		$warnings = array();
		if ( isset( $scan['warnings'] ) && is_array( $scan['warnings'] ) ) {
			foreach ( $scan['warnings'] as $warning ) {
				if ( is_array( $warning ) ) {
					$warnings[] = (string) ( $warning['message'] ?? '' );
				}
			}
		}

		return rest_ensure_response(
			array(
				'rows'         => Image_Report::size_rows( $settings->disabled_sizes(), $scan ),
				'scan_summary' => Image_Report::scan_summary_text( $scan ),
				'wp_config'    => Image_Report::wp_config_text( $scan ),
				'warnings'     => array_values( array_filter( $warnings ) ),
			)
		);
	}

	/**
	 * GET /image/thumb.
	 *
	 * @return WP_REST_Response
	 */
	public static function read_thumb(): WP_REST_Response {
		return rest_ensure_response( ( new Thumb_Payload() )->build() );
	}

	/**
	 * POST /image/thumb/cards/{card}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_thumb_card( WP_REST_Request $request ): WP_REST_Response {
		$card   = (string) $request->get_param( 'card' );
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$settings = new Thumb_Settings();
		$result   = $settings->save( $card, $fields );

		return rest_ensure_response(
			array(
				'card'   => $result['card'],
				'values' => $result['values'],
				'notice' => $result['notice'],
			)
		);
	}

	/**
	 * POST /image/rules/cards/{card}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_rules_card( WP_REST_Request $request ): WP_REST_Response {
		$card   = (string) $request->get_param( 'card' );
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$settings = new Image_Settings( new Settings_Repository() );
		$result   = $settings->save_rules_card( $card, $fields );

		$payload = array(
			'card'   => $result['card'],
			'values' => $result['values'],
			'notice' => $result['notice'],
		);

		if ( 'thumbs' === $result['card'] ) {
			// The size table's 生成状态 column comes from the scan report, so it
			// has to be recomputed after the write — otherwise a freshly ticked
			// row keeps saying 正常生成 until the next page load.
			$payload['section'] = ( new Image_Payload( new Settings_Repository() ) )->thumbs();
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /image/backup.
	 *
	 * @return WP_REST_Response
	 */
	public static function read_backup(): WP_REST_Response {
		return rest_ensure_response( ( new Backup_Payload() )->build() );
	}

	/**
	 * POST /image/backup/cards/access — the cleanup policy card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_backup_policy( WP_REST_Request $request ): WP_REST_Response {
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$result = ( new Backup_Settings() )->save( $fields );

		return rest_ensure_response(
			array(
				'card'   => $result['card'],
				'values' => $result['values'],
				'notice' => $result['notice'],
				'usage'  => self::usage(),
			)
		);
	}

	/**
	 * POST /image/backup/refresh — recount the backup directory.
	 *
	 * @return WP_REST_Response
	 */
	public static function backup_refresh(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'usage'   => self::usage( true ),
				'message' => __( '已刷新。', 'sakura-imgopt' ),
			)
		);
	}

	/**
	 * POST /image/backup/clean — delete the backups past their retention.
	 *
	 * @return WP_REST_Response
	 */
	public static function backup_clean(): WP_REST_Response {
		$result = ( new Backup_Repository() )->clean_expired();

		return rest_ensure_response(
			array(
				'usage'   => self::usage( true ),
				'freed'   => size_format( (int) ( $result['bytes'] ?? 0 ), 2 ),
				'message' => sprintf(
					/* translators: 1: number of deleted backups, 2: freed space. */
					__( '已清理 %1$d 个过期备份，释放 %2$s。', 'sakura-imgopt' ),
					(int) ( $result['count'] ?? 0 ),
					size_format( (int) ( $result['bytes'] ?? 0 ), 2 )
				),
			)
		);
	}

	/**
	 * POST /image/backup/purge — delete every backup.
	 *
	 * @return WP_REST_Response
	 */
	public static function backup_purge(): WP_REST_Response {
		$result = ( new Backup_Repository() )->purge_all();

		return rest_ensure_response(
			array(
				'usage'   => self::usage( true ),
				'freed'   => size_format( (int) ( $result['bytes'] ?? 0 ), 2 ),
				'message' => sprintf(
					/* translators: 1: number of deleted backups, 2: freed space. */
					__( '已清空 %1$d 个备份文件，释放 %2$s。', 'sakura-imgopt' ),
					(int) ( $result['count'] ?? 0 ),
					size_format( (int) ( $result['bytes'] ?? 0 ), 2 )
				),
			)
		);
	}

	/**
	 * POST /image/backup/probe — is the backup directory reachable from outside?
	 *
	 * @return WP_REST_Response
	 */
	public static function backup_probe(): WP_REST_Response {
		try {
			$probe = ( new Exposure_Probe() )->run( true );
		} catch ( \Exception $exception ) {
			return new WP_REST_Response( array( 'message' => $exception->getMessage() ), 500 );
		}

		return rest_ensure_response(
			array(
				'probe' => $probe,
				'usage' => self::usage( true ),
			)
		);
	}

	/**
	 * GET /image/stats.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function read_stats( WP_REST_Request $request ): WP_REST_Response {
		$payload = ( new Stats_Payload( new Stats_Repository() ) )->build(
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		return rest_ensure_response( $payload );
	}

	/**
	 * POST /image/stats/reset — zero the aggregate totals, keep the history.
	 *
	 * @return WP_REST_Response
	 */
	public static function reset_stats(): WP_REST_Response {
		$stats = new Stats_Repository();
		$stats->reset();

		return rest_ensure_response(
			array(
				'payload' => ( new Stats_Payload( $stats ) )->build(),
				'notice'  => array(
					'tone'    => 'ok',
					'kicker'  => 'STATISTICS / RESET',
					'title'   => __( '统计已重置', 'sakura-imgopt' ),
					'message' => __( '汇总数字已清零，每张图片的处理记录仍保留在下面的历史里。', 'sakura-imgopt' ),
				),
			)
		);
	}

	/**
	 * Backup usage in the shape the island expects.
	 *
	 * @param bool $force Skip the transient cache.
	 * @return array<string,mixed>
	 */
	private static function usage( bool $force = false ): array {
		$usage = ( new Backup_Repository() )->get_usage( $force );
		$bytes = (int) ( $usage['bytes'] ?? 0 );
		$count = (int) ( $usage['count'] ?? 0 );

		return array(
			'bytes'      => $bytes,
			'size'       => size_format( $bytes, 2 ),
			'count'      => $count,
			'count_text' => number_format_i18n( $count ),
		);
	}
}
