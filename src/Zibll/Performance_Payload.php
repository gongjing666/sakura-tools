<?php
/**
 * Payload for the Zibll performance page island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\UI\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the page head, cards and controller data for zibll-performance.
 */
final class Performance_Payload {

	/**
	 * Page id.
	 */
	private const PAGE = 'zibll-performance';

	/**
	 * Build the complete island payload.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$state     = Admin_Module::cache_payload();
		$cache     = License_Cache::get();
		$enabled   = ! empty( $state['enabled'] );
		$valid     = ! empty( $state['valid'] );
		$supported = ! empty( $state['supported'] );
		$version   = (string) ( $state['theme'] ?? '' );
		$status    = $this->status( $enabled, $valid );
		$page      = array(
			'id'      => self::PAGE,
			'module'  => 'zibll',
			'tab'     => 'performance',
			'eyebrow' => 'ZIBLL / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '' ),
			'title'   => __( '子比增强 · 性能优化', 'sakura-imgopt' ),
			'lede'    => __( '把主题每页都要跑的授权校验换成一次真实校验后的缓存。这里不伪造授权：只有主题自己校验通过的结论才会被写进缓存，失效条件全部在本页可见。', 'sakura-imgopt' ),
			'status'  => $status,
		);
		$strings = $this->strings();

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $page,
			'head'    => Page::head_data( $page ),
			'cards'   => $this->cards( $enabled, $valid, $supported, $version, $state, $cache ),
			'config'  => array(
				'action'  => Admin_Module::ACTION,
				'nonce'   => wp_create_nonce( Admin_Module::NONCE ),
				'strings' => $strings,
			),
		);
	}

	/**
	 * Page status card.
	 *
	 * @param bool $enabled Whether the cache is on.
	 * @param bool $valid   Whether the cache is valid.
	 * @return array<string,string>
	 */
	private function status( bool $enabled, bool $valid ): array {
		if ( $enabled && $valid ) {
			return array(
				'label' => __( '授权缓存', 'sakura-imgopt' ),
				'value' => __( '已启用', 'sakura-imgopt' ),
				'sub'   => __( '页面命中缓存时跳过授权校验', 'sakura-imgopt' ),
				'tone'  => 'ok',
			);
		}

		if ( $enabled ) {
			return array(
				'label' => __( '授权缓存', 'sakura-imgopt' ),
				'value' => __( '已失效', 'sakura-imgopt' ),
				'sub'   => __( '页面请求仍由子比主题校验', 'sakura-imgopt' ),
				'tone'  => 'warn',
			);
		}

		return array(
			'label' => __( '授权缓存', 'sakura-imgopt' ),
			'value' => __( '未启用', 'sakura-imgopt' ),
			'sub'   => __( '每个页面请求都会真实验证', 'sakura-imgopt' ),
			'tone'  => 'neutral',
		);
	}

