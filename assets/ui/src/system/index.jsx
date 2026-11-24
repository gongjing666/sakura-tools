/**
 * Entry point for the system settings page.
 *
 * Mounts on the <main> the server printed: the top bar and the tab strip stay
 * outside it, untouched by React, so switching pages never repaints the chrome.
 * The island owns the page head and the cards only.
 *
 * If the inline payload is missing (an older cached view, for example) the
 * server-rendered skeleton stays instead of the page turning blank.
 */
import { createRoot } from 'react-dom/client';

import App from './app.jsx';
import { readBootstrap } from './boot.js';

const mount = document.querySelector(
	'.sakura-admin[data-sakura-page="system-settings"] > .sakura-admin__main'
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
	}
}
