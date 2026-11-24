/**
 * admin-ajax calls for the sync screen.
 *
 * The island deliberately keeps using the twelve admin-ajax endpoints the
 * classic controller used instead of inventing a REST surface: those handlers
 * already carry the R18 gate, the nonce checks and the queueing rules, and a
 * second code path is a second place for them to drift.
 */
export function ajax( action, data, options ) {
	return window.SakuraAdmin.api.ajax( action, data, options || {} );
}

export function errorText( error, fallback ) {
	if ( error && error.payload && error.payload.data && error.payload.data.message ) {
		return String( error.payload.data.message );
	}

	if ( error && error.payload && error.payload.message ) {
		return String( error.payload.message );
	}

	if ( error && error.message ) {
		return String( error.message );
	}

	return fallback || '操作失败，请稍后重试。';
}

export function toast( message, tone ) {
	window.SakuraAdmin.ui.toast( message, tone || 'ok', 'bad' === tone ? { duration: 6000 } : {} );
}

/** Repaint with a toast after the reload, the way the classic page reported. */
export function reloadWithToast( message, tone ) {
	try {
		window.sessionStorage.setItem( 'sakuraSyncToast', JSON.stringify( { message: message, tone: tone || 'ok' } ) );
	} catch ( error ) {
		// Storage can be unavailable in private mode; the reload still works.
	}

	window.location.reload();
}

export function restoreToast() {
	let raw = null;

	try {
		raw = window.sessionStorage.getItem( 'sakuraSyncToast' );
		window.sessionStorage.removeItem( 'sakuraSyncToast' );
	} catch ( error ) {
		return;
	}

	if ( ! raw ) {
		return;
	}

	try {
		const info = JSON.parse( raw );

		if ( info && info.message ) {
			toast( info.message, info.tone || 'ok' );
		}
	} catch ( error ) {
		// A malformed entry is not worth reporting: it only came from us.
	}
}
