/**
 * Every REST call the image island makes goes through here.
 */

/**
 * Compare plain JSON option values (strings, numbers, booleans, string arrays).
 *
 * @param {*} a Left.
 * @param {*} b Right.
 * @return {boolean} Equal.
 */
export function same( a, b ) {
	return JSON.stringify( a ?? null ) === JSON.stringify( b ?? null );
}

/**
 * One JSON call; every failure surfaces as one Chinese sentence.
 *
 * @param {Object} rest    {url, nonce} for the current tab.
 * @param {Object} strings UI strings.
 * @param {string} path    Relative path below the tab endpoint.
 * @param {Object} [body]  JSON body; omitted for GET.
 * @return {Promise<Object>} Parsed body.
 */
export async function call( rest, strings, path, body ) {
	let response;

	try {
		response = await fetch( rest.url + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': rest.nonce,
				...( body ? { 'Content-Type': 'application/json' } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
	} catch ( error ) {
		throw new Error( strings.network );
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new Error( ( payload && payload.message ) || strings.failed );
	}

	return payload || {};
}
