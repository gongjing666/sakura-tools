<?php
/**
 * Image optimization rules screen.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var string $engine */
/** @var string $option_group */
/** @var string $menu_slug */

/** @var \Sakura\ImageOptimizer\Image_Optimizer_Module $this */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

global $wp_settings_sections, $wp_settings_fields;

$sections   = isset( $wp_settings_sections[ $menu_slug ] ) ? $wp_settings_sections[ $menu_slug ] : array();
$fields     = isset( $wp_settings_fields[ $menu_slug ] ) ? $wp_settings_fields[ $menu_slug ] : array();
$sufm_order = array( 'sakura_imgopt_section_thumbs', 'sakura_imgopt_section_rename', 'sakura_imgopt_section_compress', 'sakura_imgopt_section_backup_policy', 'sakura_imgopt_section_bulk' );
$stats_url  = \Sakura\ImageOptimizer\Admin\Registry::url( 'image', 'stats' );

\Sakura\ImageOptimizer\Admin\UI::page(
	array(
		'id'      => 'image-rules',
		'module'  => 'image',
		'tab'     => 'rules',
		'eyebrow' => 'IMAGE OPTIMIZATION / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '图像优化', 'sakura-imgopt' ),
		'lede'    => __( '轻量、可控的 WordPress 图片优化中心。统一管理缩略图裁剪、上传重命名、格式转换、原图备份与批量处理。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '图片组件', 'sakura-imgopt' ),
			'value' => $engine,
			'sub'   => __( '已启用', 'sakura-imgopt' ),
			'tone'  => 'ok',
		),
		'actions' => array(
			array(
				'label'   => __( '查看统计', 'sakura-imgopt' ),
				'url'     => $stats_url,
				'variant' => 'ghost',
			),
		),
	),
	static function () use ( $sections, $fields, $sufm_order, $option_group ) {

		echo '<div class="card-grid" data-sakura-layout="image" data-sakura-group="main">';

		$kickers = array(
			'sakura_imgopt_section_thumbs'   => __( '01 尺寸', 'sakura-imgopt' ),
			'sakura_imgopt_section_rename'   => __( '02 命名', 'sakura-imgopt' ),
			'sakura_imgopt_section_compress' => __( '03 压缩', 'sakura-imgopt' ),
			'sakura_imgopt_section_bulk'     => __( '04 批量', 'sakura-imgopt' ),
		);

		$label_targets = array(
			'sakura_imgopt_rename_enabled'      => 'sakura_imgopt_rename_enabled',
			'sakura_imgopt_rename_rule'         => 'sakura_imgopt_rename_rule',
			'sakura_imgopt_enable_compression'  => 'sakura_imgopt_enable_compression',
			'sakura_imgopt_convert_format'      => 'sakura_imgopt_convert_format',
			'sakura_imgopt_quality'             => 'sakura_imgopt_quality',
			'sakura_imgopt_backup_enabled'      => 'sakura_imgopt_backup_enabled',
			'sakura_imgopt_batch_size'          => 'sakura_imgopt_batch_size',
			'sakura_imgopt_ajax_delay'          => 'sakura_imgopt_ajax_delay',
		);

		$render_section = static function ( $section_id, $section ) use ( $fields, $kickers, $label_targets, $option_group ) {
			$section_fields = isset( $fields[ $section_id ] ) ? $fields[ $section_id ] : array();
			$slug           = str_replace( 'sakura_imgopt_section_', '', $section_id );
			$wide           = ( 'sakura_imgopt_section_thumbs' === $section_id );
			$kicker         = isset( $kickers[ $section_id ] ) ? $kickers[ $section_id ] : strtoupper( str_replace( 'sakura_imgopt_section_', '', $section_id ) );
			$full_fields    = array(
				'sakura_imgopt_disabled_sizes',
				'sakura_imgopt_rename_rule',
				'sakura_imgopt_enable_compression',
				'sakura_imgopt_convert_format',
				'sakura_imgopt_source_formats',
				'sakura_imgopt_processing_limits',
				'sakura_imgopt_backup_enabled',
			);

			\Sakura\ImageOptimizer\Admin\UI::card(
				array(
					'id'     => $slug,
					'kicker' => $kicker,
					'title'  => $section['title'],
					'wide'   => $wide,
					'save'   => __( '保存设置', 'sakura-imgopt' ),
					'form'   => array(
						'id'           => 'sakura-imgopt-card-' . $slug,
						'option_group' => $option_group,
					),
				),
				static function () use ( $section, $section_fields, $full_fields, $label_targets ) {
					if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
						echo '<div class="card__note">';
						call_user_func( $section['callback'] );
						echo '</div>';
					}

					if ( empty( $section_fields ) ) {
						return;
					}

					echo '<div class="field-list">';
					foreach ( $section_fields as $field ) {
						$field_id   = isset( $field['id'] ) ? (string) $field['id'] : '';
						$field_args = ( isset( $field['args'] ) && is_array( $field['args'] ) ) ? $field['args'] : array();
						$callback   = isset( $field['callback'] ) ? $field['callback'] : null;

						if ( ! is_callable( $callback ) ) {
							continue;
						}

						$args = array(
							'type'    => 'custom',
							'label'   => wp_strip_all_tags( isset( $field['title'] ) ? (string) $field['title'] : '' ),
							'for'     => isset( $label_targets[ $field_id ] ) ? $label_targets[ $field_id ] : '',
							'full'    => in_array( $field_id, $full_fields, true ),
							'control' => static function ( $callback_args ) use ( $callback ) {
								call_user_func( $callback, $callback_args );
							},
							'args'    => $field_args,
						);

						\Sakura\ImageOptimizer\Admin\UI::field( $args );
					}
					echo '</div>';
				}
			);
		};

		foreach ( $sufm_order as $section_id ) {
			if ( isset( $sections[ $section_id ] ) ) {
				$render_section( $section_id, $sections[ $section_id ] );
			}
		}

		foreach ( $sections as $section_id => $section ) {
			if ( in_array( $section_id, $sufm_order, true ) ) {
				continue;
			}
			$section_fields = isset( $fields[ $section_id ] ) ? $fields[ $section_id ] : array();
			if ( empty( $section_fields ) ) {
				continue;
			}
			$render_section( $section_id, $section );
		}

		echo '</div>';
	}
);
