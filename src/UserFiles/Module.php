<?php
/**
 * User file space module: wiring for the public, admin and worker surfaces.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles;

use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Repositories\Notifications;
use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Rest\Controller;
use Nboke\UserFiles\Services\Deletion;
use Nboke\UserFiles\Services\Executor;
use Nboke\UserFiles\Services\Notifier;
use Nboke\UserFiles\Services\Quota;
use Nboke\UserFiles\Services\Rate_Limiter;
use Nboke\UserFiles\Services\Sync_Service;
use Nboke\UserFiles\Services\Theme_Limits;
use Nboke\UserFiles\Services\Uploader;
use Sakura\ImageOptimizer\Contracts\Module as Module_Contract;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Owns one service graph; every other class receives its dependencies.
 */
final class Module implements Module_Contract {

	/**
	 * Cron hook for the deletion worker and the notification drain.
	 */
	public const TICK = 'sufm_tick';

	/**
	 * Fast-path cron hook scheduled one second after a confirmed batch.
	 */
	public const DELETE_TICK = 'sufm_delete_tick';

	/**
	 * Registered service graph.
	 *
	 * @var array<string, object>|null
	 */
	private ?array $services = null;

	/**
	 * Shared hook loader, kept so late surfaces register through it too.
	 *
	 * @var Hook_Loader|null
	 */
	private ?Hook_Loader $hooks = null;

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$this->hooks = $hooks;

		$hooks->add_action( 'admin_init', array( $this, 'maybe_install' ) );
		$hooks->add_action( 'admin_init', array( $this, 'maybe_import_theme_limits' ), 20 );
		$hooks->add_action( 'update_option_' . Settings::OPTION, array( $this, 'sync_theme_limits' ), 10, 2 );
		$hooks->add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		$hooks->add_action( 'init', array( $this, 'schedule' ) );
		$hooks->add_action( self::TICK, array( $this, 'tick' ) );
		// The executor schedules this one second after a confirmation; without a
		// listener the event is dropped and deletion waits for the next minute tick.
		$hooks->add_action( self::DELETE_TICK, array( $this, 'delete_tick' ) );

		$services = $this->services();

		// The admin screen hosts the on/off switch, so it must load while the
		// feature is off; everything else stays dark until it is switched on.
		if ( is_admin() && class_exists( 'Nboke\\UserFiles\\Admin\\Page' ) ) {
			( new Admin\Page() )->register_hooks( $hooks );
		}

		if ( is_admin() && class_exists( 'Nboke\\UserFiles\\Admin\\Space_Page' ) ) {
			( new Admin\Space_Page() )->register_hooks( $hooks );
		}

		// The delete guards must stay armed even while the feature is off, or
		// managed attachments become deletable straight from the media library.
		if ( class_exists( 'Nboke\\UserFiles\\Media\\Media_Hooks' ) ) {
			( new Media\Media_Hooks( $services['files'], $services['folders'], $services['requests'], $services['sync'], $services['quota'], $services['events'], $services['outbox'] ) )->register_hooks( $hooks );
		}

		// The REST surface carries the media library folder tree and transfer
		// endpoints as well as the self-service upload routes, so it is always
		// registered; each route decides on its own whether the current user may
		// act (self-service routes also require the entry switch).
		$hooks->add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Only the front-end entry follows the entry switch: which user centre a
		// theme exposes is a theme question, the media library is not.
		// Theme functions (and Zibll's URL helper) only exist after the theme is
		// loaded, so the entry provider is resolved then rather than at
		// plugins_loaded.
		$hooks->add_action( 'after_setup_theme', array( $this, 'register_frontend' ) );

