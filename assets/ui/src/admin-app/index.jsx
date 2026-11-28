/**
 * Entry point for the experimental persistent admin shell.
 *
 * The page arrives as server HTML: top bar, tab strip and content frame are
 * printed by PHP. This bundle hydrates the two chrome nodes in place and takes
 * over the content root, so opening another module or tab swaps the body
 * without a document navigation.
 *
 * The manifest decides whether the shell boots. Without it (an older cached
 * view, a page outside the allowlist) the page keeps the server frame and the
 * island still renders its content.
 */
import { createRoot } from 'react-dom/client';

import { PageContent } from './app.jsx';
import ErrorBoundary from '../shared/error-boundary.jsx';
import { createRouter } from './router.js';
import { createShell } from './shell.jsx';

/**
 * Read the inline payload printed by the view.
 *
 * @return {Object|null} Payload, or null when the page was rendered without one.
 */
function readBootstrap() {
	const nodes = Array.from( document.querySelectorAll( 'script[type="application/json"]' ) );

	/*
	 * The id differs per screen (sakura-system-bootstrap, sakura-storage-bootstrap,
	 * sakura-sync-bootstrap…), so the shape identifies it: only the screen's
	 * bootstrap carries both a page head and a page description.
	 */
	for ( const node of nodes ) {
		let data = null;

		try {
			data = JSON.parse( node.textContent || '{}' );
		} catch ( error ) {
			// A broken payload must be loud in the console but must not take the
			// rest of the admin down: the shell is still server-rendered.
			window.console.error( 'sakura-tools: a bootstrap payload could not be parsed', error );

			continue;
		}

		if ( data && data.page && data.head ) {
			return data;
		}
	}

	return null;
}

/**
 * Turn the boot marker into a readable failure note.
 *
 * @param {Element} mount Content root.
 * @return {void}
 */
function showFailure( mount ) {
	const chip = mount.querySelector( '.sakura-boot' );
	const line = mount.querySelector( '.sakura-boot__text' );

	// The marker fades in late on purpose; a broken payload has to be
	// readable right away.
	if ( chip ) {
		chip.style.opacity = '1';
		chip.style.animation = 'none';
		chip.classList.add( 'is-failed' );
		chip.setAttribute( 'role', 'alert' );
	}

	if ( line ) {
		line.textContent = '页面数据加载失败，请刷新重试。';
	}
}

const adminEl = document.querySelector( '.sakura-admin' );
const mainEl = document.querySelector( '.sakura-admin > .sakura-admin__main' );
const boot = readBootstrap();

/**
 * Read the shell manifest printed by Page::render().
 *
 * @return {Object|null} Manifest or null.
 */
function readManifest() {
	const node = document.getElementById( 'sakura-app-manifest' );

	if ( ! node ) {
		return null;
	}

	try {
		return JSON.parse( node.textContent || '{}' );
	} catch ( error ) {
		window.console.error( 'sakura-tools: the shell manifest could not be parsed', error );

		return null;
	}
}

const app = readManifest();

if ( ! mainEl ) {
	// No content root on this render: leave the server frame alone.
} else if ( window.__sakuraShellBooted ) {
	// A second copy of this bundle (a hot reload, or both entries loaded by
	// mistake) asked for the page again; the first copy owns the roots and the
	// document-level listeners.
} else if ( ! app || ! app.enabled ) {
	window.__sakuraShellBooted = true;

	/*
	 * No manifest. Either the option is off (the page would have loaded
	 * system.js) or the HTML is older than the bundle; in both cases behave
	 * exactly like the system island so the page is never left on a skeleton.
	 */
	if ( boot ) {
		// Draw the screen without the shell. The page id is already on the root
		// (Page::render prints it), so the app is picked without the manifest.
		const page = adminEl ? adminEl.getAttribute( 'data-sakura-page' ) || '' : '';

		createRoot( mainEl ).render( <ErrorBoundary resetKey={ page }><PageContent page={ page } boot={ boot } /></ErrorBoundary> );
	} else {
		showFailure( mainEl );
	}
} else if ( ! boot ) {
	window.__sakuraShellBooted = true;

	/*
	 * The manifest is on but the payload is missing (a broken cache, a proxy
	 * that ate the island). Boot the shell would read boot.chrome on null and
	 * leave the skeleton spinning forever, so fail loudly instead.
	 */
	showFailure( mainEl );
} else {
	window.__sakuraShellBooted = true;

	const shell = createShell( boot.chrome );
	const content = createRoot( mainEl );

	/**
	 * Show one page.
	 *
	 * @param {string} page    Page id.
	 * @param {Object} payload Its bootstrap payload.
	 * @param {Object} opts    Options.
	 * @param {boolean} opts.focus  Whether to move focus to the content root.
	 * @param {boolean} opts.chrome Whether to hand the shell the payload's chrome.
	 * @return {void}
	 */
	const draw = ( page, payload, { focus = true, chrome = true } = {} ) => {
		adminEl.setAttribute( 'data-sakura-page', page );

		const title = payload && payload.head && payload.head.title ? String( payload.head.title ) : '';

		if ( '' !== title ) {
			document.title = title + ' ‹ ' + app.siteName + ' — WordPress';
		}

		// Keyed by page: a different page is a different app state, and the two
		// apps keep their draft values in useState.
		content.render( <ErrorBoundary resetKey={ page }><PageContent key={ page } page={ page } boot={ payload } /></ErrorBoundary> );

		/*
		 * Never on the first paint. hydrateRoot() is asynchronous: re-rendering a
		 * root before it has hydrated makes React drop the server tree and client
		 * render the whole root ("This root received an early update…", error
		 * #424) — exactly the repaint this shell exists to avoid. The server
		 * already printed this chrome, so the first draw leaves it alone.
		 */
		if ( chrome && payload.chrome ) {
			shell.update( payload.chrome );
		}

		mainEl.removeAttribute( 'aria-busy' );

		if ( focus ) {
			// A navigation replaces everything the reader was looking at; moving
			// focus to the content root is what makes Tab restart here.
			mainEl.setAttribute( 'tabindex', '-1' );
			mainEl.focus( { preventScroll: true } );
		}
	};

	createRouter( {
		manifest: app,
		initialPage: app.page,
		onStart: () => mainEl.setAttribute( 'aria-busy', 'true' ),
		onPage: draw,
		onEnd: () => mainEl.removeAttribute( 'aria-busy' ),
		onFail: ( url ) => window.location.assign( url ),
	} );

	// The server already drew this page, chrome included: hand the content root
	// the same payload so the cards arrive with the real values, and leave the
	// hydrated shell untouched until a navigation asks for a different one.
	draw( app.page, boot, { focus: false, chrome: false } );
}
