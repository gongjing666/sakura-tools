/**
 * Entry point for the image optimisation page.
 *
 * Mounts on the <main> the server printed. The top bar and the tab strip stay
 * outside it and are never re-drawn by React: they are the same on every tab,
 * and swapping them on mount is what made the bar look like it flickered.
 */
import { createRoot } from 'react-dom/client';

import App from './app.jsx';

/**
 * Read the inline payload the view prints before the root node.
 *
 * @return {Object|null} Payload or null.
 */
function readBootstrap() {
	const node = document.getElementById( 'sakura-image-bootstrap' );

	if ( ! node ) {
		return null;
	}

	try {
		return JSON.parse( node.textContent );
	} catch ( error ) {
		window.console.error( 'Sakura: image bootstrap is not valid JSON', error );

		return null;
	}
}

const mount = document.querySelector(
	'.sakura-admin[data-sakura-page^="image-"] > .sakura-admin__main'
);

if ( mount ) {
	const boot = readBootstrap();

	if ( boot ) {
		createRoot( mount ).render( <App boot={ boot } /> );
	} else {
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

		// Heads without an action row carry no marker (a chip alone would add a
		// row and shift the page when the island mounts), so the failure has to
		// land in the body instead of nowhere.
		if ( ! chip ) {
			const body = mount.querySelector( '.page-body' );

			if ( body ) {
				const note = document.createElement( 'p' );
				note.className = 'system-note';
				note.setAttribute( 'role', 'alert' );
				note.textContent = '页面数据加载失败，请刷新重试。';
				body.appendChild( note );
			}
		}
	}
}
