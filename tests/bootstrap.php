<?php
/**
 * PHPUnit bootstrap: minimal WordPress shims for pure helper tests.
 *
 * @package Sakura_Image_Optimizer
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'KB_IN_BYTES' ) ) {
	define( 'KB_IN_BYTES', 1024 );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'GB_IN_BYTES' ) ) {
	define( 'GB_IN_BYTES', 1073741824 );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error test double.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Minimal action shim: record registrations for assertions.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['sakura_test_hooks']['actions'][] = array( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Minimal filter shim: record registrations for assertions.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['sakura_test_hooks']['filters'][] = array( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Minimal wp_parse_args() shim.
	 *
	 * @param array|object $args     Arguments.
	 * @param array        $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_object( $args ) ? get_object_vars( $args ) : (array) $args );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Minimal postmeta shim.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['sakura_test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Minimal post type shim.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function get_post_type( $post_id ) {
		return $GLOBALS['sakura_test_post_types'][ $post_id ] ?? 'attachment';
	}
}
if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Minimal post lookup shim.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	function get_post( $post_id ) {
		return $GLOBALS['sakura_test_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal option shim backed by a test global.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return $GLOBALS['sakura_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	/**
	 * Minimal basename shim.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_basename( $path ) {
		return basename( (string) $path );
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * Active stylesheet shim; tests switch themes via $GLOBALS['sakura_test_theme'].
	 *
	 * @return string
	 */
	function get_stylesheet() {
		return (string) ( $GLOBALS['sakura_test_theme'] ?? 'twentytwentyfive' );
	}
}

