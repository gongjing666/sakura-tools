<?php
/**
 * Scalar-input boundaries for the two card writers.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Admin\Settings_Cards;
use Nboke\UserFiles\Admin\Space_Cards;
use Nboke\UserFiles\Settings;
use PHPUnit\Framework\TestCase;

/**
 * A posted array must never be stringified or cast to 1.
 *
 * The old code ran bare `(string)` / `(int)` / `(float)` casts on row fields,
 * so `['8']` became the literal "Array" (or 1.0) with a PHP warning. These
 * tests pin the guards and prove scalar / legacy formats keep their meaning.
 */
final class CardsScalarInputTest extends TestCase {

	/**
	 * PHP warnings captured during one call.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = array();

	/**
	 * Reset the option store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options'] = array();
		$this->warnings                 = array();
	}

	/**
	 * Drop the fixture.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['sakura_test_options'] = array();

		parent::tearDown();
	}

	/**
	 * Run a callback while collecting PHP warnings.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private function collect_warnings( callable $callback ) {
		$this->warnings = array();

		set_error_handler(
			function ( int $errno, string $message ): bool {
				$this->warnings[] = $message;

				return true;
			},
			E_WARNING | E_USER_WARNING
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Call Space_Cards::fields() without going through the option lock.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted fields.
	 * @return array<string,mixed>
	 */
	private function clean_fields( string $card, array $fields ): array {
		$method = new ReflectionMethod( Space_Cards::class, 'fields' );
		$method->setAccessible( true );

		return (array) $method->invoke( null, $card, $fields );
	}

