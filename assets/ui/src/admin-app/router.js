/**
 * Client-side navigation over the manifest allowlist.
 *
 * Everything else is left to the browser: a link outside the allowlist, a
 * modifier click, a download or `data-sakura-no-spa` is a normal navigation.
 * The first page is the server's, so this module only seeds its history entry
 * and then swaps the content root for later pages.
 *
 * There is deliberately **no payload cache**: a save goes through REST and
 * never reloads the document, so a cached payload would put the pre-save values
 * back on screen the next time the page is opened. Five routes against one
 * local REST call are cheap; a stale form is not.
 */

import { fetchPage } from './transport.js';

/**
 * How long a page fetch may take before the shell gives up and reloads.
 */
const REQUEST_TIMEOUT = 15000;

/**
 * Start listening for allowlisted navigation.
 *
 * @param {Object} options Router options.
 * @return {void}
 */
export function createRouter( options ) {
	const { manifest, initialPage, onStart, onPage, onEnd, onFail } = options;
	const routes = manifest.routes || {};

	let current = initialPage;
	let controller = null;
	let seq = 0;

	const known = ( page ) => Object.prototype.hasOwnProperty.call( routes, page );

	/*
	 * Merge instead of replacing: this entry may already carry state written by
	 * another admin script, and dropping it would break that script's back
	 * button.
	 */
	window.history.replaceState(
		{ ...( window.history.state || {} ), sakuraPage: initialPage, y: window.scrollY },
		'',
		window.location.href
	);

	/**
	 * Resolve a click target to an allowlisted route.
	 *
	 * @param {Element} target Clicked node.
	 * @return {{page: string, url: string}|null} Route, or null to let the browser act.
	 */
	function routeOf( target ) {
		if ( ! target || ! target.closest ) {
			return null;
		}

		const link = target.closest( '[data-sakura-route]' );

		if ( ! link ) {
			return null;
		}

		const page = link.getAttribute( 'data-sakura-route' ) || '';

		if ( ! known( page ) ) {
			return null;
		}

		if ( link.hasAttribute( 'download' ) || link.getAttribute( 'target' ) || link.closest( '[data-sakura-no-spa]' ) ) {
			return null;
		}

		return { page, url: link.href };
	}

	/**
	 * Open one page.
	 *
	 * @param {string} page          Page id.
	 * @param {Object} opts          Options.
	 * @param {string} opts.url      URL to push (defaults to the manifest's).
	 * @param {boolean} opts.push    Whether to add a history entry.
	 * @param {number} opts.y        Scroll position to restore after the swap.
	 * @return {Promise<void>}
	 */
	async function open( page, { url = '', push = true, y = 0 } = {} ) {
		if ( ! known( page ) ) {
			return;
		}

		if ( page === current ) {
			// Same route: no fetch, no history entry. Back and forward carry the
			// position they were left at; a fresh click starts at the top.
			window.scrollTo( 0, y );
			return;
		}

		/*
		 * Every navigation supersedes whatever was in flight, cache or not: a
		 * slower reply to an abandoned click must never win. Bumping the
		 * generation here (and not only on a miss) is what closes that race.
		 */
		if ( controller ) {
			controller.abort();
		}

		const local = new AbortController();
		const mine = ++seq;
		let timedOut = false;

		controller = local;

		/*
		 * The entry we are leaving keeps the position we left it at, so stepping
		 * back returns to the same spot instead of the top of the page.
		 */
		if ( push ) {
			window.history.replaceState(
				{ ...( window.history.state || {} ), sakuraPage: current, y: window.scrollY },
				'',
				window.location.href
			);
		}

		const timer = window.setTimeout( () => {
			timedOut = true;
			local.abort();
		}, REQUEST_TIMEOUT );

		let payload;

		onStart( page );

		try {
			payload = await fetchPage( manifest, page, local.signal );
		} catch ( error ) {
			window.clearTimeout( timer );

			// An abort that is not ours: a newer navigation took over.
			if ( error && 'AbortError' === error.name && ! timedOut ) {
				return;
			}

			onEnd();
			// Anything unexpected (stale nonce, plugin updated mid-session,
			// permission changed, a request that never answered) falls back to a
			// real navigation: the server always knows the way, and a full load
			// rebuilds every nonce.
			onFail( url || ( routes[ page ] && routes[ page ].url ) || window.location.href );

			return;
		}

		window.clearTimeout( timer );

		if ( mine !== seq ) {
			return;
		}

		current = page;

		if ( push ) {
			window.history.pushState( { sakuraPage: page, y: 0 }, '', url || routes[ page ].url );
		}

		onPage( page, payload );

		window.requestAnimationFrame( () => window.scrollTo( 0, y ) );
		onEnd();
	}

	document.addEventListener( 'click', ( event ) => {
		if (
			event.defaultPrevented ||
			0 !== event.button ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		const hit = routeOf( event.target );

		if ( ! hit ) {
			return;
		}

		event.preventDefault();
		open( hit.page, { url: hit.url, push: true, y: 0 } );
	} );

	window.addEventListener( 'popstate', ( event ) => {
		const state = event.state;

		if ( state && state.sakuraPage && known( state.sakuraPage ) ) {
			open( state.sakuraPage, { push: false, y: state.y || 0 } );
			return;
		}

		// An entry this router did not write (a deep link opened in another tab,
		// an older history): the document has to be rebuilt from scratch.
		window.location.reload();
	} );
}
