/**
 * The upload-behaviour card: two switches that are frozen once the store has
 * taken its first upload, plus the transfer concurrency.
 *
 * The switches are part of the bucket binding (R3 / R11): after the first
 * confirmed upload the server keeps them and ignores whatever the form says, so
 * a frozen card renders them as plain rows with a lock instead of checkboxes
 * that would look editable and then be discarded.
 */
import { useEffect, useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';

/**
 * @param {Object}   props         Card payload and callbacks.
 * @param {Object}   props.card    behaviour section.
 * @param {Object}   props.strings Payload strings.
 * @param {boolean}  props.saving  A save is in flight.
 * @param {Function} props.onSave  Persist the draft; the ack flag says whether
 *                                 the risky switch was just confirmed.
 * @param {Function} props.askRisk Opens the risk dialog; the callback saves.
 * @return {JSX.Element} Card.
 */
export default function BehaviourCard( { card, strings, saving, onSave, askRisk } ) {
	const base = ( ) => ( {
		no_thumb: card.switches[ 0 ].checked,
		no_local_saving: card.switches[ 1 ].checked,
		upload_concurrency: card.concurrency.value,
	} );

	const [ draft, setDraft ] = useState( base );
	const [ saved, setSaved ] = useState( base );

	// Saving another card returns this section too; only a real change to the
	// stored values should throw away what has been typed here.
	const valuesKey =
		JSON.stringify( [ card.switches[ 0 ].checked, card.switches[ 1 ].checked ] ) +
		'|' +
		card.concurrency.value;

	useEffect( () => {
		const next = {
			no_thumb: card.switches[ 0 ].checked,
			no_local_saving: card.switches[ 1 ].checked,
			upload_concurrency: card.concurrency.value,
		};

		setDraft( next );
		setSaved( next );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ valuesKey ] );

	const dirty =
		draft.no_thumb !== saved.no_thumb ||
		draft.no_local_saving !== saved.no_local_saving ||
		draft.upload_concurrency !== saved.upload_concurrency;

	const body = ( ack ) => ( {
		no_thumb: draft.no_thumb ? '1' : '',
		no_local_saving: draft.no_local_saving ? '1' : '',
		no_local_saving_ack: ack ? '1' : '0',
		upload_concurrency: String( draft.upload_concurrency ),
	} );

	const submit = ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		// Opening the risky switch has to carry an explicit acknowledgement
		// (R2): the server refuses the save without it, so ask first instead of
		// letting the request come back as an error.
		const opening = draft.no_local_saving && ! saved.no_local_saving;

		if ( opening && ! card.frozen ) {
			askRisk( () => onSave( body( true ) ) );

			return;
		}

		onSave( body( false ) );
	};

	return (
		<Card card={ card }>
			<div className={ 'behaviour-lock' + ( card.frozen ? ' is-locked' : '' ) }>{ card.lockNote }</div>

			<form className="card__form" id="sakura-s3-behaviour-form" onSubmit={ submit }>
				<div className="field-list">
					{ card.switches.map( ( item ) => (
						<div className="field field--check field--full" key={ item.name }>
							<div className="field__control">
								{ item.locked ? (
									<div className="check check--stacked is-locked">
										<span className="check__lock" aria-hidden="true" />
										<span className={ 'check__state ' + ( item.checked ? 'is-on' : 'is-off' ) }>
											{ item.state }
										</span>
										<span className="check__text">
											<strong className="check__title">{ item.label }</strong>
											<span className={ 'check__hint' + ( item.danger ? ' is-danger' : '' ) }>
												{ item.hint }
											</span>
										</span>
									</div>
								) : (
									<label className="check check--stacked">
										<input
											type="checkbox"
											className="is-switch"
											name={ item.name }
											checked={ item.name === 'no_thumb' ? draft.no_thumb : draft.no_local_saving }
											onChange={ ( event ) =>
												setDraft( ( current ) => ( {
													...current,
													[ item.name ]: event.target.checked,
												} ) )
											}
										/>
										<span className="check__text">
											<strong className="check__title">{ item.label }</strong>
											<span className={ 'check__hint' + ( item.danger ? ' is-danger' : '' ) }>
												{ item.hint }
											</span>
										</span>
									</label>
								) }
							</div>
						</div>
					) ) }

					<div className="field field--select">
						<div className="field__main">
							<label className="field__label" htmlFor="sakura-s3-concurrency">
								{ card.concurrency.label }
							</label>
							<p className="field__hint">{ card.concurrency.hint }</p>
						</div>
						<div className="field__control">
							<select
								id="sakura-s3-concurrency"
								name="upload_concurrency"
								value={ draft.upload_concurrency }
								onChange={ ( event ) =>
									setDraft( ( current ) => ( { ...current, upload_concurrency: event.target.value } ) )
								}
							>
								{ card.concurrency.options.map( ( option ) => (
									<option key={ option.value } value={ option.value }>
										{ option.label }
									</option>
								) ) }
							</select>
						</div>
					</div>

					{ card.concurrency.fallbackNote ? (
						<div className="notice-inline notice-inline--warn" role="status">
							<p>{ card.concurrency.fallbackNote }</p>
						</div>
					) : null }
				</div>

				<div className="card__savebar">
					<button
						type="submit"
						className="btn btn--primary"
						disabled={ ! dirty || saving }
						aria-busy={ saving ? 'true' : undefined }
					>
						<BusyLabel idle={ card.save } active={ strings.saving } busy={ saving } />
					</button>
				</div>
			</form>
		</Card>
	);
}
