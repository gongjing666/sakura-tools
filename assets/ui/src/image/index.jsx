/**
 * Entry point for the image optimisation page.
 *
 * Mounts on the .sakura-admin node itself, like the system page: the island owns
 * the top bar, the tab strip, the page head and the cards, and base.css expects
 * each of them to be a direct child of that node.
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

const mount = document.querySelector( '.sakura-admin[data-sakura-page^="image-"]' );

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
	}
}
