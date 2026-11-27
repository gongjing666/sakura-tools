/**
 * The two shell roots.
 *
 * Both nodes are printed by PHP and hydrated in place: `TopbarContent` and
 * `TabsContent` return the children only, so React adopts the existing
 * <header> and <nav> instead of mounting a second pair beside them. A later
 * navigation calls `render()` on the same roots — React diffs the children and
 * the nodes the operator is looking at are never replaced.
 */
import { hydrateRoot } from 'react-dom/client';

import { TabsContent, TopbarContent } from '../shared/ui.jsx';

/**
 * Adopt the server-rendered chrome.
 *
 * @param {Object} chrome Shell description from the payload.
 * @return {{update: function(Object): void}} Root handle.
 */
export function createShell( chrome ) {
	const topbarEl = document.querySelector( '.sakura-admin > .sakura-topbar' );
	const tabsEl = document.querySelector( '.sakura-admin > .sakura-tabs' );

	const topbar = topbarEl ? hydrateRoot( topbarEl, <TopbarContent chrome={ chrome } /> ) : null;
	const tabs = tabsEl ? hydrateRoot( tabsEl, <TabsContent chrome={ chrome } /> ) : null;

	return {
		/**
		 * Re-render the chrome from a new payload.
		 *
		 * @param {Object} next Shell description.
		 * @return {void}
		 */
		update( next ) {
			if ( ! next ) {
				return;
			}

			if ( topbar ) {
				topbar.render( <TopbarContent chrome={ next } /> );
			}

			if ( tabs ) {
				tabs.render( <TabsContent chrome={ next } /> );
			}
		},
	};
}
