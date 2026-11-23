<?php
/**
 * Payload for the image screens (React island).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\Rest\Image_Controller;
use Sakura\ImageOptimizer\Admin\UI\Page;
use Sakura\ImageOptimizer\Image\Upload_Renamer;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the image screens' data into one array the island can render.
 *
 * Same contract as System_Payload: the first paint, the REST reads and the
 * per-card writes all come from here, so the PHP shell and the React tree
 * cannot disagree about values.
 */
final class Image_Payload {

	/**
	 * Tab slugs this builder knows.
	 */
	public const TABS = array( 'rules', 'thumb', 'backup', 'stats' );

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository|null $settings Optional repository.
	 */
	public function __construct( ?Settings_Repository $settings = null ) {
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * The thumbnails section, recomputed from the current options.
	 *
	 * The save endpoint returns this so the size table's status column reflects
	 * what was just written instead of the values captured at first paint.
	 *
	 * @return array<string,mixed>
	 */
	public function thumbs(): array {
		return $this->thumbs_section();
	}

	/**
	 * Build the payload for one tab.
	 *
	 * @param string $tab One of TABS.
	 * @return array<string,mixed>
	 */
	public function build( string $tab = 'rules' ): array {
		$tab     = in_array( $tab, self::TABS, true ) ? $tab : 'rules';
		$payload = array(
			'tab'     => $tab,
			'chrome'  => Shell::header_data( 'image', $tab ),
			'strings' => $this->strings(),
			'rest'    => array(
				'url'   => esc_url_raw( rest_url( Image_Controller::NS . '/image/' . $tab ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
		);

		$page              = array(
			'id'      => 'image-' . $tab,
			'module'  => 'image',
			'tab'     => $tab,
			'eyebrow' => 'IMAGE OPTIMIZATION / ' . SAKURA_TOOLS_VERSION,
			'title'   => __( '图像优化', 'sakura-imgopt' ),
			'lede'    => __( '轻量、可控的 WordPress 图片优化中心。统一管理缩略图裁剪、上传重命名、格式转换、原图备份与批量处理。', 'sakura-imgopt' ),
			'status'  => array(
				'label' => __( '图片组件', 'sakura-imgopt' ),
				'value' => Image_Report::engine_label(),
				'sub'   => __( '已启用', 'sakura-imgopt' ),
				'tone'  => 'ok',
			),
			'actions' => array(
				array(
					'label'   => __( '查看统计', 'sakura-imgopt' ),
					'url'     => Registry::url( 'image', 'stats' ),
					'variant' => 'ghost',
				),
			),
			'island'  => true,
		);
		$payload['page']   = $page;
		$payload['head']   = Page::head_data( $page );

		if ( 'rules' === $tab ) {
			$payload['cards']    = $this->rules_cards();
			$payload['sections'] = array(
				'thumbs' => $this->thumbs_section(),
				'rename' => $this->rename_section(),
			);
		}

		return $payload;
	}

	/**
	 * The four rules cards: ordering, copy and field descriptors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function rules_cards(): array {
		$values = array(
			'thumbs'   => $this->card_values( 'thumbs' ),
			'rename'   => $this->card_values( 'rename' ),
			'compress' => $this->card_values( 'compress' ),
			'bulk'     => $this->card_values( 'bulk' ),
		);

		$cards = array(
			array(
				'id'      => 'thumbs',
				'kicker'  => 'Sizes',
				'title'   => __( '禁止 WordPress 裁剪', 'sakura-imgopt' ),
				'wide'    => true,
				'note'    => array(
					__( '勾选你不需要的裁剪尺寸。WordPress 将停止生成被勾选的尺寸，节省存储空间。原图始终保留。', 'sakura-imgopt' ),
					__( '点击「扫描裁剪配置」，可以查看站点配置文件、当前主题和已启用插件分别设置了哪些尺寸，以及每个尺寸现在会不会生成。', 'sakura-imgopt' ),
				),
				'fields'  => array(
					array(
						'type'  => 'size-table',
						'name'  => 'disabled_sizes',
						'label' => __( '选择要禁止的裁剪尺寸', 'sakura-imgopt' ),
						'for'   => 'sakura_imgopt_disabled_sizes',
						'full'  => true,
					),
				),
				'values'  => $values['thumbs'],
			),
			array(
				'id'      => 'rename',
				'kicker'  => 'Naming',
				'title'   => __( '上传文件重命名', 'sakura-imgopt' ),
				'note'    => array( __( '上传文件时按规则重命名，避免中文、特殊字符或重名带来的兼容问题。图片、视频、压缩包、文档等各类文件都适用，扩展名始终保持原样。', 'sakura-imgopt' ) ),
				'fields'  => array(
					array(
						'type'  => 'switch',
						'name'  => 'rename_enabled',
						'for'   => 'sakura_imgopt_rename_enabled',
						'label' => __( '开启重命名', 'sakura-imgopt' ),
						'hint'  => __( '上传时按设定规则重命名文件，避免文件名重复，减少媒体库里出现重复文件。', 'sakura-imgopt' ),
						'text'  => __( '启用', 'sakura-imgopt' ),
					),
					array(
						'type'  => 'rule-composer',
						'name'  => 'rename_rule',
						'for'   => 'sakura_imgopt_rename_rule',
						'label'      => __( '重命名规则', 'sakura-imgopt' ),
						'full'       => true,
						'hint_after' => true,
						'hint'       => __( '扩展名始终保留，没有扩展名的文件保持原名。「尺寸」只对图片生效，「内容指纹」对超过 32 MB 的文件不计算；取不到值的占位符会连同它一侧的连接符一起去掉。', 'sakura-imgopt' ),
					),
					array(
						'type'      => 'text',
						'name'      => 'rename_fixed',
						'for'       => 'sakura_imgopt_rename_fixed',
						'label'     => __( '固定文本', 'sakura-imgopt' ),
						'full'       => true,
						'maxlength'  => 24,
						'input'      => 'rule',
						'hint_after' => true,
						'hint'      => array(
							__( '填一段固定文字（例如 sakura），它就等于一个 {fixed} 占位符：点上面规则卡片里的「固定文本」就能把它插进规则，摆在最前面就是固定前缀。只保留英文字母、数字、连字符（-）和下划线（_），最长 24 个字符。', 'sakura-imgopt' ),
							__( '不建议使用中文：中文、空格和符号在保存时会被去掉，中文文件名在部分服务器、网盘和下载工具里还会变成乱码。', 'sakura-imgopt' ),
						),
					),
				),
				'values'  => $values['rename'],
			),
			array(
				'id'      => 'compress',
				'kicker'  => 'Compression',
				'title'   => __( '图片压缩与格式转换', 'sakura-imgopt' ),
				'note'    => array( __( '上传图片时按所选模式压缩或转换格式；转换结果会替换媒体库中的原图 URL，原图可按需备份。', 'sakura-imgopt' ) ),
				'fields'  => array(
					array(
						'type'  => 'switch',
						'name'  => 'enable_compression',
						'for'   => 'sakura_imgopt_enable_compression',
						'label' => __( '开启上传压缩', 'sakura-imgopt' ),
						'full'  => true,
						'hint'  => __( '上传图片时自动压缩/转换格式', 'sakura-imgopt' ),
						'text'  => __( '启用', 'sakura-imgopt' ),
					),
					array(
						'type'    => 'select',
						'name'    => 'compression_mode',
						'for'     => 'sakura_imgopt_compression_mode',
						'label'   => __( '压缩模式', 'sakura-imgopt' ),
						'width'   => 'fit',
						'options' => array(
							array( 'value' => 'compress_only', 'label' => __( '仅压缩', 'sakura-imgopt' ) ),
							array( 'value' => 'compress_convert', 'label' => __( '压缩并转换格式', 'sakura-imgopt' ) ),
							array( 'value' => 'convert_only', 'label' => __( '仅转换格式', 'sakura-imgopt' ) ),
						),
					),
					array(
						'type'  => 'number',
						'name'  => 'quality',
						'for'   => 'sakura_imgopt_quality',
						'label' => __( '压缩质量', 'sakura-imgopt' ),
						'min'   => 1,
						'max'   => 100,
						'step'  => 1,
						'width' => 'fit',
						'hint'  => __( '数字越小文件越小，数字越大画质越好。推荐 75 到 85。', 'sakura-imgopt' ),
					),
					array(
						'type'    => 'select',
						'name'    => 'convert_format',
						'for'     => 'sakura_imgopt_convert_format',
						'label'   => __( '目标格式', 'sakura-imgopt' ),
						'width'   => 'fit',
						'options' => array(
							array( 'value' => 'webp', 'label' => 'WebP' ),
							array( 'value' => 'avif', 'label' => 'AVIF' ),
						),
						'hint'    => __( 'AVIF 文件更小，但兼容性略差；需要最大兼容性请选 WebP。这个选项只在「压缩并转换格式」和「仅转换格式」下有效；「仅压缩」会保留原格式，不使用这里的设置。', 'sakura-imgopt' ),
					),
					array(
						'type'    => 'formats',
						'name'    => 'source_formats',
						'for'     => 'sakura_imgopt_source_formats',
						'label'   => __( '参与处理的源格式', 'sakura-imgopt' ),
						'options' => array(
							array( 'value' => 'jpg', 'label' => 'JPG' ),
							array( 'value' => 'jpeg', 'label' => 'JPEG' ),
							array( 'value' => 'png', 'label' => 'PNG' ),
							array( 'value' => 'gif', 'label' => 'GIF' ),
							array( 'value' => 'webp', 'label' => 'WebP' ),
							array( 'value' => 'avif', 'label' => 'AVIF' ),
							array( 'value' => 'svg', 'label' => 'SVG' ),
							array( 'value' => 'apng', 'label' => 'APNG' ),
						),
						'hint'    => array(
							__( '只有上传图片的源格式命中这里勾选的格式，才会进入压缩/转换流程；未命中的图片只按 WordPress 默认上传流程保存。', 'sakura-imgopt' ),
							__( '注意：SVG、APNG、AVIF 能否处理，取决于服务器上的图片组件（Imagick 或 GD）是否支持。', 'sakura-imgopt' ),
							__( 'SVG 默认不勾选：处理 SVG 时通常会被转成普通图片，文件反而变大。确实需要处理再勾选。', 'sakura-imgopt' ),
						),
					),
					array(
						'type'   => 'limits',
						'name'   => 'processing_limits',
						'for'    => 'sakura_imgopt_max_file_size_mb',
						'label'  => __( '大图放行上限', 'sakura-imgopt' ),
						'full'   => true,
						'parts'  => array(
							array(
								'name'  => 'max_file_size_mb',
								'label' => __( '空间尺寸', 'sakura-imgopt' ),
								'min'   => 0,
								'max'   => 1024,
								'step'  => 1,
								'unit'  => __( '单位（MB）', 'sakura-imgopt' ),
							),
							array(
								'name'     => 'max_image_width',
								'label'    => __( '分辨率', 'sakura-imgopt' ),
								'min'      => 0,
								'max'      => 20000,
								'step'     => 1,
								'unit'     => '',
								'join'     => '×',
							),
							array(
								'name'  => 'max_image_height',
								'min'   => 0,
								'max'   => 20000,
								'step'  => 1,
								'unit'  => __( '单位（PX）', 'sakura-imgopt' ),
							),
						),
						'hint'   => __( '超过其中任意一个上限的图片会直接跳过：不备份、不压缩、也不转换格式，避免服务器处理大图时超时。填 0 表示不限制。默认 24 MB、3840 × 2160（4K，竖图同样适用）。', 'sakura-imgopt' ),
					),
					array(
						'type'  => 'switch',
						'name'  => 'backup_enabled',
						'for'   => 'sakura_imgopt_backup_enabled',
						'label' => __( '备份原图', 'sakura-imgopt' ),
						'full'  => true,
						'hint'  => __( '处理图片前，先把原图复制到服务器上的备份文件夹，方便以后一键还原。只有真正被处理的图片才会保留备份；关闭后不再创建新备份，已经存在的备份仍可还原。还原成功后，这次备份会自动删除。', 'sakura-imgopt' ),
						'text'  => __( '启用', 'sakura-imgopt' ),
					),
				),
				'values'  => $values['compress'],
			),
			array(
				'id'      => 'bulk',
				'kicker'  => 'Batch',
				'title'   => __( '批量优化性能', 'sakura-imgopt' ),
				'wide'    => true,
				'note'    => array(
					__( '媒体库「图像优化」批量处理时的性能参数。批量优化采用分批异步处理，避免请求超时和服务器过载。', 'sakura-imgopt' ),
					__( '注意：批量优化仅适用于存储在本地服务器的图片。如果媒体库已经被阿里云 OSS、腾讯云 COS、七牛、又拍云等云存储插件接管，图片实际在云端、本地没有副本，这里的批量优化就无法处理。这种情况建议在「上传时」就完成压缩和格式转换。', 'sakura-imgopt' ),
				),
				'alert'   => true,
				'fields'  => array(
					array(
						'type'  => 'number',
						'name'  => 'batch_size',
						'for'   => 'sakura_imgopt_batch_size',
						'label' => __( '每批处理张数', 'sakura-imgopt' ),
						'min'   => 1,
						'max'   => 20,
						'step'  => 1,
						'width' => 'small',
						'hint'  => __( '每次处理几张图片（1 到 20）。调大速度更快，但更占服务器资源；配置一般的服务器建议保持 1。', 'sakura-imgopt' ),
					),
					array(
						'type'  => 'number',
						'name'  => 'ajax_delay',
						'for'   => 'sakura_imgopt_ajax_delay',
						'label' => __( '批次间延迟（毫秒）', 'sakura-imgopt' ),
						'min'   => 0,
						'max'   => 2000,
						'step'  => 100,
						'width' => 'small',
						'unit'  => __( '毫秒', 'sakura-imgopt' ),
						'hint'  => __( '每批之间的休息时间（0–2000 毫秒）。调大对前端访问更友好，调小处理更快。推荐 300–500。', 'sakura-imgopt' ),
					),
				),
				'values'  => $values['bulk'],
			),
		);

		foreach ( $cards as &$card ) {
			$card['form'] = array(
				'id'    => 'sakura-imgopt-card-' . $card['id'],
				'card'  => $card['id'],
				'save'  => __( '保存设置', 'sakura-imgopt' ),
				'group' => 'sakura_imgopt_settings',
			);
			$card         = $this->place( $card );
		}
		unset( $card );

		return $cards;
	}

	/**
	 * Card values keyed by option base name.
	 *
	 * @param string $card Card id.
	 * @return array<string,mixed>
	 */
	public function card_values( string $card ): array {
		$settings = new Image_Settings( $this->settings );

		return $settings->rules_values( $card );
	}

	/**
	 * Attach the grid placement from Layout::PAGES.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return array<string,mixed>
	 */
	private function place( array $card ): array {
		$spot = Layout::card( Layout::layout_for_page( 'image-rules' ), (string) $card['id'] );

		// Array, not an object: the shared <Card> reads [row, col, span] for the
		// --g-card-* custom properties, the same shape Layout::card() returns.
		$card['placement'] = null === $spot ? null : array( $spot[0], $spot[1], $spot[2] );

		return $card;
	}

	/**
	 * Size table data plus the scan summary above it.
	 *
	 * @return array<string,mixed>
	 */
	private function thumbs_section(): array {
		$scan     = Image_Report::scan();
		$disabled = $this->settings->disabled_sizes();
		$rows     = Image_Report::size_rows( $disabled, $scan );

		$warnings = array();
		if ( is_array( $scan ) && isset( $scan['warnings'] ) && is_array( $scan['warnings'] ) ) {
			foreach ( $scan['warnings'] as $warning ) {
				if ( is_array( $warning ) ) {
					$warnings[] = (string) ( $warning['message'] ?? '' );
				}
			}
		}

		return array(
			'rows'         => $rows,
			'scan_summary' => Image_Report::scan_summary_text( $scan ),
			'wp_config'    => Image_Report::wp_config_text( $scan ),
			'warnings'     => array_values( array_filter( $warnings ) ),
			'empty'        => array() === $rows,
			'columns'      => array(
				__( '禁用', 'sakura-imgopt' ),
				__( '尺寸名称', 'sakura-imgopt' ),
				__( '宽 × 高', 'sakura-imgopt' ),
				__( '生成状态', 'sakura-imgopt' ),
				__( '裁剪方式', 'sakura-imgopt' ),
				__( '来源', 'sakura-imgopt' ),
			),
			'notes'        => array(
				__( '未勾选的尺寸会正常生成。一般建议保留 thumbnail（缩略图）和 medium（中等），其余按需禁用；来源为「仅静态发现」的尺寸当前不会生成，可以放心勾选。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Rename rule editor data: tokens, preview, default.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * The rename card's server-derived labels and preview samples.
	 *
	 * The {fixed} chip spells out the stored text, so this has to be rebuilt
	 * after a save — otherwise the chip keeps advertising the previous value.
	 *
	 * @return array<string,mixed>
	 */
	public function rename_section(): array {
		$fixed = $this->settings->rename_fixed();
		$rule  = $this->settings->rename_rule();

		$common = array(
			'{filename}' => __( '原文件名', 'sakura-imgopt' ),
			'{ext}'      => __( '扩展名', 'sakura-imgopt' ),
			'{date}'     => __( '日期', 'sakura-imgopt' ),
			'{time}'     => __( '时间', 'sakura-imgopt' ),
			'{year}'     => __( '年', 'sakura-imgopt' ),
			'{month}'    => __( '月', 'sakura-imgopt' ),
			'{day}'      => __( '日', 'sakura-imgopt' ),
			'{rand}'     => __( '5 位随机', 'sakura-imgopt' ),
			'{rand8}'    => __( '8 位随机', 'sakura-imgopt' ),
			'{fixed}'    => '' === $fixed
				? __( '固定文本（未填）', 'sakura-imgopt' )
				: sprintf(
					/* translators: %s: the fixed text the user typed. */
					__( '固定文本 · %s', 'sakura-imgopt' ),
					$fixed
				),
		);

		$more = array(
			'{hash}'  => __( '内容指纹', 'sakura-imgopt' ),
			'{wxh}'   => __( '尺寸（仅图片）', 'sakura-imgopt' ),
			'{user}'  => __( '上传者', 'sakura-imgopt' ),
			'{yy}'    => __( '2 位年', 'sakura-imgopt' ),
			'{week}'  => __( '周数', 'sakura-imgopt' ),
			'{dow}'   => __( '星期', 'sakura-imgopt' ),
			'{ts}'    => __( '时间戳', 'sakura-imgopt' ),
		);

		$samples = Upload_Renamer::preview_tokens( $fixed );

		return array(
			'rule'         => $rule,
			'fixed'        => $fixed,
			// The island spells the {fixed} chip and the preview out of the live
			// draft value, so both move while the user is still typing.
			/* translators: %s: the fixed text the user typed. */
			'fixed_prefix' => __( '固定文本 · %s', 'sakura-imgopt' ),
			'fixed_empty'  => __( '固定文本（未填）', 'sakura-imgopt' ),
			'default_rule' => '{date}-{time}-{rand}',
			'tokens'       => $this->token_list( $common ),
			'more_tokens'  => $this->token_list( $more ),
			'samples'      => $samples,
			'preview'      => Upload_Renamer::preview_filename( $rule, $fixed ),
			'labels'       => array(
				'reset'       => __( '恢复默认', 'sakura-imgopt' ),
				'more'        => __( '更多占位符', 'sakura-imgopt' ),
				'less'        => __( '收起更多占位符', 'sakura-imgopt' ),
				'preview'     => __( '预览', 'sakura-imgopt' ),
				'insert'      => __( '点击插入', 'sakura-imgopt' ),
				'remove'      => __( '点击移除', 'sakura-imgopt' ),
				'fixedOn'     => __( '点击移除固定文本', 'sakura-imgopt' ),
				'fixedOff'    => __( '点击插入固定文本', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Turn a token map into a list.
	 *
	 * @param array<string,string> $tokens Token => label.
	 * @return array<int,array{token:string,label:string}>
	 */
	private function token_list( array $tokens ): array {
		$list = array();
		foreach ( $tokens as $token => $label ) {
			$list[] = array(
				'token' => (string) $token,
				'label' => (string) $label,
			);
		}

		return $list;
	}

	/**
	 * UI strings the island needs.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'saving'    => __( '保存中…', 'sakura-imgopt' ),
			'saved'     => __( '已保存', 'sakura-imgopt' ),
			'dismiss'   => __( '知道了', 'sakura-imgopt' ),
			'close'     => __( '关闭', 'sakura-imgopt' ),
			'closeIcon' => \Sakura\ImageOptimizer\Admin\UI\Modal::close_icon(),
			'mascot'    => \Sakura\ImageOptimizer\Admin\UI\Modal::mascot(),
			'scanning'  => __( '正在扫描…', 'sakura-imgopt' ),
			'scan'      => __( '扫描裁剪配置', 'sakura-imgopt' ),
			'scanFail'  => __( '扫描失败，请稍后重试。', 'sakura-imgopt' ),
			'failed'    => __( '设置未保存，请稍后重试。', 'sakura-imgopt' ),
			'network'   => __( '网络请求失败，服务器没有响应。', 'sakura-imgopt' ),
		);
	}
}
