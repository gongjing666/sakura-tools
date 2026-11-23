/**
 * Entry point for the system settings island.
 *
 * Mounts once per page load. If the inline payload is missing (an older cached
 * view, for example) the server-rendered mount point keeps its "载入中" line
 * instead of turning the page blank.
 */
import { createRoot } from 'react-dom/client';

import App from './app.jsx';
import { readBootstrap } from './boot.js';

const mount = document.getElementById( 'sakura-system-root' );

if ( mount ) {
	const boot = readBootstrap();

	if ( boot ) {
		createRoot( mount ).render( <App boot={ boot } /> );
	} else {
		mount.textContent = '页面数据加载失败，请刷新重试。';
	}
}
