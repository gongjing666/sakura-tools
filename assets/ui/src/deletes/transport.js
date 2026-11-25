/**
 * JSON transport for the delete-queue island.
 *
 * Snapshot reads and row actions both come back in the shape the boot payload
 * uses, so a response can be merged into state without a second request.
 */

async function decode( response ) {
	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		const message = payload && payload.message ? String( payload.message ) : '请求失败，请稍后重试。';

		throw new Error( message );
	}

	return payload || {};
}

export async function fetchSnapshot( rest, params, signal ) {
	const query = new URLSearchParams();

	query.set( 'state', params.state || 'all' );
	query.set( 'page', String( params.page || 1 ) );
	query.set( 'per_page', String( params.per_page || 50 ) );

	const response = await fetch( rest.url + '?' + query.toString(), {
		method: 'GET',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': rest.nonce },
		signal,
	} );

	return decode( response );
}

export async function postAction( rest, action, body, signal ) {
	const response = await fetch( rest.url + '/' + action, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': rest.nonce,
		},
		body: JSON.stringify( body || {} ),
		signal,
	} );

	return decode( response );
}

export function exportHref( base, ids ) {
	if ( ! ids || ! ids.length ) {
		return base;
	}

	return base + '&ids=' + encodeURIComponent( ids.join( ',' ) );
}
