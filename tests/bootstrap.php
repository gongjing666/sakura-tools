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
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
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

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Cached upload dir shim. Storage::drop_missing_candidates() reads this one
	 * because it wants the same base URL the rest of core caches.
	 *
	 * @return array<string,string>
	 */
	function wp_get_upload_dir() {
		return array(
			'basedir' => $GLOBALS['sakura_test_upload_dir'] ?? sys_get_temp_dir(),
			'baseurl' => $GLOBALS['sakura_test_upload_baseurl'] ?? '',
		);
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
require_once dirname( __DIR__ ) . '/src/S3/Connection_Probe.php';
require_once dirname( __DIR__ ) . '/src/S3/Object_Store.php';
require_once dirname( __DIR__ ) . '/src/S3/S3_Credential_Error.php';
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
require_once dirname( __DIR__ ) . '/includes/class-s3-doge-cloud.php';
require_once dirname( __DIR__ ) . '/src/Image/No_Gain_Guard.php';
require_once dirname( __DIR__ ) . '/includes/class-gd-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-imagick-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-backup.php';
require_once dirname( __DIR__ ) . '/includes/class-image-processor.php';
require_once dirname( __DIR__ ) . '/includes/class-media-library-module.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Rendition.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Provider.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Providers/Bitiful.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Providers/Generic.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Settings.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Context.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Size_Catalog.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Policy.php';
require_once dirname( __DIR__ ) . '/src/Thumbnail/Stats.php';
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

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return true;
	}
}

/*
 * Every cron-removal shim appends the hook name to one global so a test can
 * assert exactly which events an uninstall or deactivation asked to drop.
 */
