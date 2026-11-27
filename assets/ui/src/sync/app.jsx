/**
 * The sync center island.
 *
 * The server decides every number; this component arranges them and owns the
 * interactions. Mutations go through REST, are followed by `rest.refresh`, and
 * the returned payload replaces what is on screen — the document is never
 * rebuilt.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { PageHead } from '../shared/ui.jsx';
import TasksCard from './tasks.jsx';
import HealthCard from './health.jsx';
import StorageCard from './storage.jsx';
import ReconcileCard from './reconcile.jsx';
import LogsCard from './logs.jsx';
import IgnoredCard from './ignored.jsx';
import NotesCard from './notes.jsx';
import { post, refresh, errorText, toast } from './transport.js';
import { format } from './format.js';
import { Frontline, KpiStrip } from './overview.jsx';

export default function App( { boot } ) {
	const rest = boot.rest || {};
	const strings = boot.strings || {};
	const [ page, setPage ] = useState( () => ( {
		data: boot.data || {},
		cards: Array.isArray( boot.cards ) ? boot.cards : [],
		scan: ( boot.data && boot.data.view && boot.data.view.scan ) || {},
		revision: 0,
	} ) );
	const [ error, setError ] = useState( '' );
	const tokenRef = useRef( 0 );
	const busyRef = useRef( false );

	const data = page.data;
	const view = data.view || {};
	const cards = page.cards;
	const stringsAll = useMemo( () => ( { ...strings, ...( data.strings || {} ) } ), [ strings, data.strings ] );

	const locked = Boolean( view.locked );
	const frozen = Boolean( view.frozen );
	const indexEnabled = Boolean( view.index_enabled );

	/** Replace what the first paint showed with a freshly fetched payload. */
	const adopt = useCallback( ( payload ) => {
		if ( ! payload || ! payload.data || 'object' !== typeof payload.data ) {
			return;
		}

		const next = payload.data;

		setPage( ( current ) => ( {
			data: next,
			cards: Array.isArray( payload.cards ) ? payload.cards : current.cards,
			scan: ( next.view && next.view.scan ) || current.scan,
			revision: current.revision + 1,
		} ) );
		setError( '' );
	}, [] );

	/**
	 * Refresh the page after a mutation, then report. This replaces the document
	 * reload the classic page did: the payload carries view / rows / logs / cards.
	 *
	 * @param {string} message Copy from the response or strings.
	 * @param {string} tone    Toast tone.
	 * @return {Promise<void>}
	 */
	const settle = useCallback( async ( message, tone ) => {
		try {
			adopt( await refresh( rest, stringsAll ) );
		} catch ( failure ) {
			// The mutation itself succeeded; a stale screen is the only cost, and the
			// next action rebuilds it.
		}

		if ( message ) {
			toast( message, tone );
		}
	}, [ adopt, rest, stringsAll ] );

	/** Non-stale guard for polling: a new scan replaces the old follower. */
	const follow = ( scanId ) => {
		const token = ++tokenRef.current;
		const delays = [ 400, 1000, 2000, 5000 ];
		let attempt = 0;

		return new Promise( ( resolve, reject ) => {
			const step = () => {
				if ( token !== tokenRef.current ) {
					resolve( { state: 'detached' } );
					return;
				}

				post( rest, 'scan/step', { scan_id: scanId }, stringsAll )
					.then( ( result ) => {
						if ( token !== tokenRef.current ) {
							resolve( { state: 'detached' } );
							return;
						}

						setPage( ( current ) => ( {
							...current,
							scan: {
								...current.scan,
								id: Number( result.scan_id || scanId ),
								state: String( result.state || '' ),
								phase: String( result.phase || '' ),
								running: 'queued' === result.state || 'running' === result.state,
								processed: Number( result.processed || 0 ),
								busy: Boolean( result.busy ),
							},
						} ) );

						if ( 'queued' === result.state || 'running' === result.state ) {
							attempt = Math.min( attempt + 1, delays.length - 1 );
							window.setTimeout( step, delays[ attempt ] );
							return;
						}

						resolve( result );
					} )
					.catch( reject );
			};

			step();
		} );
	};

	/** One scan-start / resume path, shared by the buttons and the dialog. */
	const startScan = ( mode ) => {
		if ( busyRef.current ) {
			return;
		}

		busyRef.current = true;
		const path = 'resume' === mode ? 'scan/resume' : 'scan';

		post( rest, path, {}, stringsAll )
			.then( ( result ) => {
				const state = String( result.state || '' );

				if ( ! result.scan_id ) {
					throw new Error( stringsAll.unknownError );
				}

				if ( 'done' === state ) {
					return settle( result.message || stringsAll.done );
				}

				if ( 'queued' !== state && 'running' !== state ) {
					return settle( result.message || stringsAll.done );
				}

				setPage( ( current ) => ( {
					...current,
					scan: {
						...current.scan,
						id: Number( result.scan_id ),
						state,
						phase: String( result.phase || '' ),
						running: true,
						processed: Number( ( result.progress && result.progress.processed ) || 0 ),
					},
				} ) );

				return follow( result.scan_id ).then( ( final ) => {
					// A cancel (or a newer scan) already settled this through its own path.
					if ( 'detached' === ( final && final.state ) ) {
						return undefined;
					}

					return settle(
						( final && final.message ) || stringsAll.done,
						'cancelled' === ( final && final.state ) ? 'warn' : 'ok'
					);
				} );
			} )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				busyRef.current = false;
			} );
	};

	const refreshDiff = ( button ) => {
		if ( busyRef.current ) {
			return;
		}

		busyRef.current = true;
		const restore = button ? window.SakuraAdmin.ui.busy( button, true ) : null;

		post( rest, 'refresh', {}, stringsAll )
			.then( ( result ) => settle( result.message || stringsAll.refreshed ) )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				if ( restore ) {
					restore();
				}

				busyRef.current = false;
			} );
	};

	const cancelScan = () => {
		post( rest, 'scan/cancel', { scan_id: Number( page.scan.id || 0 ) }, stringsAll )
			.then( () => {
				++tokenRef.current;

				return settle( stringsAll.cancelled, 'warn' );
			} )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.cancelFailed ), 'bad' );
			} );
	};

	const retryAll = () => {
		post( rest, 'retry', { attachment_id: 0 }, stringsAll )
			.then( ( result ) => settle( result.message || stringsAll.retryDone ) )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.unknownError ), 'bad' );
			} );
	};

	const toggleIndex = () => {
		post( rest, 'toggle', { enabled: indexEnabled ? '0' : '1' }, stringsAll )
			.then( ( result ) => settle( result.message || stringsAll.actionDone ) )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.unknownError ), 'bad' );
			} );
	};

	const testConnection = ( button ) => {
		const restore = button ? window.SakuraAdmin.ui.busy( button, true ) : null;

		post( rest, 'test', {}, stringsAll )
			.then( ( result ) => {
				toast( result.message || stringsAll.testDone );
				const pill = document.querySelector( '.status-card__ping' );

				if ( pill && result.latency != null ) {
					const ms = pill.querySelector( '[data-sakura-ping-ms]' );

					if ( ms ) {
						ms.textContent = Number( result.latency ) + ' ms';
					}
				}
			} )
			.catch( ( failure ) => {
				toast( errorText( failure, stringsAll.unknownError ), 'bad' );
			} )
			.finally( () => {
				if ( restore ) {
					restore();
				}
			} );
	};

	const actions = {
		scan: () => startScan( 'full' ),
		refresh: ( button ) => refreshDiff( button ),
		cancel: cancelScan,
		resume: () => startScan( 'resume' ),
		'retry-all': retryAll,
		'toggle-index': toggleIndex,
		'test-connection': testConnection,
	};

	const setRows = ( rows ) => setPage( ( current ) => ( {
		...current,
		data: { ...current.data, rows },
	} ) );

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="page-body">
				{ error ? (
					<div className="system-note" role="alert">
						<p>{ error }</p>
					</div>
				) : null }
				<Notices view={ view } strings={ stringsAll } onScan={ actions.scan } onToggle={ toggleIndex } />
				<Frontline frontline={ view.frontline } />
				<KpiStrip kpis={ view.kpis } label={ ( stringsAll.ui || {} ).summaryAria } />
				<div className="card-grid" data-sakura-layout={ boot.page.id }>
					<IgnoredCard
						card={ findCard( cards, 'ignored' ) }
						data={ data }
						strings={ stringsAll }
						rest={ rest }
						frozen={ frozen }
						onRefresh={ settle }
					/>
					<TasksCard
						card={ findCard( cards, 'tasks' ) }
						view={ view }
						rows={ data.rows }
						scan={ page.scan }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						indexEnabled={ indexEnabled }
						onAction={ actions }
					/>
					<HealthCard
						card={ findCard( cards, 'health' ) }
						view={ view }
						strings={ stringsAll }
						data={ data }
						frozen={ frozen }
						indexEnabled={ indexEnabled }
						onToggle={ toggleIndex }
						onTest={ actions[ 'test-connection' ] }
					/>
					<StorageCard
						card={ findCard( cards, 'storage' ) }
						view={ view }
						scan={ page.scan }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						onScan={ actions.scan }
						onResume={ actions.resume }
					/>
					<NotesCard card={ findCard( cards, 'notes' ) } notes={ strings.notes } />
					<ReconcileCard
						card={ findCard( cards, 'reconcile' ) }
						view={ view }
						rows={ data.rows }
						rest={ rest }
						data={ data }
						strings={ stringsAll }
						locked={ locked }
						frozen={ frozen }
						revision={ page.revision }
						onScan={ actions.scan }
						onRows={ setRows }
						onRefresh={ settle }
						onError={ setError }
					/>
					<LogsCard
						card={ findCard( cards, 'logs' ) }
						logs={ data.logs }
						data={ data }
						strings={ stringsAll }
					/>
				</div>
			</div>
			<LatencyPill rest={ rest } data={ data } strings={ stringsAll } />
		</>
	);
}

