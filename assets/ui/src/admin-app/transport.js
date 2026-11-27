/**
 * Fetch a page payload for the persistent shell.
 *
 * The route is `GET /wp-json/sakura-tools/v1/pages/{page}` and answers with the
 * same array the page's own first paint carries, so a navigation and a reload
 * cannot disagree about a value.
 */

/**
 * Read one page.
 *
 * @param {Object} manifest Boot manifest: endpoint + nonce.
 * @param {string} page     Page id.
 * @param {AbortSignal} signal Abort signal for a superseded navigation.
 * @return {Promise<Object>} Payload.
 */

/**
 * Append one page id to the REST endpoint.
 *
 * `rest_url()` prints a path on pretty permalinks and `?rest_route=%2F…` on the
 * plain ones, so the id has to go into whichever part carries the route;
 * appending to the string works on the first and only looks like it works on
 * the second.
 *
 * @param {string} endpoint manifest.endpoint.
 * @param {string} page     Page id.
 * @return {string}
 */
function pageUrl( endpoint, page ) {
	const url = new URL( String( endpoint || '' ), window.location.href );
	const id = encodeURIComponent( page );

	if ( url.searchParams.has( 'rest_route' ) ) {
		const route = url.searchParams.get( 'rest_route' ) || '';

		url.searchParams.set( 'rest_route', route.replace( /\/$/, '' ) + '/' + id );

		return url.toString();
	}

	url.pathname = url.pathname.replace( /\/$/, '' ) + '/' + id;

	return url.toString();
}
export async function fetchPage( manifest, page, signal ) {
	const url = pageUrl( manifest.endpoint, page );

	let response;

	try {
		response = await fetch( url, {
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': manifest.nonce,
			},
			signal,
		} );
	} catch ( error ) {
		if ( error && 'AbortError' === error.name ) {
			throw error;
		}

		throw new Error( '网络请求失败，服务器没有响应。' );
	}

	const payload = await response.json().catch( () => null );

	if ( ! response.ok || ! payload || ! payload.head ) {
		const message = payload && payload.message ? String( payload.message ) : '';

		throw new Error( message || '服务器返回 ' + response.status + '，请稍后重试。' );
	}

	return payload;
}
