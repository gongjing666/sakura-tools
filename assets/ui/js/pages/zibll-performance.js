/**
 * Zibll integration page: license cache controller.
 *
 * The save button runs the check server-side; this only re-runs it on demand
 * and reports the outcome next to the button.
 *
 * @package Sakura_Image_Optimizer
 */
( function () {
	'use strict';

	var admin = window.SakuraAdmin;
	if ( ! admin || ! admin.ready ) {
		return;
	}

	admin.ready( function () {
		var root = admin.root();
		if ( ! root || 'zibll-performance' !== root.getAttribute( 'data-sakura-page' ) ) {
			return;
		}

		var config = admin.page && admin.page.data ? admin.page.data : {};
		var strings = config.strings || {};
		var run = admin.qs( '#zibll-warm', root );
		var status = admin.qs( '#zibll-warm-status', root );

		if ( ! run || ! config.action || ! config.nonce ) {
			return;
		}

		var busy = false;

		function setStatus( text, tone ) {
			if ( ! status ) {
				return;
			}

			status.textContent = text || '';
			status.classList.remove( 'is-ok', 'is-bad' );

			if ( tone ) {
				status.classList.add( 'is-' + tone );
			}
		}

		run.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}

			busy = true;
			admin.ui.busy( run, true );
			setStatus( strings.checking || '正在验证…' );

			admin.api.ajax( config.action, { nonce: config.nonce }, { timeout: 60000 } )
				.then( function ( data ) {
					var message = ( data && data.message ) || strings.checked || '验证通过，缓存已刷新。';
					setStatus( message, 'ok' );
					admin.ui.toast( message, 'ok' );
				} )
				.catch( function ( error ) {
					var message = strings.failed || '验证没有通过，缓存已清除。';

					if ( error && error.payload && error.payload.data && error.payload.data.message ) {
						message = error.payload.data.message;
					}

					setStatus( message, 'bad' );
					admin.ui.toast( message, 'bad', { duration: 6000 } );
				} )
				.then( function () {
					busy = false;
					admin.ui.busy( run, false );
				} );
		} );
	} );
}() );
