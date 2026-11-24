/**
 * The upload throughput card.
 *
 * The card is data: PHP hands over the tiles, the sentence above them and the
 * tone of the last-error tile, so a refresh only swaps the array and never runs
 * a second copy of the formatting rules. That is the bug the old panel had —
 * PHP printed a bare count on first paint while the JavaScript refresh added
 * thousands separators, so the same number changed shape after 30 seconds.
 *
 * Polling keeps the old panel's shape too: a fixed interval that skips a tick
 * while the document is hidden instead of pausing and resuming the timer, so a
 * tab left in the background does not drift out of phase when it comes back.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';

/** Milliseconds between two automatic refreshes when PHP does not say. */
const FALLBACK_POLL_MS = 30000;

/** Milliseconds before a refresh gives up, the budget the old AJAX call had. */
const REQUEST_TIMEOUT = 15000;

/**
 * Ask the server for the card again.
 *
 * @param {string} url     Route of the card.
 * @param {string} nonce   REST nonce.
 * @param {Object} strings Card copy, for the fallback messages.
 * @return {Promise<Object>} Fresh card.
 */
async function requestSection( url, nonce, strings ) {
	const controller = new AbortController();
	const timer = window.setTimeout( () => {
		controller.abort();
	}, REQUEST_TIMEOUT );

	let response;

	try {
		response = await fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
			signal: controller.signal,
		} );
	} catch ( error ) {
		// A timeout and a dropped connection look the same from here: the server
		// never answered. A rejected nonce or a plugin error comes back as a
		// response and is reported below with the server's own message.
		throw new Error( strings.network || '' );
	} finally {
		window.clearTimeout( timer );
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch ( error ) {
		payload = null;
	}

	if ( ! response.ok || ! payload || ! payload.section ) {
		throw new Error( ( payload && payload.message ) || strings.failed || '' );
	}

	return payload.section;
}

/**
 * Route of the card, preferring the one the shell already resolved.
 *
 * The bootstrap payload carries rest.url as the base of the storage routes
 * ("/storage"), not of this card, so appending the whole path to it would give
 * "/storage/storage/metrics"; the last segment is therefore tested first.
 *
 * @param {Object} rest REST coordinates from the bootstrap payload.
 * @param {Object} card Card data.
 * @return {string} Route, or an empty string when the shell gave nothing.
 */
function metricsUrl( rest, card ) {
	if ( rest && rest.metrics && rest.metrics.refresh ) {
		return rest.metrics.refresh;
	}

	if ( card.rest && card.rest.refresh ) {
		return card.rest.refresh;
	}

	const base = ( rest && rest.url ) || '';

	if ( ! base ) {
		return '';
	}

	return /(^|\/)storage$/.test( base ) ? base + '/metrics' : base + '/storage/metrics';
}

/**
 * One statistic tile. Wide and bad travel as class names so the layout stays in
 * the stylesheet, exactly like the server-rendered card did.
 *
 * @param {Object} props      Tile props.
 * @param {Object} props.tile Tile data.
 * @return {JSX.Element} Tile.
 */
function Tile( { tile } ) {
	let className = 'kpi';

	if ( tile.wide ) {
		className += ' kpi--wide';
	}

	if ( 'bad' === tile.tone ) {
		className += ' kpi--bad';
	}

	return (
		<div className={ className } data-sakura-metric={ tile.key }>
			<span className="kpi__label">{ tile.label }</span>
			<strong
				className={ tile.text ? 'kpi__value kpi__value--text' : 'kpi__value' }
				title={ tile.title ? tile.title : undefined }
			>
				{ tile.value }
			</strong>
			{ tile.sub ? <span className="kpi__sub">{ tile.sub }</span> : null }
		</div>
	);
}

/**
 * The upload throughput card.
 *
 * @param {Object} props         Card props.
 * @param {Object} props.section Card data from the bootstrap payload.
 * @param {Object} props.rest    REST coordinates from the bootstrap payload.
 * @param {string} props.nonce   REST nonce.
 * @return {JSX.Element} Card.
 */
