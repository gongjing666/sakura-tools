/**
 * The one REST transport every React page uses.
 *
 * Pages receive `rest` in their payload: `{url, nonce, refresh, ...named}`.
 * Nothing here derives an endpoint from another by string surgery — the PHP
 * contract requires each endpoint to be named explicitly.
 */

/**
 * Join a base URL and a relative path without doubling slashes.
 *
 * @param {string} base Base URL.
 * @param {string} path Relative path.
 * @return {string}
 */
export function join( base, path ) {
	if ( ! path ) {
		return base;
	}

	return String( base ).replace( /\/$/, '' ) + '/' + String( path ).replace( /^\//, '' );
}

/**
 * Turn any failure into one operator-readable sentence.
 *
 * @param {Object} strings Payload strings.
 * @param {Object} payload Decoded error body, if any.
 * @param {number} status  HTTP status.
 * @return {string}
 */
export function errorMessage( strings, payload, status ) {
	const fallback = ( strings && strings.failed ) || '请求失败，请稍后重试。';

	if ( payload && payload.message ) {
		return String( payload.message );
	}

	if ( status ) {
		return fallback + '（' + status + '）';
	}

	return fallback;
}

/**
 * One JSON request.
 *
 * @param {Object} rest  Payload rest block.
 * @param {string} path  Path below rest.url, or an absolute URL when it starts with http.
 * @param {Object} opts  Request options.
 * @param {string} opts.method HTTP method.
 * @param {Object} opts.body   JSON body.
 * @param {Object} opts.strings Payload strings.
 * @param {AbortSignal} opts.signal Abort signal.
 * @return {Promise<Object>} Decoded body.
 */
export async function request( rest, path, { method = 'GET', body, strings, signal } = {} ) {
	const url = /^https?:\/\//.test( String( path ) ) ? String( path ) : join( rest.url, path );
	let response;

	try {
		response = await fetch( url, {
			method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': rest.nonce || '',
				...( body ? { 'Content-Type': 'application/json' } : {} ),
			},
			body: body ? JSON.stringify( body ) : undefined,
			signal,
		} );
	} catch ( error ) {
		if ( error && 'AbortError' === error.name ) {
			throw error;
		}

		throw new Error( ( strings && strings.network ) || '网络请求失败，服务器没有响应。' );
	}

	const payload = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new Error( errorMessage( strings, payload, response.status ) );
	}

	return payload || {};
}

/**
 * POST JSON to a named route.
 *
 * @param {Object} rest   Payload rest block.
 * @param {string} path   Relative path.
 * @param {Object} body   Body.
 * @param {Object} strings Payload strings.
 * @param {AbortSignal} signal Abort signal.
 * @return {Promise<Object>}
 */
export function post( rest, path, body, strings, signal ) {
	return request( rest, path, { method: 'POST', body: body || {}, strings, signal } );
}

/**
 * Fetch the whole page payload again (rest.refresh) after a mutation.
 *
 * @param {Object} rest   Payload rest block.
 * @param {Object} strings Payload strings.
 * @return {Promise<Object>} Fresh payload.
 */
export function refresh( rest, strings ) {
	return request( rest, rest.refresh, { method: 'GET', strings } );
}