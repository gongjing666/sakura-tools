import { useEffect, useRef, useState } from 'react';
import { Card, PageHead } from '../shared/ui.jsx';
import Pager from '../files/shared/Pager.jsx';
import EmptyState from '../files/shared/EmptyState.jsx';
import { apiGet, apiPost, apiRequest, confirmAction, errorText, fileSize, fill, toast } from '../files/shared/transport.js';
import SanctionDialog from './sanction.jsx';

/**
 * User quota table. Every mutation still goes through the existing REST
 * endpoints; this component only owns list state and the two legacy dialogs.
 */
export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = config.strings || {};
	const card = ( boot.cards || [] )[ 0 ] || { id: 'users', title: '', description: '' };
	const [ query, setQuery ] = useState( { page: 1, per: 25, search: '' } );
	const [ searchDraft, setSearchDraft ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ reload, setReload ] = useState( 0 );
	const [ drafts, setDrafts ] = useState( {} );
	const [ saving, setSaving ] = useState( 0 );
	const [ note, setNote ] = useState( '' );
	const [ noteBad, setNoteBad ] = useState( false );
	const [ sanctionUser, setSanctionUser ] = useState( null );
	const [ syncing, setSyncing ] = useState( false );
	const [ resetting, setResetting ] = useState( false );
	const syncTimer = useRef( 0 );
	const syncDone = useRef( false );

	useEffect( () => {
		const controller = new AbortController();

		setLoading( true );
		apiGet( config, 'admin/users', {
			search: query.search,
			page: query.page,
			per_page: query.per,
		}, controller.signal )
			.then( ( data ) => {
				setRows( Array.isArray( data.rows ) ? data.rows : [] );
				setTotal( Number( data.total ) || 0 );
				setLoading( false );
			} )
			.catch( ( error ) => {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				setRows( [] );
				setTotal( 0 );
				setLoading( false );
				toast( errorText( error, strings.loadFailed ), 'bad' );
			} );

		return () => controller.abort();
	}, [ query.page, query.per, query.search, reload ] );

	useEffect( () => {
		const next = {};
		rows.forEach( ( row ) => {
			next[ row.id ] = String( Math.round( ( Number( row.quota ) || 0 ) / 1048576 ) );
		} );
		setDrafts( next );
	}, [ rows ] );

	useEffect( () => () => {
		if ( syncTimer.current ) {
			window.clearTimeout( syncTimer.current );
		}
	}, [] );

	const load = ( page = query.page ) => {
		setQuery( ( current ) => ( { ...current, page } ) );
	};

	const runSearch = () => {
		const value = searchDraft.trim();

		if ( value === query.search && query.page === 1 ) {
			setReload( ( value ) => value + 1 );
			return;
		}

		setQuery( ( current ) => ( { ...current, page: 1, search: value } ) );
	};

	const refresh = () => {
		setQuery( ( current ) => ( { ...current, page: 1 } ) );
		setReload( ( value ) => value + 1 );
	};

	const saveQuota = ( row ) => {
		const raw = String( drafts[ row.id ] ?? '' ).trim();
		let bytes = -1;

		if ( '' !== raw ) {
			if ( ! /^\d+$/.test( raw ) ) {
				toast( strings.quotaInvalid || '', 'warn' );
				return;
			}

			bytes = Math.round( Number( raw ) * 1048576 );

			if ( ! Number.isFinite( bytes ) ) {
				toast( strings.quotaInvalid || '', 'warn' );
				return;
			}
		}

		setSaving( row.id );
		apiRequest( config, 'admin/users/' + encodeURIComponent( row.id ) + '/quota', 'PATCH', { quota_bytes: bytes } )
			.then( () => {
				toast( fill( strings.saved || '', { name: row.name || row.login } ), 'ok' );
				setReload( ( value ) => value + 1 );
			} )
			.catch( ( error ) => toast( errorText( error, strings.saveFailed ), 'bad' ) )
			.finally( () => setSaving( 0 ) );
	};

	const finishSync = ( state ) => {
		if ( syncTimer.current ) {
			window.clearTimeout( syncTimer.current );
			syncTimer.current = 0;
		}

		setSyncing( false );
		setNoteBad( false );
		setNote( fill( strings.syncDone || '', { message: ( state && state.last ) || '' } ) );

		if ( ! syncDone.current ) {
			syncDone.current = true;
			toast( strings.syncDone || '', 'ok' );
		}

		setReload( ( value ) => value + 1 );
	};

	const pollSync = () => {
		syncTimer.current = 0;
		apiGet( config, 'admin/sync', { t: Date.now() } )
			.then( ( state ) => {
				if ( state && state.state === 'running' ) {
					const totalSync = Number( state.total ) || 0;
					const scanned = Number( state.scanned ) || 0;

					setNote( fill( strings.syncProgress || '', {
						scanned,
						total: totalSync,
						percent: totalSync ? Math.round( scanned / totalSync * 100 ) : 0,
						updated: Number( state.updated ) || 0,
					} ) );
					syncTimer.current = window.setTimeout( pollSync, 1200 );
					return;
				}

				finishSync( state || {} );
			} )
			.catch( ( error ) => {
				setSyncing( false );
				setNoteBad( true );
				setNote( errorText( error, strings.syncFailed ) );
				toast( errorText( error, strings.syncFailed ), 'bad' );
			} );
	};

	const startSync = () => {
		if ( syncing ) {
			return;
		}

		syncDone.current = false;
		setSyncing( true );
		setNoteBad( false );
		setNote( strings.syncing || '' );

		apiPost( config, 'admin/sync', {} )
			.then( ( state ) => {
				if ( state && state.state === 'running' ) {
					pollSync();
					return;
				}

				finishSync( state || {} );
			} )
			.catch( ( error ) => {
				setSyncing( false );
				setNoteBad( true );
				setNote( errorText( error, strings.syncStartFailed ) );
				toast( errorText( error, strings.syncStartFailed ), 'bad' );
			} );
	};

	const resetQuotas = () => {
		confirmAction( strings.resetMessage || '', {
			title: strings.resetTitle || '',
			confirmText: strings.resetConfirm || strings.confirm || '',
			cancelText: strings.cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			setResetting( true );
			apiPost( config, 'admin/reset-quotas', {} )
				.then( ( data ) => {
					const count = Number( data && data.reset ) || 0;
					const message = fill( strings.resetDone || '', { count } );

					setNoteBad( false );
					setNote( message );
					toast( message, 'ok' );
					refresh();
				} )
				.catch( ( error ) => {
					const message = errorText( error, strings.resetFailed );
					setNoteBad( true );
					setNote( message );
					toast( message, 'bad' );
				} )
				.finally( () => setResetting( false ) );
		} );
	};

	const usagePercent = ( row ) => {
		const quota = Number( row.quota ) || 0;
		const used = Number( row.used ) || 0;

		return quota > 0 ? Math.min( 100, Math.round( used / quota * 100 ) ) : 0;
	};

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout="files-users" data-sakura-group="main">
				<Card
					card={ card }
					actions={
						<>
							<button type="button" className="btn btn--small btn--ghost" disabled={ resetting } onClick={ resetQuotas }>
								{ strings.reset || '' }
							</button>
							<button type="button" className="btn btn--small btn--ghost" disabled={ syncing } onClick={ startSync }>
								{ syncing ? strings.syncing || '' : strings.sync || '' }
							</button>
							<button type="button" className="btn btn--small btn--ghost" onClick={ refresh }>
								{ strings.refresh || '' }
							</button>
						</>
					}
				>
					<div className="toolbar toolbar--filter" role="search">
						<label className="toolbar__field toolbar__field--search">
							<span className="screen-reader-text">{ strings.searchUser || '' }</span>
							<input
								type="search"
								value={ searchDraft }
								placeholder={ strings.searchUser || '' }
								onChange={ ( event ) => setSearchDraft( event.target.value ) }
								onKeyDown={ ( event ) => {
									if ( event.key === 'Enter' ) {
										event.preventDefault();
										runSearch();
									}
								} }
							/>
						</label>
					</div>

					<div className="table-scroll" hidden={ !loading && rows.length === 0 }>
						<table className="table table--responsive sakura-files-table">
							<thead>
								<tr>
									<th scope="col">{ strings.userColumn }</th>
									<th scope="col">{ strings.usageColumn }</th>
									<th scope="col">{ strings.filesColumn }</th>
									<th scope="col">{ strings.quotaColumn }</th>
									<th scope="col">{ strings.sanctionColumn }</th>
								</tr>
							</thead>
							<tbody>
								{ loading ? (
									<tr className="is-loading">
										<td colSpan={ 5 } data-label="">{ strings.loading || '' }</td>
									</tr>
								) : rows.map( ( row ) => (
									<tr key={ row.id }>
										<td data-label={ strings.userColumn }>
											<div className="user-cell" title={ row.email || undefined }>
												<strong>{ row.name || row.login || '#' + row.id }</strong>
												<span>{ row.login || row.email || '#' + row.id }</span>
											</div>
										</td>
										<td data-label={ strings.usageColumn }>
											<div className="usage">
												<div className="usage__line">
													<strong>{ fileSize( row.used ) }</strong>
													<span>{ ' / ' + fileSize( row.quota ) }</span>
												</div>
												<div className="usage__track">
													<span
														className={ usagePercent( row ) >= 90 ? 'usage__fill is-high' : 'usage__fill' }
														style={ { width: usagePercent( row ) + '%' } }
													/>
												</div>
											</div>
										</td>
										<td data-label={ strings.filesColumn }>
											<div className="files-cell">
												<div className="files-cell__line">
													<strong>{ Number( row.files ) || 0 }</strong>
													<span>{ strings.filesUnit || '' }</span>
												</div>
												<div className="files-cell__line">
													<span>{ ( Number( row.folders ) || 0 ) + ' ' + ( strings.foldersUnit || '' ) }</span>
												</div>
											</div>
										</td>
										<td data-label={ strings.quotaColumn }>
											<div className="quota-cell">
												<input
													type="number"
													min="0"
													step="1"
													value={ drafts[ row.id ] ?? '' }
													placeholder={ strings.quotaPlaceholder || '' }
													title={ fill( strings.quotaTitle || '', { size: fileSize( row.quota ) } ) }
													aria-label={ fill( strings.quotaTitle || '', { size: fileSize( row.quota ) } ) }
													onChange={ ( event ) => setDrafts( ( current ) => ( { ...current, [ row.id ]: event.target.value } ) ) }
												/>
												<button
													type="button"
													className="btn btn--small btn--ghost"
													disabled={ saving === row.id }
													onClick={ () => saveQuota( row ) }
												>
													{ strings.save || '' }
												</button>
											</div>
										</td>
										<td data-label={ strings.sanctionColumn }>
											<div className="sanction-cell">
												{ row.sanction && row.sanction.badge ? (
													<span className="badge badge--warn" title={ row.sanction.ends || strings.sanctionForever || '' }>
														{ row.sanction.badge }
													</span>
												) : null }
												<button
													type="button"
													className="btn btn--small btn--ghost"
													disabled={ false === row.can_sanction }
													title={ false === row.can_sanction ? ( row.id === 0 ? strings.sanctionSelf || '' : strings.sanctionAdmin || '' ) : '' }
													onClick={ () => setSanctionUser( row ) }
												>
													{ strings.sanction || '' }
												</button>
											</div>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					<p className={ noteBad ? 'live-note is-error' : 'live-note' } aria-live="polite">{ note }</p>

					<div className="page-empty" hidden={ loading || rows.length > 0 }>
						<EmptyState title={ strings.emptyTitle || '' } message={ strings.emptyMessage || '' } />
					</div>

					<Pager
						page={ query.page }
						per={ query.per }
						total={ total }
						sizes={ [ 25, 50, 100 ] }
						strings={ strings }
						onChange={ ( page, per ) => setQuery( ( current ) => ( { ...current, page, per } ) ) }
					/>
				</Card>
			</div>

			{ sanctionUser ? (
				<SanctionDialog
					user={ sanctionUser }
					config={ config }
					onClose={ () => setSanctionUser( null ) }
					onSaved={ () => setReload( ( value ) => value + 1 ) }
				/>
			) : null }
		</>
	);
}
