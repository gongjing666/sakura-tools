/**
 * POST one REST route under /storage/sync/.
 *
 * The handlers answer with the same shapes the admin-ajax endpoints used, so a
 * response is merged straight into state; failures carry { message } and are
 * thrown as Error(message), exactly like errorText() expects.
 *
 * @param {Object} config Payload config (restUrl + restNonce).
 * @param {string} path   Route path under /storage/sync/, e.g. 'scan/step'.
 * @param {Object} body   JSON body.
 * @return {Promise<Object>} Decoded response.
 */
export function post( config, path, body ) {
	const base = String( config.restUrl || '' ).replace( /\/$/, '' );

	return fetch( base + '/storage/sync/' + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.restNonce || '',
		},
		body: JSON.stringify( body || {} ),
	} ).then( ( response ) =>
		response.text().then( ( raw ) => {
			let data = null;

			try {
				data = raw ? JSON.parse( raw ) : null;
			} catch ( error ) {
				data = null;
			}

			if ( ! response.ok ) {
				throw new Error( errorText( { payload: data }, config.strings && config.strings.unknownError ) );
			}

			return data || {};
		} )
	);
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
