/**
 * Delete queue screen: selection bookkeeping, state filter and the destructive
 * confirmation before dropping rows.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin ) {
		return;
	}

	var config  = admin.page && admin.page.data ? admin.page.data : {};
	var strings = config.strings || {};

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var cursor = 0;

		return String( template === undefined || template === null ? '' : template ).replace( /%(\d+\$)?[sd]/g, function ( token, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ cursor++ ];
			return value === undefined || value === null ? token : String( value );
		} );
	}

	function selection( form ) {
		var toggles = admin.qsa( '[data-sakura-role="select-all"], [data-sakura-role="select-all-mobile"]', form );
		var note    = admin.qs( '#sufm-delete-selected', form );

		function boxes() {
			return admin.qsa( 'input[name="ids[]"]', form );
		}

		function sync() {
			var list    = boxes();
			var checked = list.filter( function ( box ) {
				return box.checked;
			} );

			toggles.forEach( function ( toggle ) {
				toggle.checked = list.length > 0 && checked.length === list.length;
				toggle.indeterminate = checked.length > 0 && checked.length < list.length;
			} );
			if ( note ) {
				note.hidden = 0 === checked.length;
				note.textContent = checked.length ? format( strings.selected || '%d', checked.length ) : '';
			}

			return checked;
		}

		toggles.forEach( function ( toggle ) {
			admin.on( toggle, 'change', function () {
				boxes().forEach( function ( box ) {
					box.checked = toggle.checked;
				} );
				sync();
			} );
		} );

		admin.delegate( form, 'change', 'input[name="ids[]"]', function () {
			sync();
		} );

		sync();

		return { sync: sync };
	}

	function initFilter( root ) {
		var form = admin.qs( '[data-sakura-queue-filter]', root );
		if ( ! form ) {
			return;
		}
		var select = admin.qs( '#sufm-delete-state', form );
		if ( ! select ) {
			return;
		}
		admin.on( select, 'change', function () {
			if ( form.requestSubmit ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		} );
	}

	function initQueue( root ) {
		var form = admin.qs( '[data-sakura-queue-form]', root );
		if ( ! form ) {
			return;
		}

		var state = selection( form );

		admin.on( form, 'submit', function ( event ) {
			var submitter = event.submitter || null;
			var task      = submitter ? String( submitter.value || '' ) : '';
			var checked   = state.sync();

			if ( 'export' === task ) {
				return;
			}

			if ( ! checked.length ) {
				event.preventDefault();
				admin.ui.toast( strings.noneSelected || '', 'warn' );
				return;
			}

			if ( submitter && 'drop' === submitter.getAttribute( 'data-sakura-confirm-task' ) ) {
				event.preventDefault();
				admin.ui.confirm(
					strings.dropDesc || '',
					{
						title: strings.dropTitle || '',
						confirmText: strings.dropConfirm || '',
						danger: true
					}
				).then( function ( confirmed ) {
					if ( confirmed ) {
						form.submit();
					}
				} );
			}
		} );
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root ) {
			return;
		}
		initFilter( root );
		initQueue( root );
	} );
}( window, document ) );