function findCard( cards, id ) {
	return ( cards || [] ).find( ( card ) => card.id === id ) || { id: id, title: '' };
}

/** The state strips above the grid, in the same order the classic page used. */
function Notices( { view, strings, onScan, onToggle } ) {
	const ui = strings.ui || {};
	const scan = view.scan || {};
	const items = [];

	if ( view.frozen ) {
		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="frozen">
				<p>{ ui.frozen }</p>
			</div>
		);
	}

	if ( view.reconcile_gated ) {
		const from = view.reconcile_gate && view.reconcile_gate.from ? view.reconcile_gate.from : '';
		const bucket = view.reconcile_gate && view.reconcile_gate.bucket ? view.reconcile_gate.bucket : '';
		const text = from ? format( ui.gatedFrom, from, bucket ) : ui.gated;

		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="gated">
				<p>{ text }</p>
			</div>
		);
	}

	if ( ! view.index_enabled ) {
		items.push(
			<div className="alert alert--warn" role="status" key="compat">
				<strong className="alert__title">{ ui.compatTitle }</strong>
				<div className="alert__body" dangerouslySetInnerHTML={ { __html: view.legacy_message || '' } } />
				<div className="button-row">
					<button
						type="button"
						className="btn btn--small"
						data-sakura-action="toggle-index"
						disabled={ view.frozen }
						onClick={ onToggle }
					>
						{ ui.enableIndex }
					</button>
				</div>
			</div>
		);
	}

	if ( scan.running || scan.resumable ) {
		const text = scan.running
			? format( ui.scanRunning, scan.phase_label || ui.scanPhaseFallback, scan.processed )
			: ui.scanInterrupted;

		items.push(
			<div className="notice-inline notice-inline--info" role="status" key="scan">
				<p>{ text }</p>
			</div>
		);
	}

	if ( view.calibrate_stale && ! view.locked ) {
		items.push(
			<div className="notice-inline notice-inline--warn" role="status" key="calibrate">
				<p>{ ui.calibrate }</p>
				<div className="button-row">
					<button type="button" className="btn btn--small" disabled={ view.frozen } onClick={ onScan }>
						{ ui.calibrateNow }
					</button>
				</div>
			</div>
		);
	}

	if ( view.difference > 0 || view.failed_total > 0 ) {
		items.push(
			<div className="alert alert--warn" role="status" key="diff">
				<div
					className="alert__body"
					dangerouslySetInnerHTML={ {
						__html: format( ui.diff, view.difference, view.pending, view.failed_count, view.remote ),
					} }
				/>
			</div>
		);
	}

	if ( ! items.length ) {
		return null;
	}

	return <div className="sync-notices">{ items }</div>;
}

