<?php
/**
 * Where the editor panel is allowed to appear.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Editor;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Two Zibll screens, one editor id.
 */
final class Context {

	/**
	 * TinyMCE / textarea id used by both screens.
	 */
	public const EDITOR_ID = 'post_content';

	/**
	 * Whether this request renders one of the two writing screens.
	 */
	public static function is_screen(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_page_template' ) && is_page_template( 'pages/newposts.php' ) ) {
			return true;
		}

		// Zibll's BBS route loads its template directly: /posts-edit/ sets the
		// forum_post_edit query var to 'add' or a post id.
		$var = get_query_var( 'forum_post_edit' );

		return null !== $var && false !== $var && '' !== $var;
	}

	/**
	 * Desktop only: the panel needs 400px and the theme's mobile toolbar has no
	 * room for another button.
	 */
	public static function is_desktop(): bool {
		return ! wp_is_mobile();
	}

	/**
	 * Full gate: right screen, desktop, logged in, may manage own files and the
	 * editor switch is on.
	 */
	public static function allows(): bool {
		return self::is_screen()
			&& self::is_desktop()
			&& is_user_logged_in()
			&& current_user_can( Capabilities::MANAGE_OWN )
			&& Settings::editor_enabled();
	}
}
