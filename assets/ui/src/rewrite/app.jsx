import { useEffect, useRef, useState } from 'react';
import { Card, PageHead, Prompt } from '../shared/ui.jsx';
import { Dialog } from '../shared/Dialog.jsx';
import Target from './target.jsx';
import Scan from './scan.jsx';
import Run from './run.jsx';
import History from './history.jsx';
import { post } from './transport.js';

const MAX_STEPS = 400;

function indexesOf( tasks ) {
	return ( tasks || [] ).map( ( task ) => Number( task.index ) );
}

function errorText( error, fallback ) {
	return error && error.message ? String( error.message ) : ( fallback || '' );
}

/**
 * Page root.
 *
 * The payload owns the scan state, hosts, scope and copy; this component only
 * edits the demand and swaps in the summary each REST call returns.
 *
 * @param {Object} props Boot payload.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const data = boot.data || {};
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const scope = data.scope || { displayPath: '/', source: 'upload_path' };
	const targetFields = data.targetFields || [];
	const [ state, setState ] = useState( data.state || {} );
	const [ from, setFrom ] = useState( ( data.form || {} ).from || '' );
	const [ to, setTo ] = useState( ( data.form || {} ).to || '' );
	const [ selected, setSelected ] = useState( () =>
		( ( data.state || {} ).tasks || [] )
			.filter( ( task ) => task.enabled )
			.map( ( task ) => Number( task.index ) )
	);
	const [ busy, setBusy ] = useState( false );
	const [ preview, setPreview ] = useState( { open: false, label: '', loading: false, rows: [] } );
	const [ notice, setNotice ] = useState( null );
	const [ dialog, setDialog ] = useState( null );
	const alive = useRef( true );
	const autoResumed = useRef( false );
	const opener = useRef( null );

	useEffect( () => {
		alive.current = true;

		return () => {
			alive.current = false;
		};
	}, [] );

	// One prompt shape for every result; the kicker and titles come from the
	// payload, so the island never invents copy.
	const notify = ( message, tone = 'ok' ) => {
		if ( ! alive.current || ! message ) {
			return;
		}

		setNotice( {
			tone,
			kicker: strings.noticeKicker,
			title: 'bad' === tone ? strings.noticeErrorTitle : strings.noticeTitle,
			message,
			closeLabel: strings.close,
			closeIcon: strings.closeIcon,
			mascot: strings.mascot,
			button: strings.dismiss,
		} );
	};

	const ask = ( options ) => {
		opener.current = document.querySelector( '[data-sakura-return-target]' );
		setDialog( options );
	};

	const apply = ( next, syncSelection = false ) => {
		if ( ! next || ! alive.current ) {
			return;
		}

		setState( next );

		if ( syncSelection ) {
			setSelected( indexesOf( next.tasks ) );
		}
	};

	const followScan = async ( first ) => {
		let current = first;
		let steps = 0;

		while ( current && 'scanning' === current.status ) {
			if ( steps >= MAX_STEPS ) {
				throw new Error( strings.unknown );
			}

			current = await post( rest, 'scan-step', {}, strings );
			apply( current, true );
			steps += 1;
		}

		return current;
	};

	useEffect( () => {
		if ( autoResumed.current ) {
			return;
		}

		autoResumed.current = true;

		if ( 'scanning' !== state.status ) {
			return;
		}

		setBusy( true );

		( async () => {
			try {
				const current = await followScan( state );
				const count = ( ( current || {} ).tasks || [] ).length;

				notify( count ? strings.scanDone : strings.scanEmpty, count ? 'ok' : 'warn' );
			} catch ( error ) {
				notify( errorText( error, strings.unknown ), 'bad' );
			} finally {
				if ( alive.current ) {
					setBusy( false );
				}
			}
		} )();
	}, [] );

	const onField = ( key, value ) => {
		if ( 'from' === key ) {
			setFrom( value );
		} else if ( 'to' === key ) {
			setTo( value );
		}
	};

	const onSelect = ( index, checked ) => {
		const number = Number( index );

		setSelected( ( current ) => {
			if ( checked ) {
				return current.indexOf( number ) >= 0 ? current : current.concat( number );
			}

			return current.filter( ( item ) => item !== number );
		} );
	};

	const onSelectAll = ( checked ) => {
		const tasks = state.tasks || [];

		setSelected( checked ? indexesOf( tasks ) : [] );
	};

	const onPreset = ( groups ) => {
		const tasks = state.tasks || [];

		setSelected(
			tasks
				.filter( ( task ) => ! groups || groups.indexOf( String( task.group || '' ) ) >= 0 )
				.map( ( task ) => Number( task.index ) )
		);
	};

	const scan = async () => {
		if ( busy ) {
			return;
		}

		const nextFrom = from.trim();
		const nextTo = to.trim();

		if ( ! nextFrom || ! nextTo ) {
			notify( strings.badHost, 'bad' );
			return;
		}

		if ( nextFrom === nextTo ) {
			notify( strings.hostsSame, 'bad' );
			return;
		}

		setBusy( true );

		try {
			let current = await post( rest, 'scan', { from: nextFrom, to: nextTo }, strings );
			apply( current, true );
			current = await followScan( current );

			const count = ( ( current || {} ).tasks || [] ).length;

			notify( count ? strings.scanDone : strings.scanEmpty, count ? 'ok' : 'warn' );
		} catch ( error ) {
			notify( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const startRun = async () => {
		setBusy( true );

		try {
			let current = await post( rest, 'run', { tasks: selected, batch: data.batch }, strings );
			apply( current );
			let steps = 0;

			while ( current && 'running' === current.status ) {
				if ( steps >= MAX_STEPS ) {
					throw new Error( strings.unknown );
				}

				current = await post( rest, 'run', { tasks: [], batch: data.batch }, strings );
				apply( current );
				steps += 1;
			}

			notify( strings.runDone, 'ok' );
		} catch ( error ) {
			notify( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const run = () => {
		if ( busy ) {
			return;
		}

		if ( ! ( state.tasks || [] ).length ) {
			notify( strings.noTasks, 'warn' );
			return;
		}

		if ( 'running' !== state.status && ! selected.length ) {
			notify( strings.noSelection, 'warn' );
			return;
		}

		ask( {
			tone: 'warn',
			title: strings.confirmTitle,
			message: strings.confirmRun,
			confirmLabel: strings.confirmButton,
			cancelLabel: strings.cancel,
			onConfirm: startRun,
		} );
	};

	const startRevert = async ( runId ) => {
		setBusy( true );

		try {
			let after = 0;
			let steps = 0;
			let result;

			do {
				if ( steps >= MAX_STEPS ) {
					throw new Error( strings.unknown );
				}

				result = await post( rest, 'revert', { runId, after, batch: data.batch }, strings );

				if ( result && result.runs ) {
					setState( ( current ) => ( { ...current, runs: result.runs } ) );
				}

				after = Number( result.lastId ) || 0;
				steps += 1;
			} while ( result && ! result.done );

			notify( strings.revertDone, 'ok' );
		} catch ( error ) {
			notify( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const revert = ( runId ) => {
		if ( busy || ! runId ) {
			return;
		}

		ask( {
			tone: 'warn',
			title: strings.revertTitle,
			message: strings.revertDesc,
			confirmLabel: strings.revertButton,
			cancelLabel: strings.cancel,
			onConfirm: () => startRevert( runId ),
		} );
	};

	const startReset = async () => {
		setBusy( true );

		try {
			const current = await post( rest, 'reset', {}, strings );
			apply( current, true );
			notify( strings.resetDone, 'ok' );
		} catch ( error ) {
			notify( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const reset = () => {
		if ( busy ) {
			return;
		}

		ask( {
			tone: 'warn',
			title: strings.resetTitle,
			message: strings.resetDesc,
			confirmLabel: strings.resetButton,
			cancelLabel: strings.cancel,
			onConfirm: startReset,
		} );
	};

	const openPreview = async ( index ) => {
		if ( busy ) {
			return;
		}

		setPreview( { open: true, label: strings.previewTitle, loading: true, rows: [] } );

		try {
			const current = await post( rest, 'preview', { index }, strings );

			if ( alive.current ) {
				setPreview( {
					open: true,
					label: current && current.label ? current.label : strings.previewTitle,
					loading: false,
					rows: current && current.samples ? current.samples : [],
				} );
			}
		} catch ( error ) {
			notify( errorText( error, strings.unknown ), 'bad' );

			if ( alive.current ) {
				setPreview( ( current ) => ( { ...current, loading: false } ) );
			}
		}
	};

	const cards = {};

	( boot.cards || [] ).forEach( ( card ) => {
		cards[ card.id ] = card;
	} );

	return (
		<>
			<PageHead head={ boot.head } />

			<div className="page-body">
				<Card card={ cards.target } titleProps={ { tabIndex: -1, 'data-sakura-return-target': '1' } }>
					<Target
						fields={ targetFields }
						values={ { from, to } }
						onChange={ onField }
						hostOptions={ data.hosts || [] }
						scope={ scope }
						strings={ strings }
						disabled={ busy }
					/>
				</Card>

				<Card card={ cards.scan }>
					<Scan
						state={ state }
						selected={ selected }
						strings={ strings }
						busy={ busy }
						scope={ scope }
						onSelect={ onSelect }
						onSelectAll={ onSelectAll }
						onPreset={ onPreset }
						onScan={ scan }
						onReset={ reset }
						onPreview={ openPreview }
						preview={ preview }
						onClosePreview={ () => setPreview( ( current ) => ( { ...current, open: false } ) ) }
					/>
				</Card>

				<Card card={ cards.run }>
					<Run
						state={ state }
						strings={ strings }
						busy={ busy }
						selectedCount={ selected.length }
						onRun={ run }
					/>
				</Card>

				<Card card={ cards.history }>
					<History
						runs={ state.runs || [] }
						strings={ strings }
						busy={ busy }
						onRevert={ revert }
					/>
				</Card>
			</div>

			{ notice ? (
				<Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ opener } />
			) : null }

			{ dialog ? (
				<Dialog
					tone={ dialog.tone || 'info' }
					kicker={ strings.noticeKicker }
					title={ dialog.title }
					message={ dialog.message }
					confirmLabel={ dialog.confirmLabel }
					cancelLabel={ dialog.cancelLabel }
					busy={ busy }
					returnFocus={ opener }
					onClose={ () => setDialog( null ) }
					onConfirm={ () => {
						const action = dialog.onConfirm;

						setDialog( null );

						if ( action ) {
							action();
						}
					} }
				/>
			) : null }
		</>
	);
}
