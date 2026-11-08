<?php
/**
 * Server-rendered shell for the editor panel.
 *
 * The panel owns its whole front end. These four are panel-only forks of the
 * user-centre files, so either side can change without dragging the other
 * along:
 *
 *   includes/views/editor-picker-app.php      markup
 *   assets/editor-picker.css                  styles (scoped to #sufm-picker)
 *   assets/editor-picker-manager.js           file-manager behaviour
 *   assets/editor-picker.js                   shell: placement, pick, insert
 *
 * What the panel subtracts is CSS-only: the storage summary card, the 扩容
 * button and the 容量详情 button. What it adds is the “插入到正文” bar.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Editor;

use Nboke\UserFiles\Frontend\Tab;
use Nboke\UserFiles\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the panel once, in the footer.
 */
final class Picker {

	/**
	 * Render the panel.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$template = SAKURA_TOOLS_PATH . 'includes/views/editor-picker-app.php';

		if ( ! is_readable( $template ) ) {
			return;
		}

		// The view needs the same copy the user centre passes down; the strings
		// and the notice text are already assembled by Tab::script_data().
		$config = ( new Tab() )->script_data();

		$data = array(
			'strings'       => isset( $config['strings'] ) && is_array( $config['strings'] ) ? $config['strings'] : array(),
			'notice'        => isset( $config['notice'] ) && is_array( $config['notice'] ) ? $config['notice'] : array(),
			'tree_style'    => Settings::tree_style(),
			'folder_accent' => Settings::folder_accent(),
		);

		ob_start();
		include $template;
		$markup = (string) ob_get_clean();

		if ( '' === $markup ) {
			return;
		}

		// The panel stylesheet is scoped to #sufm-picker, so the folder look has
		// to live on the panel element itself, not on the inner `.sufm` root.
		$classes = 'sufm sufm-picker-app sufm-style-' . Settings::tree_style();
		$accent  = Settings::folder_accent();

		if ( '' !== $accent ) {
			$classes .= ' sufm-accent-' . sanitize_html_class( $accent );
		}
		?>
		<?php // The sidebar entry card. `mountTrigger()` moves it into `.sidebar`; pages without one get it floating bottom-right. ?>
		<button type="button" class="sufm-trigger sufm-editor" id="sufm-picker-trigger" aria-expanded="false" aria-controls="sufm-picker">
			<span class="sufm-trigger-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" focusable="false"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H9l2.5 2.5H18A2.5 2.5 0 0 1 20.5 10v6A2.5 2.5 0 0 1 18 18.5H5.5A2.5 2.5 0 0 1 3 16z"/></svg></span>
			<span class="sufm-trigger-text">
				<strong><?php esc_html_e( '文件空间', 'sakura-imgopt' ); ?></strong>
				<span><?php esc_html_e( '写作时复用已上传的附件', 'sakura-imgopt' ); ?></span>
			</span>
			<span class="sufm-trigger-state" data-sufm-trigger-state><?php esc_html_e( '展开', 'sakura-imgopt' ); ?></span>
		</button>

		<aside class="<?php echo esc_attr( $classes ); ?>" id="sufm-picker" hidden aria-label="<?php echo esc_attr__( '文件空间', 'sakura-imgopt' ); ?>">
			<button type="button" class="sufm-icon-btn sufm-picker-x" data-sufm-close aria-label="<?php echo esc_attr__( '收起文件空间', 'sakura-imgopt' ); ?>" title="<?php echo esc_attr__( '收起文件空间', 'sakura-imgopt' ); ?>"><svg class="sufm-icon" aria-hidden="true" focusable="false"><use href="#sufm-i-x"></use></svg></button>
			<?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output built and escaped by the plugin. ?>

			<div class="sufm-picker-foot">
				<span class="sufm-picker-picked" data-sufm-picked><?php esc_html_e( '单击选中，双击直接插入', 'sakura-imgopt' ); ?></span>
				<button type="button" class="sufm-btn sufm-btn-primary" data-sufm-insert disabled><?php esc_html_e( '插入到正文', 'sakura-imgopt' ); ?></button>
			</div>
		</aside>
		<?php
	}
}
