<?php
/**
 * User file space: generic shortcode entry point.
 *
 * Works with any theme: publish a page containing `[sufm_files]` and the file
 * space renders there with the same markup and assets as the Zibll tab.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Frontend\Providers;

use Nboke\UserFiles\Frontend\Provider;
use Nboke\UserFiles\Frontend\Tab;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Mounts the file space through the `[sufm_files]` shortcode.
 */
final class Generic implements Provider {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'sufm_files';

	/**
	 * Cache key for the embed page lookup.
	 */
	private const PAGE_CACHE = 'sufm_embed_page';

	/**
	 * Renderer shared with the module.
	 *
	 * @var Tab
	 */
	private Tab $tab;

	/**
	 * Constructor.
	 *
	 * @param Tab $tab Renderer.
	 */
	public function __construct( Tab $tab ) {
		$this->tab = $tab;
	}

	/**
	 * Machine id.
	 */
	public function id(): string {
		return 'shortcode';
	}

	/**
	 * Admin label.
	 */
	public function label(): string {
		return __( '通用入口 · 短代码 [sufm_files]', 'sakura-imgopt' );
	}

	/**
	 * Always available; this is the fallback for every other theme.
	 */
	public function supports(): bool {
		return true;
	}

	/**
	 * Register the shortcode and the conditional asset load.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @param Tab         $tab   Renderer.
	 */
	public function register( Hook_Loader $hooks, Tab $tab ): void {
		$this->tab = $tab;

		$hooks->add_action( 'init', array( $this, 'register_shortcode' ) );
		$hooks->add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Register `[sufm_files]`.
	 */
	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function shortcode(): string {
		return $this->tab->render();
	}

	/**
	 * Enqueue assets only on a singular view that embeds the shortcode.
	 */
	public function maybe_enqueue(): void {
		if ( ! $this->is_entry_screen() ) {
			return;
		}

		$this->tab->enqueue_assets();
	}

	/**
	 * Whether the current singular view contains the shortcode.
	 */
	public function is_entry_screen(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return function_exists( 'has_shortcode' ) && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Permalink of the first published page embedding the shortcode, if any.
	 */
	public function entry_url(): string {
		$page_id = $this->find_embed_page();

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );

			return is_string( $url ) ? $url : '';
		}

		return '';
	}

	/**
	 * Locate (and cache) the embed page. Scanning is bounded because sites can
	 * hold thousands of pages and the lookup runs on an admin screen.
	 */
	private function find_embed_page(): int {
		$cached = (int) get_transient( self::PAGE_CACHE );

		if ( $cached > 0 && 'page' === get_post_type( $cached ) && has_shortcode( (string) get_post_field( 'post_content', $cached ), self::SHORTCODE ) ) {
			return $cached;
		}

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $pages as $page_id ) {
			if ( has_shortcode( (string) get_post_field( 'post_content', (int) $page_id ), self::SHORTCODE ) ) {
				set_transient( self::PAGE_CACHE, (int) $page_id, HOUR_IN_SECONDS );

				return (int) $page_id;
			}
		}

		return 0;
	}

	/**
	 * Drop the cached page id (called when settings are saved).
	 */
	public static function flush_page_cache(): void {
		delete_transient( self::PAGE_CACHE );
	}
}
