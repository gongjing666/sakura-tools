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

		function update() {
			var styleInput = appearance.querySelector( 'input[name="sufm_settings[tree_style]"]:checked' );
			var accentInput = appearance.querySelector( 'input[name="sufm_settings[folder_accent]"]:checked' );
			var style = checkedValue( 'sufm_settings[tree_style]' ) || 'windows';
			var accent = checkedValue( 'sufm_settings[folder_accent]' );

			if ( preview ) {
				preview.setAttribute( 'data-style', style );
				preview.setAttribute( 'data-accent', accent );
			}

			markSelected();

			if ( summary ) {
				var styleLabel = labelOf( styleInput ) || strings.styleFallback || style;
				var accentLabel = labelOf( accentInput ) || strings.accentFallback || '';
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

		update();
	} );
}( window, document ) );
