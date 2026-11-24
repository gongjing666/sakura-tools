/**
 * The image optimisation page.
 *
 * The top bar and the tab strip are printed by PHP once and are never touched
 * from here: they are identical on every tab, so re-drawing them on mount only
 * produced a repaint of the bar — the flicker readers reported while switching
 * tabs. This file owns the page head and the cards, and swaps them in place when
 * another tab of the same module is opened, so the chrome stays where it is.
 */

import { Fragment, useCallback, useEffect, useState } from 'react';
import { PageHead, Prompt } from '../shared/ui.jsx';
import BackupPage from './backup.jsx';
import RulesPage from './rules.jsx';
import StatsPage from './stats.jsx';
import ThumbPage from './thumb.jsx';
import { call } from './transport.js';

const TABS = [ 'rules', 'thumb', 'backup', 'stats' ];

/**
 * Which tab of this module a URL points at.
 *
 * @param {string} url Absolute or relative URL.
 * @return {string} Tab id, or '' when the link belongs to another page.
 */
function tabOf( url ) {
	let target;

	try {
		target = new URL( url, window.location.href );
	} catch ( error ) {
		return '';
	}

	if ( 'sakura-tools-image' !== ( target.searchParams.get( 'page' ) || '' ) ) {
		return '';
	}

	const tab = target.searchParams.get( 't' ) || 'rules';

	return TABS.includes( tab ) ? tab : '';
}

/**
 * Move the highlight in the server-rendered chrome to the tab now on screen.
 *
 * @param {string} url The URL being opened.
 * @return {void}
 */
function syncChrome( url ) {
	const target = new URL( url, window.location.href );
	const wanted = target.searchParams.get( 't' ) || 'rules';

	document.querySelectorAll( '.sakura-tabs .sakura-tab' ).forEach( ( node ) => {
		if ( ! node.href ) {
			return;
		}

		const tab = new URL( node.href ).searchParams.get( 't' ) || 'rules';
		const active = tab === wanted;

		node.classList.toggle( 'is-active', active );

		if ( active ) {
			node.setAttribute( 'aria-current', 'page' );
		} else {
			node.removeAttribute( 'aria-current' );
		}
	} );
}

/**
 * Page root.
 *
 * @param {Object} props Boot payload.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const [ payload, setPayload ] = useState( boot );
	const [ head, setHead ] = useState( boot.head );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( '' );

	// The namespace root, so a tab switch can ask for any of the four payloads
	// with the same nonce the page booted with.
	const base = payload.rest.url.replace( /\/image\/[a-z]+$/, '' );

	// Every page hands over { tone, kicker, title, message }; the icon, the close
	// label and the dismiss label only exist in strings, so merge them once here
	// instead of repeating the same four fields in four payloads.
	const showNotice = ( next ) =>
		setNotice( {
			closeIcon: payload.strings.closeIcon,
			mascot: payload.strings.mascot,
			closeLabel: payload.strings.close,
			button: payload.strings.dismiss,
			...next,
		} );

	/**
	 * Open another tab without rebuilding the document.
	 *
	 * @param {string} tab Target tab id.
	 * @param {string} url Its URL (pushed into history on success).
	 * @return {Promise<void>}
	 */
	const open = useCallback(
		async ( tab, url ) => {
			if ( loading ) {
				return;
			}

			setLoading( tab );

			try {
				const next = await call(
					{ url: base, nonce: payload.rest.nonce },
					payload.strings,
					'/image/' + tab
				);

				setPayload( next );
				setHead( next.head );
				setError( '' );
				setNotice( null );
				syncChrome( url );
				window.history.pushState( { sakuraTab: tab, payload: next }, '', url );
				window.scrollTo( 0, 0 );
			} catch ( failure ) {
				// Anything unexpected (a stale nonce, a plugin update mid-session)
				// falls back to a real navigation: the server always knows the way.
				window.location.href = url;
			} finally {
				setLoading( '' );
			}
		},
		[ base, loading, payload.rest.nonce, payload.strings ]
	);

	// Chrome lives outside this tree, so the interception has to listen on the
	// document. Links to other modules are left alone — those are different
	// bundles and genuinely different pages.
	useEffect( () => {
		const onClick = ( event ) => {
			if (
				event.defaultPrevented ||
				0 !== event.button ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}

			const link = event.target.closest( '.sakura-tabs a, .sakura-mods a' );

			if ( ! link || ! link.href ) {
				return;
			}

			const tab = tabOf( link.href );

			if ( ! tab ) {
				return;
			}

			event.preventDefault();

			if ( tab === payload.tab ) {
				return;
			}

			open( tab, link.href );
		};

		document.addEventListener( 'click', onClick );

		return () => document.removeEventListener( 'click', onClick );
	}, [ open, payload.tab ] );

	// Back / forward: the payload travels in the history entry, so a step back
	// is a re-render, not a reload. The entry the browser landed on is seeded
	// with the boot payload first — otherwise stepping back past it would find
	// no state and force a full load.
	useEffect( () => {
		if ( ! window.history.state || ! window.history.state.payload ) {
			window.history.replaceState(
				{ sakuraTab: boot.tab, payload: boot },
				'',
				window.location.href
			);
		}

		const onPop = ( event ) => {
			const state = event.state;

			if ( state && state.payload ) {
				setPayload( state.payload );
				setHead( state.payload.head );
				setNotice( null );
				setError( '' );
				syncChrome( window.location.href );
			} else {
				window.location.reload();
			}
		};

		window.addEventListener( 'popstate', onPop );

		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	let body = null;

	if ( 'rules' === payload.tab ) {
		body = (
			<RulesPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
			/>
		);
	}

	if ( 'thumb' === payload.tab ) {
		body = (
			<ThumbPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
			/>
		);
	}

	if ( 'backup' === payload.tab ) {
		body = (
			<BackupPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
				onUsage={ ( usage ) =>
					setHead( ( current ) => ( {
						...current,
						status: {
							...current.status,
							value: usage.count_text,
							sub: usage.size,
						},
					} ) )
				}
			/>
		);
	}

	if ( 'stats' === payload.tab ) {
		body = (
			<StatsPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
				onHead={ setHead }
			/>
		);
	}

	return (
		<Fragment>
			<PageHead head={ head } />

			<div className="page-body" data-sakura-tab={ payload.tab }>
				{ error ? (
					<div className="system-note" role="alert">
						<p>{ error }</p>
					</div>
				) : null }
				{ payload.alerts && payload.alerts.length ? (
					<div className="system-note" data-tone="info">
						{ payload.alerts.map( ( alert ) => (
							<p key={ alert.text } data-tone={ alert.tone }>
								{ alert.title ? <strong>{ alert.title }：</strong> : null }
								{ alert.text }
							</p>
						) ) }
					</div>
				) : null }
				{ body }
			</div>

			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } /> : null }
		</Fragment>
	);
}
