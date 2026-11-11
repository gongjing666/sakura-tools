<?php
/**
 * View partial. Included from a settings/media method; uses the including scope.
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

$sections = isset( $wp_settings_sections[ $menu_slug ] ) ? $wp_settings_sections[ $menu_slug ] : array();
$fields   = isset( $wp_settings_fields[ $menu_slug ] ) ? $wp_settings_fields[ $menu_slug ] : array();
$sufm_order = array( 'sakura_imgopt_section_thumbs', 'sakura_imgopt_section_rename', 'sakura_imgopt_section_compress', 'sakura_imgopt_section_backup_policy', 'sakura_imgopt_section_bulk' );
$stats_url = admin_url( 'admin.php?page=sakura-tools-stats' );

settings_errors();
?>
<div class="sakura-sync-app">
	<div class="app">
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'image',
			'rules',
			/* translators: %s: image engine name */
			array( 'status' => sprintf( __( '图片组件 %s', 'sakura-imgopt' ), $engine ) )
		);

		\Sakura\ImageOptimizer\Admin\UI::hero(
			array(
				'eyebrow' => 'SAKURA TOOLS / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( '图像优化', 'sakura-imgopt' ),
				'lede'    => __( '轻量、可控的 WordPress 图片优化中心。统一管理缩略图裁剪、上传重命名、格式转换、原图备份与批量处理。', 'sakura-imgopt' ),
			)
		);
		?>
		<div class="compact-cards compact-cards--settings" data-sakura-layout="image" data-sakura-group="main">
			<?php
			$kickers = array(
				'sakura_imgopt_section_thumbs'   => __( '01 尺寸', 'sakura-imgopt' ),
				'sakura_imgopt_section_rename'   => __( '02 命名', 'sakura-imgopt' ),
				'sakura_imgopt_section_compress' => __( '03 压缩', 'sakura-imgopt' ),
				'sakura_imgopt_section_bulk'     => __( '04 批量', 'sakura-imgopt' ),
			);
			$render_section = static function ( $section_id, $section ) use ( $fields, $kickers, $option_group ) {
				$section_fields = isset( $fields[ $section_id ] ) ? $fields[ $section_id ] : array();
				$wide           = ( 'sakura_imgopt_section_thumbs' === $section_id );
				$slug           = str_replace( 'sakura_imgopt_section_', '', $section_id );
				$kicker         = isset( $kickers[ $section_id ] ) ? $kickers[ $section_id ] : strtoupper( str_replace( 'sakura_imgopt_section_', '', $section_id ) );

				\Sakura\ImageOptimizer\Admin\UI::card(
					array(
						'card'   => $slug,
						'kicker' => $kicker,
						'title'  => $section['title'],
						'wide'   => $wide,
						'save'   => __( '保存设置', 'sakura-imgopt' ),
						'form'   => array(
							'id'           => 'sakura-imgopt-card-' . $slug,
							'option_group' => $option_group,
						),
					),
					static function () use ( $section, $section_fields, $section_id ) {
						if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
							echo '<div class="section-note">';
							call_user_func( $section['callback'] );
							echo '</div>';
						}

						if ( empty( $section_fields ) ) {
							return;
						}

						echo '<table class="form-table" role="presentation">';
						foreach ( $section_fields as $field ) {
							$field_args = is_array( $field['args'] ) ? $field['args'] : array();
							// The thumbs checklist owns its own label so the label and the
							// scan button can sit on one horizontal line above the table.
							if ( 'sakura_imgopt_section_thumbs' === $section_id && 'sakura_imgopt_disabled_sizes' === ( $field['id'] ?? '' ) ) {
								$field_args['show_label'] = true;
								$field_args['label']      = wp_strip_all_tags( $field['title'] );
								echo '<tr class="sakura-imgopt-thumbs-row"><td colspan="2" class="sakura-imgopt-thumbs-cell">';
								call_user_func( $field['callback'], $field_args );
								echo '</td></tr>';
								continue;
							}
							echo '<tr><th scope="row">' . wp_kses_post( $field['title'] ) . '</th><td>';
							call_user_func( $field['callback'], $field_args );
							echo '</td></tr>';
						}
						echo '</table>';
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
			?>
		</div>
	</div>
</div>
