import { useEffect, useRef, useState } from 'react';
import { Card, PageHead } from '../shared/ui.jsx';
import Target from './target.jsx';
import Scan from './scan.jsx';
import Run from './run.jsx';
import History from './history.jsx';
import { confirm, errorText, post, toast } from './transport.js';

const MAX_STEPS = 400;

function indexesOf( tasks ) {
	return ( tasks || [] ).map( ( task ) => Number( task.index ) );
}

export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = config.strings || {};
	const scope = config.scope || { displayPath: '/', source: 'upload_path' };
	const targetFields = config.targetFields || [];
	const [ state, setState ] = useState( config.state || {} );
	const [ from, setFrom ] = useState( ( config.form || {} ).from || '' );
	const [ to, setTo ] = useState( ( config.form || {} ).to || '' );
	const [ selected, setSelected ] = useState( () =>
		( ( config.state || {} ).tasks || [] )
			.filter( ( task ) => task.enabled )
			.map( ( task ) => Number( task.index ) )
	);
	const [ busy, setBusy ] = useState( false );
	const [ preview, setPreview ] = useState( { open: false, label: '', loading: false, rows: [] } );
	const alive = useRef( true );
	const autoResumed = useRef( false );

	useEffect( () => {
		alive.current = true;

		return () => {
			alive.current = false;
		};
	}, [] );

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
		let data = first;
		let steps = 0;

		while ( data && 'scanning' === data.status ) {
			if ( steps >= MAX_STEPS ) {
				throw new Error( strings.unknown );
			}

			data = await post( config, 'sakura_url_rewrite_scan_step', {}, 60000 );
			apply( data, true );
			steps += 1;
		}

		return data;
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
				const data = await followScan( state );
				const count = ( ( data || {} ).tasks || [] ).length;

				toast( count ? strings.scanDone : strings.scanEmpty, count ? 'ok' : 'warn' );
			} catch ( error ) {
				toast( errorText( error, strings.unknown ), 'bad' );
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
			toast( strings.badHost, 'bad' );
			return;
		}

		if ( nextFrom === nextTo ) {
			toast( strings.hostsSame, 'bad' );
			return;
		}

		setBusy( true );

		try {
			let data = await post( config, 'sakura_url_rewrite_scan', { from: nextFrom, to: nextTo }, 60000 );
			apply( data, true );
			data = await followScan( data );

			const count = ( ( data || {} ).tasks || [] ).length;

			toast( count ? strings.scanDone : strings.scanEmpty, count ? 'ok' : 'warn' );
		} catch ( error ) {
			toast( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const run = async () => {
		if ( busy ) {
			return;
		}

		if ( ! ( state.tasks || [] ).length ) {
			toast( strings.noTasks, 'warn' );
			return;
		}

		if ( 'running' !== state.status && ! selected.length ) {
			toast( strings.noSelection, 'warn' );
			return;
		}

		const ok = await confirm( strings.confirmRun, {
			title: strings.confirmTitle,
			confirmText: strings.confirmButton,
			cancelText: strings.cancel,
			tone: 'warn',
		} );

		if ( ! ok || ! alive.current ) {
			return;
		}

		setBusy( true );

		try {
			let data = await post( config, 'sakura_url_rewrite_run', { tasks: selected, batch: config.batch }, 90000 );
			apply( data );
			let steps = 0;

			while ( data && 'running' === data.status ) {
				if ( steps >= MAX_STEPS ) {
					throw new Error( strings.unknown );
				}

				data = await post( config, 'sakura_url_rewrite_run', { tasks: [], batch: config.batch }, 90000 );
				apply( data );
				steps += 1;
			}

			toast( strings.runDone, 'ok' );
		} catch ( error ) {
			toast( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const revert = async ( runId ) => {
		if ( busy || ! runId ) {
			return;
		}

		const ok = await confirm( strings.revertDesc, {
			title: strings.revertTitle,
			confirmText: strings.revertButton,
			cancelText: strings.cancel,
			tone: 'warn',
			danger: true,
		} );

		if ( ! ok || ! alive.current ) {
			return;
		}

		setBusy( true );

		try {
			let after = 0;
			let steps = 0;
			let data;

			do {
				if ( steps >= MAX_STEPS ) {
					throw new Error( strings.unknown );
				}

				data = await post( config, 'sakura_url_rewrite_revert', { runId, after, batch: config.batch }, 90000 );

				if ( data && data.runs ) {
					setState( ( current ) => ( { ...current, runs: data.runs } ) );
				}

				after = Number( data.lastId ) || 0;
				steps += 1;
			} while ( data && ! data.done );

			toast( strings.revertDone, 'ok' );
		} catch ( error ) {
			toast( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const reset = async () => {
		if ( busy ) {
			return;
		}

		const ok = await confirm( strings.resetDesc, {
			title: strings.resetTitle,
			confirmText: strings.resetButton,
			cancelText: strings.cancel,
			tone: 'warn',
		} );

		if ( ! ok || ! alive.current ) {
			return;
		}

		setBusy( true );

		try {
			const data = await post( config, 'sakura_url_rewrite_reset', {}, 30000 );
			apply( data, true );
			toast( strings.resetDone, 'ok' );
		} catch ( error ) {
			toast( errorText( error, strings.unknown ), 'bad' );
		} finally {
			if ( alive.current ) {
				setBusy( false );
			}
		}
	};

	const openPreview = async ( index ) => {
		if ( busy ) {
			return;
		}

		setPreview( { open: true, label: strings.previewTitle, loading: true, rows: [] } );

		try {
			const data = await post( config, 'sakura_url_rewrite_preview', { index }, 30000 );

			if ( alive.current ) {
				setPreview( {
					open: true,
					label: data && data.label ? data.label : strings.previewTitle,
					loading: false,
					rows: data && data.samples ? data.samples : [],
				} );
			}
		} catch ( error ) {
			toast( errorText( error, strings.unknown ), 'bad' );

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
						hostOptions={ config.hosts || [] }
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
		</>
	);
}
