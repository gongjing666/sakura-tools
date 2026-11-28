import { useEffect, useRef, useState } from 'react';
import { Card } from '../shared/ui.jsx';
import Pager from '../files/shared/Pager.jsx';
import EmptyState from '../files/shared/EmptyState.jsx';
import { apiGet, apiPost, apiRequest, confirmAction, errorText, fileSize, fill, toast } from '../files/shared/transport.js';
import SanctionDialog from './sanction.jsx';

/**
 * User quota card, embedded in the review workbench.
 *
 * It is the old files-users screen without its page head and card grid: the
 * caller supplies the card metadata and the component only owns list state,
 * quota edits, the capacity sync and the shared sanction dialog.
 */
export default function UsersCard( { card = {}, rest, strings, reloadKey = 0, target = false, canSanction = false, canSync = false } ) {
	const config = rest || {};
	const copy = strings || {};
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
	const syncAlive = useRef( true );
	const sanctionTrigger = useRef( null );

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
				toast( errorText( error, copy.loadFailed ), 'bad' );
			} );

		return () => controller.abort();
	}, [ query.page, query.per, query.search, reload, reloadKey ] );

	useEffect( () => {
		const next = {};
		rows.forEach( ( row ) => {
			next[ row.id ] = String( Math.round( ( Number( row.quota ) || 0 ) / 1048576 ) );
		} );
		setDrafts( next );
	}, [ rows ] );

	useEffect( () => () => {
		syncAlive.current = false;

		if ( syncTimer.current ) {
			window.clearTimeout( syncTimer.current );
			syncTimer.current = 0;
		}
	}, [] );

	const runSearch = () => {
		const value = searchDraft.trim();

		if ( value === query.search && query.page === 1 ) {
			setReload( ( current ) => current + 1 );
			return;
		}

		setQuery( ( current ) => ( { ...current, page: 1, search: value } ) );
	};

	const refresh = () => {
		setQuery( ( current ) => ( { ...current, page: 1 } ) );
		setReload( ( current ) => current + 1 );
	};

	const saveQuota = ( row ) => {
		const raw = String( drafts[ row.id ] ?? '' ).trim();
		let bytes = -1;

		if ( '' !== raw ) {
			if ( ! /^\d+$/.test( raw ) ) {
				toast( copy.quotaInvalid || '', 'warn' );
				return;
			}

			bytes = Math.round( Number( raw ) * 1048576 );

			if ( ! Number.isFinite( bytes ) ) {
				toast( copy.quotaInvalid || '', 'warn' );
				return;
			}
		}

		setSaving( row.id );
		apiRequest( config, 'admin/users/' + encodeURIComponent( row.id ) + '/quota', 'PATCH', { quota_bytes: bytes } )
			.then( () => {
				toast( fill( copy.saved || '', { name: row.name || row.login } ), 'ok' );
				setReload( ( current ) => current + 1 );
			} )
			.catch( ( error ) => toast( errorText( error, copy.saveFailed ), 'bad' ) )
			.finally( () => setSaving( 0 ) );
	};

	const finishSync = ( state ) => {
		if ( syncTimer.current ) {
			window.clearTimeout( syncTimer.current );
			syncTimer.current = 0;
		}

		setSyncing( false );
		setNoteBad( false );
		setNote( fill( copy.syncDone || '', { message: ( state && state.last ) || '' } ) );

		if ( ! syncDone.current ) {
			syncDone.current = true;
			toast( copy.syncDone || '', 'ok' );
		}

		setReload( ( current ) => current + 1 );
	};

	const pollSync = () => {
		syncTimer.current = 0;

		if ( ! syncAlive.current ) {
			return;
		}

		apiGet( config, 'admin/sync', { t: Date.now() } )
			.then( ( state ) => {
				if ( ! syncAlive.current ) {
					return;
				}

				if ( state && state.state === 'running' ) {
					const totalSync = Number( state.total ) || 0;
					const scanned = Number( state.scanned ) || 0;

					setNote( fill( copy.syncProgress || '', {
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
				if ( ! syncAlive.current ) {
					return;
				}

				setSyncing( false );
				setNoteBad( true );
				setNote( errorText( error, copy.syncFailed ) );
				toast( errorText( error, copy.syncFailed ), 'bad' );
			} );
	};

	const startSync = () => {
		if ( syncing || ! canSync ) {
			return;
		}

		syncDone.current = false;
		setSyncing( true );
		setNoteBad( false );
		setNote( copy.syncing || '' );

		apiPost( config, 'admin/sync', {} )
			.then( ( state ) => {
				if ( ! syncAlive.current ) {
					return;
				}

				if ( state && state.state === 'running' ) {
					pollSync();
					return;
				}

				finishSync( state || {} );
			} )
			.catch( ( error ) => {
				if ( ! syncAlive.current ) {
					return;
				}

				setSyncing( false );
				setNoteBad( true );
				setNote( errorText( error, copy.syncStartFailed ) );
				toast( errorText( error, copy.syncStartFailed ), 'bad' );
			} );
	};

	const resetQuotas = () => {
		confirmAction( copy.resetMessage || '', {
			title: copy.resetTitle || '',
			confirmText: copy.resetConfirm || copy.confirm || '',
			cancelText: copy.cancel || '',
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
					const message = fill( copy.resetDone || '', { count } );

					setNoteBad( false );
					setNote( message );
					toast( message, 'ok' );
					refresh();
				} )
				.catch( ( error ) => {
					const message = errorText( error, copy.resetFailed );
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
			<Card
				card={ card }
				className={ target ? 'card--target' : '' }
				actions={
					<>
						<button type="button" className="btn btn--small btn--ghost" disabled={ resetting } onClick={ resetQuotas }>
							{ copy.reset || '' }
						</button>
						<button type="button" className="btn btn--small btn--ghost" disabled={ syncing || ! canSync } onClick={ startSync }>
							{ syncing ? copy.syncing || '' : copy.sync || '' }
						</button>
						<button type="button" className="btn btn--small btn--ghost" onClick={ refresh }>
							{ copy.refresh || '' }
						</button>
					</>
				}
			>
				<div className="toolbar toolbar--filter" role="search">
					<label className="toolbar__field toolbar__field--search">
						<span className="screen-reader-text">{ copy.searchUser || '' }</span>
						<input
							type="search"
							value={ searchDraft }
							placeholder={ copy.searchUser || '' }
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

				<div className="table-scroll" hidden={ ! loading && rows.length === 0 }>
					<table className="table table--responsive sakura-files-table">
						<thead>
							<tr>
								<th scope="col">{ copy.userColumn || '' }</th>
								<th scope="col">{ copy.usageColumn || '' }</th>
								<th scope="col">{ copy.filesColumn || '' }</th>
								<th scope="col">{ copy.quotaColumn || '' }</th>
								<th scope="col" hidden={ ! canSanction }>{ copy.sanctionColumn || '' }</th>
							</tr>
						</thead>
						<tbody>
							{ loading ? (
								<tr className="is-loading">
									<td colSpan={ canSanction ? 5 : 4 } data-label="">{ copy.loading || '' }</td>
								</tr>
							) : rows.map( ( row ) => (
								<tr key={ row.id }>
									<td data-label={ copy.userColumn }>
										<div className="user-cell">
											<strong>{ row.name || row.login || '#' + row.id }</strong>
											<span>{ row.login || '#' + row.id }</span>
										</div>
									</td>
									<td data-label={ copy.usageColumn }>
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
									<td data-label={ copy.filesColumn }>
										<div className="files-cell">
											<div className="files-cell__line">
												<strong>{ Number( row.files ) || 0 }</strong>
												<span>{ copy.filesUnit || '' }</span>
											</div>
											<div className="files-cell__line">
												<span>{ ( Number( row.folders ) || 0 ) + ' ' + ( copy.foldersUnit || '' ) }</span>
											</div>
										</div>
									</td>
									<td data-label={ copy.quotaColumn }>
										<div className="quota-cell">
											<input
												type="number"
												min="0"
												step="1"
												value={ drafts[ row.id ] ?? '' }
												placeholder={ copy.quotaPlaceholder || '' }
												title={ fill( copy.quotaTitle || '', { size: fileSize( row.quota ) } ) }
												aria-label={ fill( copy.quotaTitle || '', { size: fileSize( row.quota ) } ) }
												onChange={ ( event ) => setDrafts( ( current ) => ( { ...current, [ row.id ]: event.target.value } ) ) }
											/>
											<button
												type="button"
												className="btn btn--small btn--ghost"
												disabled={ saving === row.id }
												onClick={ () => saveQuota( row ) }
											>
												{ copy.save || '' }
											</button>
										</div>
									</td>
					<td data-label={ copy.sanctionColumn } hidden={ ! canSanction }>
						<div className="sanction-cell">
							{ row.sanction && row.sanction.badge ? (
								<span className="badge badge--warn" title={ row.sanction.ends || copy.sanctionForever || '' }>
									{ row.sanction.badge }
								</span>
							) : null }
							{ canSanction ? (
								<button
									type="button"
									className="btn btn--small btn--ghost"
									disabled={ false === row.can_sanction }
									title={ false === row.can_sanction ? ( row.id === 0 ? copy.sanctionSelf || '' : copy.sanctionAdmin || '' ) : '' }
									onClick={ ( event ) => {
										sanctionTrigger.current = event.currentTarget;
										setSanctionUser( row );
									} }
								>
									{ copy.sanction || '' }
								</button>
							) : null }
						</div>
					</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>

				<div className="users-mobile">
					{ loading ? <p className="users-mobile__loading">{ copy.loading || '' }</p> : null }
					{ ! loading && rows.map( ( row ) => {
						const percent = usagePercent( row );

						return (
							<article className="users-mobile__card" key={ row.id }>
								<header className="users-mobile__head">
									<div className="users-mobile__identity">
										<strong>{ row.name || row.login || '#' + row.id }</strong>
										<span>{ row.login || '#' + row.id }</span>
									</div>
									<div className="users-mobile__head-actions">
										{ row.sanction && row.sanction.badge ? (
											<span className="badge badge--warn" title={ row.sanction.ends || copy.sanctionForever || '' }>
												{ row.sanction.badge }
											</span>
										) : null }
										{ canSanction ? (
											<button
												type="button"
												className="btn btn--small btn--ghost"
												disabled={ false === row.can_sanction }
												title={ false === row.can_sanction ? ( row.id === 0 ? copy.sanctionSelf || '' : copy.sanctionAdmin || '' ) : '' }
												onClick={ ( event ) => {
													sanctionTrigger.current = event.currentTarget;
													setSanctionUser( row );
												} }
											>
												{ copy.sanction || '' }
											</button>
										) : null }
									</div>
								</header>

								<dl className="users-mobile__stats">
									<div>
										<dt>{ copy.usageColumn || '' }</dt>
										<dd>
											<strong>{ fileSize( row.used ) }</strong>
											<span>{ ' / ' + fileSize( row.quota ) }</span>
										</dd>
										<div className="usage__track users-mobile__track">
											<span className={ percent >= 90 ? 'usage__fill is-high' : 'usage__fill' } style={ { width: percent + '%' } } />
										</div>
									</div>
									<div>
										<dt>{ copy.filesColumn || '' }</dt>
										<dd>
											<strong>{ Number( row.files ) || 0 }</strong>
											<span>{ copy.filesUnit || '' }</span>
											<span>{ ' · ' + ( Number( row.folders ) || 0 ) + ' ' + ( copy.foldersUnit || '' ) }</span>
										</dd>
									</div>
								</dl>

								<div className="users-mobile__quota">
									<label htmlFor={ 'users-mobile-quota-' + row.id }>{ copy.quotaColumn || '' }</label>
									<div className="users-mobile__quota-row">
										<input
											id={ 'users-mobile-quota-' + row.id }
											type="number"
											min="0"
											step="1"
											value={ drafts[ row.id ] ?? '' }
											placeholder={ copy.quotaPlaceholder || '' }
											title={ fill( copy.quotaTitle || '', { size: fileSize( row.quota ) } ) }
											aria-label={ fill( copy.quotaTitle || '', { size: fileSize( row.quota ) } ) }
											onChange={ ( event ) => setDrafts( ( current ) => ( { ...current, [ row.id ]: event.target.value } ) ) }
										/>
										<button type="button" className="btn btn--small btn--ghost" disabled={ saving === row.id } onClick={ () => saveQuota( row ) }>
											{ copy.save || '' }
										</button>
									</div>
								</div>
							</article>
						);
					} ) }
				</div>

				<p className={ noteBad ? 'live-note is-error' : 'live-note' } aria-live="polite">{ note }</p>

				<div className="page-empty" hidden={ loading || rows.length > 0 }>
					<EmptyState title={ copy.emptyTitle || '' } message={ copy.emptyMessage || '' } />
				</div>

				<Pager
					page={ query.page }
					per={ query.per }
					total={ total }
					sizes={ [ 25, 50, 100 ] }
					strings={ copy }
					onChange={ ( page, per ) => setQuery( ( current ) => ( { ...current, page, per } ) ) }
				/>
			</Card>

			{ sanctionUser ? (
				<SanctionDialog
					user={ sanctionUser }
					rest={ config }
					strings={ copy }
					returnFocus={ sanctionTrigger }
					onClose={ () => setSanctionUser( null ) }
					onSaved={ () => setReload( ( current ) => current + 1 ) }
				/>
			) : null }
		</>
	);
}
