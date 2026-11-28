import { useEffect, useRef, useState } from 'react';
import { apiGet, apiPost, confirmAction, errorText, fill, toast } from '../files/shared/transport.js';

function Field( { id, label, hint, children } ) {
	return (
		<div className="field field--full">
			<div className="field__main">
				<label className="field__label" htmlFor={ id }>{ label }</label>
				{ hint ? <p className="field__hint">{ hint }</p> : null }
			</div>
			<div className="field__control">{ children }</div>
		</div>
	);
}

/**
 * The sanction dialog keeps the legacy decision rules: the server owns the
 * ladder and the closed reason list, while a permanent upload ban requires
 * typing the target login name.
 *
 * It is a native <dialog>, so the browser owns the focus trap. Focus return
 * is explicit: React unmounts the dialog before the platform can hand focus
 * back, which left document.activeElement on <body>. The opener is recorded
 * on mount and restored on every close path, falling back to the card title.
 */
export default function SanctionDialog( { user, rest, strings, onClose, onSaved, returnFocus } ) {
	const dialogRef = useRef( null );
	const opener = useRef( null );
	const fallback = useRef( null );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ form, setForm ] = useState( { type: '', days: '7', reason: '', note: '', gate: '' } );
	const settled = useRef( false );

	useEffect( () => {
		const controller = new AbortController();

		apiGet( rest, 'admin/users/' + encodeURIComponent( user.id ) + '/sanction', {}, controller.signal )
			.then( ( response ) => {
				if ( ! response.can_sanction ) {
					toast( strings.sanctionAdmin || '', 'bad' );
					close();
					return;
				}

				const types = response.types || [];
				const durations = response.durations || [];
				const reasons = response.reasons || [];
				const suggestion = response.suggestion || {};
				const firstFinite = durations.find( ( option ) => Number( option.value ) > 0 );
				const type = suggestion.type || ( types[ 0 ] ? types[ 0 ].value : '' );
				let days = firstFinite ? String( firstFinite.value ) : '0';

				if ( suggestion.days !== undefined && suggestion.days !== null && 'warn' !== String( type ) ) {
					days = String( suggestion.days );
				}

				setData( response );
				setForm( {
					type,
					days,
					reason: reasons[ 0 ] ? String( reasons[ 0 ].value ) : 'other',
					note: '',
					gate: '',
				} );
				setLoading( false );
			} )
			.catch( ( error ) => {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				toast( errorText( error, strings.sanctionLoadFailed ), 'bad' );
				close();
			} );

		return () => controller.abort();
	}, [ user.id ] );

	useEffect( () => {
		const dialog = dialogRef.current;
		const trigger = ( returnFocus && returnFocus.current ) || document.activeElement;

		opener.current = trigger && trigger !== document.body ? trigger : null;

		const card = trigger && trigger.closest ? trigger.closest( '.card' ) : null;
		const title = card ? card.querySelector( '.card__title' ) : document.querySelector( '[data-sakura-return-target]' );

		// A heading is not focusable by default; the contract's fallback target
		// must accept programmatic focus when the opener is gone.
		if ( title && ! title.hasAttribute( 'tabindex' ) ) {
			title.setAttribute( 'tabindex', '-1' );
		}

		fallback.current = title || null;

		if ( dialog && ! dialog.open ) {
			dialog.showModal();
		}

		return () => {
			if ( dialog && dialog.open ) {
				dialog.close();
			}
		};
	}, [] );

	const restoreFocus = () => {
		const trigger = opener.current;
		const title = fallback.current;

		opener.current = null;
		fallback.current = null;

		window.setTimeout( () => {
			if ( trigger && document.contains( trigger ) ) {
				trigger.focus( { preventScroll: true } );
				return;
			}

			if ( title && document.contains( title ) ) {
				title.focus( { preventScroll: true } );
			}
		}, 0 );
	};

	const close = () => {
		if ( settled.current ) {
			return;
		}

		settled.current = true;

		// Close while the element is still attached so the native focus return
		// runs; the timer then restores focus even if React unmounts first.
		if ( dialogRef.current && dialogRef.current.open ) {
			dialogRef.current.close();
		}

		onClose();
		restoreFocus();
	};

	const types = ( data && data.types ) || [];
	const durations = ( data && data.durations ) || [];
	const reasons = ( data && data.reasons ) || [];
	const warning = 'warn' === form.type;
	const permanent = ! warning && '0' === String( form.days );
	const login = data && data.user ? String( data.user.login ) : '';
	const suggestion = data && data.suggestion ? data.suggestion : null;
	const applyDisabled = busy || ( permanent && form.gate.trim() !== login );

	const submit = () => {
		if ( ! data || applyDisabled ) {
			return;
		}

		const body = {
			type: form.type,
			days: warning ? 0 : Number( form.days ),
			reason_code: form.reason,
			note: form.note.trim(),
		};

		if ( permanent ) {
			body.confirm = form.gate.trim();
		}

		setBusy( true );
		apiPost( rest, 'admin/users/' + encodeURIComponent( user.id ) + '/sanction', body )
			.then( () => {
				toast( fill( strings.sanctionApplied || '', { name: user.name || user.login } ), 'ok' );
				onSaved();
				close();
			} )
			.catch( ( error ) => toast( errorText( error, strings.sanctionApplyFailed ), 'bad' ) )
			.finally( () => setBusy( false ) );
	};

	const revoke = () => {
		if ( ! data || ! data.active ) {
			return;
		}

		confirmAction( strings.sanctionRevokeMessage || '', {
			title: fill( strings.sanctionRevokeTitle || '', { name: user.name || user.login } ),
			note: strings.sanctionRevokeNote || '',
			tone: 'danger',
			danger: true,
			confirmText: strings.sanctionRevoke || '',
			cancelText: strings.cancel || '',
		} ).then( ( confirmed ) => {
			if ( ! confirmed ) {
				return;
			}

			setBusy( true );
			apiPost( rest, 'admin/sanctions/' + encodeURIComponent( data.active.id ) + '/revoke', {} )
				.then( () => {
					toast( fill( strings.sanctionRevoked || '', { name: user.name || user.login } ), 'ok' );
					onSaved();
					close();
				} )
				.catch( ( error ) => toast( errorText( error, strings.sanctionRevokeFailed ), 'bad' ) )
				.finally( () => setBusy( false ) );
		} );
	};

	return (
		<dialog
			ref={ dialogRef }
			className="sakura-dialog"
			aria-labelledby="sufm-sanction-title"
			onCancel={ ( event ) => {
				event.preventDefault();
				close();
			} }
		>
			<form className="sakura-dialog__form" onSubmit={ ( event ) => event.preventDefault() }>
				<div className="dialog__body">
					<div className="dialog__text">
						<h2 className="dialog__title" id="sufm-sanction-title">
							{ fill( strings.sanctionTitle || '', { name: user.name || user.login } ) }
						</h2>
						{ loading ? (
							<p className="dialog__message">{ strings.loading || '' }</p>
						) : data && data.active ? (
							<p className="dialog__message">{ fill( strings.sanctionActive || '', { label: data.active.label || '' } ) }</p>
						) : (
							<p className="dialog__message">{ strings.sanctionNone || '' }</p>
						) }
					</div>
				</div>

				{ ! loading && data ? (
					<>
						<div className="field-list sanction-form">
							<Field id="sufm-sanction-type" label={ strings.sanctionType || '' }>
								<select
									id="sufm-sanction-type"
									value={ form.type }
									onChange={ ( event ) => setForm( ( current ) => ( { ...current, type: event.target.value } ) ) }
								>
									{ types.map( ( option ) => (
										<option key={ option.value } value={ option.value }>{ option.label }</option>
									) ) }
								</select>
							</Field>
							<Field id="sufm-sanction-days" label={ strings.sanctionDuration || '' }>
								<select
									id="sufm-sanction-days"
									value={ form.days }
									disabled={ warning }
									onChange={ ( event ) => setForm( ( current ) => ( { ...current, days: event.target.value } ) ) }
								>
									{ durations.map( ( option ) => (
										<option key={ option.value } value={ option.value }>{ option.label }</option>
									) ) }
								</select>
							</Field>
							<Field id="sufm-sanction-reason" label={ strings.sanctionReason || '' }>
								<select
									id="sufm-sanction-reason"
									value={ form.reason }
									onChange={ ( event ) => setForm( ( current ) => ( { ...current, reason: event.target.value } ) ) }
								>
									{ reasons.map( ( option ) => (
										<option key={ option.value } value={ option.value }>{ option.label }</option>
									) ) }
								</select>
							</Field>
							<Field id="sufm-sanction-note" label={ strings.sanctionNote || '' }>
								<input
									id="sufm-sanction-note"
									type="text"
									maxLength={ 120 }
									value={ form.note }
									placeholder={ strings.sanctionNote || '' }
									onChange={ ( event ) => setForm( ( current ) => ( { ...current, note: event.target.value } ) ) }
								/>
							</Field>
						</div>

						{ suggestion && suggestion.reason ? (
							<p className="field__hint">{ fill( strings.sanctionSuggest || '', { reason: suggestion.reason } ) }</p>
						) : null }

						{ permanent ? (
							<div className="dialog__gate">
								<label className="dialog__gate-label">
									{ fill( strings.sanctionForeverLabel || '', { login } ) }
									<input
										className="dialog__gate-input"
										type="text"
										value={ form.gate }
										autoComplete="off"
										spellCheck={ false }
										onChange={ ( event ) => setForm( ( current ) => ( { ...current, gate: event.target.value } ) ) }
									/>
								</label>
							</div>
						) : null }

						<div className="dialog__actions">
							<button type="button" className="btn btn--ghost" onClick={ close }>{ strings.cancel || '' }</button>
							<button type="button" className="btn btn--primary" disabled={ applyDisabled } onClick={ submit }>
								{ strings.sanctionApply || '' }
							</button>
							{ data.active ? (
								<button type="button" className="btn btn--danger" disabled={ busy } onClick={ revoke }>
									{ strings.sanctionRevoke || '' }
								</button>
							) : null }
						</div>
					</>
				) : null }
			</form>
		</dialog>
	);
}