export default function MetricsCard( { section, rest, nonce } ) {
	const [ card, setCard ] = useState( section );
	const [ pending, setPending ] = useState( false );
	const [ error, setError ] = useState( '' );

	const busy = useRef( false );
	const alive = useRef( true );
	const latest = useRef( null );

	// The shell may hand over a newer server render (a save elsewhere on the
	// page re-sends the whole payload); the server's copy wins over a poll.
	useEffect( () => {
		if ( section ) {
			setCard( section );
		}
	}, [ section ] );

	useEffect( () => {
		alive.current = true;

		return () => {
			alive.current = false;
		};
	}, [] );

	const strings = card.strings || ( section && section.strings ) || {};

	const url = metricsUrl( rest, card );

	const token =
		nonce ||
		( rest && rest.nonce ) ||
		( card.rest && card.rest.nonce ) ||
		'';

	const refresh = useCallback(
		async ( manual ) => {
			// One request at a time: the old panel showed a spinner and disabled
			// its button for the same reason — a slow poll plus an impatient click
			// would otherwise race and the older answer would win.
			if ( busy.current || ! url ) {
				return;
			}

			busy.current = true;
			setPending( true );

			try {
				const next = await requestSection( url, token, strings );

				if ( alive.current ) {
					setCard( ( current ) => ( { ...current, ...next } ) );
					setError( '' );
				}
			} catch ( failure ) {
				// A background refresh keeps the last good numbers on screen and
				// stays quiet; a click has to say why nothing moved.
				const text = ( failure && failure.message ) || strings.failed || '';

				if ( manual && text && alive.current ) {
					setError( text );
				}
			} finally {
				busy.current = false;

				if ( alive.current ) {
					setPending( false );
				}
			}
		},
		[ strings, token, url ]
	);

	// The interval reads through a ref so a new closure does not restart it:
	// the old panel kept one timer for the life of the page.
	useEffect( () => {
		latest.current = refresh;
	} );

	const pollMs = Number( card.poll_ms ) > 0 ? Number( card.poll_ms ) : FALLBACK_POLL_MS;

	useEffect( () => {
		const tick = window.setInterval( () => {
			if ( document.hidden || ! latest.current ) {
				return;
			}

			latest.current( false );
		}, pollMs );

		return () => {
			window.clearInterval( tick );
		};
	}, [ pollMs ] );

	const kpis = Array.isArray( card.kpis ) ? card.kpis : [];
	const unsynced = card.unsynced;
	const updated = card.updated || {};

	return (
		<Card card={ card }>
			<div className="metrics-panel">
				<div className="metrics-toolbar">
					<span className="metrics-toolbar__status" aria-live="polite">
						{ updated.text || '' }
					</span>
					<button
						type="button"
						className="btn btn--ghost btn--small"
						disabled={ pending }
						aria-busy={ pending ? 'true' : undefined }
						onClick={ () => {
							refresh( true );
						} }
					>
						<BusyLabel
							idle={ strings.refresh || '' }
							active={ strings.loading || '' }
							busy={ pending }
						/>
					</button>
				</div>

				<div className="kpis kpis--metrics" role="group" aria-label={ card.title || '' }>
					{ kpis.map( ( tile ) => (
						<Tile key={ tile.key || tile.label } tile={ tile } />
					) ) }
				</div>

				{ unsynced ? (
					<div className="notice-inline notice-inline--warn">
						{ unsynced.before }{' '}
						<a href={ unsynced.link ? unsynced.link.url : '' }>
							{ unsynced.link ? unsynced.link.label : '' }
						</a>{' '}
						{ unsynced.after }
					</div>
				) : null }

				{ error ? (
					<div className="notice-inline notice-inline--bad" role="alert">
						{ error }
					</div>
				) : null }
			</div>
		</Card>
	);
}