	/**
	 * Card metadata and per-card data.
	 *
	 * @param bool                 $enabled   Whether the cache is on.
	 * @param bool                 $valid     Whether the cache is valid.
	 * @param bool                 $supported Whether the theme version is supported.
	 * @param string               $version   Theme version.
	 * @param array<string,mixed>  $state     Cache payload.
	 * @param array<string,mixed>  $cache     Raw cache option.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( bool $enabled, bool $valid, bool $supported, string $version, array $state, array $cache ): array {
		$definitions = array(
			'license-cache' => array(
				'id'          => 'license-cache',
				'kicker'      => 'License',
				'title'       => __( 'Zibll 授权缓存', 'sakura-imgopt' ),
				'description' => __( '子比 8.8 起每个请求（含 wp-admin）都会跑一次授权校验：9.2 约 33 ms，其中大半是 OPcache 存不下的 eval。这里只缓存“已经真实通过”的结论。', 'sakura-imgopt' ),
				'form'        => $this->form(),
				'save'        => __( '保存并验证', 'sakura-imgopt' ),
			),
			'cache-status' => array(
				'id'          => 'cache-status',
				'kicker'      => 'Status',
				'title'       => __( '授权缓存状态', 'sakura-imgopt' ),
				'description' => __( '当前请求与缓存的实际对比结果；任何一项对不上，请求都会回到主题自己的校验。', 'sakura-imgopt' ),
			),
		);

		$data = array(
			'license-cache' => array(
				'enabled'         => $enabled,
				'valid'           => $valid,
				'duration'        => Settings::duration(),
				'unit'            => Settings::unit(),
				'units'           => Settings::units(),
				'max'             => Settings::MAX_DURATION,
				'supported'       => $supported,
				'version'         => $version,
				'supported_label' => License::supported_label(),
				'invalid_notice'  => $enabled && ! $valid,
				'option'          => Settings::OPTION,
				'ack'             => Settings::ACK_FIELD,
			),
			'cache-status' => array(
				'rows' => $this->rows( $state, $cache, $supported, $version ),
			),
		);

		$cards = array();

		foreach ( $definitions as $id => $card ) {
			$card['placement'] = Layout::card( self::PAGE, $id ) ?? array( 0, 1, 12 );
			$card['data']      = $data[ $id ];
			$cards[]           = $card;
		}

		return $cards;
	}

	/**
	 * Native options.php form description.
	 *
	 * @return array<string,string>
	 */
	private function form(): array {
		return array(
			'action'     => admin_url( 'options.php' ),
			'group'      => Settings::GROUP,
			'nonce'      => wp_create_nonce( Settings::GROUP . '-options' ),
			'markers'    => 'option_fields',
			'option'     => Settings::OPTION,
			'markerCard' => Settings::CARD,
		);
	}

