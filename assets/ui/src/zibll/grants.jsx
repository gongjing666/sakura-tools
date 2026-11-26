import { useEffect, useRef, useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { apiGet, apiPost, errorText, toast } from '../files/shared/transport.js';

function GrantDialog( { mode, strings, onCancel, onDone } ) {
	const [ value, setValue ] = useState( 'extend' === mode ? '30' : '' );
	const [ busy, setBusy ] = useState( false );
	const dialogRef = useRef( null );

	useEffect( () => {
		if ( dialogRef.current && ! dialogRef.current.open ) {
			dialogRef.current.showModal();
		}
	}, [] );

	const title = 'extend' === mode ? strings.grantExtendTitle : strings.grantRevokeTitle;
	const prompt = 'extend' === mode ? strings.grantExtendPrompt : strings.grantRevokePrompt;
	const confirmText = 'extend' === mode ? strings.grantExtend : strings.grantRevoke;

	const submit = () => {
		if ( busy ) {
			return;
		}

		if ( 'revoke' === mode && value.trim() !== strings.revokeWord ) {
			toast( strings.invalidWord, 'bad' );
			return;
		}

		if ( 'extend' === mode ) {
			const days = parseInt( value, 10 );
			if ( ! isFinite( days ) || days < 1 || days > 3650 ) {
				toast( strings.invalidDays, 'bad' );
				return;
			}
		}

		setBusy( true );
		onDone( value.trim() ).finally( () => setBusy( false ) );
	};

	return (
		<dialog
			ref={ dialogRef }
			className={ 'sakura-dialog' + ( 'revoke' === mode ? ' sakura-dialog--danger' : '' ) }
			onCancel={ ( event ) => {
				event.preventDefault();
				onCancel();
			} }
		>
			<form className="sakura-dialog__form" onSubmit={ ( event ) => event.preventDefault() }>
				<div className="dialog__body">
					<div className="dialog__text">
						<h2 className="dialog__title">{ title }</h2>
						<p className="dialog__message">{ prompt }</p>
					</div>
					<input
						type={ 'extend' === mode ? 'number' : 'text' }
						value={ value }
						min={ 'extend' === mode ? '1' : undefined }
						max={ 'extend' === mode ? '3650' : undefined }
						onChange={ ( event ) => setValue( event.target.value ) }
						autoFocus
					/>
				</div>
				<div className="dialog__actions">
					<button type="button" className="btn btn--ghost" onClick={ onCancel }>{ strings.cancel }</button>
					<button type="button" className="btn btn--primary" disabled={ busy } onClick={ submit }>{ confirmText }</button>
				</div>
			</form>
		</dialog>
	);
}

export default function Grants( { card, strings, config } ) {
	const [ items, setItems ] = useState( [] );
	const [ state, setState ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ action, setAction ] = useState( null );

	const load = () => {
		setState( 'loading' );
		setError( '' );
		apiGet( config, 'admin/space/grants', {} )
			.then( ( data ) => {
				setItems( Array.isArray( data.items ) ? data.items : [] );
				setState( 'ready' );
			} )
			.catch( ( err ) => {
				setState( 'error' );
				setError( errorText( err, strings.grantsError ) );
				toast( errorText( err, strings.grantsError ), 'bad' );
			} );
	};

	useEffect( () => {
		load();
		const refresh = () => load();
		window.addEventListener( 'sakura:space-grants-refresh', refresh );

		return () => window.removeEventListener( 'sakura:space-grants-refresh', refresh );
	}, [] );

	const done = ( value ) => {
		const request = 'extend' === action.mode
			? apiPost( config, 'admin/space/grants/' + encodeURIComponent( action.id ) + '/extend', { days: parseInt( value, 10 ) } )
			: apiPost( config, 'admin/space/grants/' + encodeURIComponent( action.id ) + '/revoke', { note: strings.revokeWord } );

		return request
			.then( () => {
				toast( 'extend' === action.mode ? strings.grantExtendDone : strings.grantRevokeDone, 'ok' );
				setAction( null );
				load();
			} )
			.catch( ( err ) => toast( errorText( err, strings.grantActionFailed ), 'bad' ) );
	};

	return (
		<>
			<Card
				card={ card }
				actions={
					<button type="button" className="btn btn--small btn--ghost" onClick={ load } disabled={ 'loading' === state }>
						{ strings.refreshGrants }
					</button>
				}
			>
				{ 'loading' === state && ! items.length ? (
					<div className="empty-state"><p className="empty-state__title">{ strings.grantsLoading }</p></div>
				) : null }
				{ 'error' === state ? (
					<div className="empty-state">
						<p className="empty-state__title">{ strings.grantsError }</p>
						<p className="empty-state__message">{ error }</p>
					</div>
				) : null }
				{ 'ready' === state && ! items.length ? (
					<div className="empty-state">
						<p className="empty-state__title">{ strings.grantsEmpty }</p>
						<p className="empty-state__message">{ strings.grantsEmptyMessage }</p>
					</div>
				) : null }
				{ items.length ? (
					<div className="table-scroll">
						<table className="table table--compact table--responsive">
							<thead>
								<tr>
									<th scope="col">{ strings.grantTimeUser }</th>
									<th scope="col">{ strings.grantPackageSize }</th>
									<th scope="col">{ strings.grantExpiry }</th>
									<th scope="col">{ strings.grantState }</th>
									<th scope="col">{ strings.grantActions }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<td data-label={ strings.grantTimeUser }>
											<div>{ item.created_at || '' }</div>
											<div className="zibll-grant-user">{ '#' + ( item.owner_id || '' ) }</div>
										</td>
										<td data-label={ strings.grantPackageSize }>
											<div className="zibll-grant-package">{ item.label || '' }</div>
											<div className="zibll-grant-size">{ item.bytes_human || '' }</div>
										</td>
										<td data-label={ strings.grantExpiry }>{ item.permanent ? strings.permanent : ( item.expires_at || '' ) }</td>
										<td data-label={ strings.grantState }>
											<span className={ 'badge badge--' + ( 'active' === item.state ? 'ok' : ( 'pending' === item.state ? 'warn' : 'info' ) ) }>
												{ item.state_label || item.state || '' }
											</span>
										</td>
										<td data-label={ strings.grantActions }>
											{ 'active' === item.state ? (
												<div className="button-row">
													<button type="button" className="btn btn--ghost btn--small" onClick={ () => setAction( { mode: 'extend', id: item.id } ) }>
														{ strings.grantExtend }
													</button>
													<button type="button" className="btn btn--danger btn--small" onClick={ () => setAction( { mode: 'revoke', id: item.id } ) }>
														{ strings.grantRevoke }
													</button>
												</div>
											) : null }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : null }
			</Card>

			{ action ? (
				<GrantDialog
					mode={ action.mode }
					strings={ strings }
					onCancel={ () => setAction( null ) }
					onDone={ done }
				/>
			) : null }
		</>
	);
}
