<?php
/**
 * In-place save marker.
 *
 * A card opts into saving without a reload through form.inplace, which prints
 * data-sakura-inplace. ui.js keys its fetch interception off that attribute and
 * nothing else, so the attribute has to appear exactly on the forms the server
 * opted in: on every form it would stop the pages where a neighbouring card
 * prints values derived from the saved one from ever refreshing.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\UI\Card;

require_once dirname( __DIR__, 2 ) . '/src/Admin/UI/Card.php';

final class CardInplaceTest extends TestCase {

	/**
	 * Render one card and return its markup.
	 *
	 * @param array<string,mixed> $form Form arguments.
	 * @return string
	 */
	private function render( array $form ): string {
		ob_start();
		Card::render(
			array(
				'id'          => 'layout',
				'title'       => '布局设置',
				'form'        => $form,
				'save'        => '保存布局设置',
				'grid_column' => array( 1, 12 ),
			),
			static function () {
				echo '<p class="body">x</p>';
			}
		);

		return (string) ob_get_clean();
	}

	public function test_inplace_card_prints_the_attribute(): void {
		$html = $this->render(
			array(
				'markers' => 'none',
				'inplace' => true,
			)
		);

		$this->assertStringContainsString( 'data-sakura-inplace="1"', $html );
		$this->assertStringContainsString( '<form class="card__form"', $html );
	}

	public function test_default_card_keeps_navigating(): void {
		$html = $this->render( array( 'markers' => 'none' ) );

		$this->assertStringNotContainsString( 'data-sakura-inplace', $html );
	}

	/**
	 * A GET form cannot be submitted through fetch with a body, and a card with
	 * markers=none prints its own nonce, so neither may claim the attribute.
	 */
	public function test_unsupported_forms_never_opt_in(): void {
		$get = $this->render(
			array(
				'markers' => 'none',
				'method'  => 'GET',
				'inplace' => true,
			)
		);
		$this->assertStringNotContainsString( 'data-sakura-inplace', $get );

		$custom = $this->render(
			array(
				'markers' => 'none',
				'inplace' => true,
			)
		);
		$this->assertStringContainsString( 'data-sakura-inplace', $custom, 'markers=none posts through options.php too' );
	}
}
