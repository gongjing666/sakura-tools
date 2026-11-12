<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Backup\Backup_Repository;
use Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface;
use Sakura\ImageOptimizer\Image\Crop_Config_Scanner;
use Sakura\ImageOptimizer\Image\Size_Catalog;
use Sakura\ImageOptimizer\Settings\Settings_Repository;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 后台设置页面
 */
class Image_Optimizer_Module implements Module {

	private $option_group = 'sakura_imgopt_settings';
	private $menu_slug    = 'sakura-tools-optimize';
	private $backup_group = 'sakura_imgopt_backup_settings';
	private $backup_slug  = 'sakura-tools-backup';

	/**
	 * Stats persistence boundary.
	 *
	 * @var Stats_Repository_Interface
	 */
	private Stats_Repository_Interface $stats;

	/**
	 * Backup persistence boundary.
	 *
	 * @var Backup_Repository_Interface
	 */
	private Backup_Repository_Interface $backup;

	/**
	 * Settings persistence boundary.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param Stats_Repository_Interface|null    $stats    Stats repository.
	 * @param Backup_Repository_Interface|null   $backup   Backup repository.
	 * @param Settings_Repository_Interface|null $settings Settings repository.
	 */
	public function __construct( ?Stats_Repository_Interface $stats = null, ?Backup_Repository_Interface $backup = null, ?Settings_Repository_Interface $settings = null ) {
		$this->stats    = $stats ?? new Stats_Repository();
		$this->backup   = $backup ?? new Backup_Repository();
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * Register admin hooks with the central loader.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		$hooks->add_action( 'admin_post_sakura_imgopt_reset_stats', array( $this, 'handle_reset_stats' ) );
		$hooks->add_action( 'wp_ajax_sakura_imgopt_scan_crop', array( $this, 'handle_scan_crop_ajax' ) );
		$hooks->add_action( 'wp_ajax_sakura_imgopt_stats_page', array( $this, 'ajax_stats_page' ) );
	}

