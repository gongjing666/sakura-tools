/**
 * The delete-queue island.
 *
 * The first paint comes from the PHP bootstrap; every later number comes from
 * the polling snapshot. The server owns all derived values (states, rates,
 * ETAs, labels), this component owns selection, dialogs and the polling loop.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { Card, PageHead, Prompt } from '../shared/ui.jsx';
import LivePanel from './live.jsx';
import QueueTable from './table.jsx';
import { exportHref, fetchSnapshot, postAction } from './transport.js';

export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = boot.strings || {};
	const actions = strings.actions || {};
	const notices = strings.notices || {};
	const [ head, setHead ] = useState( boot.head );
	const [ snapshot, setSnapshot ] = useState( boot.snapshot );
	const [ state, setState ] = useState( boot.snapshot.state || 'all' );
	const [ page, setPage ] = useState( ( boot.snapshot.pagination || {} ).page || 1 );
	const [ auto, setAuto ] = useState( true );
	const [ busy, setBusy ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ failures, setFailures ] = useState( 0 );
	const baseline = useRef( {
		done: ( boot.snapshot.counts || {} ).done || 0,
		pending: ( ( boot.snapshot.counts || {} ).queued || 0 ) + ( ( boot.snapshot.counts || {} ).running || 0 ),
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
				config.rest,
				{
					state: latest.current.state,
					page: latest.current.page,
					per_page: config.per_page || 50,
				},
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
	}, [ apply, config.per_page, config.rest, notices.refreshFail ] );

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
		const base = pending + running > 0 ? ( config.poll || {} ).active : ( config.poll || {} ).idle;
		const cap = ( config.poll || {} ).max || 30000;
		const delay = Math.min( cap, ( base || 15000 ) * Math.pow( 2, Math.min( 3, failures ) ) );

		timer.current = window.setTimeout( load, delay );

		return () => {
			if ( timer.current ) {
				window.clearTimeout( timer.current );
			}
		};
	}, [ auto, snapshot, failures, load, config.poll ] );

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
			const answer = await postAction( config.rest, action, {
				ids,
				state,
				page,
				per_page: config.per_page || 50,
			} );

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

		window.SakuraAdmin.ui
			.confirm( strings.dropConfirm.text, {
				title: strings.dropConfirm.title,
				confirmText: strings.dropConfirm.confirm,
				danger: true,
			} )
			.then( ( confirmed ) => {
				if ( confirmed ) {
					runAction( 'drop', ids );
				}
			} );
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

	return (
		<>
			<PageHead head={ head } />

			<div className="page-body">
				{ error ? (
					<div className="system-note" role="status" data-tone="warn">
						<p>{ error }</p>
					</div>
				) : null }

				<Card card={ boot.card } titleProps={ { tabIndex: -1, 'data-sakura-return-target': '1' } }>
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
						onRetry={ ( ids ) => runAction( 'retry', ids ) }
						onDrop={ drop }

						busy={ busy }
						exportHref={ exportHref( ( config.export || {} ).url || '', selectedRows.map( ( row ) => row.id ) ) }
					/>
				</Card>
			</div>

			{ prompt ? <Prompt notice={ prompt } onClose={ () => setNotice( null ) } /> : null }
		</>
	);
}