/** Hero pill: re-measures the bucket every 30s while the tab is visible. */
function LatencyPill( { rest, data, strings } ) {
	useEffect( () => {
		const pill = document.querySelector( '[data-sakura-ping]' );

		if ( ! pill ) {
			return undefined;
		}

		const recent = [];
		let timer = null;
		let stopped = false;

		const render = ( state, text, ms ) => {
			pill.setAttribute( 'data-state', state );
			const label = pill.querySelector( '.status-card__ping-text' );

			if ( label ) {
				label.textContent = text;
			}

			const value = pill.querySelector( '[data-sakura-ping-ms]' );

			if ( value ) {
				value.textContent = ms == null ? '' : ms + ' ms';
			}

			pill.setAttribute( 'title', ( strings.latencyHint || '' ) + ( ms == null ? '' : ' · ' + ms + ' ms' ) );
		};

		const measure = () => {
			if ( stopped ) {
				return;
			}

			post( rest, 'latency', {}, strings )
				.then( ( result ) => {
					const ms = Number( result.ms || 0 );
					recent.push( ms );

					while ( recent.length > 3 ) {
						recent.shift();
					}

					const sorted = recent.slice().sort( ( a, b ) => a - b );
					const median = sorted[ Math.floor( sorted.length / 2 ) ];
					const state = median < 200 ? 'good' : median <= 500 ? 'slow' : 'bad';
					const label = 'good' === state ? strings.latencyOk : 'slow' === state ? strings.latencySlow : strings.latencyBad;

					render( state, label, median );
				} )
				.catch( () => {
					render( 'unknown', strings.latencyFail, null );
				} );
		};

		const start = () => {
			stopped = false;
			measure();
			timer = window.setInterval( measure, Number( data.latencyMs || 30000 ) );
		};

		const stop = () => {
			stopped = true;

			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		};

		const onVisibility = () => {
			if ( document.hidden ) {
				stop();
			} else {
				start();
			}
		};

		start();
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			stop();
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ rest, data, strings ] );

	return null;
}
