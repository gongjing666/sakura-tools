<?php
/**
 * User file space: resolves which front-end entry point is active.
 *
 * Adding a theme later means adding one class under Providers/ and listing it
 * in providers(); no other file references a theme function.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Frontend;

use Nboke\UserFiles\Frontend\Providers\Generic;
use Nboke\UserFiles\Frontend\Providers\Zibll;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the first supported provider and exposes its status to the admin UI.
 */
final class Entry {

	/**
	 * Renderer passed to providers.
	 *
	 * @var Tab
	 */
	private Tab $tab;

	/**
	 * Resolved provider, memoised per request.
	 *
	 * @var Provider|null
	 */
	private ?Provider $provider = null;

	/**
	 * Constructor.
	 *
	 * @param Tab|null $tab Optional renderer; one is created when omitted.
	 */
	public function __construct( ?Tab $tab = null ) {
		$this->tab = $tab ?? new Tab();
	}

	/**
	 * Candidate providers, most specific first, generic last.
	 *
	 * @return array<int, Provider>
	 */
	public function providers(): array {
		return array(
			new Zibll( $this->tab ),
			new Generic( $this->tab ),
		);
	}

	/**
	 * The active provider.
	 *
	 * The `sufm_frontend_provider` filter lets a site plug in another theme
	 * adapter without touching this plugin.
	 */
	public function provider(): Provider {
		if ( null !== $this->provider ) {
			return $this->provider;
		}

		$chosen = new Generic( $this->tab );

		foreach ( $this->providers() as $provider ) {
			if ( $provider->supports() ) {
				$chosen = $provider;
				break;
			}
		}

		$this->provider = apply_filters( 'sufm_frontend_provider', $chosen, $this->tab );

		return $this->provider instanceof Provider ? $this->provider : $chosen;
	}

	/**
	 * Register the active entry point.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$this->provider()->register( $hooks, $this->tab );
	}

	/**
	 * Whether the current request renders the file space.
	 */
	public function is_entry_screen(): bool {
		return $this->provider()->is_entry_screen();
	}

	/**
	 * URL of the active entry point ('' when it has not been published yet).
	 */
	public function entry_url(): string {
		return $this->provider()->entry_url();
	}

	/**
	 * Snapshot for the admin screen.
	 *
	 * @return array{theme: string, stylesheet: string, provider: string, label: string, url: string, generic: bool}
	 */
	public function status(): array {
		$provider = $this->provider();
		$theme    = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;

		return array(
			'theme'      => $theme && is_object( $theme ) ? (string) $theme->get( 'Name' ) : '',
			'stylesheet' => function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '',
			'provider'   => $provider->id(),
			'label'      => $provider->label(),
			'url'        => $provider->entry_url(),
			'generic'    => 'shortcode' === $provider->id(),
		);
	}
}
