/**
 * JSON transport for the storage island.
 *
 * The REST routes answer with the same shapes the boot payload uses, so a
 * response can be merged straight into state. Errors arrive as
 * { code, message } and are thrown as Error(message) — the card shows the
 * sentence, the code is only interesting to a developer.
 */

/**
 * POST one route.
 *
 * @param {Object} rest    { url, nonce } from the payload.
 * @param {Object} strings Payload strings, for the generic failure line.
 * @param {string} path    Route path, e.g. '/cards/connection'.
 * @param {Object} body    JSON body.
 * @return {Promise<Object>} Decoded response.
 */
export async function call( rest, strings, path, body ) {
	const response = await fetch( rest.url.replace( /\/storage$/, '' ) + '/storage' + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': rest.nonce,
		},
		body: JSON.stringify( body || {} ),
	} );

	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		const message = payload && payload.message ? payload.message : strings.failed;

		throw new Error( message || '请求失败，请重试。' );
	}

	return payload || {};
}

/**
 * Shallow value comparison, good enough for drafts of scalars.
 *
 * @param {Object} a Left side.
 * @param {Object} b Right side.
 * @return {boolean} True when the two carry the same values.
 */
export function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}
