import { useEffect, useRef, useState } from 'react';
import { Card, PageHead } from '../shared/ui.jsx';
import Pager from '../files/shared/Pager.jsx';
import EmptyState from '../files/shared/EmptyState.jsx';
import { apiGet, apiPost, confirmAction, errorText, fileSize, fill, toast } from '../files/shared/transport.js';
import CapsMatrix from './caps.jsx';

function statusTone( status ) {
	if ( status === 'pending_review' || status === 'needs_review' ) {
		return 'warn';
	}

	if ( status === 'approved' || status === 'queued' || status === 'executing' ) {
		return 'info';
	}

	if ( status === 'completed' ) {
		return 'ok';
	}

	if ( status === 'rejected' || status === 'failed' || status === 'partial_failed' ) {
		return 'bad';
	}

	return 'neutral';
}

/**
 * Review queue, approved selection, batch execution and the capability form.
 * All destructive endpoints are the same ones the legacy controller called;
 * the React layer only decides what is enabled and when to refresh.
 */
export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = config.strings || {};
	const cards = boot.cards || [];
	const queueCard = cards[ 0 ] || {};
	const executeCard = cards[ 1 ] || {};
	const capsCard = cards[ 2 ] || {};
	const [ head, setHead ] = useState( boot.head );
	const [ query, setQuery ] = useState( { page: 1, per: 20, status: '', search: '' } );
	const [ searchDraft, setSearchDraft ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ reload, setReload ] = useState( 0 );
	const [ approved, setApproved ] = useState( [] );
	const [ approvedReload, setApprovedReload ] = useState( 0 );
	const [ selected, setSelected ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ phrase, setPhrase ] = useState( '' );
	const [ batch, setBatch ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const batchTimer = useRef( 0 );

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
		const controller = new AbortController();

		apiGet( config, 'admin/delete-requests', { status: 'approved', page: 1, per_page: 50 }, controller.signal )
			.then( ( data ) => {
				setApproved( ( data.rows || [] ).filter( ( row ) => ! Number( row.batch_id ) ) );
				setSelected( [] );
				setPreview( null );
			} )
			.catch( ( error ) => {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				setApproved( [] );
				toast( errorText( error, strings.approvedLoadFailed ), 'bad' );
			} );

		return () => controller.abort();
	}, [ approvedReload ] );

	useEffect( () => () => {
		if ( batchTimer.current ) {
			window.clearTimeout( batchTimer.current );
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
		setPreview( null );
		setSelected( ( current ) =>
			current.includes( id ) ? current.filter( ( value ) => value !== id ) : [ ...current, id ]
		);
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
			setBusy( key );
			apiPost( config, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/review', { decision } )
				.then( () => {
					toast( fill( strings.reviewDone || '', values ), 'ok' );
					setReload( ( value ) => value + 1 );
					refreshApproved();
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => setBusy( '' ) );
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
			setBusy( key );
			apiPost( config, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/revoke', { reason: '' } )
				.then( () => {
					toast( fill( strings.revokeDone || '', values ), 'ok' );
					setReload( ( value ) => value + 1 );
					refreshApproved();
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => setBusy( '' ) );
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
			setBusy( key );
			apiPost( config, 'admin/delete-batches/' + encodeURIComponent( batchId ) + '/stop', {} )
				.then( () => {
					toast( fill( strings.stopDone || '', values ), 'warn' );
					pollBatch( batchId );
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => setBusy( '' ) );
		} );
	};

	const openFiles = ( row, button ) => {
		const admin = window.SakuraAdmin;

		if ( ! admin || ! admin.ui || typeof admin.ui.mediaPreview !== 'function' ) {
			toast( strings.failed || '', 'bad' );
			return;
		}

		const key = 'files:' + row.id;
		setBusy( key );
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
			.finally( () => setBusy( '' ) );
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

			setBusy( 'execute' );
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
						pollBatch( batchId );
					}
				} )
				.catch( ( error ) => toast( errorText( error ), 'bad' ) )
				.finally( () => setBusy( '' ) );
		} );
	};

	const pollBatch = ( batchId ) => {
		if ( batchTimer.current ) {
			window.clearTimeout( batchTimer.current );
			batchTimer.current = 0;
		}

		apiGet( config, 'admin/delete-batches/' + encodeURIComponent( batchId ), {} )
			.then( ( data ) => {
				const next = data.batch || {};
				const running = next.state === 'queued' || next.state === 'executing';

				setBatch( next );

				if ( running ) {
					batchTimer.current = window.setTimeout( () => pollBatch( batchId ), 4000 );
					return;
				}

				toast( fill( strings.batchFinished || '', { id: next.id || batchId } ), 'ok' );
				setReload( ( value ) => value + 1 );
			} )
			.catch( ( error ) => {
				toast( errorText( error ), 'bad' );
				batchTimer.current = window.setTimeout( () => pollBatch( batchId ), 6000 );
			} );
	};

	const statusLabel = ( status ) => ( strings.statusLabels && strings.statusLabels[ status ] ) || status || '—';
	const ownerName = ( row ) => row.owner_name || fill( strings.ownerId || '', { id: row.owner_id } );
	const selectedCount = selected.length;
	const batchRunning = batch && ( batch.state === 'queued' || batch.state === 'executing' );

	const actionCell = ( row ) => {
		const status = row.status;
		const batchId = Number( row.batch_id ) || 0;

		if ( status === 'pending_review' || status === 'needs_review' ) {
			return (
				<div className="queue-actions">
					<button type="button" className="btn btn--small btn--primary" disabled={ busy.startsWith( 'review:' + row.id ) } onClick={ () => review( row, 'approve' ) }>
						{ strings.approve || '' }
					</button>
					<button type="button" className="btn btn--small btn--ghost" disabled={ busy.startsWith( 'review:' + row.id ) } onClick={ () => review( row, 'reject' ) }>
						{ strings.reject || '' }
					</button>
					{ Number( row.item_count ) > 0 ? (
						<button type="button" className="btn btn--small btn--ghost" disabled={ busy === 'files:' + row.id } onClick={ ( event ) => openFiles( row, event.currentTarget ) }>
							{ strings.viewFiles || '' }
						</button>
					) : null }
				</div>
			);
		}

		if ( status === 'approved' ) {
			return (
				<div className="queue-actions">
					<button type="button" className="btn btn--small btn--danger" disabled={ busy === 'revoke:' + row.id } onClick={ () => revoke( row ) }>
						{ strings.revoke || '' }
					</button>
				</div>
			);
		}

		if ( ( status === 'queued' || status === 'executing' ) && batchId ) {
			return (
				<div className="queue-actions">
					<button type="button" className="btn btn--small btn--danger" disabled={ busy === 'stop:' + batchId } onClick={ () => stopBatch( batchId, 'stop:' + batchId ) }>
						{ strings.stopBatch || '' }
					</button>
				</div>
			);
		}

		return <span className="text-muted">{ strings.noActions || '—' }</span>;
	};

	return (
		<>
			<PageHead head={ head } />
			<div className="card-grid" data-sakura-layout="files-queue" data-sakura-group="main">
				<Card card={ queueCard }>
					<div className="toolbar" role="search">
						<label className="toolbar__field">
							<span className="screen-reader-text">{ strings.statusLabel || '' }</span>
							<select
								value={ query.status }
								onChange={ ( event ) => setQuery( ( current ) => ( { ...current, page: 1, status: event.target.value } ) ) }
							>
								{ ( config.queueStatuses || [] ).map( ( option ) => (
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
									{ selectedCount ? fill( strings.pickCount || '', { count: selectedCount } ) : ( strings.pickNone || '' ) }
								</span>
							</header>
							<div className="approved-list">
								{ approved.length ? approved.map( ( row ) => (
									<label className="approved-item" key={ row.id }>
										<input
											type="checkbox"
											value={ row.id }
											checked={ selected.includes( row.id ) }
											onChange={ () => toggleSelected( row.id ) }
										/>
										<span className="approved-item__label">{ '#' + row.id + ' · ' + ownerName( row ) }</span>
										<span className="approved-item__meta">{ fill( strings.items || '', { count: row.item_count || 0 } ) + ' · ' + fileSize( row.bytes ) }</span>
									</label>
								) ) : (
									<p className="text-muted">{ strings.approvedEmpty || '' }</p>
								) }
							</div>
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
									disabled={ busy === 'execute' || ! selectedCount || ! preview || ! preview.hash }
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
										<button type="button" className="btn btn--small btn--danger" disabled={ busy === 'stop:' + batch.id } onClick={ () => stopBatch( batch.id ) }>
											{ strings.stopBatch || '' }
										</button>
									) : null }
								</div>
							) : null }
						</section>
					</div>
				</Card>

				<CapsMatrix card={ capsCard } config={ config } />
			</div>
		</>
	);
}