	/**
	 * Status table rows.
	 *
	 * @param array<string,mixed> $state     Cache payload.
	 * @param array<string,mixed> $cache     Raw cache option.
	 * @param bool                $supported Whether the theme version is supported.
	 * @param string              $version   Theme version.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $state, array $cache, bool $supported, string $version ): array {
		$created      = (int) ( $cache['created_at'] ?? 0 );
		$source       = (string) ( $cache['source'] ?? '' );
		$sources      = array(
			'admin_enable' => __( '保存时启用验证', 'sakura-imgopt' ),
			'ajax_manual'  => __( '手动复验', 'sakura-imgopt' ),
			'cron'         => __( '每日复验', 'sakura-imgopt' ),
			'manual'       => __( '手动验证', 'sakura-imgopt' ),
		);
		$source_label = isset( $sources[ $source ] ) ? $sources[ $source ] : $source;
		$valid        = ! empty( $state['valid'] );
		$ok           = ! empty( $cache['ok'] );
		$expires      = License::expires_at();
		$cache_until  = (int) ( $state['cache_until'] ?? 0 );
		$next_run     = (int) ( $state['next_run'] ?? 0 );

		$cache_value = __( '尚未写入', 'sakura-imgopt' );
		if ( $valid ) {
			$cache_value = __( '有效', 'sakura-imgopt' );
		} elseif ( $ok ) {
			$cache_value = __( '已写入，但与当前环境或授权对不上，未生效', 'sakura-imgopt' );
		}

		$cache_meta = '';
		if ( $valid && $created > 0 ) {
			$cache_meta = sprintf(
				/* translators: 1: verification time, 2: verification source. */
				__( '（%1$s 验证，来源：%2$s）', 'sakura-imgopt' ),
				$this->time_label( $created ),
				$source_label
			);
		}

		$lifetime_meta = '';
		if ( $cache_until > 0 ) {
			$lifetime_meta = sprintf(
				/* translators: %s: cache expiry time. */
				__( '到期时间 %s', 'sakura-imgopt' ),
				$this->time_label( $cache_until )
			);
		} elseif ( $ok ) {
			$lifetime_meta = __( '不设时间上限，仅受授权到期约束', 'sakura-imgopt' );
		}

		return array(
			array(
				'key'   => 'theme',
				'label' => __( '主题版本', 'sakura-imgopt' ),
				'value' => '' !== $version ? $version : __( '未知', 'sakura-imgopt' ),
				'badge' => array(
					'text' => $supported ? __( '已验证版本', 'sakura-imgopt' ) : __( '未验证版本', 'sakura-imgopt' ),
					'tone' => $supported ? 'ok' : 'warn',
				),
				'meta'  => '',
			),
			array(
				'key'   => 'cache',
				'label' => __( '授权缓存', 'sakura-imgopt' ),
				'value' => $cache_value,
				'badge' => array(),
				'meta'  => $cache_meta,
			),
			array(
				'key'   => 'lifetime',
				'label' => __( '缓存有效期', 'sakura-imgopt' ),
				'value' => (string) $state['lifetime_label'],
				'badge' => array(),
				'meta'  => $lifetime_meta,
			),
			array(
				'key'   => 'expires',
				'label' => __( '授权到期', 'sakura-imgopt' ),
				'value' => $this->time_label( $expires ),
				'badge' => array(),
				'meta'  => $expires <= 0 ? __( '主题没有记录到期时间，缓存不会因到期自动失效', 'sakura-imgopt' ) : '',
			),
			array(
				'key'   => 'next',
				'label' => __( '下次复验', 'sakura-imgopt' ),
				'value' => $next_run > 0 ? $this->time_label( $next_run ) : __( '未排程', 'sakura-imgopt' ),
				'badge' => array(),
				'meta'  => '',
			),
		);
	}

	/**
	 * Local time label, matching the old view.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function time_label( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d H:i', $timestamp );
		}

		if ( function_exists( 'date_i18n' ) ) {
			return (string) date_i18n( 'Y-m-d H:i', $timestamp );
		}

		return '—';
	}

	/**
	 * UI copy.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'cacheSwitch'  => __( '启用授权缓存', 'sakura-imgopt' ),
			'revalidate'   => __( '重新验证', 'sakura-imgopt' ),
			'checking'     => __( '正在验证…', 'sakura-imgopt' ),
			'checked'      => __( '验证通过，缓存已刷新。', 'sakura-imgopt' ),
			'failed'       => __( '验证没有通过，缓存已清除。', 'sakura-imgopt' ),
			'disabled'     => __( '请先启用授权缓存，再重新验证。', 'sakura-imgopt' ),
			'duration'     => __( '缓存时长', 'sakura-imgopt' ),
			'unit'         => __( '单位', 'sakura-imgopt' ),
			'hint'         => __( '保存时真实调用一次子比的授权校验；通过后按上面的有效期缓存，前台与后台页面命中缓存时跳过 zib_init_add_action。到期后先回到主题校验，复验完成后重新生效。选「永久」时只受授权到期约束。', 'sakura-imgopt' ),
			/* translators: 1: current theme version, 2: supported versions. */
			'unsupportedNotice' => __( '当前子比版本 %1$s 未经验证（已验证：%2$s）。继续启用需要勾选下面的确认项。', 'sakura-imgopt' ),
			'ackLabel'     => __( '我了解风险，仍要在这个版本上启用', 'sakura-imgopt' ),
			'ackHint'      => __( '确认只在本次保存时生效，不会被记住；下次保存仍需要重新勾选。', 'sakura-imgopt' ),
			'invalidNotice' => __( '开关是开着的，但缓存与当前主题版本、站点地址或授权内容对不上，因此没有生效。请重新保存，或点「重新验证」再做一次。', 'sakura-imgopt' ),
			'statusNote'   => __( '缓存与主题版本、站点地址、授权内容绑定；换域名、改授权或换主题都会让它自动失效。复验按上面设置的有效期排程，到期后自动重新预热。', 'sakura-imgopt' ),
		);
	}
}
