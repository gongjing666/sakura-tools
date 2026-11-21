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
 * Each screen keeps its own page head and cards; only the chrome lives here.
 */
final class Shell {

	/**
	 * Print the top bar and the module's tab strip.
	 *
	 * One tab is shown as well: a module that starts with a single screen still
	 * reads as "this is a tab", and the strip simply grows when the module gains
	 * more screens.
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
				<span class="brand-mark" aria-hidden="true"><?php echo self::mark_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
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

		<?php if ( count( (array) $current['tabs'] ) >= 1 ) : ?>
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
		list( $module, $tab ) = Registry::current();

		UI::page(
			array(
				'id'      => Route::current_page(),
				'module'  => (string) $module,
				'tab'     => (string) $tab,
				'eyebrow' => __( '工具箱', 'sakura-imgopt' ),
				'title'   => __( '页面暂不可用', 'sakura-imgopt' ),
				'lede'    => __( '这个页面还没有注册渲染器。', 'sakura-imgopt' ),
			),
			static function (): void {}
		);
	}

	/**
	 * The brand mark glyph, taken from the bundled SVG.
	 *
	 * The file is the only copy of this artwork: the admin menu icon masks the
	 * same file (see assets/ui/admin-menu-icon.css), so the two can never drift
	 * apart. Reading it once per request is enough — the string is cached.
	 *
	 * @return string SVG markup, or the plain letter when the file is missing.
	 */
	private static function mark_svg(): string {
		static $svg = null;

		if ( null !== $svg ) {
			return $svg;
		}

		$path = defined( 'SAKURA_TOOLS_PATH' ) ? (string) SAKURA_TOOLS_PATH . 'assets/ui/icons/sakura-mark.svg' : '';
		$raw  = '' !== $path && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled asset, no HTTP fallback wanted.

		$svg = false === $raw || '' === trim( $raw ) ? 'S' : trim( $raw );

		return $svg;
	}
}
