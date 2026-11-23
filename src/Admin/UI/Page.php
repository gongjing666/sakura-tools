<?php
/**
 * Page shell for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

use Sakura\ImageOptimizer\Admin\Appearance;
use Sakura\ImageOptimizer\Admin\Shell;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the root node, shared chrome, page head and main content area.
 */
final class Page {

	/**
	 * Render one page.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @param callable            $body Main content.
	 * @return void
	 */
	public static function render( array $args, callable $body ): void {
		$id     = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$module = isset( $args['module'] ) ? sanitize_key( (string) $args['module'] ) : '';
		$tab    = isset( $args['tab'] ) ? sanitize_key( (string) $args['tab'] ) : '';
		$chrome = isset( $args['chrome'] ) && is_array( $args['chrome'] ) ? $args['chrome'] : array();

		/*
		 * A page rendered by a React island passes `island => true`: the island
		 * draws the top bar, the tab strip and the page head itself from
		 * Shell::header_data() / Page::head_data(), and React mounts on the
		 * .sakura-admin node itself so the `>` selectors in base.css keep
		 * matching. The server keeps only the node and the fallback note.
		 */
		$island = ! empty( $args['island'] );

		$classes = 'sakura-admin';

		$style = Appearance::inline_style();

		echo '<div class="' . esc_attr( $classes ) . '"';
		if ( '' !== $id ) {
			echo ' data-sakura-page="' . esc_attr( $id ) . '"';
		}
		if ( '' !== $style ) {
			echo ' style="' . esc_attr( $style ) . '"';
		}
		echo '>';

		if ( $island ) {
			$body();
			self::settings_feedback();
			echo '</div>';

			return;
		}

		if ( '' !== $module ) {
			Shell::header( $module, $tab, $chrome );
		}

		echo '<main class="sakura-admin__main">';
		self::head( $args );
		echo '<div class="page-body">';
		$body();
		echo '</div>';
		echo '</main>';

		/*
		 * Every card form posts to options.php, so WordPress answers with its own
		 * green notice above the page. Turn that answer into the shared prompt:
		 * it is an event (the operator just clicked 保存), it belongs in the same
		 * dialog as every other result, and the stock notice renders outside the
		 * .sakura-admin root where none of our tokens reach it.
		 */
		self::settings_feedback();

		echo '</div>';
	}

	/**
	 * Convert pending settings errors into the prompt dialog.
	 *
	 * Runs once per page render. Anything left over (the Settings API kept it in
	 * a transient) is still surfaced, just in our own shape.
	 *
	 * @return void
	 */
	private static function settings_feedback(): void {
		if ( ! function_exists( 'settings_errors' ) ) {
			return;
		}

		/*
		 * Print into a buffer and keep nothing: settings_errors() without the
		 * $clear argument pops and prints the stack in one go, so this both reads
		 * the messages and stops WordPress from rendering them again further down
		 * the page (views that call settings_errors() themselves would otherwise
		 * leave a second copy).
		 */
		ob_start();
		settings_errors();
		$stock = (string) ob_get_clean();

		if ( '' === trim( $stock ) ) {
			return;
		}

		$ok       = false !== strpos( $stock, 'notice-success' );
		$warn     = false !== strpos( $stock, 'notice-warning' );
		$messages = array();

		if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/s', $stock, $found ) ) {
			foreach ( $found[1] as $fragment ) {
				$text = trim( wp_strip_all_tags( $fragment ) );
				if ( '' !== $text ) {
					$messages[] = $text;
				}
			}
		}

		if ( array() === $messages ) {
			return;
		}

		$tone = $ok ? 'ok' : ( $warn ? 'warn' : 'bad' );

