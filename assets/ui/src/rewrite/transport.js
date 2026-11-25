/**
 * admin-ajax transport shared by the rewrite islands.
 *
 * The server owns the scan/run cursor, so every call returns the full public
 * summary; the client never reconstructs progress from local counts.
 */
export function post( config, action, payload, timeout = 60000 ) {
	const admin = window.SakuraAdmin;

	if ( ! admin || ! admin.api || ! admin.api.ajax ) {
		return Promise.reject( new Error( '页面脚本还没有准备好，请刷新重试。' ) );
	}

	return admin.api.ajax(
		action,
		{ nonce: ( config || {} ).nonce || '', ...( payload || {} ) },
		{ timeout }
	);
}

export function toast( message, tone = 'info' ) {
	const admin = window.SakuraAdmin;

	if ( ! message || ! admin || ! admin.ui || ! admin.ui.toast ) {
		return;
	}

	admin.ui.toast( message, tone, { duration: 'bad' === tone ? 6000 : 4200 } );
}

export function confirm( message, options ) {
	const admin = window.SakuraAdmin;

	if ( admin && admin.ui && admin.ui.confirm ) {
		return admin.ui.confirm( message, options );
	}

	return Promise.resolve( window.confirm( `${ options.title || '' }\n\n${ message || '' }` ) );
}

export function errorText( error, fallback ) {
	if ( error && error.payload ) {
		if ( error.payload.data && error.payload.data.message ) {
			return String( error.payload.data.message );
		}

		if ( error.payload.message ) {
			return String( error.payload.message );
		}
	}

	if ( error && error.message ) {
		return String( error.message );
	}

	return fallback || '';
}