if ( ! function_exists( 'sakura_test_record_unscheduled' ) ) {
	/**
	 * Record one hook removal for the current test.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	function sakura_test_record_unscheduled( $hook ) {
		$GLOBALS['sakura_test_unscheduled'][] = $hook;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		sakura_test_record_unscheduled( $hook );
		return 0;
	}
}

if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( $hook ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		sakura_test_record_unscheduled( $hook );
		return 0;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		sakura_test_record_unscheduled( $hook );
		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Multisite switch for tests; off unless a test opts in.
	 *
	 * @return bool
	 */
	function is_multisite() {
		return (bool) ( $GLOBALS['sakura_test_is_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * Site-id list used by the multisite uninstall loop.
	 *
	 * @param array $args Query args; only 'fields' matters here.
	 * @return array<int,int>
	 */
	function get_sites( $args = array() ) {
		return $GLOBALS['sakura_test_site_ids'] ?? array( 1 );
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * Record the blog switch and adopt its table prefix.
	 *
	 * @param int $site_id Blog id.
	 * @return bool
	 */
	function switch_to_blog( $site_id ) {
		$GLOBALS['sakura_test_switched_to'][] = (int) $site_id;
		if ( isset( $GLOBALS['wpdb'] ) && isset( $GLOBALS['sakura_test_prefixes'][ $site_id ] ) ) {
			$GLOBALS['wpdb']->prefix = $GLOBALS['sakura_test_prefixes'][ $site_id ];
		}
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return true;
	}
}

if ( ! class_exists( 'Sakura_Test_Role' ) ) {
	/**
	 * Role double carrying the capability set a test seeds.
	 */
	final class Sakura_Test_Role {

		/**
		 * Capability map, cap => bool.
		 *
		 * @var array<string,bool>
		 */
		private array $caps;

		/**
		 * Duplicate the roles so the WordPress storage can be inspected.
		 *
		 * @param mixed $role_name Role slug.
		 * @return static|null
		 */
		public static function for_name( $role_name ) {
			$roles = $GLOBALS['sakura_test_role_caps'] ?? array();
			if ( ! isset( $roles[ $role_name ] ) ) {
				return null;
			}
			return new self( (array) $roles[ $role_name ] );
		}

		/**
		 * @param array<string,bool> $caps Capability map.
		 */
		public function __construct( array $caps ) {
			$this->caps = $caps;
		}

		/**
		 * @param string $cap Capability name.
		 * @return bool
		 */
		public function has_cap( $cap ) {
			return ! empty( $this->caps[ $cap ] );
		}

		/**
		 * @param string $cap Capability name.
		 * @return void
		 */
		public function remove_cap( $cap ) {
			unset( $this->caps[ $cap ] );
			foreach ( (array) ( $GLOBALS['sakura_test_role_caps'] ?? array() ) as $role_name => $role_caps ) {
				if ( isset( $role_caps[ $cap ] ) ) {
					unset( $GLOBALS['sakura_test_role_caps'][ $role_name ][ $cap ] );
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	/**
	 * Minimal wp_roles() double exposing the seeded role list.
	 *
	 * @return object
	 */
	function wp_roles() {
		$roles = $GLOBALS['sakura_test_role_caps'] ?? array();
		return (object) array( 'roles' => $roles );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * @param string $role_name Role slug.
	 * @return Sakura_Test_Role|null
	 */
	function get_role( $role_name ) {
		return Sakura_Test_Role::for_name( $role_name );
	}
}

if ( ! class_exists( 'Sakura_Test_Wpdb', false ) ) {
	/**
	 * Recording $wpdb double for uninstall assertions.
	 *
	 * Rows are not stored: the point is to see which statements a cleanup
	 * routine issues, and which like patterns it feeds the transient sweep.
	 */
	final class Sakura_Test_Wpdb {

		/** @var string */
		public $prefix = 't_';

		/** @var string */
		public $options = 't_options';

		/** @var string */
		public $postmeta = 't_postmeta';

		/** @var string */
		public $usermeta = 't_usermeta';

		/** @var array<int,string> */
		public $queries = array();

		/** @var array<int,array{0:string,1:array<string,mixed>}> */
		public $deletes = array();

		/** @var array<int,mixed> */
		public $prepared = array();

		/** @var mixed */
		private $previous = null;

		/** @var bool */
		private $had_previous = false;

		/**
		 * Put this double in place of the real connection.
		 *
		 * @return void
		 */
		public function install(): void {
			$this->had_previous = array_key_exists( 'wpdb', $GLOBALS );
			$this->previous     = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb']    = $this;
		}

		/**
		 * Restore whatever was there before.
		 *
		 * @return void
		 */
		public function restore(): void {
			if ( $this->had_previous ) {
				$GLOBALS['wpdb'] = $this->previous;
				return;
			}
			unset( $GLOBALS['wpdb'] );
		}

		/**
		 * @param string $text Raw pattern.
		 * @return string
		 */
		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared[] = $args[0] ?? null;
			return (string) $query;
		}

		/**
		 * @param string $sql Statement.
		 * @return bool
		 */
		public function query( $sql ) {
			$this->queries[] = (string) $sql;
			return true;
		}

		/**
		 * Baseline readers. They answer "nothing there" the way a real
		 * connection would when the row does not exist, so code that runs an
		 * optional lookup cannot blow up on the double's missing method.
		 *
		 * @param string $query Query.
		 * @param string $output Output format.
		 * @param int    $y Row index.
		 * @return object|null
		 */
		public function get_row( $query, $output = OBJECT, $y = 0 ) {
			$this->queries[] = (string) $query;
			return null;
		}

		/**
		 * @param string $query Query.
		 * @param int    $x Column index.
		 * @param int    $y Row index.
		 * @return string|null
		 */
		public function get_var( $query, $x = 0, $y = 0 ) {
			$this->queries[] = (string) $query;
			return null;
		}

		/**
		 * @param string $query Query.
		 * @param string $output Output format.
		 * @return array<int,mixed>
		 */
		public function get_results( $query, $output = OBJECT ) {
			$this->queries[] = (string) $query;
			return array();
		}

		/**
		 * @param string $query Query.
		 * @param int    $x Column index.
		 * @return array<int,string>
		 */
		public function get_col( $query = '', $x = 0 ) {
			$this->queries[] = (string) $query;
			return array();
		}

		/**
		 * @param string              $table Table name.
		 * @param array<string,mixed> $data  Columns.
		 * @return int|false
		 */
		public function insert( $table, $data ) {
			$this->queries[] = (string) $table;
			return false;
		}

		/**
		 * @param string              $table        Table name.
		 * @param array<string,mixed> $data         Columns.
		 * @param array<string,mixed> $where        Conditions.
		 * @return int|false
		 */
		public function update( $table, $data, $where ) {
			$this->queries[] = (string) $table;
			return 0;
		}

		/**
		 * @param string               $table Table name.
		 * @param array<string,mixed>  $where Column => value.
		 * @return int
		 */
		public function delete( $table, $where ) {
			$this->deletes[] = array( (string) $table, (array) $where );
			return 1;
		}

		/**
		 * LIKE operands handed to prepare(), unescaped so the assertion can be
		 * written with the real transient names.
		 *
		 * @return array<int,string>
		 */
		public function likes(): array {
			$patterns = array();
			foreach ( $this->prepared as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$patterns[] = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $value );
			}
			return $patterns;
		}

		/**
		 * Meta keys deleted from the usermeta table.
		 *
		 * @return array<int,string>
		 */
		public function usermeta_keys(): array {
			$keys = array();
			foreach ( $this->deletes as $delete ) {
				if ( $delete[0] !== $this->usermeta || ! isset( $delete[1]['meta_key'] ) ) {
					continue;
				}
				$keys[] = (string) $delete[1]['meta_key'];
			}
			return $keys;
		}

		/**
		 * Invoke a private Uninstaller method.
		 *
		 * @param string $method Method name.
		 * @return void
		 */
		public function invoke( $method ): void {
			$reflection = new ReflectionMethod( \Sakura\ImageOptimizer\Uninstaller::class, $method );
			$reflection->setAccessible( true );
			$reflection->invoke( null );
		}
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

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	/**
	 * Same stub as wp_remote_post: production uses the safe variant.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	function wp_safe_remote_post( $url, $args = array() ) {
		return wp_remote_post( $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Minimal body shim.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal JSON shim.
	 *
	 * @param mixed $value   Value.
	 * @param int   $options Flags.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test shim.
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

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Admin-context shim; tests flip $GLOBALS['sakura_test_is_admin'].
	 *
	 * @return bool
	 */
	function is_admin() {
		return (bool) ( $GLOBALS['sakura_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * AJAX-context shim; tests flip $GLOBALS['sakura_test_doing_ajax'].
	 *
	 * @return bool
	 */
	function wp_doing_ajax() {
		return (bool) ( $GLOBALS['sakura_test_doing_ajax'] ?? false );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Attachment-type shim; defaults to true, tests override per id.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	function wp_attachment_is_image( $attachment_id ) {
		$map = $GLOBALS['sakura_test_attachment_is_image'] ?? array();
		if ( is_array( $map ) && array_key_exists( $attachment_id, $map ) ) {
			return (bool) $map[ $attachment_id ];
		}

		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	/**
	 * Attachment URL shim backed by $GLOBALS['sakura_test_attachment_urls'].
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false
	 */
	function wp_get_attachment_url( $attachment_id ) {
		$urls = $GLOBALS['sakura_test_attachment_urls'] ?? array();
		return $urls[ $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * Attached-file shim backed by $GLOBALS['sakura_test_attached_files'].
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $unfiltered    Ignored.
	 * @return string|false
	 */
	function get_attached_file( $attachment_id, $unfiltered = false ) {
		unset( $unfiltered );
		$files = $GLOBALS['sakura_test_attached_files'] ?? array();
		return $files[ $attachment_id ] ?? '';
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability shim; tests flip $GLOBALS['sakura_test_can'] to deny access.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		unset( $capability );
		return (bool) ( $GLOBALS['sakura_test_can'] ?? true );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Minimal user meta shim backed by a test global.
	 *
	 * @param int    $user_id  User id.
	 * @param string $key      Meta key.
	 * @param bool   $single   Whether to return a single value.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['sakura_test_user_meta'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Minimal user meta writer.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['sakura_test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Minimal user meta remover.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		$existed = isset( $GLOBALS['sakura_test_user_meta'][ $user_id ][ $key ] );
		unset( $GLOBALS['sakura_test_user_meta'][ $user_id ][ $key ] );
		return $existed;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Deterministic nonce shim.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action ) {
		return 'nonce-' . (string) $action;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Minimal escaping shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Minimal attribute escaping shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Minimal URL escaping shim.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escaped translation shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = '' ) {
		unset( $domain );
		return esc_html( (string) $text );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Escaped translated-attribute shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_attr_e( $text, $domain = '' ) {
		unset( $domain );
		echo esc_attr( (string) $text );
	}
}
