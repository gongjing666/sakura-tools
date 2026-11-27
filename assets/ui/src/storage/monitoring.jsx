/**
 * Monitoring and alerting card: the read-only Prometheus endpoint plus the
 * webhook alert option.
 *
 * The island only edits a draft. Alerting::sanitize() stays the one place that
 * validates and clamps the numbers, and the values the save endpoint returns
 * replace both draft and saved, so the card never shows a number the option
 * does not hold.
 */

import { useRef, useState } from 'react';
import { BusyLabel, Card, Field, Prompt } from '../shared/ui.jsx';

/**
 * Switch values arrive from PHP as 0/1 or "0"/"1" (true only after a local
 * edit), so Boolean() would read "0" as on.
 *
 * @param {*} value Stored value.
 * @return {boolean} Whether the switch is on.
 */
function isOn( value ) {
	return true === value || 1 === value || '1' === value;
}

/**
 * The shape compared for dirtiness. Numbers compare as strings: the server
 * clamps them, so 40 and "40" describe the same stored value and retyping the
 * same number must not look like a change.
 *
 * @param {Object} values Card values.
 * @return {Object} Normalized values.
 */
function normalize( values ) {
	const source = values || {};

	return {
		enabled: isOn( source.enabled ) ? 1 : 0,
		webhook: String( source.webhook === undefined || source.webhook === null ? '' : source.webhook ),
		fail_rate: String( source.fail_rate === undefined || source.fail_rate === null ? '' : source.fail_rate ),
		min_attempts: String( source.min_attempts === undefined || source.min_attempts === null ? '' : source.min_attempts ),
		cooldown: String( source.cooldown === undefined || source.cooldown === null ? '' : source.cooldown ),
	};
}

/**
 * Flat equality for the shapes this card compares.
 *
 * @param {*} left Left value.
 * @param {*} right Right value.
 * @return {boolean} Whether both encode the same JSON.
 */
function same( left, right ) {
	return JSON.stringify( left === undefined ? null : left ) === JSON.stringify( right === undefined ? null : right );
}

/**
 * POST JSON to the plugin's REST namespace with the island nonce.
 *
 * @param {string} url Endpoint URL.
 * @param {string} nonce X-WP-Nonce.
 * @param {Object} strings Payload strings (network and status fallbacks).
 * @param {Object} [body] Request body.
 * @return {Promise<Object>} Parsed response.
 */
async function postJson( url, nonce, strings, body ) {
	let response;

	try {
		response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body || {} ),
		} );
	} catch ( failure ) {
		throw new Error( strings.network );
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch ( failure ) {
		payload = null;
	}

	if ( ! response.ok ) {
		throw new Error(
			payload && payload.message
				? payload.message
				: strings.failedStatus.replace( '%d', String( response.status ) )
		);
	}

	return payload || {};
}

/**
 * Copy text with the async clipboard API, falling back to the legacy
 * textarea + execCommand path on non-secure origins and embedded frames.
 *
 * @param {string} text Text to copy.
 * @return {Promise<boolean>} Whether the copy succeeded.
 */
async function copyText( text ) {
	if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
		try {
			await window.navigator.clipboard.writeText( text );
			return true;
		} catch ( failure ) {
			// Fall through: the async API rejects outside secure contexts.
		}
	}

	const helper = document.createElement( 'textarea' );
	helper.value = text;
	helper.setAttribute( 'readonly', 'readonly' );
	helper.style.position = 'fixed';
	helper.style.top = '0';
	helper.style.left = '-9999px';
	document.body.appendChild( helper );
	helper.select();

	let copied = false;

	try {
		copied = document.execCommand( 'copy' );
	} catch ( failure ) {
		copied = false;
	}

	document.body.removeChild( helper );

	return copied;
}

/**
 * A one-line control with the shared field nesting.
 *
 * The shared Field only knows number and select, but the endpoint row is
 * read-only and full width and the webhook row carries a placeholder, so this
 * prints the same field/field__main/field__control markup Card::render() and
 * Field::render() print.
 *
 * @param {Object} props Row props.
 * @return {JSX.Element} Field row.
 */
