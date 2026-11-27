<?php
/**
 * Shared admin chrome: top bar and tab strip.
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
 *
 * ## Markup rules (hydration contract)
 *
 * With `sakura_imgopt_admin_app` on, this markup is handed to React through
 * `hydrateRoot()` instead of being left alone. Hydration compares DOM nodes and
 * text, so the server has to produce the same tree the client renders:
 *
 * - no whitespace between two tags — a newline there is a text node the client
 *   tree does not have, and React aborts the hydration (measured against
 *   React 19: "Hydration failed … - {newline, two spaces}");
 * - one text node per contiguous run of text — the HTML parser merges two
 *   adjacent strings into one node, so the React side must not split them
 *   either;
 * - the same element structure as `assets/ui/src/shared/ui.jsx`.
 *
 * `tests/Admin/ShellMarkupTest.php` compares this output against the markup
 * React produces for the same data (`npm run fixture`), so the two renderers
 * cannot drift apart unnoticed. Attributes are compared after entity decoding:
 * `&amp;` and `&#038;` are the same URL, a newline and a space are not the
 * same node.
 */
final class Shell {

	/**
	 * Describe the chrome as data.
	 *
	 * The React island renders the same top bar and tab strip from this
	 * description, so the two renderers cannot drift: render() below is built
	 * from exactly the same array.
	 *
	 * @param string              $module Active module id.
	 * @param string              $tab    Active tab id.
	 * @param array<string,mixed> $args   Status, actions and legacy flat keys.
	 * @return array<string,mixed>|null Null when the module is unknown.
	 */
	public static function header_data( string $module, string $tab = '', array $args = array() ): ?array {
		$modules = Registry::modules();

		if ( ! isset( $modules[ $module ] ) ) {
			return null;
		}

		$current = $modules[ $module ];

		list( $status, $status_tone, $status_id ) = self::status_args( $args );

		$mods = array();
		foreach ( $modules as $id => $definition ) {
			$mods[] = array(
				'id'     => (string) $id,
				'label'  => (string) $definition['label'],
				'url'    => Registry::url( (string) $id ),
				'route'  => Route::page_id( (string) $id, Registry::default_tab( (string) $id ) ),
				'active' => (string) $id === $module,
			);
		}

		$tabs = array();
		foreach ( (array) $current['tabs'] as $id => $definition ) {
			$gate = Registry::tab_gate( (string) $module, (string) $id );

			// Capability missing: leave it out entirely, so the page never leaks.
			if ( empty( $gate['capable'] ) ) {
				continue;
			}

			$tabs[] = array(
				'id'      => (string) $id,
				'label'   => (string) $definition['label'],
				'url'     => Registry::url( $module, (string) $id ),
				'route'   => Route::page_id( (string) $module, (string) $id ),
				'active'  => (string) $id === $tab,
				'enabled' => ! empty( $gate['enabled'] ),
				'note'    => isset( $gate['note'] ) ? (string) $gate['note'] : '',
			);
		}

		$actions = array();
		foreach ( isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array() as $action ) {
			if ( empty( $action['label'] ) || empty( $action['url'] ) ) {
				continue;
			}
			$actions[] = array(
				'label' => (string) $action['label'],
				'url'   => (string) $action['url'],
			);
		}

		return array(
			'brand'   => array(
				'label' => __( '樱花工具箱', 'sakura-imgopt' ),
				'mark'  => self::mark_svg(),
				'path'  => (string) $current['label'],
			),
			'mods'    => $mods,
			'tabs'    => $tabs,
			'status'  => array(
				'text' => $status,
				'tone' => $status_tone,
				'id'   => $status_id,
			),
			'actions' => $actions,
			'labels'  => array(
				'mods' => __( '功能模块', 'sakura-imgopt' ),
				'tabs' => __( '模块页面', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * The chrome as HTML, for callers that need the string.
	 *
	 * @param string              $module Active module id.
	 * @param string              $tab    Active tab id.
	 * @param array<string,mixed> $args   Same arguments as header_data().
	 * @return string
	 */
	public static function html( string $module, string $tab = '', array $args = array() ): string {
		return self::render( self::header_data( $module, $tab, $args ) );
	}

	/**
	 * Print the top bar and the module's tab strip.
	 *
	 * One tab is shown as well: a module that starts with a single screen still
	 * reads as "this is a tab", and the strip simply grows when the module gains
	 * more screens.
	 *
	 * @param string              $module Active module id.
	 * @param string              $tab    Active tab id.
	 * @param array<string,mixed> $args   Status, actions and legacy flat keys.
	 * @return void
	 */
	public static function header( string $module, string $tab = '', array $args = array() ): void {
		echo self::html( $module, $tab, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped in render().
	}

	/**
	 * Render one chrome description.
	 *
	 * @param array<string,mixed>|null $data header_data() output.
	 * @return string
	 */
	public static function render( ?array $data ): string {
		if ( null === $data ) {
			return '';
		}

		return self::topbar_html( $data ) . self::tabs_html( $data );
	}

	/**
	 * The top bar.
	 *
	 * @param array<string,mixed> $data Chrome description.
	 * @return string
	 */
	private static function topbar_html( array $data ): string {
		$parts = array();

		$parts[] = '<header class="topbar sakura-topbar"><div class="brand">';
		// Raw on purpose: the mark is the bundled SVG this plugin ships, and the
		// React sibling injects the same string through dangerouslySetInnerHTML.
		// Never let this value come from an option or a request.
		$parts[] = '<span class="brand-mark" aria-hidden="true">' . $data['brand']['mark'] . '</span>';
		$parts[] = '<strong>' . esc_html( $data['brand']['label'] ) . '</strong>';
		$parts[] = '<span class="path"><span class="slash">/</span> ' . esc_html( $data['brand']['path'] ) . '</span>';
		$parts[] = '</div><nav class="sakura-mods" aria-label="' . esc_attr( $data['labels']['mods'] ) . '">';

		foreach ( $data['mods'] as $mod ) {
			$parts[] = '<a class="' . esc_attr( 'sakura-mod' . ( $mod['active'] ? ' is-active' : '' ) ) . '"'
				. ' href="' . esc_url( $mod['url'] ) . '"'
				. ' data-sakura-route="' . esc_attr( $mod['route'] ) . '"'
				. ( $mod['active'] ? ' aria-current="page"' : '' )
				. '>' . esc_html( $mod['label'] ) . '</a>';
		}

		$parts[] = '</nav><div class="top-actions">';

		$status = $data['status'];

		if ( '' !== $status['text'] ) {
			$parts[] = '<span class="' . esc_attr( 'conn-pill' . ( '' !== $status['tone'] ? ' is-' . $status['tone'] : '' ) ) . '"'
				. ( '' !== $status['id'] ? ' id="' . esc_attr( $status['id'] ) . '"' : '' )
				. '><span class="dot"></span><span>' . esc_html( $status['text'] ) . '</span></span>';
		}

		foreach ( $data['actions'] as $action ) {
			$parts[] = '<a class="btn btn--small" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
		}

		$parts[] = '</div></header>';

		return implode( '', $parts );
	}

	/**
	 * The tab strip of the active module.
	 *
	 * @param array<string,mixed> $data Chrome description.
	 * @return string Empty when the module has no capable tab.
	 */
	private static function tabs_html( array $data ): string {
		if ( count( $data['tabs'] ) < 1 ) {
			return '';
		}

		$parts = array();

		$parts[] = '<nav class="sakura-tabs" aria-label="' . esc_attr( $data['labels']['tabs'] ) . '">';

		foreach ( $data['tabs'] as $tab_data ) {
			if ( $tab_data['enabled'] ) {
				$parts[] = '<a class="' . esc_attr( 'sakura-tab' . ( $tab_data['active'] ? ' is-active' : '' ) ) . '"'
					. ' href="' . esc_url( $tab_data['url'] ) . '"'
					. ' data-sakura-route="' . esc_attr( $tab_data['route'] ) . '"'
					. ( $tab_data['active'] ? ' aria-current="page"' : '' )
					. '>' . esc_html( $tab_data['label'] ) . '</a>';
				continue;
			}

			$parts[] = '<span class="' . esc_attr( 'sakura-tab is-disabled' . ( $tab_data['active'] ? ' is-active' : '' ) ) . '"'
				. ' aria-disabled="true" tabindex="-1" title="' . esc_attr( $tab_data['note'] ) . '">'
				. esc_html( $tab_data['label'] )
				. '<span class="sakura-tab-lock" aria-hidden="true">' . UI::lock_icon() . '</span>'
				. '<span class="screen-reader-text">' . esc_html( $tab_data['note'] ) . '</span></span>';
		}

		$parts[] = '</nav>';

		return implode( '', $parts );
	}

	/**
	 * Normalise the status pill arguments.
	 *
	 * Two shapes reach this method: a plain string from a caller that only has
	 * one line of text, and the array header_data() itself returns — a payload
	 * hands its own chrome back to Page::render(), so the shell and the island
	 * read one structure instead of two.
	 *
	 * Tags are stripped because the React renderer prints the value as text:
	 * markup here would make the two renderers disagree and break hydration.
	 * The status pill is therefore a plain-text slot: a caller that needs
	 * emphasis has to express it through the tone, not through markup.
	 *
	 * @param array<string,mixed> $args Header arguments.
	 * @return array{0:string,1:string,2:string} Text, tone and id.
	 */
	private static function status_args( array $args ): array {
		$text = '';
		$tone = '';
		$id   = '';

		if ( isset( $args['status'] ) ) {
			if ( is_array( $args['status'] ) ) {
				$text = isset( $args['status']['text'] ) ? (string) $args['status']['text'] : '';
				$tone = isset( $args['status']['tone'] ) ? (string) $args['status']['tone'] : '';
				$id   = isset( $args['status']['id'] ) ? (string) $args['status']['id'] : '';
			} else {
				$text = (string) $args['status'];
			}
		}

		// Flat keys, kept because they were the documented shape before the
		// payload started handing back its own chrome array.
		if ( isset( $args['status_tone'] ) ) {
			$tone = (string) $args['status_tone'];
		}
		if ( isset( $args['status_id'] ) ) {
			$id = (string) $args['status_id'];
		}

		$text = trim( wp_strip_all_tags( $text ) );
		$tone = in_array( $tone, array( 'ok', 'off', 'warn', 'error' ), true ) ? $tone : '';
		$id   = '' !== $id ? sanitize_key( $id ) : '';

		return array( $text, $tone, $id );
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
