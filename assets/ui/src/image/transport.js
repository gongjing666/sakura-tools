/**
 * Every REST call the image island makes goes through here.
 */

/**
 * Canonical form of a JSON option value.
 *
 * Object keys and array items are sorted, so a value that differs only by the
 * order of its members is the same value: toggling a checkbox off and on again
 * must not leave the card dirty (the array really is a set here).
 *
 * @param {*} value Value.
 * @return {string} Comparison key.
 */
function canonical( value ) {
	if ( Array.isArray( value ) ) {
		return '[' + value.map( canonical ).sort().join( ',' ) + ']';
	}

	if ( value && 'object' === typeof value ) {
		return (
			'{' +
			Object.keys( value )
				.sort()
				.map( ( key ) => JSON.stringify( key ) + ':' + canonical( value[ key ] ) )
				.join( ',' ) +
			'}'
		);
	}

	return JSON.stringify( value ?? null );
}

/**
 * Compare plain JSON option values (strings, numbers, booleans, arrays).
 *
 * @param {*} a Left.
 * @param {*} b Right.
 * @return {boolean} Equal.
 */
export function same( a, b ) {
	return canonical( a ) === canonical( b );
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