	/**
	 * Register settings.
	 *
	 * The menu itself lives in Admin\Menu; this module only owns the sections
	 * and fields of the 图片优化 module.
	 */
	public function register_settings() {
		// ── 节：禁止 WordPress 裁剪 ──────────────────
		add_settings_section(
			'sakura_imgopt_section_thumbs',
			__( '禁止 WordPress 裁剪', 'sakura-imgopt' ),
			array( $this, 'section_thumbs_cb' ),
			$this->menu_slug
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_disabled_sizes',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_disabled_sizes' ),
				'default'           => array(),
			)
		);
		add_settings_field(
			'sakura_imgopt_disabled_sizes',
			__( '选择要禁止的裁剪尺寸', 'sakura-imgopt' ),
			array( $this, 'field_size_checklist_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_thumbs',
			array( 'label_for' => 'sakura_imgopt_disabled_sizes' )
		);

		// ── 节：上传文件重命名 ─────────────────────────
		add_settings_section(
			'sakura_imgopt_section_rename',
			__( '上传文件重命名', 'sakura-imgopt' ),
			array( $this, 'section_rename_cb' ),
			$this->menu_slug
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_rename_enabled',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		add_settings_field(
			'sakura_imgopt_rename_enabled',
			__( '开启重命名', 'sakura-imgopt' ),
			array( $this, 'field_checkbox_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_rename',
			array(
				'label_for' => 'sakura_imgopt_rename_enabled',
				'desc'      => __( '上传时按设定规则重命名文件，避免文件名重复，减少媒体库里出现重复文件。', 'sakura-imgopt' ),
			)
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_rename_rule',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_rename_rule' ),
				'default'           => '{date}-{time}-{rand}',
			)
		);
		add_settings_field(
			'sakura_imgopt_rename_rule',
			__( '重命名规则', 'sakura-imgopt' ),
			array( $this, 'field_rename_rule_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_rename',
			array( 'label_for' => 'sakura_imgopt_rename_rule' )
		);

		// ── 节：图片压缩 ────────────────────────────
		add_settings_section(
			'sakura_imgopt_section_compress',
			__( '图片压缩与格式转换', 'sakura-imgopt' ),
			array( $this, 'section_compress_cb' ),
			$this->menu_slug
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_enable_compression',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		add_settings_field(
			'sakura_imgopt_enable_compression',
			__( '开启上传压缩', 'sakura-imgopt' ),
			array( $this, 'field_checkbox_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array(
				'label_for' => 'sakura_imgopt_enable_compression',
				'desc'      => __( '上传图片时自动压缩/转换格式', 'sakura-imgopt' ),
			)
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_compression_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_mode' ),
				'default'           => 'compress_only',
			)
		);
		add_settings_field(
			'sakura_imgopt_compression_mode',
			__( '压缩模式', 'sakura-imgopt' ),
			array( $this, 'field_mode_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array( 'label_for' => 'sakura_imgopt_compression_mode' )
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_quality',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => 82,
			)
		);
		add_settings_field(
			'sakura_imgopt_quality',
			__( '压缩质量', 'sakura-imgopt' ),
			array( $this, 'field_quality_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array(
				'label_for' => 'sakura_imgopt_quality',
				'desc'      => __( '数字越小文件越小，数字越大画质越好。推荐 75 到 85。', 'sakura-imgopt' ),
			)
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_convert_format',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_format' ),
				'default'           => 'webp',
			)
		);
		add_settings_field(
			'sakura_imgopt_convert_format',
			__( '目标格式', 'sakura-imgopt' ),
			array( $this, 'field_format_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array( 'label_for' => 'sakura_imgopt_convert_format' )
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_source_formats',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_source_formats' ),
				'default'           => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'apng' ),
			)
		);
		add_settings_field(
			'sakura_imgopt_source_formats',
			__( '参与处理的源格式', 'sakura-imgopt' ),
			array( $this, 'field_source_formats_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array( 'label_for' => 'sakura_imgopt_source_formats' )
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_max_file_size_mb',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_file_size_mb' ),
				'default'           => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_FILE_SIZE_MB,
			)
		);
		register_setting(
			$this->option_group,
			'sakura_imgopt_max_image_width',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_dimension' ),
				'default'           => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_WIDTH,
			)
		);
		register_setting(
			$this->option_group,
			'sakura_imgopt_max_image_height',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_dimension' ),
				'default'           => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_HEIGHT,
			)
		);
		add_settings_field(
			'sakura_imgopt_processing_limits',
			__( '大图放行上限', 'sakura-imgopt' ),
			array( $this, 'field_processing_limits_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array( 'label_for' => 'sakura_imgopt_max_file_size_mb' )
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_backup_enabled',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		add_settings_field(
			'sakura_imgopt_backup_enabled',
			__( '备份原图', 'sakura-imgopt' ),
			array( $this, 'field_checkbox_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_compress',
			array(
				'label_for' => 'sakura_imgopt_backup_enabled',
				'default'   => 1,
				'desc'      => __( '处理图片前，先把原图复制到服务器上的备份文件夹，方便以后一键还原。只有真正被处理的图片才会保留备份；关闭后不再创建新备份，已经存在的备份仍可还原。还原成功后，这次备份会自动删除。', 'sakura-imgopt' ),
			)
		);

		// ── 节：备份清理策略（绑定到「备份管理」页，独立分组）──────────
		add_settings_section(
			'sakura_imgopt_section_backup_policy',
			__( '自动清理策略', 'sakura-imgopt' ),
			array( $this, 'section_backup_policy_cb' ),
			$this->backup_slug
		);

		register_setting(
			$this->backup_group,
			'sakura_imgopt_backup_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => 0,
			)
		);
		add_settings_field(
			'sakura_imgopt_backup_retention_days',
			__( '保留天数', 'sakura-imgopt' ),
			array( $this, 'field_retention_days_cb' ),
			$this->backup_slug,
			'sakura_imgopt_section_backup_policy',
			array( 'label_for' => 'sakura_imgopt_backup_retention_days' )
		);

		register_setting(
			$this->backup_group,
			'sakura_imgopt_backup_max_mb',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_mb' ),
				'default'           => 0,
			)
		);
		add_settings_field(
			'sakura_imgopt_backup_max_mb',
			__( '容量上限', 'sakura-imgopt' ),
			array( $this, 'field_max_mb_cb' ),
			$this->backup_slug,
			'sakura_imgopt_section_backup_policy',
			array( 'label_for' => 'sakura_imgopt_backup_max_mb' )
		);

		register_setting(
			$this->backup_group,
			'sakura_imgopt_delete_data_on_uninstall',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		add_settings_field(
			'sakura_imgopt_delete_data_on_uninstall',
			__( '卸载时删除数据', 'sakura-imgopt' ),
			array( $this, 'field_checkbox_cb' ),
			$this->backup_slug,
			'sakura_imgopt_section_backup_policy',
			array(
				'label_for' => 'sakura_imgopt_delete_data_on_uninstall',
				'default'   => 0,
				'desc'      => __( '默认关闭：卸载插件后仍保留设置、云存储密钥、统计数据与备份文件，重新安装可以接着用。开启后卸载会删除这些数据，并把本插件改过的图片地址和上传目录恢复原样。', 'sakura-imgopt' ),
			)
		);

		// ── 节：批量优化性能 ───────────────────────────
		add_settings_section(
			'sakura_imgopt_section_bulk',
			__( '批量优化性能', 'sakura-imgopt' ),
			array( $this, 'section_bulk_cb' ),
			$this->menu_slug
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_batch_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
				'default'           => 1,
			)
		);
		add_settings_field(
			'sakura_imgopt_batch_size',
			__( '每批处理张数', 'sakura-imgopt' ),
			array( $this, 'field_batch_size_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_bulk',
			array(
				'label_for' => 'sakura_imgopt_batch_size',
				'desc'      => __( '每次处理几张图片（1 到 20）。调大速度更快，但更占服务器资源；配置一般的服务器建议保持 1。', 'sakura-imgopt' ),
			)
		);

		register_setting(
			$this->option_group,
			'sakura_imgopt_ajax_delay',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_ajax_delay' ),
				'default'           => 500,
			)
		);
		add_settings_field(
			'sakura_imgopt_ajax_delay',
			__( '批次间延迟（毫秒）', 'sakura-imgopt' ),
			array( $this, 'field_ajax_delay_cb' ),
			$this->menu_slug,
			'sakura_imgopt_section_bulk',
			array(
				'label_for' => 'sakura_imgopt_ajax_delay',
				'desc'      => __( '每批之间的休息时间（0–2000 毫秒）。调大对前端访问更友好，调小处理更快。推荐 300–500。', 'sakura-imgopt' ),
			)
		);

		// 每张卡片只提交自己的字段；未提交的 option 保持原值。
		Card_Form::guard_options(
			$this->option_group,
			array(
				'thumbs'  => array( 'sakura_imgopt_disabled_sizes' ),
				'rename'  => array( 'sakura_imgopt_rename_enabled', 'sakura_imgopt_rename_rule' ),
				'compress' => array(
					'sakura_imgopt_enable_compression',
					'sakura_imgopt_compression_mode',
					'sakura_imgopt_convert_format',
					'sakura_imgopt_source_formats',
					'sakura_imgopt_quality',
					'sakura_imgopt_max_file_size_mb',
					'sakura_imgopt_max_image_width',
					'sakura_imgopt_max_image_height',
					'sakura_imgopt_backup_enabled',
				),
				'bulk'    => array( 'sakura_imgopt_batch_size', 'sakura_imgopt_ajax_delay' ),
			)
		);
		Card_Form::guard_options(
			$this->backup_group,
			array(
				'backup_policy' => array(
					'sakura_imgopt_backup_retention_days',
					'sakura_imgopt_backup_max_mb',
					'sakura_imgopt_delete_data_on_uninstall',
				),
			)
		);
	}

	// ── 回调函数 ──────────────────────────────────────

	public function section_bulk_cb() {
		echo '<p>' . esc_html__( '媒体库「图像优化」批量处理时的性能参数。批量优化采用分批异步处理，避免请求超时和服务器过载。', 'sakura-imgopt' ) . '</p>';
		echo '<p class="card__note-alert">'
			. esc_html__( '注意：批量优化仅适用于存储在本地服务器的图片。如果媒体库已经被阿里云 OSS、腾讯云 COS、七牛、又拍云等云存储插件接管，图片实际在云端、本地没有副本，这里的批量优化就无法处理。这种情况建议在「上传时」就完成压缩和格式转换。', 'sakura-imgopt' )
			. '</p>';
	}

	public function field_batch_size_cb( $args ) {
		$val = (int) $this->settings->get( 'batch_size', 1 );
		printf(
			'<input type="number" id="sakura_imgopt_batch_size" name="sakura_imgopt_batch_size" value="%s" min="1" max="20" step="1" style="width:80px" />',
			esc_attr( (string) $val )
		);
		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function field_ajax_delay_cb( $args ) {
		$val = (int) $this->settings->get( 'ajax_delay', 500 );
		printf(
			'<input type="number" id="sakura_imgopt_ajax_delay" name="sakura_imgopt_ajax_delay" value="%s" min="0" max="2000" step="100" style="width:80px" /> %s',
			esc_attr( (string) $val ),
			esc_html__( '毫秒', 'sakura-imgopt' )
		);
		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function section_thumbs_cb() {
		echo '<p>' . esc_html__( '勾选你不需要的裁剪尺寸。WordPress 将停止生成被勾选的尺寸，节省存储空间。原图始终保留。', 'sakura-imgopt' ) . '</p>';
		echo '<p>' . esc_html__( '点击「扫描裁剪配置」，可以查看站点配置文件、当前主题和已启用插件分别设置了哪些尺寸，以及每个尺寸现在会不会生成。', 'sakura-imgopt' ) . '</p>';
	}

	/**
	 * 渲染「按尺寸禁用 WordPress 裁剪」列表与配置扫描入口。
	 */
	public function field_size_checklist_cb( $args ) {
		$show_label = ! empty( $args['show_label'] );
		$label      = $show_label && isset( $args['label'] ) ? (string) $args['label'] : '';
		unset( $args );
		$disabled  = Thumbnail_Manager::get_disabled_sizes();
		$available = $this->get_available_image_sizes();
		$scan      = Crop_Config_Scanner::stored();
		$scan_rows = ( is_array( $scan ) && isset( $scan['sizes'] ) && is_array( $scan['sizes'] ) ) ? $scan['sizes'] : array();

		// 合并当前运行尺寸、上次扫描结果与禁用标记：已从主题/插件移除的尺寸也要可见，才能取消勾选。
		$names = array_keys( $available );
		foreach ( array_merge( Crop_Config_Scanner::stored_names(), $disabled ) as $stored_name ) {
			$stored_name = Size_Catalog::sanitize_name( (string) $stored_name );
			if ( '' !== $stored_name && ! in_array( $stored_name, $names, true ) ) {
				$names[] = $stored_name;
			}
		}

		if ( empty( $names ) ) {
			echo '<p class="description">' . esc_html__( '当前未注册任何中间尺寸。', 'sakura-imgopt' ) . '</p>';
			return;
		}

		$summary_text   = is_array( $scan ) ? $this->format_scan_summary( $scan ) : __( '尚未扫描', 'sakura-imgopt' );
		$wp_config_text = is_array( $scan ) ? $this->format_wp_config_note( $scan ) : __( 'wp-config.php：尚未扫描。', 'sakura-imgopt' );
		$warnings       = array();
		if ( is_array( $scan ) && isset( $scan['warnings'] ) && is_array( $scan['warnings'] ) ) {
			$warnings = $scan['warnings'];
		}

		echo '<div class="sakura-imgopt-scan-bar">';
		if ( '' !== $label ) {
			printf( '<span class="sakura-imgopt-field-label">%s</span>', esc_html( $label ) );
		}
		printf(
			'<button type="button" class="btn btn--ghost btn--small" id="sakura-imgopt-scan-crop">%s</button>',
			esc_html__( '扫描裁剪配置', 'sakura-imgopt' )
		);
		printf(
			'<span class="sakura-imgopt-scan-summary" id="sakura-imgopt-scan-result">%s</span>',
			esc_html( $summary_text )
		);
		echo '</div>';
		echo '<p class="description">' . esc_html__( '扫描会读取站点配置、当前主题和已启用插件的设置，按「WordPress 自带 → 主题 → 插件 → 本插件禁用清单」的顺序判断每个尺寸来自哪里。同一个尺寸被多处设置时，后面的会覆盖前面的；实际已经注册的尺寸优先。扫描只更新「来源」和「状态」，不会改动你的勾选。', 'sakura-imgopt' ) . '</p>';

		printf(
			'<div class="sakura-imgopt-scan-warnings" id="sakura-imgopt-scan-warnings"%s><strong>%s</strong><ul>%s</ul></div>',
			empty( $warnings ) ? ' hidden' : '',
			esc_html__( '扫描提示', 'sakura-imgopt' ),
			wp_kses_post( implode( '', array_map( static function ( $warning ) { return '<li>' . esc_html( (string) ( $warning['message'] ?? '' ) ) . '</li>'; }, $warnings ) ) )
		);

		echo '<div class="sakura-imgopt-size-scroll"><fieldset class="sakura-imgopt-size-fieldset">';
		echo '<table class="sakura-imgopt-size-table"><thead><tr>';
		echo '<th class="sakura-imgopt-col-check">' . esc_html__( '禁用', 'sakura-imgopt' ) . '</th>';
		echo '<th>' . esc_html__( '尺寸名称', 'sakura-imgopt' ) . '</th>';
		echo '<th>' . esc_html__( '宽 × 高', 'sakura-imgopt' ) . '</th>';
		echo '<th>' . esc_html__( '生成状态', 'sakura-imgopt' ) . '</th>';
		echo '<th>' . esc_html__( '裁剪方式', 'sakura-imgopt' ) . '</th>';
		echo '<th>' . esc_html__( '来源', 'sakura-imgopt' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $names as $size_key ) {
			if ( '_big_threshold' === $size_key ) {
				// 大图缩放阈值单独成行，避免与普通尺寸重复。
				continue;
			}

			$info           = $available[ $size_key ] ?? null;
			$scan_row       = $scan_rows[ $size_key ] ?? null;
			$is_disabled    = in_array( $size_key, $disabled, true );
			$width          = null !== $info ? (int) $info['width'] : (int) ( $scan_row['width'] ?? 0 );
			$height         = null !== $info ? (int) $info['height'] : (int) ( $scan_row['height'] ?? 0 );
			$crop           = null !== $info ? ! empty( $info['crop'] ) : ! empty( $scan_row['crop'] );
			$status         = 'enabled';
			$status_label   = __( '正常生成', 'sakura-imgopt' );
			$reason         = '';
			$sources        = array();

			if ( is_array( $scan_row ) ) {
				// Sources are kept even when the plugin overrides the status, so a
				// disabled core/theme size still shows where it came from.
				$reason  = (string) ( $scan_row['reason'] ?? '' );
				$sources = isset( $scan_row['sources'] ) && is_array( $scan_row['sources'] ) ? $scan_row['sources'] : array();
			}

			if ( $is_disabled ) {
				$status       = 'disabled_by_plugin';
				$status_label = __( '已禁止', 'sakura-imgopt' );
			} elseif ( is_array( $scan_row ) ) {
				$status       = (string) ( $scan_row['status'] ?? 'unknown' );
				$status_label = (string) ( $scan_row['status_label'] ?? $status_label );
			}

			$source_labels = array();
			$source_titles = array();
			foreach ( $sources as $source ) {
				$label = (string) ( $source['label'] ?? '' );
				if ( '' === $label || isset( $source_labels[ $label ] ) ) {
					continue;
				}
				$source_labels[ $label ] = $label;
				$source_titles[]         = $label . '：' . (string) ( $source['detail'] ?? '' );
			}

			if ( empty( $source_labels ) ) {
				if ( is_array( $scan_row ) && ! empty( $scan_row['registered'] ) ) {
					$sources_html = esc_html__( '运行中已注册，未定位静态来源', 'sakura-imgopt' );
				} elseif ( 'large' === $size_key ) {
					$sources_html = esc_html__( 'WordPress 核心（设置中已关闭）', 'sakura-imgopt' );
				} elseif ( $is_disabled ) {
					$sources_html = '—';
				} else {
					$sources_html = esc_html__( '未扫描', 'sakura-imgopt' );
				}
			} else {
				$tags = array();
				foreach ( $source_labels as $label ) {
					$tags[] = sprintf(
						'<span class="sakura-imgopt-source-tag" title="%s">%s</span>',
						esc_attr( implode( "\n", $source_titles ) ),
						esc_html( $label )
					);
				}
				$sources_html = implode( ' ', $tags );
			}

			$dims_label = ( $width > 0 || $height > 0 )
				? ( $width > 0 ? (string) $width : __( '自动', 'sakura-imgopt' ) ) . ' × ' . ( $height > 0 ? (string) $height : __( '自动', 'sakura-imgopt' ) )
				: '—';

			$status_class = 'ok';
			if ( 'disabled_by_plugin' === $status ) {
				$status_class = 'neutral';
			} elseif ( in_array( $status, array( 'removed', 'filtered' ), true ) ) {
				$status_class = 'warn';
			} elseif ( in_array( $status, array( 'unregistered', 'unknown' ), true ) ) {
				$status_class = 'bad';
			}
			if ( ! empty( $scan_row['conflict'] ) ) {
				$status_class .= ' conflict';
				$reason        = trim( $reason . ' ' . __( '多来源宽高不一致，已按运行时值显示。', 'sakura-imgopt' ) );
			}

			printf(
				'<tr data-size="%1$s"><td class="sakura-imgopt-col-check"><input type="checkbox" name="sakura_imgopt_disabled_sizes[]" value="%1$s" %2$s /></td><td><code>%1$s</code></td><td data-field="dims">%3$s</td><td data-field="status"><span class="sakura-imgopt-status %4$s"%5$s>%6$s</span></td><td data-field="crop">%7$s</td><td data-field="sources">%8$s</td></tr>',
				esc_attr( $size_key ),
				checked( $is_disabled, true, false ),
				esc_html( $dims_label ),
				esc_attr( $status_class ),
				'' !== $reason ? ' title="' . esc_attr( $reason ) . '"' : '',
				esc_html( $status_label ),
				esc_html( '—' === $dims_label ? '—' : ( $crop ? __( '硬裁剪', 'sakura-imgopt' ) : __( '等比缩放', 'sakura-imgopt' ) ) ),
				$sources_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments above.
			);
		}

		$big_disabled = in_array( '_big_threshold', $disabled, true );
		$big_status   = $big_disabled ? __( '已禁止', 'sakura-imgopt' ) : __( '正常启用', 'sakura-imgopt' );
		$big_class    = $big_disabled ? 'neutral' : 'ok';
		$big_reason   = $big_disabled
			? __( '已在本插件中禁止，上传超大图时不会再生成 2560 像素的缩放版。', 'sakura-imgopt' )
			: __( '未在本插件中禁止，上传超大图时 WordPress 会照常生成 2560 像素的缩放版。', 'sakura-imgopt' );
		printf(
			'<tr data-size="_big_threshold"><td class="sakura-imgopt-col-check"><input type="checkbox" name="sakura_imgopt_disabled_sizes[]" value="_big_threshold" %s /></td><td><code>' . esc_html__( '2560px 缩放版', 'sakura-imgopt' ) . '</code></td><td data-field="dims">2560px+</td><td data-field="status"><span class="sakura-imgopt-status %s" title="%s">%s</span></td><td data-field="crop">%s</td><td data-field="sources">' . esc_html__( 'WordPress 自带规则', 'sakura-imgopt' ) . '</td></tr>',
			checked( $big_disabled, true, false ),
			esc_attr( $big_class ),
			esc_attr( $big_reason ),
			esc_html( $big_status ),
			esc_html__( '超大图缩放', 'sakura-imgopt' )
		);

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( '未勾选的尺寸会正常生成。一般建议保留 thumbnail（缩略图）和 medium（中等），其余按需禁用；来源为「仅静态发现」的尺寸当前不会生成，可以放心勾选。', 'sakura-imgopt' ) . '</p>';
		printf( '<p class="description" id="sakura-imgopt-scan-wpconfig">%s</p>', esc_html( $wp_config_text ) );
		echo '</fieldset></div>';
	}

	/**
	 * Human-readable scan summary.
	 *
	 * @param array<string,mixed> $scan Stored scan result.
	 * @return string
	 */
	private function format_scan_summary( array $scan ): string {
		$summary = isset( $scan['summary'] ) && is_array( $scan['summary'] ) ? $scan['summary'] : array();
		$pending = (int) ( $summary['unregistered'] ?? 0 ) + (int) ( $summary['filtered'] ?? 0 ) + (int) ( $summary['unknown'] ?? 0 );

		$text = sprintf(
			/* translators: 1: scan time, 2: duration in ms, 3: total, 4: enabled, 5: disabled, 6: removed, 7: pending, 8: conflicts */
			__( '上次扫描：%1$s（%2$d ms）· 尺寸 %3$d：正常 %4$d / 已禁止 %5$d / 已移除 %6$d / 待核实 %7$d / 冲突 %8$d', 'sakura-imgopt' ),
			wp_date( 'Y-m-d H:i:s', (int) ( $scan['generated_at'] ?? time() ) ),
			(int) ( $scan['duration_ms'] ?? 0 ),
			(int) ( $summary['total'] ?? 0 ),
			(int) ( $summary['enabled'] ?? 0 ),
			(int) ( $summary['disabled'] ?? 0 ),
			(int) ( $summary['removed'] ?? 0 ),
			$pending,
			(int) ( $summary['conflicts'] ?? 0 )
		);

		$theme = isset( $scan['theme'] ) && is_array( $scan['theme'] ) ? $scan['theme'] : array();
		$text .= sprintf(
			/* translators: %d: files scanned in the active theme */
			__( ' · 主题 %d 个文件', 'sakura-imgopt' ),
			(int) ( $theme['files_scanned'] ?? 0 )
		);

		$plugins      = isset( $scan['plugins'] ) && is_array( $scan['plugins'] ) ? $scan['plugins'] : array();
		$plugin_files = 0;
		foreach ( $plugins as $plugin ) {
			if ( is_array( $plugin ) ) {
				$plugin_files += (int) ( $plugin['files_scanned'] ?? 0 );
			}
		}
		$text .= sprintf(
			/* translators: 1: number of plugins, 2: files scanned across plugins */
			__( ' · 插件 %1$d 个（%2$d 个文件）', 'sakura-imgopt' ),
			count( $plugins ),
			$plugin_files
		);

		return $text;
	}

	/**
	 * Compact wp-config.php scan note.
	 *
	 * @param array<string,mixed> $scan Stored scan result.
	 * @return string
	 */
	private function format_wp_config_note( array $scan ): string {
		$config = isset( $scan['wp_config'] ) && is_array( $scan['wp_config'] ) ? $scan['wp_config'] : array();
		$path   = (string) ( $config['path'] ?? '' );
		$label  = 'wp-config.php';
		$where  = ( '' === $path || $label === $path ) ? $label : sprintf( '%s（%s）', $label, $path );
		if ( empty( $config['readable'] ) ) {
			return sprintf(
				/* translators: %s: wp-config.php label/path. */
				__( '%s：未找到或不可读，已跳过常量扫描。', 'sakura-imgopt' ),
				$where
			);
		}

		$constants = isset( $config['constants'] ) && is_array( $config['constants'] ) ? $config['constants'] : array();
		$defined   = array();
		foreach ( $constants as $constant ) {
			if ( is_array( $constant ) && ! empty( $constant['defined'] ) ) {
				$defined[] = sprintf( '%s=%s', (string) ( $constant['name'] ?? '' ), (string) ( $constant['display'] ?? '' ) );
			}
		}

		return sprintf(
			/* translators: 1: wp-config label/path, 2: total constants, 3: defined count, 4: defined values or default note */
			__( '%1$s：相关常量 %2$d 个，已定义 %3$d 个%4$s', 'sakura-imgopt' ),
			$where,
			count( $constants ),
			count( $defined ),
			empty( $defined ) ? __( '（均使用默认值）', 'sakura-imgopt' ) : '（' . implode( '、', $defined ) . '）'
		);
	}

	public function section_rename_cb() {
		echo '<p>' . esc_html__( '上传图片时按规则重命名文件，避免中文、特殊字符或重名带来的兼容问题。', 'sakura-imgopt' ) . '</p>';
	}

	public function section_compress_cb() {
		echo '<p>' . esc_html__( '上传图片时按所选模式压缩或转换格式；转换结果会替换媒体库中的原图 URL，原图可按需备份。', 'sakura-imgopt' ) . '</p>';
	}

	/**
	 * 通用开关字段。
	 *
	 * @param array<string, mixed> $args 字段参数（label_for、desc）。
	 */
	public function field_checkbox_cb( $args ) {
		$key = isset( $args['label_for'] ) ? (string) $args['label_for'] : '';
		if ( '' === $key ) {
			return;
		}

		$value = (int) $this->settings->get( $key );

		// A hidden zero makes an unchecked card checkbox save as 0 instead of
		// disappearing from the request.
		printf(
			'<input type="hidden" name="%1$s" value="0" />',
			esc_attr( $key )
		);
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( 1, $value, false ),
			esc_html__( '启用', 'sakura-imgopt' )
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( (string) $args['desc'] ) );
		}
	}

	/**
	 * 重命名规则字段。
	 *
	 * @param array<string, mixed> $args 字段参数。
	 */
	public function field_rename_rule_cb( $args ) {
		unset( $args );

		printf(
			'<input type="text" class="regular-text" id="sakura_imgopt_rename_rule" name="sakura_imgopt_rename_rule" value="%s" />',
			esc_attr( $this->settings->rename_rule() )
		);
		echo '<p class="description">' . esc_html__( '可用占位符：{filename} 原文件名、{ext} 扩展名、{date} 日期、{time} 时间、{year}/{month}/{day}、{rand} 5 位随机、{rand8} 8 位随机。示例：{date}-{time}-{rand}', 'sakura-imgopt' ) . '</p>';
	}

	/**
	 * 清洗重命名规则：保留受支持的占位符，其余花括号标记移除。
	 *
	 * @param mixed $value 原始值。
	 * @return string
	 */
	public function sanitize_rename_rule( $value ) {
		$rule = sanitize_text_field( (string) $value );
		if ( '' === $rule ) {
			return '{date}-{time}-{rand}';
		}

		$allowed = array( '{filename}', '{ext}', '{date}', '{time}', '{year}', '{month}', '{day}', '{rand}', '{rand8}' );
		$rule    = (string) preg_replace_callback(
			'/\{[a-zA-Z0-9_]+\}/',
			static function ( $matches ) use ( $allowed ) {
				return in_array( $matches[0], $allowed, true ) ? $matches[0] : '';
			},
			$rule
		);
		$rule    = (string) preg_replace( '~[\\/:*?"<>|]+~', '-', $rule );
		$rule    = trim( $rule, " -_." );

		return '' === $rule ? '{date}-{time}-{rand}' : $rule;
	}

	public function field_mode_cb() {
		$mode = $this->settings->compression_mode();
		?>
		<fieldset>
			<label>
				<input type="radio" name="sakura_imgopt_compression_mode" value="compress_only" <?php checked( 'compress_only', $mode ); ?> />
				<?php esc_html_e( '仅压缩', 'sakura-imgopt' ); ?>
			</label>
			<label>
				<input type="radio" name="sakura_imgopt_compression_mode" value="compress_convert" <?php checked( 'compress_convert', $mode ); ?> />
				<?php esc_html_e( '压缩并转换格式', 'sakura-imgopt' ); ?>
			</label>
			<label>
				<input type="radio" name="sakura_imgopt_compression_mode" value="convert_only" <?php checked( 'convert_only', $mode ); ?> />
				<?php esc_html_e( '仅转换格式', 'sakura-imgopt' ); ?>
			</label>
		</fieldset>
		<?php
	}

	public function field_format_cb() {
		$format = $this->settings->convert_format();
		?>
		<select class="sakura-imgopt-format-select" id="sakura_imgopt_convert_format" name="sakura_imgopt_convert_format">
			<option value="webp" <?php selected( 'webp', $format ); ?>>WebP</option>
			<option value="avif" <?php selected( 'avif', $format ); ?>>AVIF</option>
		</select>
		<p class="description" id="sakura_imgopt_convert_format_desc">
			<?php esc_html_e( 'AVIF 文件更小，但兼容性略差；需要最大兼容性请选 WebP。这个选项只在「压缩并转换格式」和「仅转换格式」下有效；「仅压缩」会保留原格式，不使用这里的设置。', 'sakura-imgopt' ); ?>
		</p>
		<?php
	}

	public function field_source_formats_cb( $args ) {
		$selected = $this->settings->get( $args['label_for'], \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_SOURCE_FORMATS );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}

		$formats = $this->get_supported_source_formats();
		echo '<fieldset class="sakura-imgopt-source-formats">';
		echo '<div class="sakura-imgopt-source-format-grid">';
		foreach ( $formats as $value => $label ) {
			printf(
				'<label class="sakura-imgopt-source-format-item"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /><span>%4$s</span></label>',
				esc_attr( $args['label_for'] ),
				esc_attr( $value ),
				checked( in_array( $value, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
		echo '<div class="sakura-imgopt-source-format-notes">';
		echo '<p class="description">' . esc_html__( '只有上传图片的源格式命中这里勾选的格式，才会进入压缩/转换流程；未命中的图片只按 WordPress 默认上传流程保存。', 'sakura-imgopt' ) . '</p>';
		echo '<p class="description">' . esc_html__( '注意：SVG、APNG、AVIF 能否处理，取决于服务器上的图片组件（Imagick 或 GD）是否支持。', 'sakura-imgopt' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'SVG 默认不勾选：处理 SVG 时通常会被转成普通图片，文件反而变大。确实需要处理再勾选。', 'sakura-imgopt' ) . '</p>';
		echo '</div>';
		echo '</fieldset>';
	}

	public function field_quality_cb() {
		$quality = (int) $this->settings->quality();
		printf(
			'<input type="number" id="sakura_imgopt_quality" name="sakura_imgopt_quality" value="%s" min="1" max="100" step="1" style="width:80px" />',
			esc_attr( (string) $quality )
		);
	}

	// ── 字段：大图放行上限（体积 + 分辨率）────────────
	public function field_processing_limits_cb() {
		$max_mb = (int) $this->settings->get( 'max_file_size_mb', \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_FILE_SIZE_MB );
		$max_w  = (int) $this->settings->get( 'max_image_width', \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_WIDTH );
		$max_h  = (int) $this->settings->get( 'max_image_height', \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_HEIGHT );
		printf(
			'<input type="number" min="0" max="1024" step="1" style="width:80px" id="sakura_imgopt_max_file_size_mb" name="sakura_imgopt_max_file_size_mb" value="%1$s" /> %2$s &nbsp; ' .
			'<input type="number" min="0" max="20000" step="1" style="width:80px" id="sakura_imgopt_max_image_width" name="sakura_imgopt_max_image_width" value="%3$s" /> × ' .
			'<input type="number" min="0" max="20000" step="1" style="width:80px" id="sakura_imgopt_max_image_height" name="sakura_imgopt_max_image_height" value="%4$s" /> %5$s' .
			'<p class="description">%6$s</p>',
			esc_attr( (string) $max_mb ),
			esc_html__( 'MB', 'sakura-imgopt' ),
			esc_attr( (string) $max_w ),
			esc_attr( (string) $max_h ),
			esc_html__( 'px', 'sakura-imgopt' ),
			esc_html__( '超过其中任意一个上限的图片会直接跳过：不备份、不压缩、也不转换格式，避免服务器处理大图时超时。填 0 表示不限制。默认 24 MB、3840 × 2160（4K，竖图同样适用）。', 'sakura-imgopt' )
		);
	}

	// ── sanitize：大图体积上限（0–1024 MB）────────────
	public function sanitize_max_file_size_mb( $value ) {
		return max( 0, min( 1024, absint( $value ) ) );
	}

	// ── sanitize：分辨率上限（0–20000 px）─────────────
	public function sanitize_max_dimension( $value ) {
		return max( 0, min( 20000, absint( $value ) ) );
	}

	// ── 渲染设置页面 ────────────────────────────────
	public function render_page() {
		$engine       = $this->detect_best_engine();
		$option_group = $this->option_group;
		$menu_slug    = $this->menu_slug;
		require __DIR__ . '/views/settings-main.php';
	}

	// ── 备份清理策略：section 说明 ───────────────────
	public function section_backup_policy_cb() {
		echo '<p>' . esc_html__( '设置原图备份的自动清理规则。留空或填 0 表示不限制。', 'sakura-imgopt' ) . '</p>';
	}

	// ── 字段：保留天数 ───────────────────────────────
	public function field_retention_days_cb() {
		$val = (int) $this->settings->backup_retention_days();
		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%1$s" value="%2$s" class="small-text" /> %3$s<p class="description">%4$s</p>',
			'sakura_imgopt_backup_retention_days',
			esc_attr( (string) $val ),
			esc_html__( '天', 'sakura-imgopt' ),
			esc_html__( '超过这个天数的备份会被每天自动删除。填 0 表示永久保留。', 'sakura-imgopt' )
		);
	}

	// ── 字段：容量上限 ───────────────────────────────
	public function field_max_mb_cb() {
		$val = (int) $this->settings->backup_max_mb();
		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%1$s" value="%2$s" class="small-text" /> %3$s<p class="description">%4$s</p>',
			'sakura_imgopt_backup_max_mb',
			esc_attr( (string) $val ),
			esc_html__( 'MB', 'sakura-imgopt' ),
			esc_html__( '备份占用超过这个容量时，会从最旧的备份开始自动删除。填 0 表示不限制。', 'sakura-imgopt' )
		);
	}

	// ── sanitize：保留天数（非负整数）───────────────
	public function sanitize_retention_days( $value ) {
		return max( 0, absint( $value ) );
	}

	// ── sanitize：容量上限（非负整数）───────────────
	public function sanitize_max_mb( $value ) {
		return max( 0, absint( $value ) );
	}

	// ── 渲染备份管理页 ──────────────────────────────
	public function render_backup_page() {
		$backup_group = $this->backup_group;
		$backup_slug  = $this->backup_slug;
		$backup       = $this->backup;
		require __DIR__ . '/views/settings-backup.php';
	}


	/**
	 * Render recent stats rows (shared by the initial view and AJAX pagination).
	 *
	 * @param array<int, array<string, mixed>> $logs Log rows.
	 * @return string
	 */
	public function render_stats_log_rows( array $logs ): string {
		$html = '';
		foreach ( $logs as $log ) {
			$aid       = isset( $log['attachment_id'] ) ? (int) $log['attachment_id'] : 0;
			$title     = $aid ? get_the_title( $aid ) : '';
			$edit_link = $aid ? get_edit_post_link( $aid ) : '';
			$src_fmt   = strtoupper( (string) ( $log['source_format'] ?? '' ) );
			$dst_fmt   = strtoupper( (string) ( $log['target_format'] ?? '' ) );
			$fmt       = '' !== $dst_fmt ? ( $src_fmt . ' → ' . $dst_fmt ) : $src_fmt;
			$mode_text = ( ( $log['mode'] ?? '' ) === 'batch' ) ? __( '批量', 'sakura-imgopt' ) : __( '上传', 'sakura-imgopt' );
			$is_ok     = ( ( $log['status'] ?? '' ) === 'success' );
			$is_skip   = ( ( $log['status'] ?? '' ) === 'skipped' );
			$name      = '' !== $title ? $title : ( '#' . $aid );
			$name_html = $edit_link ? '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );

			if ( $is_ok ) {
				$size_text = size_format( (int) ( $log['source_size'] ?? 0 ), 1 ) . ' → ' . size_format( (int) ( $log['target_size'] ?? 0 ), 1 );
			} elseif ( $is_skip ) {
				$size_text = size_format( (int) ( $log['source_size'] ?? 0 ), 1 ) . ' → ' . __( '未改动', 'sakura-imgopt' );
			} else {
				$size_text = '—';
			}

			if ( $is_ok ) {
				/* translators: %s: saving percentage */
				$badge = '<span class="badge badge--ok">' . esc_html( sprintf( __( '节省 %s%%', 'sakura-imgopt' ), (string) ( $log['ratio'] ?? 0 ) ) ) . '</span>';
			} elseif ( $is_skip ) {
				$badge = '<span class="badge badge--warn" title="' . esc_attr( (string) ( $log['error'] ?? '' ) ) . '">' . esc_html__( '已跳过', 'sakura-imgopt' ) . '</span>';
			} else {
				$badge = '<span class="badge badge--bad" title="' . esc_attr( (string) ( $log['error'] ?? '' ) ) . '">' . esc_html__( '失败', 'sakura-imgopt' ) . '</span>';
			}

			$html .= '<tr>';
			$html .= '<td>' . esc_html( (string) ( $log['time'] ?? '' ) ) . '</td>';
			$html .= '<td>' . $name_html . '</td>';
			$html .= '<td>' . esc_html( $fmt ) . '</td>';
			$html .= '<td>' . esc_html( strtoupper( (string) ( $log['engine'] ?? '' ) ) ) . '</td>';
			$html .= '<td>' . esc_html( $mode_text ) . '</td>';
			$html .= '<td>' . esc_html( $size_text ) . '</td>';
			$html .= '<td>' . $badge . '</td>';
			$html .= '</tr>';
		}
		return $html;
	}

	/**
	 * AJAX: return one page of the recent processing history.
	 *
	 * @return void
	 */
	public function ajax_stats_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		check_ajax_referer( 'sakura_imgopt_stats_page', 'nonce' );

		$page     = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 25;
		if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) {
			$per_page = 25;
		}

		$logs  = $this->stats->get_recent_logs( 100 );
		$total = count( $logs );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( $page, $pages );
		$rows  = array_slice( $logs, ( $page - 1 ) * $per_page, $per_page );

		wp_send_json_success(
			array(
				'rows'     => $this->render_stats_log_rows( $rows ),
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
				'pages'    => $pages,
			)
		);
	}

	// ── 渲染统计看板 ────────────────────────────────
	public function render_stats_page() {
		$stats = $this->stats;
		require __DIR__ . '/views/settings-stats.php';
	}

	// ── 处理「重置统计」请求（admin_post） ──────────────
	public function handle_reset_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_imgopt_reset_stats' );

		$this->stats->reset();

		wp_safe_redirect(
			\Sakura\ImageOptimizer\Admin\Registry::url(
				'image',
				'stats',
				array( 'reset' => '1' )
			)
		);
		exit;
	}

	// ── 启用样式（美化设置页） ───────────────────────
	public function enqueue_admin_assets( $hook ) {
		// All four image screens share one WordPress page, so the route check
		// replaces the old per-hook whitelist.
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'image' ) ) {
			return;
		}

		unset( $hook );

		if ( \Sakura\ImageOptimizer\Admin\Registry::on( 'image', 'rules' ) ) {
			Page_Assets::set_data(
				'image-rules',
				array(
					'scan' => array(
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'action'      => 'sakura_imgopt_scan_crop',
						'nonce'       => wp_create_nonce( 'sakura_imgopt_scan_crop' ),
						'statsAction' => 'sakura_imgopt_stats_page',
						'statsNonce'  => wp_create_nonce( 'sakura_imgopt_stats_page' ),
						'strings'     => array(
							'button'             => __( '扫描裁剪配置', 'sakura-imgopt' ),
							'scanning'           => __( '正在扫描…', 'sakura-imgopt' ),
							'failed'             => __( '扫描失败，请稍后重试。', 'sakura-imgopt' ),
							'never'              => __( '尚未扫描', 'sakura-imgopt' ),
							'registeredNoSource' => __( '运行中已注册，未定位静态来源', 'sakura-imgopt' ),
							'unknownSource'      => __( '未扫描', 'sakura-imgopt' ),
							'hardCrop'           => __( '硬裁剪', 'sakura-imgopt' ),
							'softCrop'           => __( '等比缩放', 'sakura-imgopt' ),
							'disabled'           => __( '已禁止', 'sakura-imgopt' ),
							'enabled'            => __( '正常启用', 'sakura-imgopt' ),
							'warningsTitle'      => __( '扫描提示', 'sakura-imgopt' ),
							'auto'               => __( '自动', 'sakura-imgopt' ),
						),
					),
				)
			);
		}

		if ( \Sakura\ImageOptimizer\Admin\Registry::on( 'image', 'stats' ) ) {
			Page_Assets::set_data(
				'image-stats',
				array(
					'stats' => array(
						'action' => 'sakura_imgopt_stats_page',
						'nonce'  => wp_create_nonce( 'sakura_imgopt_stats_page' ),
					),
				)
			);
		}

		Assets::enqueue_page();
	}

	// ── 处理「扫描裁剪配置」AJAX 请求 ─────────────────
	public function handle_scan_crop_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_imgopt_scan_crop', 'nonce' );

		$result = ( new Crop_Config_Scanner() )->scan_and_store();
		$scan   = Crop_Config_Scanner::stored();
		if ( null === $scan ) {
			wp_send_json_error( array( 'message' => __( '扫描结果保存失败，请检查数据库写入权限。', 'sakura-imgopt' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'sizes'          => $scan['sizes'],
				'summary'        => $scan['summary'],
				'summary_text'   => $this->format_scan_summary( $scan ),
				'wp_config_text' => $this->format_wp_config_note( $scan ),
				'warnings'       => $scan['warnings'],
				'big_threshold'  => $scan['big_threshold'],
				'duration_ms'    => $result['duration_ms'] ?? 0,
			)
		);
	}

	/**
	 * 获取可配置的图片尺寸。
	 *
	 * 不使用 wp_get_registered_image_subsizes()，因为它会经过 intermediate_image_sizes 过滤。
	 * 当用户禁用全部尺寸后，设置页仍必须能显示完整尺寸列表，方便用户取消勾选恢复。
	 *
	 * @return array<string,array{width:int,height:int,crop:bool}>
	 */
	private function get_available_image_sizes() {
		global $_wp_additional_image_sizes;

		$sizes         = array();
		$default_sizes = array(
			'thumbnail'    => array(
				'width'  => (int) get_option( 'thumbnail_size_w', 150 ),
				'height' => (int) get_option( 'thumbnail_size_h', 150 ),
				'crop'   => (bool) get_option( 'thumbnail_crop', false ),
			),
			'medium'       => array(
				'width'  => (int) get_option( 'medium_size_w', 300 ),
				'height' => (int) get_option( 'medium_size_h', 300 ),
				'crop'   => false,
			),
			'medium_large' => array(
				'width'  => (int) get_option( 'medium_large_size_w', 768 ),
				'height' => (int) get_option( 'medium_large_size_h', 0 ),
				'crop'   => false,
			),
			'large'        => array(
				'width'  => (int) get_option( 'large_size_w', 1024 ),
				'height' => (int) get_option( 'large_size_h', 1024 ),
				'crop'   => false,
			),
			'1536x1536'    => array(
				'width'  => 1536,
				'height' => 1536,
				'crop'   => false,
			),
			'2048x2048'    => array(
				'width'  => 2048,
				'height' => 2048,
				'crop'   => false,
			),
		);

		foreach ( $default_sizes as $name => $data ) {
			if ( $data['width'] > 0 || $data['height'] > 0 ) {
				$sizes[ $name ] = $data;
			}
		}

		if ( is_array( $_wp_additional_image_sizes ) ) {
			foreach ( $_wp_additional_image_sizes as $name => $data ) {
				$sizes[ $name ] = array(
					'width'  => isset( $data['width'] ) ? (int) $data['width'] : 0,
					'height' => isset( $data['height'] ) ? (int) $data['height'] : 0,
					'crop'   => ! empty( $data['crop'] ),
				);
			}
		}

		return $sizes;
	}

	// ── 数据清洗 ──────────────────────────────────────
	public function sanitize_mode( $val ) {
		return in_array( $val, array( 'compress_only', 'compress_convert', 'convert_only' ), true ) ? $val : 'compress_only';
	}

	public function sanitize_format( $val ) {
		return in_array( $val, array( 'webp', 'avif' ), true ) ? $val : 'webp';
	}

	public function sanitize_source_formats( $val ) {
		if ( ! is_array( $val ) ) {
			return array();
		}

		$allowed = array_keys( $this->get_supported_source_formats() );
		$cleaned = array();
		foreach ( $val as $format ) {
			$format = strtolower( sanitize_key( $format ) );
			if ( in_array( $format, $allowed, true ) ) {
				$cleaned[] = $format;
			}
		}

		return array_values( array_unique( $cleaned ) );
	}

	private function get_supported_source_formats() {
		return array(
			'jpg'  => 'JPG',
			'jpeg' => 'JPEG',
			'png'  => 'PNG',
			'gif'  => 'GIF',
			'webp' => 'WebP',
			'avif' => 'AVIF',
			'svg'  => 'SVG',
			'apng' => 'APNG',
		);
	}

	public function sanitize_quality( $val ) {
		$val = absint( $val );
		return max( 1, min( 100, $val ) );
	}

	public function sanitize_batch_size( $val ) {
		$val = absint( $val );
		return max( 1, min( 20, $val ) );
	}

	public function sanitize_ajax_delay( $val ) {
		$val = absint( $val );
		return max( 0, min( 2000, $val ) );
	}

	/**
	 * 清洗禁用尺寸数组
	 *
	 * @param mixed $val
	 * @return array
	 */
	public function sanitize_disabled_sizes( $val ) {
		if ( ! is_array( $val ) ) {
			return array();
		}
		// 白名单：只允许已知尺寸名和特殊标记。
		// 必须使用未经过 intermediate_image_sizes 过滤的尺寸源，否则全选禁用后无法再保存恢复。
		$allowed = array_merge(
			array_keys( $this->get_available_image_sizes() ),
			Crop_Config_Scanner::stored_names(),
			Thumbnail_Manager::get_disabled_sizes(),
			array( '_big_threshold', 'large' )
		);
		$cleaned   = array_intersect( $val, $allowed );
		// 去重 + 重建索引
		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * 检测最优可用引擎
	 */
	private function detect_best_engine() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$img  = new \Imagick();
				$fmts = $img->queryFormats();
				if ( in_array( 'WEBP', $fmts, true ) || in_array( 'AVIF', $fmts, true ) ) {
					$cached = __( 'Imagick（推荐）', 'sakura-imgopt' );
					return $cached;
				}
			} catch ( \Exception $e ) {
				/**
				 * Preserve the GD fallback while exposing the probe failure to
				 * debug integrations instead of silently discarding it.
				 */
				do_action( 'sakura_imgopt_imagick_probe_failed', $e );
			}
		}
		if ( extension_loaded( 'gd' ) ) {
			$cached = __( 'GD（备用）', 'sakura-imgopt' );
			return $cached;
		}
		$cached = __( '没有可用的图片组件', 'sakura-imgopt' );
		return $cached;
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Image_Optimizer_Module', __NAMESPACE__ . '\\Settings' );
