import { useEffect, useRef, useState } from 'react';
import { Card, PageHead } from '../shared/ui.jsx';
import Pager from '../files/shared/Pager.jsx';
import EmptyState from '../files/shared/EmptyState.jsx';
import { apiGet, apiPost, confirmAction, errorText, fileSize, fill, toast } from '../files/shared/transport.js';
import CapsMatrix from './caps.jsx';
import RequestDrawer from './drawer.jsx';
import UsersCard from './users.jsx';
import { statusText, statusTone } from './status.js';

/**
 * Review and disposal workbench: request queue, approved execution, user
 * capacity and the capability matrix on one page.
 *
 * The queue card keeps the original list/filter/review logic; every row also
 * opens a right-hand detail drawer. The drawer only reads through the existing
 * REST endpoints, so a failed user lookup never blocks a decision.
 */
export default function App( { boot } ) {
	const config = boot.rest || {};
	const strings = boot.strings || {};
	const data = boot.data || {};
	const permissions = data.permissions || {};
	const canExecute = permissions.execute === true;
	const canManageUsers = permissions.users === true;
	const canSync = permissions.sync === true;
	const canSanction = permissions.sanction === true;
	const cards = boot.cards || [];
	const queueCard = cards.find( ( card ) => 'queue' === card.id ) || {};
	const executeCard = cards.find( ( card ) => 'execute' === card.id ) || {};
	const usersCard = cards.find( ( card ) => 'users' === card.id ) || {};
	const capsCard = cards.find( ( card ) => 'capability-matrix' === card.id ) || {};
	const [ head, setHead ] = useState( boot.head );
	const [ query, setQuery ] = useState( { page: 1, per: 20, status: '', search: '' } );
	const [ searchDraft, setSearchDraft ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ reload, setReload ] = useState( 0 );
	const [ approved, setApproved ] = useState( [] );
	const [ approvedReload, setApprovedReload ] = useState( 0 );
	const [ approvedQuery, setApprovedQuery ] = useState( { page: 1, per: 20 } );
	const [ approvedTotal, setApprovedTotal ] = useState( 0 );
	const [ selected, setSelected ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ phrase, setPhrase ] = useState( '' );
	const [ batch, setBatch ] = useState( null );
	const [ batchError, setBatchError ] = useState( false );
	const [ busyKeys, setBusyKeys ] = useState( {} );
	const [ detail, setDetail ] = useState( null );
	const [ usersReload, setUsersReload ] = useState( 0 );
	const [ viewUsers ] = useState( () => {
		try {
			return 'users' === new URLSearchParams( window.location.search ).get( 'view' );
		} catch ( error ) {
			return false;
		}
	} );
	const [ approvedReady, setApprovedReady ] = useState( false );
	const batchTimer = useRef( 0 );
	const batchAlive = useRef( true );
	const batchFailures = useRef( 0 );
	const detailTrigger = useRef( null );

	const markBusy = ( key, active ) => setBusyKeys( ( current ) => {
		if ( active ) {
			return current[ key ] ? current : { ...current, [ key ]: true };
		}

		if ( ! current[ key ] ) {
			return current;
		}

		const next = { ...current };
		delete next[ key ];
		return next;
	} );
	const busyWith = ( prefix ) => Object.keys( busyKeys ).some( ( key ) => 0 === key.indexOf( prefix ) );
	const busyIs = ( key ) => Boolean( busyKeys[ key ] );

	const scrollToCard = ( id, behavior ) => {
		const selector = '.sakura-admin [data-sakura-card="' + id + '"]';
		const node = document.querySelector( selector ) || document.querySelector( '[data-sakura-card="' + id + '"]' );

		if ( ! node ) {
			return;
		}

		const reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		const mode = 'auto' === behavior || reduce ? 'auto' : 'smooth';

		node.scrollIntoView( { behavior: mode, block: 'start' } );
	};

	useEffect( () => {
		const controller = new AbortController();

		setLoading( true );
		apiGet( config, 'admin/delete-requests', {
			page: query.page,
			per_page: query.per,
			status: query.status,
			search: query.search,
		}, controller.signal )
			.then( ( data ) => {
				const nextCounts = data.counts || {};

				setRows( Array.isArray( data.rows ) ? data.rows : [] );
				setTotal( Number( data.total ) || 0 );
				setLoading( false );
				setHead( ( current ) => ( {
					...current,
					status: {
						...current.status,
						value: String( Number( nextCounts.pending_review ) || 0 ),
						sub: fill( strings.countSub || '', {
							approved: Number( nextCounts.approved ) || 0,
							queued: Number( nextCounts.queued ) || 0,
						} ),
					},
				} ) );
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
	}, [ query.page, query.per, query.status, query.search, reload ] );

	useEffect( () => {
		if ( ! canExecute ) {
			return undefined;
		}

		const controller = new AbortController();

		apiGet( config, 'admin/delete-requests', { status: 'approved', unbatched: 1, page: approvedQuery.page, per_page: approvedQuery.per }, controller.signal )
			.then( ( data ) => {
				setApproved( data.rows || [] );
				setApprovedTotal( Number( data.total ) || 0 );
				setPreview( null );
			} )
			.catch( ( error ) => {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				setApproved( [] );
				setApprovedTotal( 0 );
				toast( errorText( error, strings.approvedLoadFailed ), 'bad' );
			} )
			.finally( () => setApprovedReady( true ) );

		return () => controller.abort();
	}, [ approvedReload, approvedQuery.page, approvedQuery.per, canExecute ] );

	useEffect( () => () => {
		batchAlive.current = false;

		if ( batchTimer.current ) {
			window.clearTimeout( batchTimer.current );
			batchTimer.current = 0;
		}
	}, [] );

	const refreshQueue = () => {
		setQuery( ( current ) => ( { ...current, page: 1 } ) );
		setReload( ( value ) => value + 1 );
	};

	const runSearch = () => {
		const value = searchDraft.trim();

		setQuery( ( current ) => ( { ...current, page: 1, search: value } ) );
	};

	const refreshApproved = () => setApprovedReload( ( value ) => value + 1 );

	const toggleSelected = ( id ) => {
		const key = Number( id ) || 0;

		setPreview( null );
		setSelected( ( current ) =>
			current.includes( key ) ? current.filter( ( value ) => value !== key ) : [ ...current, key ]
		);
	};

	const togglePage = () => {
		const ids = approved.map( ( row ) => Number( row.id ) );
		const all = ids.length > 0 && ids.every( ( id ) => selected.includes( id ) );

		setPreview( null );
		setSelected( ( current ) => ( all
			? current.filter( ( value ) => ! ids.includes( value ) )
			: Array.from( new Set( [ ...current, ...ids ] ) ) ) );
	};

	const openDetail = ( row, trigger ) => {
		detailTrigger.current = trigger || null;
		setDetail( row );
	};

	const closeDetail = () => {
		const trigger = detailTrigger.current;

		detailTrigger.current = null;
		setDetail( null );

		if ( trigger && document.contains( trigger ) ) {
			window.setTimeout( () => trigger.focus( { preventScroll: true } ), 30 );
		}
	};

	const review = ( row, decision ) => {
		const values = { id: row.id };
		const titles = { approve: strings.approveTitle, reject: strings.rejectTitle };
		const messages = { approve: strings.approveMessage, reject: strings.rejectMessage };

		confirmAction( fill( messages[ decision ] || '', values ), {
			title: fill( titles[ decision ] || '', values ),
			confirmText: decision === 'approve' ? ( strings.approve || strings.confirm || '' ) : ( strings.confirm || '' ),
			cancelText: strings.cancel || '',
			danger: decision === 'reject',
			tone: decision === 'reject' ? 'danger' : 'neutral',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			const key = 'review:' + row.id + ':' + decision;
			markBusy( key, true );
			apiPost( config, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/review', { decision } )
				.then( () => {
					toast( fill( strings.reviewDone || '', values ), 'ok' );
					setReload( ( value ) => value + 1 );
					refreshApproved();

					if ( detail && Number( detail.id ) === Number( row.id ) ) {
						closeDetail();
					}
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => markBusy( key, false ) );
		} );
	};

	const revoke = ( row ) => {
		const values = { id: row.id };

		confirmAction( fill( strings.revokeMessage || '', values ), {
			title: fill( strings.revokeTitle || '', values ),
			confirmText: strings.revoke || strings.confirm || '',
			cancelText: strings.cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			const key = 'revoke:' + row.id;
			markBusy( key, true );
			apiPost( config, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/revoke', { reason: '' } )
				.then( () => {
					toast( fill( strings.revokeDone || '', values ), 'ok' );
					setReload( ( value ) => value + 1 );
					refreshApproved();
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => markBusy( key, false ) );
		} );
	};

	const stopBatch = ( batchId, buttonKey ) => {
		const values = { id: batchId };

		confirmAction( fill( strings.stopMessage || '', values ), {
			title: fill( strings.stopTitle || '', values ),
			confirmText: strings.stopBatch || strings.confirm || '',
			cancelText: strings.cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			const key = buttonKey || 'stop:' + batchId;
			markBusy( key, true );
			apiPost( config, 'admin/delete-batches/' + encodeURIComponent( batchId ) + '/stop', {} )
				.then( () => {
					toast( fill( strings.stopDone || '', values ), 'warn' );
					beginBatchPoll( batchId );
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => markBusy( key, false ) );
		} );
	};

	const openFiles = ( row, button ) => {
		const admin = window.SakuraAdmin;

		if ( ! admin || ! admin.ui || typeof admin.ui.mediaPreview !== 'function' ) {
			toast( strings.failed || '', 'bad' );
			return;
		}

		const key = 'files:' + row.id;
		markBusy( key, true );
		apiGet( config, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/items', {} )
			.then( ( data ) => {
				const items = data && Array.isArray( data.items ) ? data.items : [];

				if ( ! items.length ) {
					toast( strings.filesEmpty || '', 'warn' );
					return;
				}

				admin.ui.mediaPreview( items[ 0 ], {
					items,
					trigger: button,
					labels: {
						prev: strings.galleryPrev || '',
						next: strings.galleryNext || '',
						counter: strings.galleryCounter || '',
						gallery: strings.galleryLabel || '',
					},
				} );
			} )
			.catch( ( error ) => toast( errorText( error, strings.filesLoadFailed ), 'bad' ) )
			.finally( () => markBusy( key, false ) );
	};

	const previewScope = () => {
		if ( ! selected.length ) {
			toast( strings.previewNeed || '', 'warn' );
			return;
		}

		setPreviewing( true );
		apiPost( config, 'admin/delete-batches/preview', { requests: selected } )
			.then( ( data ) => setPreview( {
				hash: data.hash || '',
				items: Number( data.items ) || 0,
				bytes: Number( data.bytes ) || 0,
				requests: Array.isArray( data.requests ) ? data.requests : [],
			} ) )
			.catch( ( error ) => toast( errorText( error, strings.previewFailed ), 'bad' ) )
			.finally( () => setPreviewing( false ) );
	};

	const execute = () => {
		if ( phrase.trim() !== ( strings.phraseValue || '删除' ) ) {
			toast( strings.phraseWrong || '', 'warn' );
			return;
		}

		if ( ! selected.length || ! preview || ! preview.hash ) {
			toast( strings.previewNeed || '', 'warn' );
			return;
		}

		confirmAction( fill( strings.executeMessage || '', { count: preview.items } ), {
			title: strings.executeTitle || '',
			confirmText: strings.executeConfirm || strings.confirm || '',
			cancelText: strings.cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			markBusy( 'execute', true );
			apiPost( config, 'admin/delete-batches', { requests: selected, phrase: phrase.trim(), hash: preview.hash } )
				.then( ( data ) => {
					const batchId = Number( data.batch_id ) || 0;

					setPhrase( '' );
					setPreview( null );
					setSelected( [] );
					toast( fill( strings.batchCreated || '', { id: batchId } ), 'ok' );
					refreshApproved();
					setReload( ( value ) => value + 1 );

					if ( batchId ) {
						beginBatchPoll( batchId );
					}
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => markBusy( 'execute', false ) );
		} );
	};

	const beginBatchPoll = ( batchId ) => {
		batchFailures.current = 0;
		setBatchError( false );
		pollBatch( batchId );
	};

	const pollBatch = ( batchId ) => {
		if ( batchTimer.current ) {
			window.clearTimeout( batchTimer.current );
			batchTimer.current = 0;
		}

		if ( ! batchAlive.current ) {
			return;
		}

		apiGet( config, 'admin/delete-batches/' + encodeURIComponent( batchId ), {} )
			.then( ( data ) => {
				if ( ! batchAlive.current ) {
					return;
				}

				const next = data.batch || {};
				const running = next.state === 'queued' || next.state === 'executing';

				batchFailures.current = 0;
				setBatch( next );

				if ( running ) {
					batchTimer.current = window.setTimeout( () => pollBatch( batchId ), 4000 );
					return;
				}

				toast( fill( strings.batchFinished || '', { id: next.id || batchId } ), 'ok' );
				setReload( ( value ) => value + 1 );
			} )
			.catch( ( error ) => {
				if ( ! batchAlive.current ) {
					return;
				}

				batchFailures.current += 1;

				if ( batchFailures.current >= 6 ) {
					setBatchError( true );
					toast( strings.batchPollFailed || errorText( error ), 'bad' );
					return;
				}

				toast( errorText( error ), 'bad' );
				batchTimer.current = window.setTimeout( () => pollBatch( batchId ), 6000 );
			} );
	};

	const addToExecute = ( row ) => {
		const id = Number( row.id ) || 0;

		setPreview( null );
		setApproved( ( current ) => current.some( ( item ) => Number( item.id ) === id ) ? current : [ ...current, row ] );
		setSelected( ( current ) => current.some( ( value ) => Number( value ) === id ) ? current : [ ...current, id ] );
		toast( fill( strings.addedToExecute || '', { id: row.id } ), 'ok' );
		closeDetail();
		window.setTimeout( () => scrollToCard( 'execute' ), 120 );
	};

	const handleSanctionSaved = () => setUsersReload( ( value ) => value + 1 );

	const statusLabel = ( status ) => statusText( strings, status );
	const ownerName = ( row ) => row.owner_name || fill( strings.ownerId || '', { id: row.owner_id } );
	const selectedCount = selected.length;
	const batchRunning = batch && ( batch.state === 'queued' || batch.state === 'executing' );

	const detailButton = ( row ) => (
		<button
			type="button"
			className="btn btn--small btn--ghost"
			onClick={ ( event ) => openDetail( row, event.currentTarget ) }
		>
			{ strings.detail || '' }
		</button>
	);

	const actionCell = ( row ) => {
		const status = row.status;
		const batchId = Number( row.batch_id ) || 0;

		if ( status === 'pending_review' || status === 'needs_review' ) {
			return (
				<div className="queue-actions">
					{ detailButton( row ) }
					<button type="button" className="btn btn--small btn--primary" disabled={ busyWith( 'review:' + row.id + ':' ) } onClick={ () => review( row, 'approve' ) }>
						{ strings.approve || '' }
					</button>
					<button type="button" className="btn btn--small btn--ghost" disabled={ busyWith( 'review:' + row.id + ':' ) } onClick={ () => review( row, 'reject' ) }>
						{ strings.reject || '' }
					</button>
					{ Number( row.item_count ) > 0 ? (
						<button type="button" className="btn btn--small btn--ghost" disabled={ busyIs( 'files:' + row.id ) } onClick={ ( event ) => openFiles( row, event.currentTarget ) }>
							{ strings.viewFiles || '' }
						</button>
					) : null }
				</div>
			);
		}

		if ( status === 'approved' ) {
			return (
				<div className="queue-actions">
					{ detailButton( row ) }
					<button type="button" className="btn btn--small btn--danger" disabled={ busyIs( 'revoke:' + row.id ) } onClick={ () => revoke( row ) }>
						{ strings.revoke || '' }
					</button>
				</div>
			);
		}

		if ( ( status === 'queued' || status === 'executing' ) && batchId ) {
			return (
				<div className="queue-actions">
					{ detailButton( row ) }
					<button type="button" className="btn btn--small btn--danger" disabled={ busyIs( 'stop:' + batchId ) } onClick={ () => stopBatch( batchId, 'stop:' + batchId ) }>
						{ strings.stopBatch || '' }
					</button>
				</div>
			);
		}

		return (
			<div className="queue-actions">
				{ detailButton( row ) }
				<span className="text-muted">{ strings.noActions || '—' }</span>
			</div>
		);
	};

	return (
		<>
			<PageHead head={ head } />
			{ viewUsers && canManageUsers ? (
				<p className="view-users-jump">
					<button type="button" className="btn btn--small btn--ghost" onClick={ () => scrollToCard( 'users' ) }>
						{ strings.viewUsersJump || '' }
					</button>
				</p>
			) : null }
			<div className="card-grid" data-sakura-layout="files-queue" data-sakura-group="main">
				<Card card={ queueCard }>
					<div className="toolbar" role="search">
						<label className="toolbar__field">
							<span className="screen-reader-text">{ strings.statusLabel || '' }</span>
							<select
								value={ query.status }
								onChange={ ( event ) => setQuery( ( current ) => ( { ...current, page: 1, status: event.target.value } ) ) }
							>
								{ ( data.queueStatuses || [] ).map( ( option ) => (
									<option key={ option.value } value={ option.value }>{ option.label }</option>
								) ) }
							</select>
						</label>
						<label className="toolbar__field toolbar__field--grow">
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
						<button type="button" className="btn btn--small btn--ghost" onClick={ refreshQueue }>
							{ strings.refresh || '' }
						</button>
					</div>

					<div className="table-scroll" hidden={ ! loading && rows.length === 0 }>
						<table className="table table--responsive sakura-files-table">
							<thead>
								<tr>
									<th scope="col">{ strings.idLabel }</th>
									<th scope="col">{ strings.userLabel }</th>
									<th scope="col">{ strings.objectsLabel }</th>
									<th scope="col">{ strings.statusLabel }</th>
									<th scope="col">{ strings.timeLabel }</th>
									<th scope="col">{ strings.actionsLabel }</th>
								</tr>
							</thead>
							<tbody>
								{ loading ? (
									<tr className="is-loading">
										<td colSpan={ 6 } data-label="">{ strings.loading || '' }</td>
									</tr>
								) : rows.map( ( row ) => (
									<tr key={ row.id }>
										<td className="queue-id" data-label={ strings.idLabel }>{ '#' + row.id }</td>
										<td data-label={ strings.userLabel }>
											<div className="queue-user">
												<strong>{ ownerName( row ) }</strong>
												<span className="queue-user__id">{ fill( strings.ownerId || '', { id: row.owner_id } ) }</span>
											</div>
										</td>
										<td data-label={ strings.objectsLabel }>
											<div className="queue-object">
												<span>{ fill( strings.items || '', { count: row.item_count || 0 } ) }</span>
												<span>{ fileSize( row.bytes ) }</span>
											</div>
										</td>
										<td data-label={ strings.statusLabel }>
											<span className={ 'badge badge--' + statusTone( row.status ) }>{ statusLabel( row.status ) }</span>
										</td>
										<td className="text-muted" data-label={ strings.timeLabel }>{ row.created_at || '' }</td>
										<td className="queue-cell" data-label={ strings.actionsLabel }>{ actionCell( row ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					<div className="page-empty" hidden={ loading || rows.length > 0 }>
						<EmptyState title={ strings.emptyTitle || '' } message={ strings.emptyMessage || '' } />
					</div>

					<Pager
						page={ query.page }
						per={ query.per }
						total={ total }
						sizes={ [ 20, 30, 50 ] }
						strings={ strings }
						onChange={ ( page, per ) => setQuery( ( current ) => ( { ...current, page, per } ) ) }
					/>
				</Card>

				{ canExecute && executeCard.id ? (
					<Card card={ executeCard }>
					<div className="exec-steps">
						<section className="exec-step">
							<header className="exec-step__head">
								<span className="exec-step__no" aria-hidden="true">1</span>
								<div className="exec-step__copy">
									<h3>{ strings.pickTitle || '' }</h3>
									<p>{ strings.pickHint || '' }</p>
								</div>
								<span className="exec-step__meta">
									{ selectedCount ? fill( strings.pickCount || '', { count: selectedCount } ) + ' / ' + approvedTotal : ( strings.pickNone || '' ) }
								</span>
							</header>
							<div className="approved-list">
								{ approved.length ? (
									<label className="approved-item approved-item--all">
										<input
											type="checkbox"
											checked={ approved.every( ( row ) => selected.includes( Number( row.id ) ) ) }
											onChange={ togglePage }
										/>
										<span className="approved-item__label">{ strings.selectPage || '' }</span>
									</label>
								) : null }
								{ approved.length ? approved.map( ( row ) => (
									<label className="approved-item" key={ row.id }>
										<input
											type="checkbox"
											value={ row.id }
											checked={ selected.includes( Number( row.id ) ) }
											onChange={ () => toggleSelected( row.id ) }
										/>
										<span className="approved-item__label">{ '#' + row.id + ' · ' + ownerName( row ) }</span>
										<span className="approved-item__meta">{ fill( strings.items || '', { count: row.item_count || 0 } ) + ' · ' + fileSize( row.bytes ) }</span>
									</label>
								) ) : (
									<p className="text-muted">{ strings.approvedEmpty || '' }</p>
								) }
							</div>
							<Pager
								page={ approvedQuery.page }
								per={ approvedQuery.per }
								total={ approvedTotal }
								sizes={ [ 20, 50 ] }
								strings={ strings }
								onChange={ ( page, per ) => setApprovedQuery( { page, per } ) }
							/>
						</section>

						<section className={ 'exec-step' + ( selectedCount ? '' : ' is-locked' ) }>
							<header className="exec-step__head">
								<span className="exec-step__no" aria-hidden="true">2</span>
								<div className="exec-step__copy">
									<h3>{ strings.previewStepTitle || '' }</h3>
									<p>{ strings.previewStepHint || '' }</p>
								</div>
								<button type="button" className="btn btn--small btn--ghost" disabled={ ! selectedCount || previewing } onClick={ previewScope }>
									{ strings.previewButton || '' }
								</button>
							</header>
							{ ! selectedCount ? <p className="field__hint">{ strings.previewHint || '' }</p> : null }
							{ preview ? (
								<div className="preview-box">
									<div className="preview-box__head">
										<strong>{ fill( strings.previewTitle || '', { count: preview.items } ) }</strong>
										<span className="text-muted">{ fill( strings.previewTotal || '', { size: fileSize( preview.bytes ) } ) }</span>
									</div>
									<div>
										{ preview.requests.map( ( item ) => (
											<p className="text-muted" key={ item.id }>
												{ fill( strings.previewRequest || '', { id: item.id, count: item.count || 0, size: fileSize( item.bytes ) } ) }
											</p>
										) ) }
									</div>
									<p className="field__hint">{ fill( strings.previewHash || '', { hash: String( preview.hash ).slice( 0, 12 ) } ) }</p>
								</div>
							) : null }
						</section>

						<section className={ 'exec-step exec-step--confirm' + ( selectedCount && preview && preview.hash ? '' : ' is-locked' ) }>
							<header className="exec-step__head">
								<span className="exec-step__no" aria-hidden="true">3</span>
								<div className="exec-step__copy">
									<h3>{ strings.confirmStepTitle || '' }</h3>
									<p>{ strings.confirmStepHint || '' }</p>
								</div>
							</header>
							<div className="exec-confirm">
								<label className="screen-reader-text" htmlFor="sufm-phrase">{ strings.executeButton || '' }</label>
								<input
									id="sufm-phrase"
									type="text"
									value={ phrase }
									placeholder={ strings.phrasePlaceholder || '' }
									autoComplete="off"
									onChange={ ( event ) => setPhrase( event.target.value ) }
									onKeyDown={ ( event ) => {
										if ( event.key === 'Enter' && selectedCount && preview && preview.hash ) {
											event.preventDefault();
											execute();
										}
									} }
								/>
								<button
									type="button"
									className="btn btn--danger"
									disabled={ busyIs( 'execute' ) || ! selectedCount || ! preview || ! preview.hash }
									onClick={ execute }
								>
									{ strings.executeButton || '' }
								</button>
							</div>
							<p className="field__hint">{ strings.deleteHint || '' }</p>
							{ batch ? (
								<div className="batch-state">
									<p>{ fill( strings.batchState || '', { id: batch.id, status: statusLabel( batch.state ) } ) }</p>
									<p className="text-muted">{ fill( strings.batchProgress || '', {
										done: batch.done_items || 0,
										total: batch.total_items || 0,
										failed: batch.failed_items || 0,
									} ) }</p>
									{ batchRunning ? (
										<button type="button" className="btn btn--small btn--danger" disabled={ busyIs( 'stop:' + batch.id ) } onClick={ () => stopBatch( batch.id ) }>
											{ strings.stopBatch || '' }
										</button>
									) : null }
								</div>
							) : null }
							{ batchError ? <p className="text-muted">{ strings.batchPollFailed || '' }</p> : null }
						</section>
					</div>
					</Card>
				) : null }

				{ canManageUsers && usersCard.id ? <UsersCard card={ usersCard } rest={ config } strings={ strings } reloadKey={ usersReload } target={ viewUsers } canSanction={ canSanction } canSync={ canSync } /> : null }

				{ capsCard.id ? <CapsMatrix card={ capsCard } rest={ config } strings={ strings } /> : null }
			</div>

			{ detail ? (
				<RequestDrawer
					key={ detail.id }
					row={ detail }
					rest={ config }
					strings={ strings }
					canExecute={ canExecute }
					canSanction={ canSanction }
					statusLabel={ statusLabel }
					ownerName={ ownerName }
					busy={ detail && busyWith( 'review:' + detail.id + ':' ) ? 'review:' + detail.id : '' }
					added={ selected.some( ( id ) => Number( id ) === Number( detail.id ) ) }
					onClose={ closeDetail }
					onReview={ ( decision ) => review( detail, decision ) }
					onAddToExecute={ addToExecute }
					onSanctionSaved={ handleSanctionSaved }
				/>
			) : null }
		</>
	);
}
