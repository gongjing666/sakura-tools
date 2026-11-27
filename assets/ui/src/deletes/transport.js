/**
 * JSON transport for the delete-queue island.
 *
 * Snapshot reads and row actions both come back in the shape the server
 * snapshot uses, so a response can be merged into state without a second
 * request. Failure copy comes from the payload so the client carries no
 * sentences of its own.
 */

/**
 * Decode one response, turning a transport error into Error(message).
 *
 * @param {Response} response Fetch response.
 * @param {Object}   strings  Payload strings, for the generic failure line.
 * @return {Promise<Object>} Decoded body.
 */
async function decode( response, strings ) {
	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		const message = payload && payload.message ? String( payload.message ) : ( strings.failed || '' );

		throw new Error( message );
	}

	return payload || {};
}

/**
 * Fetch one live snapshot.
 *
 * @param {Object}   rest    Payload rest block.
 * @param {Object}   params  state / page / per_page.
 * @param {Object}   strings Payload strings.
 * @param {AbortSignal} signal Abort signal.
 * @return {Promise<Object>} Snapshot.
 */
export async function fetchSnapshot( rest, params, strings, signal ) {
	const query = new URLSearchParams();

	query.set( 'state', params.state || 'all' );
	query.set( 'page', String( params.page || 1 ) );
	query.set( 'per_page', String( params.per_page || 25 ) );

	const response = await fetch( rest.url + '?' + query.toString(), {
		method: 'GET',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': rest.nonce },
		signal,
	} );

	return decode( response, strings );
}

/**
 * POST one row action.
 *
 * @param {Object}   rest    Payload rest block.
 * @param {string}   action  retry | drop | dispatch.
 * @param {Object}   body    Body.
 * @param {Object}   strings Payload strings.
 * @param {AbortSignal} signal Abort signal.
 * @return {Promise<Object>} Decoded answer.
 */
export async function postAction( rest, action, body, strings, signal ) {
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

	return decode( response, strings );
}

/**
 * Export URL for the selected rows; an empty selection exports everything.
 *
 * @param {string} base Absolute URL from the payload.
 * @param {Array}  ids  Selected row ids.
 * @return {string} URL.
 */
export function exportHref( base, ids ) {
	if ( ! base || ! ids || ! ids.length ) {
		return base;
	}

	return base + '&ids=' + encodeURIComponent( ids.join( ',' ) );
}
