import { useEffect, useRef, useState } from 'react';
import { Badge } from '../shared/ui.jsx';
import { apiGet, errorText, fileSize, fill, toast } from '../files/shared/transport.js';
import { statusTone } from './status.js';
import SanctionDialog from './sanction.jsx';

function detailValue( value ) {
	return value === undefined || value === null || '' === String( value ) ? '—' : String( value );
}

/**
 * Right-hand drawer for one deletion request.
 *
 * It is a native <dialog> opened with showModal(): the browser owns the focus
 * trap, Escape and the backdrop, and the contents stay on the queue page. The
 * dialog is positioned as a drawer with CSS, never as a centred page modal.
 */
export default function RequestDrawer( {
	row,
	rest,
	strings,
	statusLabel,
	ownerName,
	canExecute = false,
	canSanction = false,
	busy,
	added,
	onClose,
	onReview,
	onAddToExecute,
	onSanctionSaved,
} ) {
	const dialogRef = useRef( null );
	const [ items, setItems ] = useState( [] );
	const [ itemsState, setItemsState ] = useState( 'loading' );
	const [ itemsError, setItemsError ] = useState( '' );
	const [ applicant, setApplicant ] = useState( null );
	const [ applicantState, setApplicantState ] = useState( 'loading' );
	const [ applicantVersion, setApplicantVersion ] = useState( 0 );
	const [ sanctionOpen, setSanctionOpen ] = useState( false );
	const sanctionTrigger = useRef( null );

	const titleId = 'sufm-request-drawer-title-' + row.id;
	const ids = { id: row.id };
	const sanctionTarget = {
		id: Number( row.owner_id ) || 0,
		name: ownerName( row ),
		login: String( row.owner_login || '' ),
	};
	const pending = 'pending_review' === row.status || 'needs_review' === row.status;
	const approved = 'approved' === row.status;
	const busyReview = Boolean( busy && busy.indexOf( 'review:' + row.id ) === 0 );

	useEffect( () => {
		const dialog = dialogRef.current;

		if ( dialog && ! dialog.open ) {
			dialog.showModal();
		}

		return () => {
			if ( dialog && dialog.open ) {
				dialog.close();
			}
		};
	}, [] );

	useEffect( () => {
		const controller = new AbortController();

		setItems( [] );
		setItemsState( 'loading' );
		setItemsError( '' );

		apiGet( rest, 'admin/delete-requests/' + encodeURIComponent( row.id ) + '/items', {}, controller.signal )
			.then( ( data ) => {
				setItems( data && Array.isArray( data.items ) ? data.items : [] );
				setItemsState( 'ready' );
			} )
			.catch( ( error ) => {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				setItems( [] );
				setItemsState( 'ready' );
				setItemsError( errorText( error, strings.filesLoadFailed || '' ) );
			} );

		return () => controller.abort();
	}, [ row.id ] );

	useEffect( () => {
		if ( ! canExecute ) {
			setApplicant( null );
			setApplicantState( 'missing' );
			return undefined;
		}

		const term = String( row.owner_login || row.owner_name || '' ).trim();

		if ( '' === term ) {
			setApplicant( null );
			setApplicantState( 'missing' );
			return undefined;
		}

		const controller = new AbortController();

		setApplicantState( 'loading' );

		apiGet( rest, 'admin/users', { search: term, per_page: 5 }, controller.signal )
			.then( ( data ) => {
				const rows = data && Array.isArray( data.rows ) ? data.rows : [];
				const match = rows.find( ( user ) => Number( user.id ) === Number( row.owner_id ) );

				setApplicant( match || null );
				setApplicantState( match ? 'ready' : 'missing' );
			} )
			.catch( ( error ) => {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				setApplicant( null );
				setApplicantState( 'failed' );
			} );

		return () => controller.abort();
	}, [ row.id, row.owner_id, row.owner_login, row.owner_name, applicantVersion, canExecute ] );

	const previewLabels = {
		prev: strings.galleryPrev || '',
		next: strings.galleryNext || '',
		counter: strings.galleryCounter || '',
		gallery: strings.galleryLabel || '',
	};

	const openPreview = ( item, button ) => {
		const admin = window.SakuraAdmin;

		if ( ! admin || ! admin.ui || typeof admin.ui.mediaPreview !== 'function' ) {
			toast( strings.failed || '', 'bad' );
			return;
		}

		const list = items.filter( ( entry ) => entry && entry.name );
		const index = list.indexOf( item );

		admin.ui.mediaPreview( item, {
			items: list,
			index: index < 0 ? 0 : index,
			trigger: button,
			labels: previewLabels,
		} );
	};

	const quota = Number( applicant && applicant.quota ) || 0;
	const used = Number( applicant && applicant.used ) || 0;
	const percent = quota > 0 ? Math.min( 100, Math.round( used / quota * 100 ) ) : 0;
	const missing = 'missing' === applicantState || 'failed' === applicantState;
	const missingText = 'failed' === applicantState
		? ( strings.userContextFailed || strings.applicantMissing || '' )
		: ( strings.applicantMissing || '' );
	const itemCount = itemsState === 'loading'
		? ( strings.loading || '' )
		: fill( strings.attachmentsCount || '{count} 个附件', { count: items.length } );

	return (
		<dialog
			ref={ dialogRef }
			className="queue-drawer"
			aria-labelledby={ titleId }
			onCancel={ ( event ) => {
				event.preventDefault();
				onClose();
			} }
			onClick={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<header className="queue-drawer__head">
				<div className="queue-drawer__copy">
					<p className="queue-drawer__eyebrow">
						{ strings.detail ? strings.detail + ' · #' + row.id : '#' + row.id }
					</p>
					<h2 className="queue-drawer__title" id={ titleId }>
						{ fill( strings.drawerTitle || '申请 #{id}', ids ) }
					</h2>
					<div className="queue-drawer__meta">
						<span className={ 'badge badge--' + statusTone( row.status ) }>{ statusLabel( row.status ) }</span>
						<span className="queue-drawer__meta-id">{ '#' + row.id }</span>
						<span className="queue-drawer__meta-user">{ ownerName( row ) }</span>
					</div>
				</div>
				<button
					type="button"
					className="queue-drawer__close"
					aria-label={ strings.closeDetail || strings.cancel || '' }
					onClick={ onClose }
				>
					×
				</button>
			</header>

			<div className="queue-drawer__body">
				<dl className="queue-meta">
					<div>
						<dt>{ strings.idLabel || '' }</dt>
						<dd>{ '#' + row.id }</dd>
					</div>
					<div>
						<dt>{ strings.applicant || strings.userLabel || '' }</dt>
						<dd>
							{ ownerName( row ) }
							{ row.owner_login ? <span className="queue-meta__sub">{ '@' + row.owner_login }</span> : null }
							<span className="queue-meta__sub">{ fill( strings.ownerId || 'ID {id}', { id: row.owner_id } ) }</span>
						</dd>
					</div>
					<div>
						<dt>{ strings.statusLabel || '' }</dt>
						<dd>
							<span className={ 'badge badge--' + statusTone( row.status ) }>{ statusLabel( row.status ) }</span>
						</dd>
					</div>
					<div>
						<dt>{ strings.objectsLabel || '' }</dt>
						<dd>{ fill( strings.items || '{count} 项', { count: row.item_count || 0 } ) + ' · ' + fileSize( row.bytes ) }</dd>
					</div>
					<div>
						<dt>{ strings.timeLabel || '' }</dt>
						<dd>{ detailValue( row.created_at ) }</dd>
					</div>
				</dl>

				<section className="queue-section" aria-labelledby={ titleId + '-files' }>
					<header className="queue-section__head">
						<h3 id={ titleId + '-files' }>{ strings.attachments || '' }</h3>
						<span className="queue-section__meta">{ itemCount }</span>
					</header>

					{ itemsError ? (
						<p className="queue-drawer__note is-error">{ itemsError }</p>
					) : itemsState === 'loading' ? (
						<p className="queue-drawer__note">{ strings.loading || '' }</p>
					) : items.length ? (
						<div className="queue-thumbs">
							{ items.map( ( item, index ) => (
								<button
									key={ ( item.id || 'item' ) + '-' + index }
									type="button"
									className="queue-thumb"
									title={ item.name || '' }
									aria-label={ ( strings.openGallery || '' ) + ( item.name ? '：' + item.name : '' ) }
									onClick={ ( event ) => openPreview( item, event.currentTarget ) }
								>
									<span className="queue-thumb__frame">
										{ 'image' === item.kind && item.url ? (
											<img
												src={ item.url }
												alt=""
												loading="lazy"
												decoding="async"
												onError={ ( event ) => {
													event.currentTarget.hidden = true;
												} }
											/>
										) : null }
										<span className="queue-thumb__badge">
											{ 'video' === item.kind ? '▶' : ( item.ext || item.kind_label || '·' ) }
										</span>
									</span>
									<span className="queue-thumb__name">{ item.name || '' }</span>
									<span className="queue-thumb__meta">{ item.size_label || fileSize( item.size ) }</span>
								</button>
							) ) }
						</div>
					) : (
						<p className="queue-drawer__note">{ strings.noAttachments || strings.attachmentsEmpty || '' }</p>
					) }
				</section>

				<section className="queue-section queue-context" aria-labelledby={ titleId + '-user' }>
					<header className="queue-section__head">
						<h3 id={ titleId + '-user' }>{ strings.userContext || '' }</h3>
					</header>

					{ 'loading' === applicantState ? (
						<p className="queue-drawer__note">{ strings.loading || '' }</p>
					) : missing || ! applicant ? (
						<div className="queue-context__missing">
							<p>{ missingText }</p>
						</div>
					) : (
						<>
							<div className="queue-context__head">
								<strong className="queue-context__name">{ applicant.name || applicant.login || '#' + applicant.id }</strong>
								<span className="queue-context__login">{ applicant.login ? '@' + applicant.login : '' }</span>
								{ applicant.sanction && applicant.sanction.badge ? (
									<Badge tone="warn">{ applicant.sanction.badge }</Badge>
								) : null }
							</div>
							<div className="queue-context__stats">
								<div className="queue-stat">
									<span>{ strings.quota || '' }</span>
									<strong>{ fileSize( quota ) }</strong>
								</div>
								<div className="queue-stat">
									<span>{ strings.used || '' }</span>
									<strong>{ fileSize( used ) }</strong>
								</div>
								<div className="queue-stat">
									<span>{ strings.reserved || '' }</span>
									<strong>{ fileSize( applicant.reserved ) }</strong>
								</div>
								<div className="queue-stat">
									<span>{ strings.files || '' }</span>
									<strong>{ Number( applicant.files ) || 0 }</strong>
								</div>
								<div className="queue-stat">
									<span>{ strings.folders || '' }</span>
									<strong>{ Number( applicant.folders ) || 0 }</strong>
								</div>
								<div className="queue-stat">
									<span>{ strings.pending || '' }</span>
									<strong>{ Number( applicant.pending ) || 0 }</strong>
								</div>
							</div>
							<div className="queue-context__usage">
								<div className="usage__track">
									<span
										className={ percent >= 90 ? 'usage__fill is-high' : 'usage__fill' }
										style={ { width: percent + '%' } }
									/>
								</div>
								<span title={ strings.usage || '' }>{ percent + '%' }</span>
							</div>
						</>
					) }
				</section>
			</div>

			<footer className="queue-drawer__foot">
				<div className="queue-drawer__actions">
					{ pending ? (
						<>
							<button type="button" className="btn btn--primary" disabled={ busyReview } onClick={ () => onReview( 'approve' ) }>
								{ strings.approve || '' }
							</button>
							<button type="button" className="btn btn--ghost" disabled={ busyReview } onClick={ () => onReview( 'reject' ) }>
								{ strings.reject || '' }
							</button>
						</>
					) : null }
					{ canSanction && sanctionTarget.id ? (
						<button type="button" className="btn btn--ghost" ref={ sanctionTrigger } onClick={ () => setSanctionOpen( true ) }>
							{ strings.sanction || '' }
						</button>
					) : null }
					{ approved && canExecute ? (
						<button type="button" className="btn btn--primary" disabled={ added } onClick={ () => onAddToExecute( row ) }>
							{ added ? ( strings.addedToExecute || '' ) : ( strings.addToExecute || '' ) }
						</button>
					) : null }
				</div>
				{ added ? <p className="queue-drawer__note">{ strings.alreadySelected || '' }</p> : null }
			</footer>

			{ sanctionOpen && canSanction && sanctionTarget.id ? (
				<SanctionDialog
					user={ sanctionTarget }
					rest={ rest }
					strings={ strings }
					returnFocus={ sanctionTrigger }
					onClose={ () => setSanctionOpen( false ) }
					onSaved={ () => {
						setApplicantVersion( ( value ) => value + 1 );

						if ( onSanctionSaved ) {
							onSanctionSaved();
						}
					} }
				/>
			) : null }
		</dialog>
	);
}
