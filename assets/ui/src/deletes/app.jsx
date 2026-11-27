/**
 * The delete-queue island.
 *
 * The first paint comes from the PHP bootstrap; every later number comes from
 * the polling snapshot. The server owns all derived values (states, rates,
 * ETAs, labels), this component owns selection, dialogs and the polling loop.
 *
 * The payload is the six-key page contract: boot.cards[0] is the queue card,
 * boot.data carries the snapshot, the polling cadence and the export URL, and
 * boot.rest is the base of the write routes.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { Dialog } from '../shared/Dialog.jsx';
import { Card, PageHead, Prompt } from '../shared/ui.jsx';
import DispatchCard from './dispatch.jsx';
import LivePanel from './live.jsx';
import QueueTable from './table.jsx';
import { exportHref, fetchSnapshot, postAction } from './transport.js';

export default function App( { boot } ) {
	const data = boot.data || {};
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const initial = data.snapshot || {};
	const cards = Array.isArray( boot.cards ) ? boot.cards : [];
	const notices = strings.notices || {};
	const [ head, setHead ] = useState( boot.head );
	const [ snapshot, setSnapshot ] = useState( initial );
	const [ state, setState ] = useState( initial.state || 'all' );
	const [ page, setPage ] = useState( ( initial.pagination || {} ).page || 1 );
	const [ perPage, setPerPage ] = useState( ( initial.pagination || {} ).per_page || data.per_page || 25 );
	const [ auto, setAuto ] = useState( true );
	const [ busy, setBusy ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ failures, setFailures ] = useState( 0 );
	const [ dropIds, setDropIds ] = useState( null );
	const baseline = useRef( {
		done: ( initial.counts || {} ).done || 0,
		pending: ( ( initial.counts || {} ).queued || 0 ) + ( ( initial.counts || {} ).running || 0 ),
	} );
	const latest = useRef( { state, page } );
	const inflight = useRef( null );
	const timer = useRef( null );
	const alive = useRef( true );
	const firstEffect = useRef( true );

	latest.current = { state, page };

	const apply = useCallback( ( next ) => {
		if ( ! next ) {
			return;
		}

		setSnapshot( next );

		if ( next.status ) {
			setHead( ( current ) => ( { ...current, status: { ...current.status, ...next.status } } ) );
		}

		setFailures( 0 );
		setError( '' );
	}, [] );

	const load = useCallback( async () => {
		if ( inflight.current ) {
			return;
		}

		const controller = new AbortController();
		inflight.current = controller;

		try {
			const next = await fetchSnapshot(
				rest,
				{
					state: latest.current.state,
					page: latest.current.page,
					per_page: perPage,
				},
				strings,
				controller.signal
			);

			if ( alive.current ) {
				apply( next );
			}
		} catch ( failure ) {
			if ( failure && 'AbortError' === failure.name ) {
				return;
			}

			if ( ! alive.current ) {
				return;
			}

			setFailures( ( count ) => count + 1 );
			setError( notices.refreshFail || '自动刷新失败，正在重试。' );
		} finally {
			if ( inflight.current === controller ) {
				inflight.current = null;
			}
		}
	}, [ apply, perPage, rest, notices.refreshFail, strings ] );

	useEffect( () => {
		alive.current = true;

		return () => {
			alive.current = false;

			if ( inflight.current ) {
				inflight.current.abort();
			}

			if ( timer.current ) {
				window.clearTimeout( timer.current );
			}
		};
	}, [] );

	useEffect( () => {
		if ( firstEffect.current ) {
			firstEffect.current = false;
			return;
		}

		load();
	}, [ state, page, load ] );

	useEffect( () => {
		if ( ! auto || ( typeof document !== 'undefined' && document.hidden ) ) {
			return undefined;
		}

		const pending = ( snapshot.counts || {} ).queued || 0;
		const running = ( snapshot.counts || {} ).running || 0;
		const base = pending + running > 0 ? ( data.poll || {} ).active : ( data.poll || {} ).idle;
		const cap = ( data.poll || {} ).max || 30000;
		const delay = Math.min( cap, ( base || 15000 ) * Math.pow( 2, Math.min( 3, failures ) ) );

		timer.current = window.setTimeout( load, delay );

		return () => {
			if ( timer.current ) {
				window.clearTimeout( timer.current );
			}
		};
	}, [ auto, snapshot, failures, load, data.poll ] );

	useEffect( () => {
		const onVisibility = () => {
			if ( ! document.hidden && auto ) {
				load();
			}
		};

		document.addEventListener( 'visibilitychange', onVisibility );

		return () => document.removeEventListener( 'visibilitychange', onVisibility );
	}, [ auto, load ] );

	useEffect( () => {
		const url = new URL( window.location.href );

		if ( 'all' === state ) {
			url.searchParams.delete( 'state' );
		} else {
			url.searchParams.set( 'state', state );
		}

		if ( page <= 1 ) {
			url.searchParams.delete( 'paged' );
		} else {
			url.searchParams.set( 'paged', String( page ) );
		}

		window.history.replaceState( window.history.state, '', url.toString() );
	}, [ state, page ] );

	const changeState = ( next ) => {
		if ( next === state ) {
			return;
		}

		setState( next );
		setPage( 1 );
		setSelected( [] );
	};

	const changePage = ( next ) => {
		if ( next === page ) {
			return;
		}

		setPage( next );
		setSelected( [] );
	};

	const toggle = ( id, checked ) => {
		setSelected( ( current ) => {
			if ( checked ) {
				return current.indexOf( id ) >= 0 ? current : current.concat( id );
			}

			return current.filter( ( value ) => value !== id );
		} );
	};

	const toggleAll = ( checked ) => {
		setSelected( checked ? ( snapshot.rows || [] ).map( ( row ) => row.id ) : [] );
	};

	const runAction = async ( action, ids ) => {
		if ( busy || ! ids.length ) {
			return;
		}

		setBusy( action );

		try {
			const answer = await postAction( rest, action, {
				ids,
				state,
				page,
				per_page: perPage,
			}, strings );

			if ( answer.snapshot ) {
				apply( answer.snapshot );
			}

			setSelected( [] );
			setNotice( {
				tone: answer.tone || 'ok',
				kicker: notices.kicker,
				title: 'retry' === action ? notices.retryTitle : notices.dropTitle,
				message: answer.message || '',
			} );
		} catch ( failure ) {
			setNotice( {
				tone: 'bad',
				kicker: notices.kicker,
				title: notices.errorTitle,
				message: failure.message,
			} );
		} finally {
			setBusy( '' );
		}
	};

	const drop = ( ids ) => {
		if ( ! ids.length || busy ) {
			return;
		}

		setDropIds( ids );
	};

	const refresh = () => {
		if ( busy ) {
			return;
		}

		setBusy( 'refresh' );
		load().finally( () => setBusy( '' ) );
	};

	const selectedRows = ( snapshot.rows || [] ).filter( ( row ) => selected.indexOf( row.id ) >= 0 );
	const prompt = notice
		? {
			...strings.notice,
			...notice,
			closeLabel: ( strings.notice || {} ).closeLabel,
			button: ( strings.notice || {} ).button,
		}
		: null;
	const dropConfirm = strings.dropConfirm || {};

	return (
		<>
			<PageHead head={ head } />

			<div className="page-body">
				{ error ? (
					<div className="system-note" role="status" data-tone="warn">
						<p>{ error }</p>
					</div>
				) : null }

				<DispatchCard
					dispatch={ snapshot.dispatch || {} }
					strings={ strings }
					rest={ rest }
					onSaved={ ( answered ) =>
						setSnapshot( ( current ) => ( {
							...current,
							dispatch: { ...( current.dispatch || {} ), ...answered },
						} ) )
					}
				/>

				<Card card={ cards[0] || {} } titleProps={ { tabIndex: -1, 'data-sakura-return-target': '1' } }>
					<LivePanel
						snapshot={ snapshot }
						strings={ strings }
						auto={ auto }
						busy={ busy }
						onToggle={ () => setAuto( ( value ) => ! value ) }
						onRefresh={ refresh }
						baseline={ baseline.current }
					/>
					<QueueTable
						snapshot={ snapshot }
						strings={ strings }
						selected={ selected }
						onToggle={ toggle }
						onToggleAll={ toggleAll }
						onFilter={ changeState }
						onPage={ changePage }
						perPage={ perPage }
						onPerPage={ ( size ) => {
							setPerPage( Number( size ) );
							setPage( 1 );
						} }
						onRetry={ ( ids ) => runAction( 'retry', ids ) }
						onDrop={ drop }

						busy={ busy }
						exportHref={ exportHref( ( data.export || {} ).url || '', selectedRows.map( ( row ) => row.id ) ) }
					/>
				</Card>
			</div>

			{ prompt ? <Prompt notice={ prompt } onClose={ () => setNotice( null ) } /> : null }

			{ dropIds ? (
				<Dialog
					tone="bad"
					kicker={ notices.kicker }
					title={ dropConfirm.title }
					message={ dropConfirm.text }
					confirmLabel={ dropConfirm.confirm }
					cancelLabel={ strings.cancel }
					busy={ busy === 'drop' }
					onConfirm={ () => {
						const ids = dropIds;

						setDropIds( null );
						runAction( 'drop', ids );
					} }
					onClose={ () => setDropIds( null ) }
				/>
			) : null }
		</>
	);
}
