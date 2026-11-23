/**
 * Entry point for the system settings page.
 *
 * Mounts on the .sakura-admin node itself: the island now owns the top bar, the
 * tab strip, the page head and the cards, and that node is what the `>`
 * selectors in base.css expect each of them to be a child of.
 *
 * If the inline payload is missing (an older cached view, for example) the
 * server-rendered fallback line stays instead of the page turning blank.
 */
import { createRoot } from 'react-dom/client';

import App from './app.jsx';
import { readBootstrap } from './boot.js';

const mount = document.querySelector( '.sakura-admin[data-sakura-page="system-settings"]' );

if ( mount ) {
	const boot = readBootstrap();

	if ( boot ) {
		createRoot( mount ).render( <App boot={ boot } /> );
	} else {
		const note = mount.querySelector( '.system-note' );

		if ( note ) {
			note.textContent = '页面数据加载失败，请刷新重试。';
		}
	}
}
