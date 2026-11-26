import { createRoot } from 'react-dom/client';
import App from './app.jsx';

const mount = document.querySelector( '.sakura-admin[data-sakura-page="zibll-performance"] > .sakura-admin__main' );
const node = document.getElementById( 'sakura-zibll-performance-bootstrap' );
let boot = null;

if ( node ) {
	try {
		boot = JSON.parse( node.textContent );
	} catch ( error ) {
		window.console.error( 'Sakura: zibll-performance bootstrap is not valid JSON', error );
	}
}

if ( mount && boot ) {
	createRoot( mount ).render( <App boot={ boot } /> );
} else if ( mount ) {
	const chip = mount.querySelector( '.sakura-boot' );
	const text = mount.querySelector( '.sakura-boot__text' );

	if ( chip ) {
		chip.style.opacity = '1';
		chip.style.animation = 'none';
		chip.classList.add( 'is-failed' );
		chip.setAttribute( 'role', 'alert' );

		if ( text ) {
			text.textContent = '页面数据加载失败，请刷新重试。';
		}
	}
}