		// Front-end editor panel (read-only picker + toolbar button). Registers
		// its own `wp` / `rest_api_init` hooks and gates itself per request.
		if ( class_exists( 'Nboke\\UserFiles\\Editor\\Module' ) ) {
			( new Editor\Module() )->register_hooks( $hooks );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/CLI.php';
			\WP_CLI::add_command( 'sufm', __NAMESPACE__ . '\\CLI' );
		}
	}

	/**
	 * Recursion guard for the theme option write-back.
	 *
	 * @var bool
	 */
	private static bool $syncing_theme = false;

	/**
	 * Import the active theme's upload guard once, when the plugin has no source
	 * recorded yet or the theme changed since the last import.
	 *
	 * @return void
	 */
	public function maybe_import_theme_limits(): void {
		$theme = Theme_Limits::detect();

		if ( null === $theme ) {
			return;
		}

		$settings = Settings::get();
		$source   = (string) ( $settings['theme_upload_limit_source'] ?? '' );

		if ( $source === $theme['id'] ) {
			return;
		}

		$settings['theme_upload_limit_source']      = $theme['id'];
		$settings['theme_upload_limit_ten_minutes'] = $theme['ten'];
		$settings['theme_upload_limit_day']         = $theme['day'];
		$settings['theme_upload_limit_synced_at']   = \Nboke\UserFiles\Support\Time::now();

		update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
	}

	/**
	 * Write plugin edits of the theme limits back into the theme option.
	 *
	 * @param mixed $old       Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function sync_theme_limits( $old, $new_value ): void {
		if ( self::$syncing_theme || ! is_array( $new_value ) || ! is_array( $old ) ) {
			return;
		}

		$ten = (int) ( $new_value['theme_upload_limit_ten_minutes'] ?? 0 );
		$day = (int) ( $new_value['theme_upload_limit_day'] ?? 0 );

		if ( $ten === (int) ( $old['theme_upload_limit_ten_minutes'] ?? 0 ) && $day === (int) ( $old['theme_upload_limit_day'] ?? 0 ) ) {
			return;
		}

		$theme = Theme_Limits::detect();

		if ( null === $theme ) {
			return;
		}

		self::$syncing_theme = true;

		if ( Theme_Limits::write( $theme['id'], $ten, $day ) ) {
			$new_value['theme_upload_limit_source']    = $theme['id'];
			$new_value['theme_upload_limit_synced_at'] = \Nboke\UserFiles\Support\Time::now();
			update_option( Settings::OPTION, $new_value, false );
		}

		self::$syncing_theme = false;
	}

	/**
	 * Resolve and register the front-end entry point after the theme loaded.
	 */
	public function register_frontend(): void {
		// admin-ajax.php reports is_admin() = true, and Zibll loads tab content
		// through it, so AJAX requests still need the front-end entry.
		$is_frontend = ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );

		if ( ! $is_frontend || ! Settings::entry_enabled() || ! class_exists( 'Nboke\\UserFiles\\Frontend\\Entry' ) ) {
			return;
		}

		if ( null === $this->hooks ) {
			return;
		}

		// The shared loader is already registered by now; it forwards late
		// additions straight to WordPress, so the entry still goes through the
		// one registrar instead of a private second loader.
		( new Frontend\Entry( new Frontend\Tab() ) )->register_hooks( $this->hooks );
	}

	/**
	 * Build (once) and return the service graph.
	 *
	 * @return array<string, object>
	 */
	public function services(): array {
		if ( null !== $this->services ) {
			return $this->services;
		}

		$events      = new Events();
		$files       = new Files();
		$folders     = new Folders();
		$requests    = new Requests( $files, $folders, $events );
		$quota       = new Quota();
		$outbox      = new Notifications();
		$notifier    = new Notifier( $outbox );
		$limiter     = new Rate_Limiter();
		$uploader    = new Uploader( $files, $folders, $quota, $limiter, $events );
		$deletion    = new Deletion( $files, $quota, $events );
		$executor    = new Executor( $files, $folders, $requests, $quota, $notifier, $deletion );
		$sync        = new Sync_Service( $files, $quota );

		$this->services = array(
			'events'   => $events,
			'files'    => $files,
			'folders'  => $folders,
			'requests' => $requests,
			'quota'    => $quota,
			'outbox'   => $outbox,
			'notifier' => $notifier,
			'limiter'  => $limiter,
			'uploader' => $uploader,
			'deletion' => $deletion,
			'executor' => $executor,
			'sync'     => $sync,
		);

		return $this->services;
	}

	/**
	 * Install tables when the version moved.
	 *
	 * @return void
	 */
	public function maybe_install(): void {
		Schema::maybe_install();
		Capabilities::upgrade_media_caps();
		// Roles are a per-site option, not a schema version: grant the defaults
		// once on sites that installed the module before those capabilities
		// existed. After that the matrix card owns them, so a per-request grant
		// would silently undo an operator's choice.
		Capabilities::grant_defaults();
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		$services = $this->services();

		( new Controller(
			$services['files'],
			$services['folders'],
			$services['requests'],
			$services['quota'],
			$services['uploader'],
			$services['executor'],
			$services['events'],
			$services['notifier'],
			$services['sync']
		) )->register();
	}

	/**
	 * Add a one-minute schedule for the worker.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules['sufm_minute'] = array(
			'interval' => 60,
			'display'  => __( '每分钟（文件空间任务）', 'sakura-imgopt' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring tick once.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 60, 'sufm_minute', self::TICK );
		}
	}

	/**
	 * Run one worker tick: deletion queue, outbox, expired leases.
	 *
	 * @return void
	 */
	public function tick(): void {
		// Runs regardless of the entry switch: the worker also keeps the media
		// library usage ledger and folder sizes in sync.

		$services = $this->services();

		$services['quota']->reclaim_expired();
		$services['executor']->tick( 40 );
		$services['notifier']->deliver_due( 20 );
		( new \Nboke\UserFiles\Services\Media_Notifications() )->deliver_due( 10 );
		$services['sync']->tick( 600 );

		// 1.5.0 leftovers: keep filling mime_group in the background until the
		// install-time batches are done, then drop the marker.
		if ( get_option( 'sufm_backfill_pending' ) ) {
			if ( 0 === Files::backfill_mime_group( 1000 ) ) {
				delete_option( 'sufm_backfill_pending' );
			}
		}

		// 1.6.0 search index: drain the install-time backlog and keep sweeping
		// for rows written by bulk import paths that do not tokenise inline.
		if ( Schema::VERSION === (string) get_option( Schema::VERSION_OPTION, '' ) ) {
			if ( 0 === Files::backfill_tokens( 500 ) && get_option( 'sufm_tokens_pending' ) ) {
				delete_option( 'sufm_tokens_pending' );
			}
		}
	}

	/**
	 * Run the deletion worker on the fast path a confirmation schedules.
	 *
	 * @return void
	 */
	public function delete_tick(): void {
		$services = $this->services();

		$services['executor']->tick( 10 );
	}
}
