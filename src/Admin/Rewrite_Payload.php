<?php
/**
 * Bootstrap payload for the attachment URL rewrite island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the page arguments and the one config document the island reads.
 *
 * The scan/run/revert endpoints stay admin-ajax: the service already owns the
 * cursor and time box, and the legacy fallback keeps working against the same
 * actions when the bundle is missing.
 */
final class Rewrite_Payload {

	/**
	 * Page id, also the Layout and Page_Assets key.
	 */
	public const PAGE = 'storage-rewrite';

	/**
	 * Panel that owns the scan state, hosts and strings.
	 *
	 * @var Url_Rewrite_Panel
	 */
	private Url_Rewrite_Panel $panel;

	/**
	 * Constructor.
	 *
	 * @param Url_Rewrite_Panel|null $panel Panel.
	 */
	public function __construct( ?Url_Rewrite_Panel $panel = null ) {
		$this->panel = $panel ?? new Url_Rewrite_Panel();
	}

	/**
	 * The whole bootstrap document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$config = $this->panel->page_config();
		$state  = isset( $config['state'] ) && is_array( $config['state'] ) ? $config['state'] : array();
		$cards  = $this->cards();
		$page   = $this->page_args( $state, $cards, (string) ( $config['currentHost'] ?? '' ) );

		$config['form'] = array(
			'from' => (string) ( $state['from'] ?? '' ),
			'to'   => '' !== (string) ( $state['to'] ?? '' ) ? (string) $state['to'] : (string) ( $config['currentHost'] ?? '' ),
		);

		$strings = isset( $config['strings'] ) && is_array( $config['strings'] ) ? $config['strings'] : array();

		$config['targetFields'] = array(
			array(
				'key'          => 'from',
				'id'           => 'rewrite-from',
				'type'         => 'text',
				'label'        => (string) ( $strings['fromLabel'] ?? '' ),
				'hint'         => (string) ( $strings['fromHint'] ?? '' ),
				'placeholder'  => 'bf.nboke.com',
				'list'         => 'rewrite-host-options',
				'autocomplete' => 'off',
				'spellcheck'   => false,
			),
			array(
				'key'          => 'to',
				'id'           => 'rewrite-to',
				'type'         => 'text',
				'label'        => (string) ( $strings['toLabel'] ?? '' ),
				'hint'         => (string) ( $strings['toHint'] ?? '' ),
				'placeholder'  => 'doge.nboke.com',
				'list'         => 'rewrite-host-options',
				'autocomplete' => 'off',
				'spellcheck'   => false,
			),
		);

		return array(
			'version' => SAKURA_TOOLS_VERSION,
			'page'    => $page,
			'head'    => UI\Page::head_data( $page ),
			'cards'   => $cards,
			'config'  => $config,
		);
	}

	/**
	 * Page arguments shared by the first paint and the React head.
	 *
	 * @param array<string,mixed>       $state        Scan summary.
	 * @param array<int,array<string,mixed>> $cards    Card metadata.
	 * @param string                    $current_host Current media host.
	 * @return array<string,mixed>
	 */
	private function page_args( array $state, array $cards, string $current_host ): array {
		$from = (string) ( $state['from'] ?? '' );
		$to   = (string) ( $state['to'] ?? '' );

		return array(
			'id'       => self::PAGE,
			'module'   => 'storage',
			'tab'      => 'rewrite',
			'eyebrow'  => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
			'title'    => __( '附件链接改写', 'sakura-imgopt' ),
			'lede'     => __( '换了对象存储之后，把文章、主题和插件配置里仍然指向旧域名的附件链接统一改成新域名。', 'sakura-imgopt' ),
			'status'   => array(
				'label' => __( '当前媒体域名', 'sakura-imgopt' ),
				'value' => '' !== $current_host ? $current_host : __( '未接管', 'sakura-imgopt' ),
				'sub'   => __( '改写先记账本，可回滚', 'sakura-imgopt' ),
				'tone'  => '' !== $current_host ? 'ok' : 'warn',
			),
			'actions'  => array(
				array(
					'label'   => __( '备份还原', 'sakura-imgopt' ),
					'url'     => Registry::url( 'image', 'backup' ),
					'variant' => 'quiet',
				),
				array(
					'label'   => __( '连接配置', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'connection' ),
					'variant' => 'quiet',
				),
			),
			'island'   => true,
			'skeleton' => array(
				'cards' => array_map(
					static function ( array $card ): string {
						return (string) $card['id'];
					},
					$cards
				),
			),
			'from'     => $from,
			'to'       => $to,
		);
	}

	/**
	 * Card chrome shared by PHP and React.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cards(): array {
		$cards = array(
			array(
				'id'          => 'target',
				'kicker'      => __( 'Step 1', 'sakura-imgopt' ),
				'title'       => __( '目标域名', 'sakura-imgopt' ),
				'description' => __( '只改域名，不改路径。只匹配旧域名下、路径以当前附件前缀开头的 URL；同一行里的主题/API 等链接不会动。', 'sakura-imgopt' ),
			),
			array(
				'id'          => 'scan',
				'kicker'      => __( 'Step 2', 'sakura-imgopt' ),
				'title'       => __( '扫描与选择', 'sakura-imgopt' ),
				'description' => __( '扫描当前站点前缀下的文本列，只统计旧域名 + 附件路径命中的位置；文章内容与主题数据默认勾选。', 'sakura-imgopt' ),
			),
			array(
				'id'          => 'run',
				'kicker'      => __( 'Step 3', 'sakura-imgopt' ),
				'title'       => __( '执行改写', 'sakura-imgopt' ),
				'description' => __( '分批执行，每批最多 50 行；先写账本再更新，失败的行保持原值。关闭页面后可以继续。', 'sakura-imgopt' ),
			),
			array(
				'id'          => 'history',
				'kicker'      => __( 'Rollback', 'sakura-imgopt' ),
				'title'       => __( '回滚账本', 'sakura-imgopt' ),
				'description' => __( '每次改写一行账本；回滚只把值改回，不删除账本记录（状态标为已回滚）。', 'sakura-imgopt' ),
			),
		);

		foreach ( $cards as $index => $card ) {
			$cards[ $index ]['placement'] = Layout::card( 'rewrite', (string) $card['id'] );
		}

		return $cards;
	}
}
