/**
 * Reconciliation table: filters, selection, row actions, batch transfer.
 *
 * Reads and actions go through the sync REST routes; confirmation uses the
 * shared Dialog, and every successful mutation refreshes the page payload
 * instead of reloading the document.
 */
import { useEffect, useRef, useState } from 'react';
import { Card, Badge } from '../shared/ui.jsx';
import { Dialog } from '../shared/Dialog.jsx';
import { post, errorText, toast } from './transport.js';

const MAX_SELECT = 100;

const STATUS_TONES = { synced: 'ok', pending: 'warn', failed: 'bad', remote: 'neutral', mismatch: 'warn' };

function pad2( value ) {
	return value < 10 ? '0' + value : String( value );
}

/**
 * Mirrors S3\Inventory::format_bytes() so a row rendered from the first-screen
 * payload and the same row re-rendered after a search show identical text:
 * KB / MB / GB keep two decimals there, while core.js trims to one above 10.
 */
function phpBytes( bytes ) {
	const size = Number( bytes ) || 0;
	if ( size >= 1073741824 ) {
		return ( size / 1073741824 ).toFixed( 2 ) + ' GB';
	}
	if ( size >= 1048576 ) {
		return ( size / 1048576 ).toFixed( 2 ) + ' MB';
	}
	if ( size >= 1024 ) {
		return ( size / 1024 ).toFixed( 2 ) + ' KB';
	}
	return String( Math.round( size ) ) + ' B';
}

/**
 * Both the page endpoint and fast-search hand back raw index rows, while the
 * first-screen payload is built in PHP and already carries display fields.
 * Rebuild the same shape here so both paths render alike.
 */
function normalizeRow( row, statusLabels ) {
	const key = String( row.key || '' );
	const name = row.name || key.split( '/' ).pop() || '';
	const dot = name.lastIndexOf( '.' );
	const status = row.status || 'synced';
	const mtime = Number( row.mtime || 0 );
	let dateLabel = '\u2014';

	if ( mtime ) {
		const date = new Date( mtime * 1000 );

		dateLabel = pad2( date.getMonth() + 1 ) + '/' + pad2( date.getDate() ) + ' ' + pad2( date.getHours() ) + ':' + pad2( date.getMinutes() );
	}

	return Object.assign( {}, row, {
		name,
		path: row.path || key,
		type: row.type || 'other',
		// A row that kept its local size but lost the local file was cleaned up
		// by the "delete local after upload" policy, not lost by hand.
		local_removed: ! row.local && Number( row.size || 0 ) > 0,
		ext: row.ext || ( dot > 0 && dot < name.length - 1 ? name.slice( dot + 1 ).toUpperCase() : '\u2014' ),
		status,
		status_label: row.status_label || statusLabels[ status ] || status,
		status_tone: row.status_tone || STATUS_TONES[ status ] || 'neutral',
		size_label: row.size_label || ( row.local ? phpBytes( row.size ) : '\u2014' ),
		cloud_label: row.cloud_label || ( row.cloud ? phpBytes( row.cloud_size ) : '\u2014' ),
		date_label: row.date_label || dateLabel
	} );
}

