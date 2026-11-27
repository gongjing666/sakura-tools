/**
 * The sync centre's REST helpers.
 *
 * Every request goes through the shared transport: `rest` (base URL, nonce and
 * refresh from the payload) names the endpoints, and a successful mutation is
 * followed by a page refresh instead of a document reload.
 */
import { post as postJson, refresh as refreshPayload } from '../shared/transport.js';

/**
 * POST one route below the sync controller's base URL.
 *
 * @param {Object}  rest    Payload rest block.
 * @param {string}  path    Route below /storage/sync/, e.g. 'scan/step'.
 * @param {Object}  body    JSON body.
 * @param {Object}  strings Payload strings.
 * @return {Promise<Object>} Decoded response.
 */
export function post( rest, path, body, strings ) {
	return postJson( rest, path, body || {}, strings );
}

/**
 * Fetch the whole page payload again (GET rest.refresh).
 *
 * @param {Object} rest    Payload rest block.
 * @param {Object} strings Payload strings.
 * @return {Promise<Object>} Fresh payload.
 */
export function refresh( rest, strings ) {
	return refreshPayload( rest, strings );
}

/**
 * One operator-readable sentence for a failed request.
 *
 * The shared transport already throws Error(message); the fallback only covers
 * a failure that arrives without one.
 *
 * @param {Error}  error    Failure.
 * @param {string} fallback Copy from strings.
 * @return {string}
 */
export function errorText( error, fallback ) {
	if ( error && error.message ) {
		return String( error.message );
	}

	return fallback || '操作失败，请稍后重试。';
}

/**
 * Report one result with the shared admin toast.
 *
 * @param {string} message Copy from strings or from the response.
 * @param {string} tone    ok | warn | bad | info.
 * @return {void}
 */
export function toast( message, tone ) {
	window.SakuraAdmin.ui.toast( message, tone || 'ok', 'bad' === tone ? { duration: 6000 } : {} );
}
