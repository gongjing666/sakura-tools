/**
 * File space appearance controller: live folder tree preview.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	if ( ! admin ) {
		return;
	}

	admin.ready( function () {
		var root = admin.root();
		var appearance = root ? root.querySelector( '.appearance-grid' ) : null;

		if ( ! appearance ) {
			return;
		}

		var strings = ( admin.data && admin.data.strings ) || {};
		var preview = appearance.querySelector( '[data-tree-preview]' );
		var summary = root.querySelector( '[data-style-summary]' );
		var custom = appearance.querySelector( '[data-accent-custom]' );
		var customRadio = appearance.querySelector( '[data-accent-custom-radio]' );
		var customLabel = customRadio ? customRadio.closest( '.accent-choice' ) : null;
		var customDot = appearance.querySelector( '[data-accent-custom-dot]' );
		var customColor = appearance.querySelector( '[data-accent-custom-color]' );
		var customHex = appearance.querySelector( '[data-accent-custom-hex]' );
		var customApply = appearance.querySelector( '[data-accent-custom-apply]' );

		function fill( template, values ) {
			return String( template || '' ).replace( /\{(\w+)\}/g, function ( match, key ) {
				return Object.prototype.hasOwnProperty.call( values, key ) ? String( values[ key ] ) : match;
			} );
		}

		function checkedValue( name ) {
			var input = appearance.querySelector( 'input[name="' + name + '"]:checked' );
			return input ? input.value : '';
		}

		function labelOf( input ) {
			var label = input ? input.closest( '.style-choice, .accent-choice' ) : null;
			return label ? ( label.getAttribute( 'data-label' ) || '' ) : '';
		}

		function markSelected() {
			admin.qsa( '.style-choice, .accent-choice', appearance ).forEach( function ( label ) {
				var input = label.querySelector( 'input' );
				label.classList.toggle( 'is-selected', !!( input && input.checked ) );
			} );
		}

		function isCustom( value ) {
			return /^#[0-9a-f]{6}$/i.test( value || '' );
		}

		function applyCustom( value ) {
			if ( ! isCustom( value ) || ! customRadio ) {
				return false;
			}

			customRadio.value = String( value ).toLowerCase();
			customRadio.checked = true;

			if ( customDot ) {
				customDot.style.background = customRadio.value;
			}
			if ( customColor ) {
				customColor.value = customRadio.value;
			}
			if ( customHex ) {
				customHex.value = customRadio.value;
			}

			update();
			return true;
		}

		function update() {
			var styleInput = appearance.querySelector( 'input[name="sufm_settings[tree_style]"]:checked' );
			var accentInput = appearance.querySelector( 'input[name="sufm_settings[folder_accent]"]:checked' );
			var style = checkedValue( 'sufm_settings[tree_style]' ) || 'windows';
			var accent = checkedValue( 'sufm_settings[folder_accent]' );
			var customAccent = isCustom( accent );

			if ( preview ) {
				preview.setAttribute( 'data-style', style );
				preview.setAttribute( 'data-accent', customAccent ? 'custom' : accent );
				preview.style.removeProperty( '--folder-ink' );

				if ( customAccent ) {
					preview.style.setProperty( '--folder-ink', accent );
				}
			}

			markSelected();

			if ( summary ) {
				var styleLabel = labelOf( styleInput ) || strings.styleFallback || style;
				var accentLabel = customAccent
					? ( customLabel ? customLabel.getAttribute( 'data-label' ) : '' )
					: ( labelOf( accentInput ) || strings.accentFallback || '' );
				summary.textContent = fill( strings.currentSelection || '', {
					style: styleLabel,
					accent: accentLabel
				} );
			}
		}

		admin.on( appearance, 'change', function ( event ) {
			if ( event.target && event.target.name && event.target.name.indexOf( 'sufm_settings[' ) === 0 ) {
				update();
			}
		} );

		if ( custom ) {
			admin.on( custom, 'input', function ( event ) {
				if ( event.target === customColor || event.target === customHex ) {
					applyCustom( event.target.value );
				}
			} );

			if ( customApply ) {
				customApply.addEventListener( 'click', function () {
					var value = customHex ? customHex.value : ( customColor ? customColor.value : '' );

					if ( ! applyCustom( value ) && customHex ) {
						customHex.focus();
					}
				} );
			}
		}

		update();
	} );
}( window, document ) );