export default function ReconcileCard( { card, view, rows, rest, data, strings, locked, frozen, revision, onScan, onRows, onRefresh, onError } ) {
	const s = strings.reconcile || {};
	const uiText = strings;
	const ui = strings.ui || {};
	const labels = data.labels || {};
	const statusLabels = data.statusLabels || {};
	const [ filter, setFilter ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ type, setType ] = useState( 'all' );
	const [ days, setDays ] = useState( 'all' );
	const [ pageSize, setPageSize ] = useState( Number( data.pageSize || 25 ) );
	const [ cursor, setCursor ] = useState( view.page_cursor || '' );
	const [ history, setHistory ] = useState( [ '' ] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( Boolean( view.page_more ) );
	const [ total, setTotal ] = useState( Number( view.total || 0 ) );
	const [ matched, setMatched ] = useState( Number( view.total || 0 ) );
	const [ selection, setSelection ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ adopt, setAdopt ] = useState( null );
	const [ dialog, setDialog ] = useState( null );
	const [ syncedRevision, setSyncedRevision ] = useState( revision );
	const requestRef = useRef( 0 );
	const batchRef = useRef( false );

	// A payload refresh replaces the rows with the default first page; reset the
	// table controls with it, the way the full reload used to. Render-phase state
	// updates keep this out of an effect.
	if ( revision !== syncedRevision ) {
		setSyncedRevision( revision );
		setFilter( 'all' );
		setSearch( '' );
		setType( 'all' );
		setDays( 'all' );
		setPage( 1 );
		setCursor( view.page_cursor || '' );
		setHistory( [ '' ] );
		setHasMore( Boolean( view.page_more ) );
		setTotal( Number( view.total || 0 ) );
		setMatched( Number( view.total || 0 ) );
		setSelection( {} );
	}

	const filtered = 'all' !== filter || search.trim() || 'all' !== type || 'all' !== days;
	const base = filtered ? matched : total;
	const start = rows.length ? ( page - 1 ) * pageSize + 1 : 0;
	const end = Math.min( ( page - 1 ) * pageSize + rows.length, base );
	const summaryTemplate = filtered ? uiText.summaryFiltered || uiText.summary : uiText.summary;

	/** Ask with the shared Dialog and resolve with the operator's answer. */
	const ask = ( options ) => new Promise( ( resolve ) => {
		setDialog( { ...options, resolve } );
	} );

	const answer = ( value ) => {
		const open = dialog;

		setDialog( null );

		if ( ! open ) {
			return;
		}

		open.resolve( value );

		// Cancelling is already handled by Dialog.close(); confirming unmounts
		// the card, so the trigger has to get focus back here.
		if ( value && open.returnFocus && open.returnFocus.current ) {
			const opener = open.returnFocus.current;

			window.setTimeout( () => opener.focus( { preventScroll: true } ), 0 );
		}
	};

	const selectedKeys = ( kind ) =>
		rows
			.filter( ( row ) => {
				const isSelected = selection[ row.key ];
				if ( ! isSelected ) {
					return false;
				}
				if ( 'upload' === kind ) {
					return row.local && 'synced' !== row.status;
				}
				if ( 'download' === kind ) {
					return row.cloud && ! row.local;
				}
				return row.cloud;
			} )
			.map( ( row ) => row.key );

	const selectedCount = Object.keys( selection ).filter( ( key ) => selection[ key ] ).length;
	const allSelected = rows.length > 0 && selectedCount >= rows.length;

	const toggleAll = () => {
		if ( allSelected ) {
			setSelection( {} );
			return;
		}

		const next = {};
		rows.slice( 0, MAX_SELECT ).forEach( ( row ) => {
			next[ row.key ] = true;
		} );

		if ( rows.length > MAX_SELECT ) {
			toast( ( uiText.batchLimit || '最多选择 100 项' ), 'warn' );
		}

		setSelection( next );
	};

	const toggleRow = ( key ) => {
		const next = { ...selection };
		if ( next[ key ] ) {
			delete next[ key ];
			setSelection( next );
			return;
		}

		if ( Object.keys( next ).length >= MAX_SELECT ) {
			toast( uiText.batchLimit || '最多选择 100 项', 'warn' );
			return;
		}

		next[ key ] = true;
		setSelection( next );
	};

	/** Reload one page; the fast-search path first, the index endpoint after. */
	const loadPage = ( nextCursor, nextPage, overrides ) => {
		const seq = ++requestRef.current;
		const state = overrides || {};
		const params = {
			status: state.filter != null ? state.filter : filter,
			search: state.search != null ? state.search : search.trim(),
			type: state.type != null ? state.type : type,
			days: state.days != null ? state.days : days,
			cursor: nextCursor || '',
			limit: pageSize,
		};

		const fallback = () =>
			post( rest, 'page', {
				status: params.status,
				search: params.search,
				type: params.type,
				days: params.days,
				cursor: params.cursor,
				limit: params.limit,
			}, strings );

		const run = async () => {
			if ( data.searchUrl && data.searchToken ) {
				try {
					const query = new URLSearchParams( {
						u: String( data.searchToken.u ),
						e: String( data.searchToken.e ),
						p: String( data.searchToken.p ),
						s: String( data.searchToken.s ),
						status: params.status,
						search: params.search,
						type: params.type,
						days: params.days,
						cursor: params.cursor,
						limit: String( params.limit ),
					} );
					const response = await fetch( data.searchUrl + '?' + query.toString(), {
						credentials: 'same-origin',
					} );
					const payload = await response.json();

					if ( payload && payload.success && payload.data && Array.isArray( payload.data.rows ) ) {
						if ( ! ( 0 === payload.data.rows.length && ! params.cursor && ! params.search && 'all' === params.status ) ) {
							return payload.data;
						}
					}
				} catch ( error ) {
					// Fall through to the index endpoint.
				}
			}

			return fallback();
		};

		run()
			.then( ( result ) => {
				if ( seq !== requestRef.current ) {
					return;
				}

				onRows( Array.isArray( result.rows ) ? result.rows.map( ( row ) => normalizeRow( row, statusLabels ) ) : [] );
				setCursor( result.cursor || '' );
				setHasMore( Boolean( result.has_more ) );
				setTotal( Number( result.total || 0 ) );
				setMatched( typeof result.filtered_total !== 'undefined' && result.filtered_total !== null ? Number( result.filtered_total ) : Number( result.total || 0 ) );
				setPage( nextPage );
				setSelection( {} );
				onError( '' );
			} )
			.catch( ( error ) => {
				if ( seq !== requestRef.current ) {
					return;
				}

				onError( errorText( error, uiText.pageError || '加载文件列表失败。' ) );
			} );
	};

	/** Search debounce, same 280ms window the classic controller used. */
	useEffect( () => {
		const handle = window.setTimeout( () => {
			if ( ! search ) {
				return;
			}

			setHistory( [ '' ] );
			loadPage( '', 1, { search } );
		}, 280 );

		return () => window.clearTimeout( handle );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ search ] );

	const applyFilter = ( value ) => {
		setFilter( value );
		setHistory( [ '' ] );
		loadPage( '', 1, { filter: value } );
	};

	const rowAction = ( kind, row, confirmValue ) => {
		if ( batchRef.current || locked ) {
			return;
		}

		post( rest, 'action', {
			sync_action: kind,
			object_key: row.key,
			confirm: confirmValue || '',
		}, strings )
			.then( ( result ) => onRefresh().then( () => toast(
				result.message || ( result.queued ? uiText.queued : uiText.actionDone ),
				result.queued ? 'info' : 'ok'
			) ) )
			.catch( ( error ) => {
				toast( errorText( error, uiText.unknownError ), 'bad' );
			} );
	};

	const confirmDelete = async ( row, opener ) => {
		const ok = await ask( {
			tone: 'bad',
			title: uiText.deleteTitleShort || uiText.deleteTitle,
			message: ( uiText.deleteHead || '云端对象「%s」会被永久删除。' ).replace( '%s', row.name ),
			items: [ row.name + ( row.cloud_label ? ' · ' + row.cloud_label : '' ) ],
			children: uiText.deleteNote ? <p className="small-text">{ uiText.deleteNote }</p> : null,
			confirmLabel: uiText.deleteConfirm || '永久删除',
			cancelLabel: ui.cancel || '取消',
			returnFocus: { current: opener || null },
		} );

		if ( ok ) {
			rowAction( 'delete', row, 'DELETE' );
		}
	};

	const confirmReplace = async ( row, opener ) => {
		const ok = await ask( {
			tone: 'warn',
			title: uiText.replaceTitle,
			message: uiText.replaceDesc,
			confirmLabel: uiText.replaceConfirm,
			cancelLabel: ui.cancel || '取消',
			returnFocus: { current: opener || null },
		} );

		if ( ok ) {
			rowAction( 'upload', row, 'REPLACE' );
		}
	};

	const preview = ( row ) => {
		const payload = row.preview || {};

		if ( ! payload.download && ! payload.url ) {
			post( rest, 'preview', { object_key: row.key }, strings )
				.then( ( result ) => {
					if ( result && result.preview ) {
						window.SakuraAdmin.ui.mediaPreview( result.preview, { trigger: null } );
					}
				} )
				.catch( ( error ) => toast( errorText( error, uiText.previewError ), 'bad' ) );
			return;
		}

		window.SakuraAdmin.ui.mediaPreview( payload, {} );
	};

	const startBatch = () => {
		batchRef.current = true;
		setBusy( true );
		setProgress( { done: 0, total: 1, text: uiText.batchWorking || '正在处理' } );
	};

	const finishBatch = ( message, tone ) => {
		setProgress( ( current ) => ( current ? { ...current, done: current.total } : current ) );
		window.setTimeout( () => {
			onRefresh().then( () => {
				batchRef.current = false;
				setBusy( false );
				setProgress( null );
				setSelection( {} );
				onError( '' );
				toast( message || uiText.actionDone, tone || 'ok' );
			} );
		}, 400 );
	};

	const batchTransfer = ( kind, keys, confirmValue ) => {
		if ( ! keys.length ) {
			toast( uiText.batchEmpty, 'warn' );
			return;
		}

		startBatch();
		let offset = 0;
		let rounds = 0;
		let message = '';

		const step = () => {
			post( rest, 'action', {
				sync_action: kind,
				keys,
				offset,
				seconds: 20,
				...( confirmValue ? { confirm: confirmValue } : {} ),
			}, strings )
				.then( ( result ) => {
					rounds += 1;
					const processed = Number(
						result.offset != null
							? result.offset
							: Number( result.uploaded || 0 ) + Number( result.downloaded || 0 ) + Number( result.skipped || 0 ) + Number( result.failed || 0 )
					);
					message = result.message || message;

					if ( processed > offset ) {
						offset = processed;
					}

					setProgress( { done: Math.min( offset || keys.length, keys.length ), total: keys.length, text: uiText.batchWorking || '正在处理' } );

					if ( result.done || offset >= keys.length || rounds > 200 ) {
						finishBatch( message, Number( result.failed || 0 ) > 0 ? 'warn' : 'ok' );
						return;
					}

					window.setTimeout( step, 100 );
				} )
				.catch( ( error ) => {
					batchRef.current = false;
					setBusy( false );
					toast( errorText( error, uiText.unknownError ), 'bad' );
				} );
		};

		step();
	};

	const batchUpload = async ( opener ) => {
		const keys = selectedKeys( 'upload' );

		if ( ! keys.length ) {
			toast( uiText.batchEmpty, 'warn' );
			return;
		}

		const replace = rows.filter( ( row ) => selection[ row.key ] && row.cloud ).length;

		if ( replace > 0 ) {
			const ok = await ask( {
				tone: 'warn',
				title: uiText.replaceTitle,
				message: ( uiText.batchReplace || '' ).replace( '%d', String( replace ) ),
				confirmLabel: uiText.replaceConfirm,
				cancelLabel: ui.cancel || '取消',
				returnFocus: { current: opener || null },
			} );

			if ( ! ok ) {
				return;
			}

			batchTransfer( 'upload', keys, 'REPLACE' );
			return;
		}

		batchTransfer( 'upload', keys, '' );
	};

	const batchDelete = async ( opener ) => {
		const keys = selectedKeys( 'delete' );

		if ( ! keys.length ) {
			toast( uiText.batchEmpty, 'warn' );
			return;
		}

		const listed = keys.slice( 0, 5 ).map( ( key ) => key.split( '/' ).pop() );

		if ( keys.length > listed.length && uiText.batchDeleteMore ) {
			listed.push( ( uiText.batchDeleteMore || '' ).replace( '%d', String( keys.length - listed.length ) ) );
		}

		const ok = await ask( {
			tone: 'bad',
			title: uiText.batchDeleteTitle,
			message: ( uiText.batchDeleteHead || uiText.batchDeleteDesc ).replace( '%d', String( keys.length ) ),
			items: listed,
			children: uiText.batchDeleteNote ? <p className="small-text">{ uiText.batchDeleteNote }</p> : null,
			confirmLabel: ( uiText.batchDeleteConfirm || '' ).replace( '%d', String( keys.length ) ),
			cancelLabel: ui.cancel || '取消',
			returnFocus: { current: opener || null },
		} );

		if ( ! ok ) {
			return;
		}

		startBatch();
		setProgress( { done: 0, total: keys.length, text: uiText.batchWorking || '正在处理' } );

		post( rest, 'action', { sync_action: 'delete', keys, confirm: 'DELETE' }, strings )
			.then( ( result ) => {
				setProgress( { done: keys.length, total: keys.length, text: uiText.batchWorking || '正在处理' } );
				finishBatch( result.message || uiText.actionDone, 'ok' );
			} )
			.catch( ( error ) => {
				batchRef.current = false;
				setBusy( false );
				toast( errorText( error, uiText.unknownError ), 'bad' );
			} );
	};

	const runAdopt = () => {
		let adopted = 0;
		let total = 0;

		setAdopt( { text: uiText.adoptRunning || '正在补建媒体库记录…' } );

		const step = () => {
			post( rest, 'action', { sync_action: 'adopt', seconds: 20 }, strings )
				.then( ( result ) => {
					total = Number( result.total || total );
					adopted = Number( result.adopted || adopted );
					const text = ( uiText.adoptRunning || '' ) + ' ' + adopted + ' / ' + total;
					setAdopt( { text } );

					if ( result.done || Number( result.remaining || 0 ) <= 0 ) {
						setAdopt( null );
						onRefresh().then( () => toast( result.message || uiText.adoptDone ) );
						return;
					}

					window.setTimeout( step, 100 );
				} )
				.catch( ( error ) => {
					setAdopt( null );
					toast( errorText( error, uiText.unknownError ), 'bad' );
				} );
		};

		step();
	};

	useEffect( () => {
		const guard = ( event ) => {
			if ( batchRef.current ) {
				event.preventDefault();
				event.returnValue = uiText.batchLeaveWarning || '';
			}
		};

		window.addEventListener( 'beforeunload', guard );

		return () => window.removeEventListener( 'beforeunload', guard );
	}, [ uiText.batchLeaveWarning ] );

	const ranges = modeRange( page, pageSize, base );

	return (
		<Card
			card={ card }
			actions={
				<>
					<button type="button" className="btn btn--primary btn--small" disabled={ frozen } onClick={ onScan }>
						{ s.scanAgain }
					</button>
					<a className="btn btn--ghost btn--small" href={ data.exportUrl }>
						{ s.export }
					</a>
					<button
						type="button"
						className="btn btn--ghost btn--small"
						disabled={ frozen || 0 === Number( view.missing_media ) }
						onClick={ runAdopt }
					>
						{ ( s.adopt || '补建媒体库记录（%d）' ).replace( '%d', String( view.missing_media ) ) }
					</button>
				</>
			}
		>
			<div className="recon-toolbar">
				<div className="filter-chips" role="tablist" aria-label={ ui.filterAria || '差异筛选' }>
					{ Object.keys( s.filters || {} ).map( ( value ) => (
						<button
							key={ value }
							type="button"
							role="tab"
							aria-selected={ value === filter }
							className={ 'filter-chip' + ( value === filter ? ' is-active' : '' ) }
							onClick={ () => applyFilter( value ) }
						>
							{ s.filters[ value ] }
							<span className="filter-chip__count">{ countFor( view, value ) }</span>
						</button>
					) ) }
				</div>
				<div className="recon-tools">
					<label className="recon-search">
						<span className="screen-reader-text">{ s.searchLabel }</span>
						<input
							type="search"
							id="file-search"
							value={ search }
							placeholder={ s.searchPlaceholder }
							onChange={ ( event ) => setSearch( event.target.value ) }
							onKeyDown={ ( event ) => {
								if ( 'Enter' === event.key ) {
									setHistory( [ '' ] );
									loadPage( '', 1, { search: event.currentTarget.value } );
								}
							} }
						/>
					</label>
					<label className="screen-reader-text" htmlFor="type-filter">
						{ s.typeLabel }
					</label>
					<select id="type-filter" value={ type } onChange={ ( event ) => { setType( event.target.value ); setHistory( [ '' ] ); loadPage( '', 1, { type: event.target.value } ); } }>
						<option value="all">{ s.allType }</option>
						<option value="image">{ s.typeImage }</option>
						<option value="video">{ s.typeVideo }</option>
						<option value="archive">{ s.typeArchive }</option>
						<option value="other">{ s.typeOther }</option>
					</select>
					<label className="screen-reader-text" htmlFor="date-filter">
						{ s.dateLabel }
					</label>
					<select id="date-filter" value={ days } onChange={ ( event ) => { setDays( event.target.value ); setHistory( [ '' ] ); loadPage( '', 1, { days: event.target.value } ); } }>
						<option value="all">{ s.allDate }</option>
						<option value="7">{ s.last7 }</option>
						<option value="30">{ s.last30 }</option>
					</select>
				</div>
			</div>
			{ adopt ? (
				<p className="small-text recon-adopt-status" role="status" aria-live="polite">
					{ adopt.text }
				</p>
			) : null }
			{ selectedCount > 0 ? (
				<div className="batch-bar">
					<span className="batch-bar__count">
						<strong>{ selectedCount }</strong> { s.selected } <span className="small-text">{ s.maxSelect }</span>
					</span>
					<div className="button-row">
						<button type="button" className="btn btn--quiet btn--small" disabled={ locked } onClick={ ( event ) => batchUpload( event.currentTarget ) }>
							{ s.batchUpload }
						</button>
						<button type="button" className="btn btn--quiet btn--small" disabled={ locked } onClick={ () => batchTransfer( 'download', selectedKeys( 'download' ), '' ) }>
							{ s.batchDownload }
						</button>
						<button type="button" className="btn btn--danger btn--small" disabled={ locked } onClick={ ( event ) => batchDelete( event.currentTarget ) }>
							{ s.batchDelete }
						</button>
						<button type="button" className="btn btn--quiet btn--small" onClick={ () => setSelection( {} ) }>
							{ s.batchClear }
						</button>
					</div>
				</div>
			) : null }
			{ progress ? (
				<div className="batch-progress">
					<div className="batch-progress__head">
						<span>{ progress.text }</span>
						<span>{ Math.round( ( progress.done / Math.max( 1, progress.total ) ) * 100 ) }%</span>
					</div>
					<div className="progress" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ Math.round( ( progress.done / Math.max( 1, progress.total ) ) * 100 ) }>
						<span className="progress__bar" style={ { width: Math.min( 100, Math.round( ( progress.done / Math.max( 1, progress.total ) ) * 100 ) ) + '%' } } />
					</div>
				</div>
			) : null }
			<div className="table-scroll" aria-busy={ busy ? 'true' : undefined }>
				<table className="table table--responsive" aria-label={ ui.tableAria || '本地与云端文件核对' }>
					<thead>
						<tr>
							<th className="cell-select">
								<input
									type="checkbox"
									aria-label={ s.selectAll }
									checked={ allSelected }
									ref={ ( node ) => {
										if ( node ) {
											node.indeterminate = selectedCount > 0 && ! allSelected;
										}
									} }
									onChange={ toggleAll }
								/>
							</th>
							{ ( s.columns || [] ).map( ( label ) => (
								<th key={ label }>{ label }</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.key } data-status={ row.status } data-type={ row.type }>
								<td className="cell-select" data-label="">
									<input
										type="checkbox"
										className="row-select"
										value={ row.key }
										checked={ Boolean( selection[ row.key ] ) }
										aria-label={ ( ui.selectRowAria || '选择 %s' ).replace( '%s', row.name ) }
										onChange={ () => toggleRow( row.key ) }
									/>
								</td>
								<td data-label={ ( s.columns || [] )[ 0 ] || '文件' }>
									<div className="file-cell">
										<span className="file-cell__name">{ row.name }</span>
										<span className="file-cell__path">{ row.path }</span>
									</div>
								</td>
								<td data-label={ ( s.columns || [] )[ 1 ] || '类型' }>
									<span className="type-pill">{ row.ext }</span>
								</td>
								<td data-label={ ( s.columns || [] )[ 2 ] || '大小' }>{ row.size_label }</td>
								<td data-label={ ( s.columns || [] )[ 3 ] || '本地' }>
									<span className={ 'loc ' + ( row.local ? 'loc--yes' : row.local_removed ? 'loc--warn' : 'loc--no' ) }>
										<span className="loc__dot" aria-hidden="true" />
										{ row.local ? s.exists : row.local_removed ? s.localRemoved || '已按策略删除' : s.missing }
									</span>
								</td>
								<td data-label={ ( s.columns || [] )[ 4 ] || '云端' }>
									<span className={ 'loc ' + ( row.cloud ? ( 'mismatch' === row.status ? 'loc--warn' : 'loc--yes' ) : 'loc--no' ) }>
										<span className="loc__dot" aria-hidden="true" />
										{ row.cloud ? ( 'mismatch' === row.status ? s.diffSize : s.exists ) : s.missing }
									</span>
								</td>
								<td data-label={ ( s.columns || [] )[ 5 ] || '状态' }>
									<Badge tone={ row.status_tone }>{ row.status_label }</Badge>
								</td>
								<td data-label={ ( s.columns || [] )[ 6 ] || '最后变化' }>{ row.date_label }</td>
								<td data-label={ ( s.columns || [] )[ 7 ] || '操作' }>
									<div className="action-cell">
										{ rowActionButton( row, labels, locked, { rowAction, confirmReplace, confirmDelete } ) }
										{ row.cloud ? (
											<button type="button" className="link-btn link-btn--danger" disabled={ locked } onClick={ ( event ) => confirmDelete( row, event.currentTarget ) }>
												{ labels.delete || '删除' }
											</button>
										) : null }
										<button type="button" className="link-btn" onClick={ () => preview( row ) }>
											{ labels.detail || '详情' }
										</button>
									</div>
								</td>
							</tr>
						) ) }
						{ ! rows.length ? (
							<tr className="empty-row">
								<td colSpan={ 9 } data-label="">
									<div className="empty-state">
										<strong className="empty-state__title">{ s.emptyRows }</strong>
									</div>
								</td>
							</tr>
						) : null }
					</tbody>
				</table>
			</div>
			<div className="pagination">
				<div className="pagination__sizes" role="group" aria-label={ ui.sizesAria || '每页显示行数' }>
					<span>{ ui.perPage || '每页' }</span>
					{ ( data.pageSizes || [ 25, 50, 100 ] ).map( ( size ) => (
						<button
							key={ size }
							type="button"
							className={ 'pagination__link' + ( pageSize === Number( size ) ? ' is-current' : '' ) }
							onClick={ () => {
								setPageSize( Number( size ) );
								setHistory( [ '' ] );
								loadPage( '', 1 );
							} }
						>
							{ size }
						</button>
					) ) }
				</div>
				<span className="pagination__summary">
					{ ( summaryTemplate || '显示 %1$s–%2$s 项，共 %3$s 项' )
						.replace( '%1$s', String( start ) )
						.replace( '%2$s', String( end ) )
						.replace( '%3$s', String( base ) ) }
				</span>
				<div className="pagination__pages" aria-label={ ui.pagesAria || '分页' }>
					<button
						type="button"
						className="pagination__link"
						disabled={ page <= 1 }
						onClick={ () => {
							if ( history.length <= 1 ) {
								return;
							}
							const next = history.slice( 0, -1 );
							setHistory( next );
							loadPage( next[ next.length - 1 ] || '', page - 1 );
						} }
					>
						‹
					</button>
					<span className="pagination__link is-current">{ page } / { Math.max( 1, Math.ceil( base / pageSize ) ) }</span>
					<button
						type="button"
						className="pagination__link"
						disabled={ ! hasMore }
						onClick={ () => {
							const next = cursor === history[ history.length - 1 ] ? history : [ ...history, cursor ];
							setHistory( next );
							loadPage( cursor, page + 1 );
						} }
					>
						›
					</button>
				</div>
			</div>

			{ dialog ? (
				<Dialog
					tone={ dialog.tone || 'warn' }
					kicker={ ui.dialogKicker || 'RECONCILIATION' }
					title={ dialog.title }
					message={ dialog.message }
					items={ dialog.items }
					confirmLabel={ dialog.confirmLabel }
					cancelLabel={ dialog.cancelLabel }
					returnFocus={ dialog.returnFocus }
					onConfirm={ () => answer( true ) }
					onClose={ () => answer( false ) }
				>
					{ dialog.children }
				</Dialog>
			) : null }
		</Card>
	);
}

