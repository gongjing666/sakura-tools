/**
 * Sakura Admin UI: toasts, native-dialog confirmations, busy states, copy and
 * responsive table labels. Pages call these instead of window.alert/confirm.
 *
 * @package Sakura_Image_Optimizer
 */
( function ( window, document ) {
	'use strict';

	var admin = window.SakuraAdmin;

	function host() {
		return admin.root() || document.body;
	}

	function toast( message, tone, options ) {
		var settings = options || {};
		var stack = admin.qs( '.toast-stack', host() );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'toast-stack';
			stack.setAttribute( 'role', 'status' );
			stack.setAttribute( 'aria-live', 'polite' );
			host().appendChild( stack );
		}

		var item = document.createElement( 'div' );
		item.className = 'toast toast--' + ( tone || 'info' );
		item.setAttribute( 'role', 'status' );

		var text = document.createElement( 'span' );
		text.className = 'toast__text';
		text.textContent = String( message || '' );
		item.appendChild( text );

		if ( settings.dismissible !== false ) {
			var close = document.createElement( 'button' );
			close.type = 'button';
			close.className = 'toast__close';
			close.setAttribute( 'aria-label', '关闭' );
			close.textContent = '×';
			close.addEventListener( 'click', function () {
				remove();
			} );
			item.appendChild( close );
		}

		stack.appendChild( item );

		var timer = 0;
		function remove() {
			window.clearTimeout( timer );
			if ( ! item.parentNode ) {
				return;
			}
			item.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				if ( item.parentNode ) {
					item.parentNode.removeChild( item );
				}
			}, 180 );
		}

		if ( settings.duration !== 0 && settings.duration !== false ) {
			timer = window.setTimeout( remove, Number( settings.duration ) || 4200 );
		}

		return { close: remove };
	}

	/**
	 * Promise-based confirmation using a native <dialog>. Falls back to
	 * window.confirm only when the dialog element is unavailable.
	 */
	function confirm( message, options ) {
		var settings = options || {};
		if ( typeof document.createElement( 'dialog' ).showModal !== 'function' ) {
			return Promise.resolve( window.confirm( message ) );
		}

		return new Promise( function ( resolve ) {
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'sakura-dialog';
			if ( settings.tone ) {
				dialog.classList.add( 'sakura-dialog--' + settings.tone );
			}

			var form = document.createElement( 'form' );
			form.method = 'dialog';
			form.className = 'sakura-dialog__form';

			var body = document.createElement( 'div' );
			body.className = 'dialog__body';

			var title = document.createElement( 'h2' );
			title.className = 'dialog__title';
			title.textContent = settings.title || '请确认';
			body.appendChild( title );

			var text = document.createElement( 'p' );
			text.className = 'dialog__message';
			text.textContent = String( message || '' );
			body.appendChild( text );
			form.appendChild( body );

			var actions = document.createElement( 'div' );
			actions.className = 'dialog__actions';

			var cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'btn btn--ghost';
			cancel.textContent = settings.cancelText || '取消';
			cancel.addEventListener( 'click', function () {
				dialog.close( 'cancel' );
			} );

			var accept = document.createElement( 'button' );
			accept.type = 'button';
			accept.className = settings.danger ? 'btn btn--danger' : 'btn btn--primary';
			accept.textContent = settings.confirmText || '确认';
			accept.addEventListener( 'click', function () {
				dialog.close( 'confirm' );
			} );

			actions.appendChild( cancel );
			actions.appendChild( accept );
			form.appendChild( actions );
			dialog.appendChild( form );
			host().appendChild( dialog );

			dialog.addEventListener( 'close', function () {
				var confirmed = dialog.returnValue === 'confirm';
				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}
				resolve( confirmed );
			}, { once: true } );

			dialog.showModal();
			accept.focus();
		} );
	}

	function busy( element, state ) {
		if ( ! element ) {
			return function () {};
		}
		var active = state !== false;
		element.classList.toggle( 'is-busy', active );
		element.setAttribute( 'aria-busy', active ? 'true' : 'false' );
		admin.qsa( 'button, input[type="submit"]', element ).forEach( function ( control ) {
			if ( active ) {
				if ( ! control.hasAttribute( 'data-sakura-was-disabled' ) ) {
					control.setAttribute( 'data-sakura-was-disabled', control.disabled ? '1' : '0' );
				}
				control.disabled = true;
				return;
			}
			if ( control.getAttribute( 'data-sakura-was-disabled' ) === '0' ) {
				control.disabled = false;
			}
			control.removeAttribute( 'data-sakura-was-disabled' );
		} );
		return function () {
			busy( element, false );
		};
	}

	function copy( text ) {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( String( text ) );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = String( text );
			area.setAttribute( 'readonly', 'readonly' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( '复制失败' ) );
				}
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	/**
	 * Mirrors table headers onto cells so .table--responsive can stack rows
	 * below 782px without losing the column name.
	 */
	function labelTables( context ) {
		admin.qsa( '.table--responsive', context || document ).forEach( function ( table ) {
			var headers = admin.qsa( 'thead th', table ).map( function ( cell ) {
				return cell.textContent.trim();
			} );
			if ( ! headers.length ) {
				return;
			}
			admin.qsa( 'tbody tr', table ).forEach( function ( row ) {
				admin.qsa( 'td', row ).forEach( function ( cell, index ) {
					if ( headers[ index ] && ! cell.hasAttribute( 'data-label' ) ) {
						cell.setAttribute( 'data-label', headers[ index ] );
					}
				} );
			} );
		} );
	}

	admin.ui = {
		toast: toast,
		confirm: confirm,
		busy: busy,
		copy: copy,
		labelTables: labelTables
	};
}( window, document ) );
