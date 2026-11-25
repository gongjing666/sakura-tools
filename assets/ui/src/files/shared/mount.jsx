/**
 * Mount one files list island and keep the boot-failure fallback consistent
 * with the settings page.
 */
import { createRoot } from 'react-dom/client';

function readBootstrap() {
	const node = document.getElementById( 'sakura-files-admin-bootstrap' );

	if ( ! node ) {
		return null;
	}

	try {
		return JSON.parse( node.textContent );
	} catch ( error ) {
		window.console.error( 'Sakura: files admin bootstrap is not valid JSON', error );
		return null;
	}
}

export default function mountIsland( page, App ) {
	const mount = document.querySelector( '.sakura-admin[data-sakura-page="' + page + '"] > .sakura-admin__main' );
	const boot = readBootstrap();

	if ( mount && boot ) {
		createRoot( mount ).render( <App boot={ boot } /> );
		return;
	}

	if ( ! mount ) {
		return;
	}

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