function TextRow( { field, value, onChange } ) {
	const hintId = field.hint ? field.id + '-hint' : undefined;
	const classes = [ 'field', 'field--' + field.type, field.full ? 'field--full' : '' ]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className={ classes }>
			<div className="field__main">
				<label className="field__label" htmlFor={ field.id }>
					{ field.label }
				</label>
				{ field.hint ? (
					<p className="field__hint" id={ hintId }>
						{ field.hint }
					</p>
				) : null }
			</div>
			<div className="field__control">
				<input
					type={ field.type }
					id={ field.id }
					value={ value }
					placeholder={ field.placeholder }
					autoComplete={ field.autocomplete }
					spellCheck={ field.spellcheck === false ? false : undefined }
					readOnly={ Boolean( field.readonly ) }
					aria-describedby={ hintId }
					onChange={ onChange ? ( event ) => onChange( event.target.value ) : undefined }
				/>
			</div>
		</div>
	);
}

/**
 * The monitoring card.
 *
 * @param {Object}   props           Card props.
 * @param {Object}   props.section   The monitoring card from boot.cards.
 * @param {Object}   props.rest      boot.rest (REST base URL and nonce).
 * @param {string}   props.nonce     boot.rest.nonce.
 * @param {Function} [props.onSaved] Receives { status, alerts } when a save
 *                                   response actually recomputes them.
 * @return {JSX.Element} Card.
 */
