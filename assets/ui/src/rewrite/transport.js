/**
 * The rewrite island's REST call.
 *
 * Endpoint paths are relative to the payload's `rest.url`, which already
 * points at `/storage/rewrite`; the shared transport joins them and attaches
 * the REST nonce. The server owns the scan/run cursor, so every call returns
 * the full public summary and the client never rebuilds progress locally.
 */
export { post } from '../shared/transport.js';