if ( ! function_exists( 'get_template' ) ) {
	/**
	 * Parent theme shim; follows the same test global as get_stylesheet().
	 *
	 * @return string
	 */
	function get_template() {
		return (string) ( $GLOBALS['sakura_test_theme'] ?? 'twentytwentyfive' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Minimal trailing-slash shim.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Minimal path normalization shim.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Upload dir shim backed by a test global.
	 *
	 * @return array<string,string>
	 */
	function wp_upload_dir() {
		return array( 'basedir' => $GLOBALS['sakura_test_upload_dir'] ?? sys_get_temp_dir() );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	/**
	 * Minimal filetype shim.
	 *
	 * @param string $filename File.
	 * @return array<string,string|false>
	 */
	function wp_check_filetype( $filename ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		return array( 'ext' => $ext, 'type' => $map[ $ext ] ?? false );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Minimal WP_Error check.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Minimal postmeta write shim.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['sakura_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Minimal postmeta delete shim.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['sakura_test_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal strip_tags shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Minimal transient read shim.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	function get_transient( $name ) {
		return $GLOBALS['sakura_test_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Minimal transient write shim.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL.
	 * @return bool
	 */
	function set_transient( $name, $value, $ttl = 0 ) {
		$GLOBALS['sakura_test_transients'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Minimal transient delete shim.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['sakura_test_transients'][ $name ] );
		return true;
	}
}

if ( ! class_exists( 'WP_Query', false ) ) {
	/**
	 * Minimal WP_Query test double for Sync_Status::failed_attachments().
	 */
	class WP_Query {
		/** @var int[] */
		public array $posts;

		/** @param array<string,mixed> $args Query args. */
		public function __construct( $args = array() ) {
			unset( $args );
			$this->posts = array_map( 'intval', (array) ( $GLOBALS['sakura_test_failed_ids'] ?? array() ) );
		}
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Minimal file deletion shim.
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	function wp_delete_file( $file ) {
		return file_exists( $file ) ? unlink( $file ) : true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Minimal option write shim.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['sakura_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Deterministic salt shim for credential tests.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'sakura-test-salt-' . (string) $scheme;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Minimal filter shim: return the value unchanged.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Minimal wp_parse_url() shim.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Minimal current_time() shim.
	 *
	 * @param string $type Output format.
	 * @param int    $gmt  Ignored.
	 * @return string
	 */
	function current_time( $type, $gmt = 0 ) {
		return gmdate( (string) $type );
	}
}

require_once dirname( __DIR__ ) . '/src/Upgrade_Guard.php';
require_once dirname( __DIR__ ) . '/src/Support/Text.php';
require_once dirname( __DIR__ ) . '/src/Support/Hook_Loader.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Module.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Backup_Repository_Interface.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Stats_Repository_Interface.php';
require_once dirname( __DIR__ ) . '/src/Contracts/Settings_Repository_Interface.php';
require_once dirname( __DIR__ ) . '/src/Contracts/File_System_Interface.php';
require_once dirname( __DIR__ ) . '/src/Backup/Backup_Repository.php';
require_once dirname( __DIR__ ) . '/src/Backup/Exposure_Probe.php';
require_once dirname( __DIR__ ) . '/src/Stats/Stats_Repository.php';
require_once dirname( __DIR__ ) . '/src/Settings/Settings_Repository.php';
require_once dirname( __DIR__ ) . '/src/Filesystem/File_System_Repository.php';
require_once dirname( __DIR__ ) . '/src/Image/Engine_Manager.php';
require_once dirname( __DIR__ ) . '/src/Image/File_Processor.php';
require_once dirname( __DIR__ ) . '/src/Uninstaller.php';
require_once dirname( __DIR__ ) . '/src/Image/Upload_Renamer.php';
require_once dirname( __DIR__ ) . '/src/Image/Upload_Backup.php';
require_once dirname( __DIR__ ) . '/src/Image/Metadata_Processor.php';
require_once dirname( __DIR__ ) . '/src/Image/Upload_Handler.php';
require_once dirname( __DIR__ ) . '/src/Image/Restore_Service.php';
require_once dirname( __DIR__ ) . '/src/Oss/Url_Rewriter.php';
require_once dirname( __DIR__ ) . '/src/S3/Providers.php';
require_once dirname( __DIR__ ) . '/src/S3/Credential_Cipher.php';
require_once dirname( __DIR__ ) . '/src/S3/Upload_URL.php';
require_once dirname( __DIR__ ) . '/src/S3/Options.php';
require_once dirname( __DIR__ ) . '/src/S3/Log.php';
require_once dirname( __DIR__ ) . '/src/S3/Error_Mapper.php';
require_once dirname( __DIR__ ) . '/src/S3/Object_Store.php';
require_once dirname( __DIR__ ) . '/src/S3/Client_Factory.php';
require_once dirname( __DIR__ ) . '/src/S3/Storage_Adapter.php';
require_once dirname( __DIR__ ) . '/src/S3/Restore_Plan.php';
require_once dirname( __DIR__ ) . '/src/S3/Sync_Status.php';
require_once dirname( __DIR__ ) . '/src/S3/Upload_Capabilities.php';
require_once dirname( __DIR__ ) . '/src/S3/Synchronous_Uploader.php';
require_once dirname( __DIR__ ) . '/src/S3/Storage.php';
require_once dirname( __DIR__ ) . '/src/S3/Migration_Service.php';
require_once dirname( __DIR__ ) . '/includes/class-animation-detector.php';
require_once dirname( __DIR__ ) . '/includes/class-stats.php';
require_once dirname( __DIR__ ) . '/includes/class-file-system.php';
require_once dirname( __DIR__ ) . '/src/Image/No_Gain_Guard.php';
require_once dirname( __DIR__ ) . '/includes/class-gd-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-imagick-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-backup.php';
require_once dirname( __DIR__ ) . '/includes/class-image-processor.php';
require_once dirname( __DIR__ ) . '/includes/class-media-library-module.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Rendition.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Provider.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Provider_Status.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Providers/Bitiful.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Providers/Generic.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Settings.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Context.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Size_Catalog.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Policy.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Stats.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Probe.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Decorator.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Theme_Bridge.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Theme_Cache.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/CLI.php';
require_once dirname( __DIR__ ) . '/includes/class-thumbnail-optimizer-module.php';
require_once dirname( __DIR__ ) . '/includes/class-s3-storage-module.php';
require_once dirname( __DIR__ ) . '/includes/class-image-optimizer-module.php';
require_once dirname( __DIR__ ) . '/includes/class-thumbnail-manager.php';
require_once dirname( __DIR__ ) . '/src/Image/Size_Catalog.php';
require_once dirname( __DIR__ ) . '/src/Image/Crop_Config_Scanner.php';

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Minimal untrailingslashit shim.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Minimal delete_option shim.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( $GLOBALS['sakura_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		/**
		 * Cron-context shim for tests.
		 *
		 * @return bool
		 */
		function wp_doing_cron() {
			return (bool) ( $GLOBALS['sakura_test_doing_cron'] ?? false );
		}
	}

	function wp_next_scheduled( $hook ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Minimal add_option shim backed by a test global.
	 *
	 * Mirrors WordPress: an existing option is not overwritten and false is
	 * returned, which is what the upgrade lock relies on.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function add_option( $name, $value ) {
		if ( array_key_exists( $name, $GLOBALS['sakura_test_options'] ?? array() ) ) {
			return false;
		}
		$GLOBALS['sakura_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Minimal sanitize_key shim.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! defined( 'SAKURA_TOOLS_VERSION' ) ) {
	define( 'SAKURA_TOOLS_VERSION', '1.6.5' );
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Minimal absint shim.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Minimal esc_url_raw shim.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal sanitize_text_field shim.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Minimal wp_unslash shim.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal wp_strip_all_tags shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Minimal home_url shim.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Minimal admin_url shim.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Minimal add_query_arg shim.
	 *
	 * @param array<string,mixed> $args Args.
	 * @param string              $url  Base URL.
	 * @return string
	 */
	function add_query_arg( $args, $url ) {
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( (array) $args );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Deterministic-ish password shim for token tests.
	 *
	 * @param int  $length Length.
	 * @param bool $special Special chars.
	 * @param bool $extra   Extra chars.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special = true, $extra = true ) {
		return substr( bin2hex( random_bytes( 64 ) ), 0, (int) $length );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * HTTP stub; tests may override $GLOBALS['sakura_test_http_response'].
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['sakura_test_last_http'] = array( $url, $args );
		return $GLOBALS['sakura_test_http_response'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => 'ok',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Minimal response-code shim.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Minimal user id shim.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Minimal checked() shim.
	 *
	 * @param mixed $checked Value.
	 * @param mixed $current Current.
	 * @param bool  $display Echo flag.
	 * @return string
	 */
	function checked( $checked, $current = true, $display = true ) {
		$result = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim.
		}
		return $result;
	}
}

require_once dirname( __DIR__ ) . '/src/S3/Profile_Manager.php';
require_once dirname( __DIR__ ) . '/src/S3/Settings_Saver.php';
require_once dirname( __DIR__ ) . '/src/S3/Upload_Metrics.php';
require_once dirname( __DIR__ ) . '/src/S3/Alerting.php';
require_once dirname( __DIR__ ) . '/src/S3/Prometheus.php';
require_once dirname( __DIR__ ) . '/src/S3/Monitoring_Panel.php';
require_once dirname( __DIR__ ) . '/src/Sync/Keyset_Cursor.php';
require_once dirname( __DIR__ ) . '/src/Sync/Fast_Search_Query.php';
require_once dirname( __DIR__ ) . '/src/Sync/Kind.php';
require_once dirname( __DIR__ ) . '/src/Sync/Search_Token.php';
require_once dirname( __DIR__ ) . '/src/Sync/Feature.php';
require_once dirname( __DIR__ ) . '/src/S3/Remote_Operation_Guard.php';
require_once dirname( __DIR__ ) . '/src/Sync/Index_Repository.php';
require_once dirname( __DIR__ ) . '/src/S3/Delete_Worker.php';
require_once dirname( __DIR__ ) . '/src/S3/Upload_Observer.php';
require_once dirname( __DIR__ ) . '/src/S3/Delete_Jobs.php';
require_once dirname( __DIR__ ) . '/src/S3/Upload_Verify.php';
require_once dirname( __DIR__ ) . '/src/S3/History_Migrator.php';

require_once dirname( __DIR__ ) . '/src/S3/Upload_Metrics.php';

require_once dirname( __DIR__ ) . '/src/Sync/Checksums.php';

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		$GLOBALS['sakura_test_actions'][] = array( $hook_name, $args );
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	$GLOBALS['sakura_test_cache'] = array();
	/**
	 * Minimal cache get shim.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Force flag.
	 * @param bool|null  $found Found flag.
	 * @return mixed
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$found = isset( $GLOBALS['sakura_test_cache'][ $group ][ $key ] );
		return $found ? $GLOBALS['sakura_test_cache'][ $group ][ $key ] : false;
	}

	/**
	 * Minimal cache set shim.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $value  Value.
	 * @param string     $group  Cache group.
	 * @param int        $expire Expiry.
	 * @return bool
	 */
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		$GLOBALS['sakura_test_cache'][ $group ][ $key ] = $value;
		return true;
	}

	/**
	 * Minimal cache delete shim.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return bool
	 */
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['sakura_test_cache'][ $group ][ $key ] );
		return true;
	}

	/**
	 * Minimal group flush shim.
	 *
	 * @param string $group Cache group.
	 * @return bool
	 */
	function wp_cache_flush_group( $group ) {
		$GLOBALS['sakura_test_flushed_groups'][] = $group;
		return true;
	}

	/**
	 * Force the persistent-object-cache branch in tests.
	 *
	 * @param bool|null $using Using flag.
	 * @return bool
	 */
	function wp_using_ext_object_cache( $using = null ) {
		return true;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	/**
	 * Minimal attachment URL resolver shim.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	function attachment_url_to_postid( $url ) {
		return str_contains( (string) $url, '/wp-content/uploads/' ) ? 1 : 0;
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Minimal attachment metadata shim.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	function wp_get_attachment_metadata( $attachment_id ) {
		return array( 'width' => 1200, 'height' => 800 );
	}
}
