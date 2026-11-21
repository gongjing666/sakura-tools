<?php
/**
 * Field component for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * One labelled control.
 *
 * Complex controls use type=custom and keep their existing callback: the field
 * wrapper provides the label and spacing, the callback owns the markup and the
 * submitted names.
 */
final class Field {

	/**
	 * Render a field.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 * @return void
	 */
	public static function render( array $args ): void {
		$type = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : 'text';
		$type = in_array( $type, array( 'text', 'number', 'url', 'password', 'email', 'color', 'select', 'textarea', 'checkbox', 'radio', 'toggle', 'hidden', 'custom' ), true ) ? $type : 'text';

		if ( 'hidden' === $type ) {
			self::hidden( $args );
			return;
		}

		$id          = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$label       = isset( $args['label'] ) ? (string) $args['label'] : '';
		$description = isset( $args['hint'] ) ? (string) $args['hint'] : ( isset( $args['description'] ) ? (string) $args['description'] : '' );
		$full        = ! empty( $args['full'] );
		$classes     = array( 'field', 'field--' . $type );

		if ( $full ) {
			$classes[] = 'field--full';
		}
		if ( ! empty( $args['class'] ) ) {
			$classes[] = (string) $args['class'];
		}

		$hint_id = '' !== $id && '' !== $description ? $id . '-hint' : '';

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( '' !== $label || '' !== $description ) {
			echo '<div class="field__main">';
			if ( '' !== $label ) {
				$for = isset( $args['for'] ) ? (string) $args['for'] : $id;
				if ( '' !== $for && ! in_array( $type, array( 'checkbox', 'radio', 'toggle' ), true ) ) {
					echo '<label class="field__label" for="' . esc_attr( $for ) . '">' . wp_kses_post( $label ) . '</label>';
				} else {
					echo '<span class="field__label">' . wp_kses_post( $label ) . '</span>';
				}
			}
			if ( '' !== $description ) {
				echo '<p class="field__hint"';
				if ( '' !== $hint_id ) {
					echo ' id="' . esc_attr( $hint_id ) . '"';
				}
				echo '>' . wp_kses_post( $description ) . '</p>';
			}
			echo '</div>';
		}

		echo '<div class="field__control">';
		self::control( $type, $args, $id, $hint_id );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the control markup.
	 *
	 * @param string              $type    Field type.
	 * @param array<string,mixed> $args    Field arguments.
	 * @param string              $id      Field id.
	 * @param string              $hint_id Hint id.
	 * @return void
	 */
	private static function control( string $type, array $args, string $id, string $hint_id ): void {
		if ( 'custom' === $type ) {
			$control = $args['control'] ?? null;
			if ( is_callable( $control ) ) {
				$result = call_user_func( $control, isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : $args );
				if ( is_string( $result ) ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callback owns and escapes its markup.
				}
			}
			return;
		}

		if ( 'radio' === $type ) {
			self::radio( $args, $id, $hint_id );
			return;
		}

		if ( 'checkbox' === $type || 'toggle' === $type ) {
			self::checkbox( $args, $id, $hint_id, 'toggle' === $type );
			return;
		}

		if ( 'select' === $type ) {
			self::select( $args, $id, $hint_id );
			return;
		}

		if ( 'textarea' === $type ) {
			echo '<textarea' . self::attributes( $args, $id, $hint_id, false ) . '>' . esc_textarea( (string) ( $args['value'] ?? '' ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every attribute it emits.
			return;
		}

		$input_type = in_array( $type, array( 'text', 'number', 'url', 'password', 'email', 'color' ), true ) ? $type : 'text';
		echo '<input' . self::attributes( $args, $id, $hint_id, true, $input_type ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every attribute it emits.
	}

	/**
	 * Checkbox or switch.
	 *
	 * @param array<string,mixed> $args    Field arguments.
	 * @param string              $id      Field id.
	 * @param string              $hint_id Hint id.
	 * @param bool                $toggle  Whether this is a toggle.
	 * @return void
	 */
	private static function checkbox( array $args, string $id, string $hint_id, bool $toggle ): void {
		$name    = isset( $args['name'] ) ? (string) $args['name'] : $id;
		$value   = isset( $args['value'] ) ? (string) $args['value'] : '1';
		$checked = ! empty( $args['checked'] ) || ( ! isset( $args['checked'] ) && ! empty( $args['value'] ) );
		$text    = isset( $args['text'] ) ? (string) $args['text'] : '';

		// A toggle is the switch: the same native checkbox with `.is-switch`, so
		// the keyboard, the label wrap and the submitted value stay native.
		echo '<label class="check">';
		echo '<input' . ( $toggle ? ' class="is-switch"' : '' ) . ' type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . self::checked( $checked ) . self::disabled( $args ) . self::describedby( $hint_id ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked()/disabled()/describedby() emit escaped attributes.
		echo '<span class="check__box" aria-hidden="true"></span>';
		if ( '' !== $text ) {
			echo '<span class="check__text">' . esc_html( $text ) . '</span>';
		}
		echo '</label>';
	}

	/**
	 * Radio group.
	 *
	 * @param array<string,mixed> $args    Field arguments.
	 * @param string              $id      Field id.
	 * @param string              $hint_id Hint id.
	 * @return void
	 */
	private static function radio( array $args, string $id, string $hint_id ): void {
		$name    = isset( $args['name'] ) ? (string) $args['name'] : $id;
		$value   = isset( $args['value'] ) ? (string) $args['value'] : '';
		$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

		echo '<div class="check-group" role="radiogroup">';
		foreach ( self::options( $options ) as $option_value => $option_label ) {
			echo '<label class="check">';
			echo '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option_value ) . '"' . self::checked( $value === $option_value ) . self::disabled( $args ) . self::describedby( $hint_id ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked()/disabled()/describedby() emit escaped attributes.
			echo '<span class="check__box" aria-hidden="true"></span>';
			echo '<span class="check__text">' . esc_html( $option_label ) . '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	/**
	 * Select control.
	 *
	 * @param array<string,mixed> $args    Field arguments.
	 * @param string              $id      Field id.
	 * @param string              $hint_id Hint id.
	 * @return void
	 */
	private static function select( array $args, string $id, string $hint_id ): void {
		$value   = isset( $args['value'] ) ? (string) $args['value'] : '';
		$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

		echo '<select' . self::attributes( $args, $id, $hint_id, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every attribute it emits.
		foreach ( self::options( $options ) as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Hidden input.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 * @return void
	 */
	private static function hidden( array $args ): void {
		$name  = isset( $args['name'] ) ? (string) $args['name'] : (string) ( $args['id'] ?? '' );
		$value = isset( $args['value'] ) ? (string) $args['value'] : '';

		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
	}

	/**
	 * Normalise an option map.
	 *
	 * @param array<mixed> $options Raw options.
	 * @return array<string,string>
	 */
	private static function options( array $options ): array {
		$normalised = array();
		foreach ( $options as $key => $option ) {
			if ( is_array( $option ) ) {
				$value = isset( $option['value'] ) ? (string) $option['value'] : (string) $key;
				$label = isset( $option['label'] ) ? (string) $option['label'] : $value;
			} else {
				$value = (string) $key;
				$label = (string) $option;
			}
			$normalised[ $value ] = $label;
		}

		return $normalised;
	}

	/**
	 * Shared input attributes.
	 *
	 * @param array<string,mixed> $args      Field arguments.
	 * @param string              $id        Field id.
	 * @param string              $hint_id   Hint id.
	 * @param bool                $with_value Whether to include value.
	 * @param string              $type      Input type.
	 * @return string
	 */
	private static function attributes( array $args, string $id, string $hint_id, bool $with_value, string $type = 'text' ): string {
		$name  = isset( $args['name'] ) ? (string) $args['name'] : $id;
		$attrs = array(
			'type'  => $type,
			'id'    => $id,
			'name'  => $name,
			'class' => isset( $args['input_class'] ) ? (string) $args['input_class'] : '',
		);

		if ( $with_value ) {
			$attrs['value'] = isset( $args['value'] ) ? (string) $args['value'] : '';
		}
		if ( ! empty( $args['placeholder'] ) ) {
			$attrs['placeholder'] = (string) $args['placeholder'];
		}
		if ( isset( $args['min'] ) ) {
			$attrs['min'] = (string) $args['min'];
		}
		if ( isset( $args['max'] ) ) {
			$attrs['max'] = (string) $args['max'];
		}
		if ( isset( $args['step'] ) ) {
			$attrs['step'] = (string) $args['step'];
		}
		if ( ! empty( $args['autocomplete'] ) ) {
			$attrs['autocomplete'] = (string) $args['autocomplete'];
		}
		if ( ! empty( $args['attrs'] ) && is_array( $args['attrs'] ) ) {
			$attrs = array_merge( $attrs, $args['attrs'] );
		}

		$html = '';
		foreach ( $attrs as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$html .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		if ( ! empty( $args['required'] ) ) {
			$html .= ' required';
		}
		if ( ! empty( $args['readonly'] ) ) {
			$html .= ' readonly';
		}
		$html .= self::disabled( $args );
		$html .= self::describedby( $hint_id );

		return $html;
	}

	/**
	 * Checked attribute.
	 *
	 * @param bool $checked Whether checked.
	 * @return string
	 */
	private static function checked( bool $checked ): string {
		return $checked ? ' checked' : '';
	}

	/**
	 * Disabled attribute.
	 *
	 * @param array<string,mixed> $args Field arguments.
	 * @return string
	 */
	private static function disabled( array $args ): string {
		return ! empty( $args['disabled'] ) ? ' disabled' : '';
	}

	/**
	 * aria-describedby attribute.
	 *
	 * @param string $hint_id Hint id.
	 * @return string
	 */
	private static function describedby( string $hint_id ): string {
		return '' !== $hint_id ? ' aria-describedby="' . esc_attr( $hint_id ) . '"' : '';
	}
}
