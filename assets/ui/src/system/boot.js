/**
 * Bootstrap, REST access and the canvas variables for the system island.
 *
 * The server prints the first payload inline (see includes/views/system-settings.php),
 * so the island renders with the real values on its first tick and the network
 * is only used for saving. That keeps the page useful even when the REST route
 * is briefly unavailable.
 */

export const DEFAULTS = {
	page_width: 1480,
	border_width: 1,
	radius: 18,
	shadow: 'default',
};

/**
 * Read the inline payload printed by the view.
 *
 * @return {object|null} Payload, or null when the page was rendered without one.
 */
export function readBootstrap() {
	const node = document.getElementById( 'sakura-system-bootstrap' );

	if ( ! node ) {
		return null;
	}

	try {
		return JSON.parse( node.textContent || '{}' );
	} catch ( error ) {
		// A broken payload must be loud in the console but must not take the
		// rest of the admin down: the shell is still server-rendered.
		console.error( 'sakura-tools: the system payload could not be parsed', error );

		return null;
	}
}

/**
 * Flatten the layout fields into the shape the REST route and the canvas take.
 *
 * @param {Array<object>} fields Field descriptors.
 * @return {object} Draft values keyed by field key.
 */
export function draftFromFields( fields ) {
	const draft = {};

	fields.forEach( ( field ) => {
		draft[ field.key ] = String( field.value );
	} );

	return draft;
}

/**
 * Normalise the layout half of a REST response.
 *
 * @param {object} layout Server values.
 * @return {object} Draft values.
 */
export function normalizeLayout( layout ) {
	const gaps = layout && layout.gaps ? layout.gaps : {};
	const appearance = layout && layout.appearance ? layout.appearance : {};

	return {
		gapX: String( gaps.gapX ?? 16 ),
		gapY: String( gaps.gapY ?? 18 ),
		page_width: String( appearance.page_width ?? DEFAULTS.page_width ),
		border_width: String( appearance.border_width ?? DEFAULTS.border_width ),
		radius: String( appearance.radius ?? DEFAULTS.radius ),
		shadow: String( appearance.shadow ?? DEFAULTS.shadow ),
	};
}

/**
 * Write the canvas variables the same way Appearance::inline_style() does:
 * a value equal to the stylesheet default is removed, so the tokens stay in
 * charge instead of being pinned to a redundant inline declaration.
 *
 * @param {object} state Layout values.
 */
export function applyCanvas( state ) {
	const root = document.querySelector( '.sakura-admin' );

	if ( ! root ) {
		return;
	}

	const set = ( name, value ) => {
		if ( null === value || '' === value ) {
			root.style.removeProperty( name );
		} else {
			root.style.setProperty( name, value );
		}
	};

	set( '--g-grid-gap-x', `${ state.gapX }px` );
	set( '--g-grid-gap-y', `${ state.gapY }px` );
	set( '--g-page-max', String( state.page_width ) === String( DEFAULTS.page_width ) ? null : `${ state.page_width }px` );
	set( '--g-card-border-width', String( state.border_width ) === String( DEFAULTS.border_width ) ? null : `${ state.border_width }px` );
	set( '--g-radius-card', String( state.radius ) === String( DEFAULTS.radius ) ? null : `${ state.radius }px` );

	const shadow = {
		none: 'none',
		soft: 'var(--g-shadow-soft)',
		strong: 'var(--g-shadow-pop)',
	}[ state.shadow ];

	set( '--g-shadow-card', shadow || null );
}

/**
 * Save the layout through the REST route.
 *
 * @param {object} rest    { url, nonce } from the payload.
 * @param {object} draft   Draft values.
 * @return {Promise<object>} The parsed response.
 */
export async function saveLayout( rest, draft ) {
	let response;

	try {
		response = await fetch( rest.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': rest.nonce,
				Accept: 'application/json',
			},
			body: JSON.stringify( {
				gapX: Number( draft.gapX ),
				gapY: Number( draft.gapY ),
				page_width: Number( draft.page_width ),
				border_width: Number( draft.border_width ),
				radius: Number( draft.radius ),
				shadow: String( draft.shadow ),
			} ),
		} );
	} catch ( error ) {
		// fetch only rejects on a transport failure; its message ("Failed to fetch")
		// is developer text, so say what actually happened in the operator's language.
		throw new Error( '网络请求失败，服务器没有响应。' );
	}

	const payload = await response.json().catch( () => null );

	if ( ! response.ok || ! payload ) {
		const message = payload && payload.message ? String( payload.message ) : '';

		throw new Error( message || `服务器返回 ${ response.status }，请稍后重试。` );
	}

	return payload;
}
