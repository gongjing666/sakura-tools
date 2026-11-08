<?php
/**
 * Deferred hook registrar.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Collects hooks first, then registers them with WordPress in one place.
 *
 * This keeps module wiring testable: a test can assert what would be
 * registered without booting WordPress.
 */
final class Hook_Loader {

	/**
	 * Pending actions.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private array $actions = array();

	/**
	 * Pending filters.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	private array $filters = array();

	/**
	 * Whether the queue was already handed to WordPress.
	 *
	 * Hooks added after that point (the front-end entry can only be resolved
	 * once the theme is loaded) are registered immediately instead of being
	 * queued forever.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Queue an action.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( $this->registered ) {
			add_action( $hook, $callback, $priority, $accepted_args );
			return;
		}

		$this->actions[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Queue a filter.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( $this->registered ) {
			add_filter( $hook, $callback, $priority, $accepted_args );
			return;
		}

		$this->filters[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Hand every queued hook to WordPress.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		foreach ( $this->actions as $entry ) {
			add_action( $entry['hook'], $entry['callback'], $entry['priority'], $entry['accepted_args'] );
		}

		foreach ( $this->filters as $entry ) {
			add_filter( $entry['hook'], $entry['callback'], $entry['priority'], $entry['accepted_args'] );
		}
	}

	/**
	 * Introspection for tests and diagnostics.
	 *
	 * @return array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	public function actions(): array {
		return $this->actions;
	}

	/**
	 * Introspection for tests and diagnostics.
	 *
	 * @return array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 */
	public function filters(): array {
		return $this->filters;
	}
}
