/**
 * The cost island's REST call.
 *
 * The endpoint is `POST {rest.url}/estimate`; the shared transport joins the
 * path and attaches the REST nonce, so this module never builds a URL.
 */
import { post } from '../shared/transport.js';

/**
 * Recalculate the estimate for the current form values.
 *
 * @param {Object} rest    Payload rest block.
 * @param {Object} values  Demand values.
 * @param {Object} strings Payload strings.
 * @return {Promise<Object>} Server-normalised estimate.
 */
export function estimate( rest, values, strings ) {
	return post( rest, 'estimate', values || {}, strings );
}
