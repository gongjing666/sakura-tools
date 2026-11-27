/**
 * Batch dispatch settings for the delete queue.
 *
 * Pure presentation: the policy and the live queue counters arrive in the
 * snapshot, every label arrives in strings.dispatch, and saving goes through
 * the REST route. No server-rendered form, no copy in this file.
 */
import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { postAction } from './transport.js';

export default function DispatchCard( { dispatch, strings, rest, onSaved } ) {
	const labels = ( strings && strings.dispatch ) || {};
	const copy = labels.status || {};
	const fields = Array.isArray( labels.fields ) ? labels.fields : [];
	const [ form, setForm ] = useState( dispatch && dispatch.policy ? dispatch.policy : {} );
	const [ busy, setBusy ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const queued = ( dispatch && dispatch.queued ) || {};

	const change = ( key, value ) => {
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const save = () => {
		if ( busy ) {
			return;
		}

		setBusy( true );
		setNote( '' );

		postAction( rest, 'dispatch', form, strings )
			.then( ( data ) => {
				setNote( labels.saved || '' );

				if ( onSaved ) {
					onSaved( data );
				}
			} )
			.catch( ( error ) => {
				setNote( String( ( error && error.message ) || ( labels.saveFailed || '' ) ) );
			} )
			.finally( () => setBusy( false ) );
	};

	const oldest = Number( queued.oldest || 0 );
	const wait = Number( form.max_wait || 0 );
	const waited = oldest > 0 ? Math.max( 0, Math.floor( Date.now() / 1000 ) - oldest ) : 0;
	const remain = oldest > 0 && wait > 0 ? Math.max( 0, wait - waited ) : 0;
	const count = Number( queued.count || 0 );
	const bytes = Number( queued.bytes || 0 );

	let status = copy.empty || '';

	if ( count > 0 ) {
		status = form.enabled
			? String( copy.waiting || '' )
				.replace( '%1$s', String( count ) )
				.replace( '%2$s', String( Math.round( bytes / 1048576 ) ) )
				.replace( '%3$s', String( waited ) ) +
				( wait > 0 ? String( copy.due || '' ).replace( '%s', String( remain ) ) : String( copy.checkpoint || '' ) )
			: String( copy.disabled || '' );
	}

	return (
		<Card card={ { id: 'delete-dispatch', kicker: labels.kicker, title: labels.title, description: labels.desc } }>
			<div className="field-list">
				<div className="field field--check field--full">
					<div className="field__control">
						<label className="check check--stacked">
							<input
								type="checkbox"
								className="is-switch"
								checked={ Boolean( form.enabled ) }
								onChange={ ( event ) => change( 'enabled', event.target.checked ? 1 : 0 ) }
							/>
							<span className="check__text">
								<strong className="check__title">{ labels.enabled }</strong>
								<span className="check__hint">{ labels.enabledHint }</span>
							</span>
						</label>
					</div>
				</div>

				{ fields.map( ( item ) => (
					<div className="field field--limits" key={ item.key }>
						<div className="field__main">
							<label className="field__label" htmlFor={ 'dispatch-' + item.key }>{ item.label }</label>
							<p className="field__hint">{ item.hint }</p>
						</div>
						<div className="field__control">
							<span className="field__inline">
								<span className="field__inline-part">
									<input
										id={ 'dispatch-' + item.key }
										type="number"
										inputMode="numeric"
										min={ item.min }
										max={ item.max }
										value={ form[ item.key ] == null ? '' : form[ item.key ] }
										onChange={ ( event ) => change( item.key, event.target.value ) }
									/>
									<span className="field__inline-label">{ item.unit }</span>
								</span>
							</span>
						</div>
					</div>
				) ) }
			</div>

			<p className="small-text">{ status }</p>

			<div className="card__savebar">
				{ note ? <span className="card-savebar__note">{ note }</span> : null }
				<button type="button" className="btn btn--primary" disabled={ busy } onClick={ save }>
					{ busy ? labels.saving : labels.save }
				</button>
			</div>
		</Card>
	);
}
