/**
 * JSON transport for the file-space list islands.
 *
 * The PHP REST controllers already own all business semantics; this module
 * only adds the nonce, query string and the legacy error-envelope unwrapping.
 */

function messageOf( payload ) {
	if ( payload && payload.data && payload.data.message ) {
		return String( payload.data.message );
	}

	if ( payload && payload.message ) {
		return String( payload.message );
	}

	return '';
}

async function decode( response ) {
	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new Error( messageOf( payload ) || '请求失败，请稍后重试。' );
	}

	return payload || {};
}

export function apiUrl( config, path, params ) {
	const query = new URLSearchParams();

	Object.entries( params || {} ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			query.set( key, String( value ) );
		}
	} );

	return config.restUrl + path + ( query.toString() ? '?' + query.toString() : '' );
}

export async function apiGet( config, path, params, signal ) {
	const response = await fetch( apiUrl( config, path, params ), {
		method: 'GET',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': config.nonce },
		signal,
	} );

	return decode( response );
}

export async function apiRequest( config, path, method, body, signal ) {
	const response = await fetch( apiUrl( config, path ), {
		method: method || 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify( body || {} ),
		signal,
	} );

	return decode( response );
}

export function apiPost( config, path, body, signal ) {
	return apiRequest( config, path, 'POST', body, signal );
}

export function errorText( error, fallback ) {
	if ( error && error.message ) {
		return String( error.message );
	}

	return fallback || '';
}

export function fill( template, values ) {
	return String( template || '' ).replace( /\{(\w+)\}/g, ( match, key ) =>
		Object.prototype.hasOwnProperty.call( values || {}, key ) ? String( values[ key ] ) : match
	);
}

export function toast( message, tone ) {
	const admin = window.SakuraAdmin;

	if ( message && admin && admin.ui && admin.ui.toast ) {
		admin.ui.toast( message, tone || 'info' );
	}
}

export function confirmAction( message, options ) {
	const admin = window.SakuraAdmin;

	if ( admin && admin.ui && admin.ui.confirm ) {
		return admin.ui.confirm( message, options || {} );
	}

	return Promise.resolve( window.confirm( [ ( options || {} ).title, message ].filter( Boolean ).join( '\n\n' ) ) );
}

export function fileSize( value ) {
	const size = Number( value ) || 0;
	const admin = window.SakuraAdmin;

	if ( admin && admin.format && admin.format.fileSize ) {
		return admin.format.fileSize( size );
	}

	return size + ' B';
}

export function displayName( row ) {
	return row.display_name || row.name || row.login || ( row.id ? '#' + row.id : '' );
}
