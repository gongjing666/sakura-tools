<?php
/**
 * Shared admin chrome: top bar, module switcher and tab strip.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the parts of the page that every module shares.
 *
 * Each screen keeps its own hero and cards; only the chrome lives here so the
 * eight legacy views stop carrying eight copies of the same markup.
 */
final class Shell {

	/**
	 * Enqueue the shell stylesheet. Safe to call from every module.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$path = SAKURA_TOOLS_PATH . 'assets/admin-shell.css';

		wp_enqueue_style(
			'sakura-imgopt-shell',
			SAKURA_TOOLS_URL . 'assets/admin-shell.css',
			array( 'sakura-imgopt-admin' ),
			is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION
		);
	}

	/**
	 * Print the top bar and, when the module has more than one screen, the tabs.
	 *
	 * @param string              $module  Active module id.
	 * @param string              $tab     Active tab id.
	 * @param array<string,mixed> $args    {
	 *     @type string $status       Status pill markup, shown on the right.
	 *     @type string $status_tone  Optional pill tone: ok, off, warn or error.
	 *     @type array  $actions      Extra top-bar buttons: array of [ label, url ].
	 * }
	 * @return void
	 */
	public static function header( string $module, string $tab = '', array $args = array() ): void {
		$modules = Registry::modules();

		if ( ! isset( $modules[ $module ] ) ) {
			return;
		}

		$current     = $modules[ $module ];
		$status      = isset( $args['status'] ) ? (string) $args['status'] : '';
		$status_id   = isset( $args['status_id'] ) ? (string) $args['status_id'] : '';
		$status_tone = isset( $args['status_tone'] ) ? (string) $args['status_tone'] : '';
		$status_tone = in_array( $status_tone, array( 'ok', 'off', 'warn', 'error' ), true ) ? $status_tone : '';
		$actions     = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
		?>
		<header class="topbar sakura-topbar">
			<div class="brand">
				<span class="brand-mark" aria-hidden="true">S</span>
				<strong><?php esc_html_e( '樱花工具箱', 'sakura-imgopt' ); ?></strong>
				<span class="path"><span class="slash">/</span> <?php echo esc_html( (string) $current['label'] ); ?></span>
			</div>

			<nav class="sakura-mods" aria-label="<?php esc_attr_e( '功能模块', 'sakura-imgopt' ); ?>">
				<?php foreach ( $modules as $id => $definition ) : ?>
					<a class="sakura-mod<?php echo $id === $module ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( Registry::url( (string) $id ) ); ?>"
						<?php echo $id === $module ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $definition['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="top-actions">
				<?php if ( '' !== $status ) : ?>
					<span class="conn-pill<?php echo '' !== $status_tone ? ' is-' . esc_attr( $status_tone ) : ''; ?>"<?php echo '' !== $status_id ? ' id="' . esc_attr( $status_id ) . '"' : ''; ?>><span class="dot"></span><span><?php echo wp_kses_post( $status ); ?></span></span>
				<?php endif; ?>
				<?php foreach ( $actions as $action ) : ?>
					<?php if ( empty( $action['label'] ) || empty( $action['url'] ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<a class="btn btn--small" href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) $action['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</header>

		<?php if ( count( (array) $current['tabs'] ) > 1 ) : ?>
			<nav class="sakura-tabs" aria-label="<?php esc_attr_e( '模块页面', 'sakura-imgopt' ); ?>">
				<?php foreach ( (array) $current['tabs'] as $id => $definition ) : ?>
					<?php
					$sakura_tab_gate = Registry::tab_gate( (string) $module, (string) $id );

					// Capability missing: render nothing at all, so the page never leaks.
					if ( ! $sakura_tab_gate['capable'] ) {
						continue;
					}
					?>
					<?php if ( $sakura_tab_gate['enabled'] ) : ?>
					<a class="sakura-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( Registry::url( $module, (string) $id ) ); ?>"
						<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $definition['label'] ); ?></a>
					<?php else : ?>
					<span class="sakura-tab is-disabled<?php echo $id === $tab ? ' is-active' : ''; ?>"
						aria-disabled="true"
						tabindex="-1"
						title="<?php echo esc_attr( $sakura_tab_gate['note'] ); ?>"><?php echo esc_html( (string) $definition['label'] ); ?><span class="sakura-tab-lock" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $sakura_tab_gate['note'] ); ?></span></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renderer for a missing route.
	 *
	 * @return void
	 */
	public static function missing(): void {
		?>
		<header class="topbar sakura-topbar">
			<div class="brand">
				<span class="brand-mark" aria-hidden="true">S</span>
				<strong><?php esc_html_e( '樱花工具箱', 'sakura-imgopt' ); ?></strong>
			</div>
		</header>
		<div class="card">
			<div class="card-body">
				<p><?php esc_html_e( '这个页面还没有注册渲染器。', 'sakura-imgopt' ); ?></p>
			</div>
		</div>
		<?php
	}
}