	/**
	 * An MB field posted as an array is ignored, not stringified.
	 *
	 * @return void
	 */
	public function test_mb_field_posted_as_array_is_ignored_without_warning(): void {
		$result = $this->collect_warnings(
			static function () {
				return Settings_Cards::save(
					Settings_Cards::SETTINGS,
					array(
						'quota_default_bytes_mb' => array( '512' ),
						'max_file_bytes_mb'      => '20',
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertIsArray( $result );

		$stored = get_option( Settings::OPTION );
		self::assertSame( Settings::DEFAULT_QUOTA, $stored['quota_default_bytes'], 'The array is treated as a missing field.' );
		self::assertSame( 20 * 1048576, $stored['max_file_bytes'], 'Scalar MB input is still converted.' );
	}

	/**
	 * Scalar MB input keeps its exact conversion.
	 *
	 * @return void
	 */
	public function test_scalar_mb_fields_keep_their_exact_conversion(): void {
		$result = $this->collect_warnings(
			static function () {
				return Settings_Cards::save(
					Settings_Cards::SETTINGS,
					array(
						'quota_default_bytes_mb' => '512',
						'max_file_bytes_mb'      => '1.5',
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertIsArray( $result );

		$stored = get_option( Settings::OPTION );
		self::assertSame( 536870912, $stored['quota_default_bytes'] );
		self::assertSame( (int) round( 1.5 * 1048576 ), $stored['max_file_bytes'] );
	}

	/**
	 * float(array) used to become 1.0, i.e. a 1 MB level step.
	 *
	 * @return void
	 */
	public function test_level_rule_arrays_do_not_become_one(): void {
		$clean = $this->collect_warnings(
			function () {
				return $this->clean_fields(
					Space_Cards::LEVEL,
					array(
						'level_rule' => array(
							'enabled'           => '1',
							'points_per_step'   => array( '10' ),
							'bytes_per_step_mb' => array( '2' ),
						),
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( 0, $clean['level_rule']['points_per_step'], 'int(array) used to be 1.' );
		self::assertSame( 0.0, $clean['level_rule']['bytes_per_step_mb'], 'float(array) used to be 1.0 MB.' );
		self::assertSame( 1, $clean['level_rule']['enabled'] );
	}

	/**
	 * Scalar level-rule values are unchanged.
	 *
	 * @return void
	 */
	public function test_scalar_level_rule_is_unchanged(): void {
		$clean = $this->collect_warnings(
			function () {
				return $this->clean_fields(
					Space_Cards::LEVEL,
					array(
						'level_rule' => array(
							'enabled'           => '1',
							'points_per_step'   => '10',
							'bytes_per_step_mb' => '2',
						),
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( 10, $clean['level_rule']['points_per_step'] );
		self::assertSame( 2.0, $clean['level_rule']['bytes_per_step_mb'] );
	}

	/**
	 * A non-scalar `gb` must not shadow a scalar legacy `bytes_gb`.
	 *
	 * The old precedence (`gb` first, then `bytes_gb`) still holds for scalars.
	 *
	 * @return void
	 */
	public function test_cash_rows_fall_back_to_scalar_legacy_bytes_gb(): void {
		$clean = $this->collect_warnings(
			function () {
				return $this->clean_fields(
					Space_Cards::PLANS,
					array(
						'packages' => array(
							array(
								'id'       => 'p1',
								'label'    => '旧格式',
								'bytes_gb' => '12',
								'price'    => '30',
								'days'     => '30',
								'active'   => '1',
							),
							array(
								'id'       => 'p2',
								'label'    => '新格式',
								'gb'       => '5',
								'bytes_gb' => '99',
								'price'    => '10',
								'days'     => '7',
							),
							array(
								'id'       => 'p3',
								'label'    => '坏尺寸',
								'gb'       => array( '8' ),
								'bytes_gb' => '12',
								'price'    => '30',
								'days'     => '30',
							),
						),
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertCount( 3, $clean['packages'] );
		self::assertSame( '12', $clean['packages'][0]['bytes_gb'], 'Legacy bytes_gb only.' );
		self::assertSame( '5', $clean['packages'][1]['bytes_gb'], 'Scalar gb still wins over bytes_gb.' );
		self::assertSame( '12', $clean['packages'][2]['bytes_gb'], 'Array gb is absent, legacy fallback applies.' );
		self::assertSame( '30', $clean['packages'][0]['price'] );
		self::assertSame( 1, $clean['packages'][0]['active'] );
	}

	/**
	 * Non-scalar row fields never raise a conversion warning.
	 *
	 * @return void
	 */
	public function test_cash_and_point_row_arrays_do_not_warn(): void {
		$clean = $this->collect_warnings(
			function () {
				return $this->clean_fields(
					Space_Cards::PLANS,
					array(
						'packages'        => array(
							array(
								'id'     => array( 'p4' ),
								'label'  => array( 'x' ),
								'price'  => array( '10' ),
								'days'   => array( '7' ),
								'remove' => array( '1' ),
								'active' => array( '1' ),
							),
						),
						'points_packages' => array(
							array(
								'id'     => 'q1',
								'label'  => '',
								'gb'     => '5',
								'points' => array( '100' ),
								'days'   => '365',
							),
							array(
								'id'     => 'q2',
								'label'  => array( 'x' ),
								'gb'     => '5',
								'points' => '100',
								'days'   => '365',
							),
						),
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( '', $clean['packages'][0]['id'] );
		self::assertSame( '', $clean['packages'][0]['label'] );
		self::assertSame( '', $clean['packages'][0]['price'] );
		self::assertSame( 0, $clean['packages'][0]['remove'], 'A non-scalar remove flag must not delete the row.' );
		self::assertSame( 0, $clean['packages'][0]['active'] );
		self::assertSame( '', $clean['points_packages'][0]['points'] );
		self::assertSame( '5', $clean['points_packages'][0]['gb'] );
		self::assertSame( '365', $clean['points_packages'][0]['days'] );
		self::assertSame( '', $clean['points_packages'][1]['label'] );
		self::assertSame( '100', $clean['points_packages'][1]['points'] );
	}

	/**
	 * The points exchange config arrays collapse to zero, not one.
	 *
	 * @return void
	 */
	public function test_points_config_arrays_collapse_to_zero(): void {
		$clean = $this->collect_warnings(
			function () {
				return $this->clean_fields(
					Space_Cards::PLANS,
					array(
						'points' => array(
							'points_per_gb'    => array( '90' ),
							'max_gb_per_order' => '10',
							'daily_cap_gb'     => array( '20' ),
						),
					)
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( 0, $clean['points']['points_per_gb'] );
		self::assertSame( 10, $clean['points']['max_gb_per_order'] );
		self::assertSame( 0, $clean['points']['daily_cap_gb'] );
	}

	/**
	 * Level and VIP bonus maps drop non-scalars but keep zero entries.
	 *
	 * @return void
	 */
	public function test_bonus_maps_drop_arrays_without_warnings(): void {
		$result = $this->collect_warnings(
			function () {
				return array(
					'level' => $this->clean_fields(
						Space_Cards::LEVEL,
						array(
							'level_rule'     => array( 'enabled' => '0' ),
							'level_bonus_mb' => array(
								1 => '512',
								2 => array( '1024' ),
								3 => '0',
							),
						)
					),
					'vip'   => $this->clean_fields(
						Space_Cards::VIP,
						array(
							'vip_bonus_mb' => array(
								1 => '1024',
								2 => array( 'bad' ),
							),
						)
					),
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( array( 1 => '512', 3 => '0' ), $result['level']['level_bonus_mb'], 'Array value skipped, zero kept.' );
		self::assertSame( array( 1 => '1024' ), $result['vip']['vip_bonus_mb'], 'Array value skipped.' );
	}

	/**
	 * An array order icon is an empty string; scalar legacy input is unchanged.
	 *
	 * @return void
	 */
	public function test_order_icon_array_is_empty_and_scalar_is_kept(): void {
		$result = $this->collect_warnings(
			function () {
				return array(
					'array'  => $this->clean_fields( Space_Cards::PLANS, array( 'order_icon' => array( 'icon' ) ) ),
					'scalar' => $this->clean_fields(
						Space_Cards::PLANS,
						array(
							'order_icon' => 'https://example.test/i.svg',
							'packages'   => 'not-an-array',
						)
					),
				);
			}
		);

		self::assertSame( array(), $this->warnings );
		self::assertSame( '', $result['array']['order_icon'] );
		self::assertSame( 'https://example.test/i.svg', $result['scalar']['order_icon'] );
		self::assertSame( array(), $result['scalar']['packages'], 'A scalar packages field is treated as absent.' );
	}
}