export default function MonitoringCard( { section, rest, nonce, onSaved } ) {
	const strings = section.strings;
	const prometheus = section.prometheus;
	const alert = section.alert;
	const enabledHintId = alert.enabled.id + '-hint';

	const [ saved, setSaved ] = useState( section.values || {} );
	const [ draft, setDraft ] = useState( section.values || {} );
	const [ url, setUrl ] = useState( prometheus.url );
	const [ copyStatus, setCopyStatus ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ rotating, setRotating ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const returnFocus = useRef( null );

	const dirty = ! same( normalize( draft ), normalize( saved ) );

	/**
	 * One draft value.
	 *
	 * @param {string} key   Option key.
	 * @param {*}      value New value.
	 */
	const setValue = ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	/**
	 * Show the shared prompt. Title and kicker are the card's; the message is
	 * always the server's, so the copy matches what actually happened.
	 *
	 * @param {Object} meta    Notice metadata from the payload.
	 * @param {string} message Server message.
	 */
	const showNotice = ( meta, message ) => {
		setNotice( {
			kicker: meta.kicker,
			title: meta.title,
			message: message || '',
			closeLabel: strings.close,
			closeIcon: strings.closeIcon,
			mascot: strings.mascot,
			button: strings.dismiss,
		} );
	};

	/**
	 * Adopt server state after a write.
	 *
	 * @param {Object} state Response state.
	 */
	const applyState = ( state ) => {
		if ( state && state.values ) {
			setSaved( state.values );
			setDraft( state.values );
		}

		if ( state && state.prometheus && state.prometheus.url ) {
			setUrl( state.prometheus.url );
		}
	};

	/**
	 * Save the alert option.
	 *
	 * @param {Event} event Submit event.
	 * @return {Promise<void>} Resolves when the request finishes.
	 */
	const save = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		returnFocus.current = event.currentTarget.querySelector( 'button[type="submit"]' );
		setSaving( true );
		setCopyStatus( '' );

		try {
			const payload = await postJson( rest.monitoring.save, nonce, strings, { fields: draft } );
			const state = payload.state || {};

			applyState( state );
			showNotice(
				state.webhook_dropped ? strings.notices.saved_dropped : strings.notices.saved,
				payload.message
			);

			// This card changes no page-level status or alert: those come from
			// the stored bucket profile. Forward the recomputed pair only when
			// the response carries one; calling onSaved( undefined ) would
			// clear the status the page booted with.
			if ( onSaved && state.status ) {
				onSaved( { status: state.status, alerts: state.alerts } );
			}
		} catch ( failure ) {
			showNotice( strings.notices.failed, failure.message );
		} finally {
			setSaving( false );
		}
	};

	/**
	 * Fire a test webhook with the saved configuration, like the old handler.
	 *
	 * @param {Event} event Click event.
	 * @return {Promise<void>} Resolves when the request finishes.
	 */
	const test = async ( event ) => {
		if ( testing ) {
			return;
		}

		returnFocus.current = event.currentTarget;
		setTesting( true );
		setCopyStatus( '' );

		try {
			const payload = await postJson( rest.monitoring.test, nonce, strings, {} );
			showNotice( payload.ok ? strings.notices.test_ok : strings.notices.test_bad, payload.message );
		} catch ( failure ) {
			showNotice( strings.notices.test_bad, failure.message );
		} finally {
			setTesting( false );
		}
	};

	/**
	 * Rotate the read-only metrics token.
	 *
	 * @param {Event} event Click event.
	 * @return {Promise<void>} Resolves when the request finishes.
	 */
	const rotate = async ( event ) => {
		if ( rotating ) {
			return;
		}

		returnFocus.current = event.currentTarget;
		setRotating( true );
		setCopyStatus( '' );

		try {
			const payload = await postJson( rest.monitoring.rotate, nonce, strings, {} );

			applyState( payload.state || {} );
			showNotice( strings.notices.rotated, payload.message );
		} catch ( failure ) {
			showNotice( strings.notices.failed, failure.message );
		} finally {
			setRotating( false );
		}
	};

	/**
	 * Copy the scrape URL. The readable copy exists because the token is inside
	 * the address and a wrong character silently breaks the scrape.
	 *
	 * @param {Event} event Click event.
	 * @return {Promise<void>} Resolves once the clipboard call settles.
	 */
	const copy = async ( event ) => {
		returnFocus.current = event.currentTarget;
		const copied = await copyText( url );

		setCopyStatus( copied ? strings.copied : strings.copy_failed );
	};

	return (
		<>
			<Card card={ section }>
				<form className="card__form" id={ section.form.id } onSubmit={ save }>
					<div className="monitor-grid">
						<section className="monitor-panel" aria-labelledby={ prometheus.title_id }>
							<h3 className="monitor-panel__title" id={ prometheus.title_id }>
								{ prometheus.title }
							</h3>
							<p className="small-text">{ prometheus.description }</p>
							<TextRow field={ prometheus.field } value={ url } />
							<div className="monitor-panel__actions">
								<button type="button" className="btn btn--ghost btn--small" onClick={ copy }>
									{ prometheus.copy_label }
								</button>
								<button
									type="button"
									className="btn btn--ghost btn--small"
									aria-busy={ rotating ? 'true' : undefined }
									onClick={ rotate }
								>
									<BusyLabel
										idle={ prometheus.rotate_label }
										active={ strings.rotating }
										busy={ rotating }
									/>
								</button>
							</div>
							{ copyStatus ? (
								<p className="field__hint" role="status">
									{ copyStatus }
								</p>
							) : null }
						</section>

						<section className="monitor-panel" aria-labelledby={ alert.title_id }>
							<h3 className="monitor-panel__title" id={ alert.title_id }>
								{ alert.title }
							</h3>
							<div className="field-list">
								<div className="field field--full field--check">
									<div className="field__main">
										<span className="field__label">{ alert.enabled.label }</span>
										<p className="field__hint" id={ enabledHintId }>
											{ alert.enabled.hint }
										</p>
									</div>
									<div className="field__control">
										<label className="check check--stacked" htmlFor={ alert.enabled.id }>
											<input
												id={ alert.enabled.id }
												className="is-switch"
												type="checkbox"
												checked={ isOn( draft.enabled ) }
												aria-describedby={ enabledHintId }
												onChange={ ( event ) =>
													setValue( alert.enabled.key, event.target.checked ? 1 : 0 )
												}
											/>
											<span className="check__text">
												<strong className="check__title">{ alert.enabled.text }</strong>
											</span>
										</label>
									</div>
								</div>

								<TextRow
									field={ alert.webhook }
									value={ draft[ alert.webhook.key ] }
									onChange={ ( value ) => setValue( alert.webhook.key, value ) }
								/>

								<div className="monitor-fields field field--full">
									{ alert.thresholds.map( ( field ) => (
										<Field
											key={ field.id }
											field={ field }
											value={ draft[ field.key ] }
											onChange={ setValue }
										/>
									) ) }
								</div>
							</div>

						</section>
					</div>

					<div className="card__savebar monitor-savebar">
						<div className="monitor-savebar__aside">
							<button
								type="button"
								className="btn btn--ghost btn--small"
								aria-busy={ testing ? 'true' : undefined }
								onClick={ test }
							>
								<BusyLabel
									idle={ alert.test_label }
									active={ strings.testing }
									busy={ testing }
								/>
							</button>
						</div>
						<button
							type="submit"
							className="btn btn--primary"
							disabled={ ! dirty }
							aria-busy={ saving ? 'true' : undefined }
							aria-disabled={ saving ? 'true' : undefined }
						>
							<BusyLabel
								idle={ section.form.save }
								active={ strings.saving }
								busy={ saving }
							/>
						</button>
					</div>
				</form>
			</Card>
			{ notice ? (
				<Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ returnFocus } />
			) : null }
		</>
	);
}