		\Sakura\ImageOptimizer\Admin\UI::modal(
			array(
				'id'        => 'sakura-settings-result-modal',
				'return_to' => '.page-body .btn--primary',
				'tone'    => $tone,
				'size'    => 'compact',
				'kicker'  => 'SETTINGS / SAVED',
				'title'   => 'ok' === $tone ? __( '设置已保存', 'sakura-imgopt' ) : ( 'warn' === $tone ? __( '已保存，但有需要注意的地方', 'sakura-imgopt' ) : __( '设置未保存', 'sakura-imgopt' ) ),
				'message' => implode( ' ', $messages ),
			)
		);
	}

	/**
	 * Describe the page head as data.
	 *
	 * The React island renders the same header from this description and head()
	 * below is built from it, so the PHP markup and the island cannot disagree
	 * about what a head is.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @return array<string,mixed>
	 */
	public static function head_data( array $args ): array {
		$id      = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$eyebrow = isset( $args['eyebrow'] ) ? (string) $args['eyebrow'] : '';
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$lede    = isset( $args['lede'] ) ? (string) $args['lede'] : '';
		$tagline = isset( $args['tagline'] ) ? (string) $args['tagline'] : '';
		$stamp   = isset( $args['stamp'] ) && is_array( $args['stamp'] ) ? $args['stamp'] : array();

		/*
		 * V2 hero: a page that does not describe its own hero still gets the
		 * shared one. An explicit argument always wins, so a view can still stay
		 * plain by passing an empty string.
		 */
		if ( ! isset( $args['tagline'] ) ) {
			$tagline = Hero::tagline( $id );
		}
		if ( array() === $stamp ) {
			$stamp = Hero::stamp();
		}

		return array(
			'id'      => $id,
			'eyebrow' => $eyebrow,
			'title'   => $title,
			'lede'    => $lede,
			/*
			 * The tagline is the one place a page may use inline emphasis: V2
			 * paints the <em> with the accent gradient. Anything beyond em /
			 * strong / span is stripped, so a view cannot smuggle layout in.
			 */
			'tagline' => '' === $tagline ? '' : wp_kses( $tagline, array( 'em' => array(), 'strong' => array(), 'span' => array() ) ),
			'status'  => self::status_data( isset( $args['status'] ) && is_array( $args['status'] ) ? $args['status'] : array() ),
			'actions' => self::actions_data( isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array() ),
			'stamp'   => array(
				'zh'    => isset( $stamp['zh'] ) ? (string) $stamp['zh'] : '',
				'en'    => isset( $stamp['en'] ) ? (string) $stamp['en'] : '',
				'place' => isset( $stamp['place'] ) && 'aside' === $stamp['place'] ? 'aside' : 'copy',
			),
		);
	}

	/**
	 * Normalise the status card arguments.
	 *
	 * @param array<string,mixed> $status Status arguments.
	 * @return array<string,mixed>
	 */
	private static function status_data( array $status ): array {
		if ( array() === $status ) {
			return array();
		}

		$tone = isset( $status['tone'] ) ? sanitize_key( (string) $status['tone'] ) : 'neutral';

		return array(
			'label'     => isset( $status['label'] ) ? (string) $status['label'] : '',
			'value'     => isset( $status['value'] ) ? (string) $status['value'] : '',
			'unit'      => isset( $status['unit'] ) ? (string) $status['unit'] : '',
			'sub'       => isset( $status['sub'] ) ? (string) $status['sub'] : '',
			'tone'      => in_array( $tone, array( 'ok', 'warn', 'bad', 'neutral' ), true ) ? $tone : 'neutral',
			'id'        => isset( $status['id'] ) ? sanitize_key( (string) $status['id'] ) : '',
			'sub_id'    => isset( $status['sub_id'] ) ? sanitize_key( (string) $status['sub_id'] ) : '',
			'title'     => isset( $status['title'] ) ? (string) $status['title'] : '',
			'ping'      => ! empty( $status['ping'] ),
			'ping_name' => isset( $status['ping_name'] ) ? (string) $status['ping_name'] : '',
		);
	}

	/**
	 * Normalise the page action list.
	 *
	 * @param array<int,mixed> $actions Action list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function actions_data( array $actions ): array {
		$valid = array();

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
				continue;
			}

			$variant = isset( $action['variant'] ) ? sanitize_key( (string) $action['variant'] ) : 'ghost';

			$valid[] = array(
				'label'   => (string) $action['label'],
				'url'     => (string) $action['url'],
				'variant' => in_array( $variant, array( 'primary', 'ghost', 'quiet', 'danger' ), true ) ? $variant : 'ghost',
				'id'      => isset( $action['id'] ) ? sanitize_key( (string) $action['id'] ) : '',
				'attrs'   => isset( $action['attrs'] ) && is_array( $action['attrs'] ) ? $action['attrs'] : array(),
			);
		}

		return $valid;
	}

	/**
	 * Page heading.
	 *
	 * @param array<string,mixed> $args Page arguments.
	 * @return void
	 */
	private static function head( array $args ): void {
		$head = self::head_data( $args );

		echo '<header class="page-head">';
		echo '<div class="page-head__copy">';
		if ( '' !== $head['eyebrow'] ) {
			echo '<p class="page-head__eyebrow">' . esc_html( $head['eyebrow'] ) . '</p>';
		}
		if ( '' !== $head['title'] ) {
			echo '<h1 id="sakura-page-title">' . esc_html( $head['title'] ) . '</h1>';
		}
		if ( '' !== $head['tagline'] ) {
			echo '<span class="page-head__tag">' . $head['tagline'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() in head_data().
		}
		if ( '' !== $head['lede'] ) {
			echo '<p class="page-head__lede">' . esc_html( $head['lede'] ) . '</p>';
		}
		self::actions( $head['actions'] );
		/*
		 * The stamp belongs to the copy column: as a third child of the header
		 * grid it would claim the "auto" column and push the status card onto a
		 * second row, stretching both across the full width.
		 */
		$stamp_in_aside = 'aside' === $head['stamp']['place'];
		if ( ! $stamp_in_aside ) {
			self::stamp( $head['stamp'] );
		}
		echo '</div>';

		if ( ! empty( $head['status'] ) || ( $stamp_in_aside && ! empty( $head['stamp']['zh'] ) ) ) {
			echo '<aside class="page-head__aside">';
			if ( ! empty( $head['status'] ) ) {
				self::status( $head['status'] );
			}
			if ( $stamp_in_aside ) {
				self::stamp( $head['stamp'] );
			}
			echo '</aside>';
		}

		echo '</header>';
	}

	/**
	 * Seal that names the page, never its state.
	 *
	 * Verbatim from the design draft (docs/设计稿/s3-sync-center-sakura.html):
	 * one SVG with a dashed outer ring, a hairline inner ring, a solid glyph
	 * and a letterspaced caption — drawn rather than assembled from a CSS
	 * circle, because the two rings and the optical centring of the glyph are
	 * the whole look. Colours ride on `currentColor`, so the seal follows the
	 * theme while keeping the draft's proportions.
	 *
	 * `place` decides the column: the copy column (default) or the aside
	 * column, which is where the draft puts it.
	 *
	 * @param array<string,mixed> $stamp Stamp arguments.
	 * @return void
	 */
	private static function stamp( array $stamp ): void {
		if ( empty( $stamp['zh'] ) ) {
			return;
		}

		echo '<svg class="page-head__stamp" viewBox="0 0 120 120" aria-hidden="true" focusable="false">';
		echo '<circle cx="60" cy="60" r="53" fill="rgba(255,255,255,.72)" stroke="currentColor" stroke-opacity=".34" stroke-width="1.6" stroke-dasharray="4 6"/>';
		echo '<circle cx="60" cy="60" r="43" fill="none" stroke="currentColor" stroke-opacity=".22" stroke-width="1"/>';
		echo '<text x="60" y="62" text-anchor="middle" font-size="35" font-weight="800" fill="currentColor">' . esc_html( (string) $stamp['zh'] ) . '</text>';
		if ( ! empty( $stamp['en'] ) ) {
			echo '<text x="60" y="82" text-anchor="middle" font-size="8.5" letter-spacing="2.4" fill="var(--g-faint)">' . esc_html( (string) $stamp['en'] ) . '</text>';
		}
		echo '</svg>';
	}

	/**
	 * Small status card.
	 *
	 * @param array<string,mixed> $status Status arguments.
	 * @return void
	 */
	private static function status( array $status ): void {
		if ( empty( $status ) ) {
			return;
		}

		$tones = array( 'ok', 'warn', 'bad', 'neutral' );
		$tone  = isset( $status['tone'] ) ? sanitize_key( (string) $status['tone'] ) : 'neutral';
		$tone  = in_array( $tone, $tones, true ) ? $tone : 'neutral';

		echo '<div class="status-card status-card--' . esc_attr( $tone ) . '">';
		if ( ! empty( $status['label'] ) ) {
			echo '<span class="status-card__label">' . esc_html( (string) $status['label'] ) . '</span>';
		}
		$value_id = isset( $status['id'] ) ? sanitize_key( (string) $status['id'] ) : '';
		$sub_id   = isset( $status['sub_id'] ) ? sanitize_key( (string) $status['sub_id'] ) : '';
		// 显示值可能被缩写（长存储桶 id），完整值留在 title 里，悬停即可核对。
		$value_title = isset( $status['title'] ) ? (string) $status['title'] : '';

		echo '<span class="status-card__line">';
		echo '<strong class="status-card__value"';
		if ( '' !== $value_id ) {
			echo ' id="' . esc_attr( $value_id ) . '"';
		}
		if ( '' !== $value_title ) {
			echo ' title="' . esc_attr( $value_title ) . '"';
		}
		echo '>' . esc_html( (string) ( $status['value'] ?? '' ) ) . '</strong>';
		if ( ! empty( $status['unit'] ) ) {
			echo '<span class="status-card__unit">' . esc_html( (string) $status['unit'] ) . '</span>';
		}
		echo '</span>';
		if ( ! empty( $status['sub'] ) ) {
			echo '<span class="status-card__sub"';
			if ( '' !== $sub_id ) {
				echo ' id="' . esc_attr( $sub_id ) . '"';
			}
			echo '>' . esc_html( (string) $status['sub'] ) . '</span>';
		}
		if ( ! empty( $status['ping'] ) ) {
			// Filled by the page controller: the round-trip time to the bucket.
			echo '<span class="status-card__ping" data-sakura-ping data-state="checking">';
			echo '<i class="status-card__dot" aria-hidden="true"></i>';
			if ( ! empty( $status['ping_name'] ) ) {
				echo '<span class="status-card__ping-name">' . esc_html( (string) $status['ping_name'] ) . '</span>';
				echo '<span class="status-card__ping-sep" aria-hidden="true">·</span>';
			}
			echo '<span class="status-card__ping-text">' . esc_html__( '检测中…', 'sakura-imgopt' ) . '</span>';
			echo '<b class="status-card__ping-ms" data-sakura-ping-ms></b>';
			echo '</span>';
		}
		echo '</div>';
	}

	/**
	 * Page-level actions.
	 *
	 * @param array<int,mixed> $actions Action list.
	 * @return void
	 */
	private static function actions( array $actions ): void {
		$valid = array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) && ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
				$valid[] = $action;
			}
		}

		if ( empty( $valid ) ) {
			return;
		}

		echo '<div class="page-head__actions">';
		foreach ( $valid as $action ) {
			$variant   = isset( $action['variant'] ) ? sanitize_key( (string) $action['variant'] ) : 'ghost';
			$variant   = in_array( $variant, array( 'primary', 'ghost', 'quiet', 'danger' ), true ) ? $variant : 'ghost';
			$action_id = isset( $action['id'] ) ? sanitize_key( (string) $action['id'] ) : '';

			echo '<a class="btn btn--' . esc_attr( $variant ) . '" href="' . esc_url( (string) $action['url'] ) . '"';
			if ( '' !== $action_id ) {
				echo ' id="' . esc_attr( $action_id ) . '"';
			}
			if ( ! empty( $action['attrs'] ) && is_array( $action['attrs'] ) ) {
				foreach ( $action['attrs'] as $attr_name => $attr_value ) {
					$attr_name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $attr_name );
					if ( '' !== $attr_name ) {
						echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"';
					}
				}
			}
			echo '>' . esc_html( (string) $action['label'] ) . '</a>';
		}
		echo '</div>';
	}
}
