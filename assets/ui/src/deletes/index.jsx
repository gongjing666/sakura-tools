/**
 * Mount the delete-queue island.
 *
 * Same contract as the other islands: read the bootstrap JSON, hand it to the
 * app, and if it is missing say so on the boot chip instead of leaving a
 * skeleton spinning forever.
 */
import { createRoot } from 'react-dom/client';
import App from './app.jsx';

function readBootstrap() {
	const node = document.getElementById( 'sakura-deletes-bootstrap' );

	if ( ! node ) {
		return null;
	}

	try {
		return JSON.parse( node.textContent );
	} catch ( error ) {
		window.console.error( 'Sakura: deletes bootstrap is not valid JSON', error );
		return null;
	}
}

const mount = document.querySelector( '.sakura-admin[data-sakura-page="storage-deletes"] > .sakura-admin__main' );
const boot = readBootstrap();

if ( mount && boot ) {
	createRoot( mount ).render( <App boot={ boot } /> );
} else if ( mount ) {
	const chip = mount.querySelector( '.sakura-boot' );
	const line = mount.querySelector( '.sakura-boot__text' );
	const text = '页面数据加载失败，请刷新重试。';

	if ( chip ) {
		chip.style.opacity = '1';
		chip.style.animation = 'none';
		chip.classList.add( 'is-failed' );
		chip.setAttribute( 'role', 'alert' );

		if ( line ) {
			line.textContent = text;
		}
	} else {
		const body = mount.querySelector( '.page-body' );

		if ( body ) {
			const note = document.createElement( 'p' );
			note.className = 'system-note';
			note.setAttribute( 'role', 'alert' );
			note.textContent = text;
			body.appendChild( note );
		}
	}
}
