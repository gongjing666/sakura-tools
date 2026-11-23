<?php
/**
 * Backup policy: the one card on the backup tab that writes options.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the three backup-policy options.
 *
 * The tab is a React island, so nothing here may call a wp-admin function:
 * REST requests do not load `wp-admin/includes/template.php` and the module's
 * `register_setting()` calls never run outside `admin_init`. Every value is
 * clamped here instead, exactly like `Image_Settings`.
 */
final class Backup_Settings {

	/**
	 * Option names this card owns.
	 *
	 * @var array<int,string>
	 */
	public const OPTIONS = array(
		'sakura_imgopt_backup_retention_days',
		'sakura_imgopt_backup_max_mb',
		'sakura_imgopt_delete_data_on_uninstall',
	);

	/**
	 * Values the card needs.
	 *
	 * @return array<string,int>
	 */
	public function values(): array {
		return array(
			'retention_days' => (int) get_option( 'sakura_imgopt_backup_retention_days', 0 ),
			'max_mb'         => (int) get_option( 'sakura_imgopt_backup_max_mb', 0 ),
			'delete_data'    => (int) get_option( 'sakura_imgopt_delete_data_on_uninstall', 0 ),
		);
	}

	/**
	 * Save the card.
	 *
	 * @param array<string,mixed> $fields Submitted values.
	 * @return array{card:string,values:array<string,int>,notice:array<string,string>}
	 */
	public function save( array $fields ): array {
		$before = $this->values();

		$retention = isset( $fields['retention_days'] ) ? max( 0, (int) $fields['retention_days'] ) : $before['retention_days'];
		$max_mb    = isset( $fields['max_mb'] ) ? max( 0, (int) $fields['max_mb'] ) : $before['max_mb'];
		$delete    = ! empty( $fields['delete_data'] ) ? 1 : 0;

		update_option( 'sakura_imgopt_backup_retention_days', $retention );
		update_option( 'sakura_imgopt_backup_max_mb', $max_mb );
		update_option( 'sakura_imgopt_delete_data_on_uninstall', $delete );

		$after = $this->values();

		return array(
			'card'   => 'access',
			'values' => $after,
			'notice' => $this->notice( $fields, $before, $after ),
		);
	}

	/**
	 * Prompt for the save.
	 *
	 * @param array<string,mixed> $fields Submitted values.
	 * @param array<string,int>   $before Values before the save.
	 * @param array<string,int>   $after  Values after the save.
	 * @return array<string,string>
	 */
	private function notice( array $fields, array $before, array $after ): array {
		$asked = array(
			'retention_days' => isset( $fields['retention_days'] ) ? (int) $fields['retention_days'] : $before['retention_days'],
			'max_mb'         => isset( $fields['max_mb'] ) ? (int) $fields['max_mb'] : $before['max_mb'],
			'delete_data'    => ! empty( $fields['delete_data'] ) ? 1 : 0,
		);

		$adjusted = array();

		foreach ( $asked as $key => $value ) {
			if ( (int) $value !== (int) $after[ $key ] ) {
				$adjusted[] = $key;
			}
		}

		if ( $adjusted ) {
			return array(
				'tone'    => 'warn',
				'kicker'  => 'SETTINGS / CHECK',
				'title'   => __( '已保存，部分数值按规则调整', 'sakura-imgopt' ),
				'message' => __( '保留天数与容量上限不能是负数，已按 0 写入。', 'sakura-imgopt' ),
			);
		}

		$messages = array( __( '清理策略已保存，下一次计划任务会按新规则执行。', 'sakura-imgopt' ) );

		if ( 1 === $after['delete_data'] ) {
			$messages[] = __( '注意：卸载插件时会删除设置、云存储密钥、统计数据与备份文件，并把改过的图片地址恢复原样。', 'sakura-imgopt' );
		}

		return array(
			'tone'    => 'ok',
			'kicker'  => 'SETTINGS / SAVED',
			'title'   => __( '设置已保存', 'sakura-imgopt' ),
			'message' => implode( ' ', $messages ),
		);
	}
}
