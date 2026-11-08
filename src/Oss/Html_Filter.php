<?php
/**
 * Scoped OSS HTML rewriter.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Oss;

defined( 'ABSPATH' ) || exit;

/**
 * Walks HTML with the WordPress HTML API and only rewrites media URLs that
 * sit inside an explicitly allowed list, card or sidebar scope.
 */
final class Html_Filter {

	/**
	 * Validated OSS thumbnail options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $options Validated plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Filter a full document or fragment.
	 *
	 * @param string $html          HTML to filter.
	 * @param bool   $force_sidebar Whether the fragment is already known to be a sidebar widget.
	 * @return string
	 */
	public function filter( string $html, bool $force_sidebar ): string {
		$host = (string) ( $this->options['oss_host'] ?? '' );

		if ( '' === $html || '' === $host || false === stripos( $html, $host ) ) {
			return $html;
		}

		if ( ! class_exists( 'WP_HTML_Processor' ) ) {
			return $html;
		}

		$is_full_document = ! $force_sidebar && ( false !== stripos( $html, '<html' ) || false !== stripos( $html, '<!doctype' ) );
		$processor        = $is_full_document
			? \WP_HTML_Processor::create_full_parser( $html )
			: \WP_HTML_Processor::create_fragment( $html );

		if ( ! $processor ) {
			return $html;
		}

		$suffixes = array(
			'list'    => Url_Rewriter::build_scope_suffix( $this->options, 'list' ),
			'card'    => Url_Rewriter::build_scope_suffix( $this->options, 'card' ),
			'sidebar' => Url_Rewriter::build_scope_suffix( $this->options, 'sidebar' ),
		);

		$sidebar_classes = self::class_list( (string) ( $this->options['sidebar_classes'] ?? '' ) );
		$row_classes     = self::class_list( (string) ( $this->options['row_classes'] ?? '' ) );
		$post_classes    = self::class_list( (string) ( $this->options['post_classes'] ?? '' ) );
		$thumb_classes   = self::class_list( (string) ( $this->options['thumb_classes'] ?? '' ) );

		$sidebar_scopes  = array();
		$posts_rows      = array();
		$post_scopes     = array();
		$thumbnail_areas = array();

		while ( $processor->next_token() ) {
			$tag = $processor->get_tag();
			if ( ! $tag ) {
				continue;
			}

			$depth = $processor->get_current_depth();
			$this->trim_scopes( $sidebar_scopes, $depth );
			$this->trim_scopes( $posts_rows, $depth );
			$this->trim_scopes( $post_scopes, $depth );
			$this->trim_scopes( $thumbnail_areas, $depth );

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			if ( $force_sidebar ) {
				$this->rewrite_current_media_tag( $processor, $host, $suffixes['sidebar'] );
				continue;
			}

			if ( $this->matches_any_class( $processor, $sidebar_classes ) ) {
				$sidebar_scopes[] = array( 'depth' => $depth );
			}

			if ( $this->matches_any_class( $processor, $row_classes ) ) {
				$posts_rows[] = array( 'depth' => $depth );
			}

			$in_sidebar   = ! empty( $sidebar_scopes );
			$in_posts_row = ! empty( $posts_rows );

			if (
				! $in_sidebar &&
				$in_posts_row &&
				$this->matches_any_class( $processor, $post_classes ) &&
				! $processor->has_class( 'product-item' )
			) {
				$mode = '';
				if ( $processor->has_class( 'card' ) ) {
					$mode = 'card';
				} elseif ( $processor->has_class( 'list' ) ) {
					$mode = 'list';
				}

				if ( $mode ) {
					$post_scopes[] = array(
						'depth' => $depth,
						'mode'  => $mode,
					);
				}
			}

			if (
				! empty( $post_scopes ) &&
				$this->matches_any_class( $processor, $thumb_classes )
			) {
				$thumbnail_areas[] = array( 'depth' => $depth );
			}

			if ( $in_sidebar && ! empty( $this->options['sidebar_enabled'] ) ) {
				$this->rewrite_current_media_tag( $processor, $host, $suffixes['sidebar'] );
				continue;
			}

			if ( empty( $post_scopes ) || empty( $thumbnail_areas ) ) {
				continue;
			}

			$current_post = end( $post_scopes );
			$mode         = isset( $current_post['mode'] ) ? $current_post['mode'] : '';
			if ( isset( $suffixes[ $mode ] ) ) {
				$this->rewrite_current_media_tag( $processor, $host, $suffixes[ $mode ] );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Drop scopes that the current token has left.
	 *
	 * @param array<int, array<string, int|string>> $scopes Scope stack.
	 * @param int                                   $depth  Current HTML depth.
	 */
	private function trim_scopes( array &$scopes, int $depth ): void {
		while ( ! empty( $scopes ) ) {
			$current = end( $scopes );
			if ( isset( $current['depth'] ) && (int) $current['depth'] <= $depth ) {
				break;
			}
			array_pop( $scopes );
		}
	}

	/**
	 * Rewrite media attributes on the current tag.
	 *
	 * @param \WP_HTML_Processor $processor HTML processor.
	 * @param string             $host      Allowed OSS host.
	 * @param string             $suffix    Query suffix.
	 */
	private function rewrite_current_media_tag( $processor, string $host, string $suffix ): void {
		if ( '' === $suffix ) {
			return;
		}

		$tag = $processor->get_tag();
		if ( 'IMG' === $tag ) {
			foreach ( array( 'src', 'data-src', 'switch-src', 'white-src', 'dark-src' ) as $attribute ) {
				$this->rewrite_url_attribute( $processor, $attribute, $host, $suffix, false );
			}
			foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) {
				$this->rewrite_url_attribute( $processor, $attribute, $host, $suffix, true );
			}
			return;
		}

		if ( 'SOURCE' === $tag ) {
			foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) {
				$this->rewrite_url_attribute( $processor, $attribute, $host, $suffix, true );
			}
			return;
		}

		if ( 'VIDEO' === $tag ) {
			$this->rewrite_url_attribute( $processor, 'poster', $host, $suffix, false );
		}
	}

	/**
	 * Rewrite one URL or srcset attribute.
	 *
	 * @param \WP_HTML_Processor $processor HTML processor.
	 * @param string             $attribute Attribute name.
	 * @param string             $host      OSS host.
	 * @param string             $suffix    Query suffix.
	 * @param bool               $is_srcset Whether the value is a srcset.
	 */
	private function rewrite_url_attribute( $processor, string $attribute, string $host, string $suffix, bool $is_srcset ): void {
		$value = $processor->get_attribute( $attribute );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return;
		}

		$new_value = $is_srcset
			? Url_Rewriter::rewrite_srcset( $value, $host, $suffix )
			: Url_Rewriter::rewrite_image_url( $value, $host, $suffix );

		if ( $new_value !== $value ) {
			$processor->set_attribute( $attribute, $new_value );
		}
	}

	/**
	 * Whether the current tag carries any configured class.
	 *
	 * @param \WP_HTML_Processor $processor HTML processor.
	 * @param string[]           $classes   Class list.
	 * @return bool
	 */
	private function matches_any_class( $processor, array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( $processor->has_class( $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a comma-separated class configuration.
	 *
	 * @param string $value Configuration value.
	 * @return string[]
	 */
	private static function class_list( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}