/** One row's primary action, chosen by status the way the classic table did. */
function rowActionButton( row, labels, locked, handlers ) {
	const upload = labels.upload || '上传';
	const reupload = labels.reupload || '重新上传';
	const download = labels.download || '下载';

	if ( 'failed' === row.status ) {
		return row.local ? (
			<button
				type="button"
				className="link-btn"
				disabled={ locked }
				onClick={ ( event ) => ( row.cloud ? handlers.confirmReplace( row, event.currentTarget ) : handlers.rowAction( 'upload', row, '' ) ) }
			>
				{ reupload }
			</button>
		) : (
			<button type="button" className="link-btn" disabled={ locked } onClick={ () => handlers.rowAction( 'download', row, '' ) }>
				{ download }
			</button>
		);
	}

	if ( 'pending' === row.status ) {
		return (
			<button type="button" className="link-btn" disabled={ locked } onClick={ () => handlers.rowAction( 'upload', row, '' ) }>
				{ upload }
			</button>
		);
	}

	if ( 'remote' === row.status ) {
		return (
			<button type="button" className="link-btn" disabled={ locked } onClick={ () => handlers.rowAction( 'download', row, '' ) }>
				{ download }
			</button>
		);
	}

	if ( 'mismatch' === row.status ) {
		return (
			<button type="button" className="link-btn" disabled={ locked } onClick={ ( event ) => handlers.confirmReplace( row, event.currentTarget ) }>
				{ reupload }
			</button>
		);
	}

	return null;
}

function countFor( view, value ) {
	if ( 'all' === value ) {
		return view.total;
	}

	return view[ value ] != null ? view[ value ] : 'failed' === value ? view.failed_count : 0;
}

function modeRange( page, size, base ) {
	const start = base ? ( page - 1 ) * size + 1 : 0;
	const end = Math.min( ( page - 1 ) * size + size, base );

	return { start, end };
}
