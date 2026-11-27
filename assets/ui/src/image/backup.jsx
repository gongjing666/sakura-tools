/**
 * The backup tab: two read-only cards (usage, directory exposure) and the
 * cleanup-policy card. Actions POST to the REST endpoints and refresh the
 * numbers in place — the document never navigates.
 */

import { useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';
import { call, same } from './transport.js';

/**
 * One KPI cell.
 *
 * @param {Object} props Kpi.
 * @return {JSX.Element} Kpi.
 */
function Kpi( { kpi } ) {
	return (
		<div className="kpi" data-tone={ kpi.tone || undefined }>
			<p className="kpi__label">{ kpi.label }</p>
			<p className="kpi__value" id={ kpi.id || undefined }>
				{ kpi.value }
			</p>
			{ kpi.sub ? <p className="kpi__sub">{ kpi.sub }</p> : null }
		</div>
	);
}

/**
 * Action buttons, one busy flag for the whole row.
 *
 * @param {Object} props Card, transport and the action runner.
 * @return {JSX.Element} Buttons.
 */
function Actions( { card, strings, onAction, busy } ) {
	return (
		<div className="card__savebar">
			{ ( card.buttons || [] ).map( ( button ) => (
				<button
					key={ button.action }
					type="button"
					className={ 'btn' + ( button.variant ? ' btn--' + button.variant : '' ) }
					disabled={ Boolean( busy ) }
					onClick={ () => onAction( button.action ) }
				>
					<BusyLabel idle={ button.label } active={ strings.working } busy={ busy === button.action } />
				</button>
			) ) }
		</div>
	);
}

/**
 * Read-only body: kpis, a message, a hint and a code block.
 *
 * @param {Object} props Card.
 * @return {JSX.Element} Body.
 */
function ReadOnlyBody( { card, message, code } ) {
	return (
		<>
			{ card.kpis ? (
				<div className="kpis kpis--card">
					{ card.kpis.map( ( kpi ) => (
						<Kpi key={ kpi.label } kpi={ kpi } />
					) ) }
				</div>
			) : null }
			{ message ? (
				<p className="system-note" data-tone={ message.tone } id={ message.id }>
					{ message.text }
				</p>
			) : null }
			{ card.hint ? <p className="field__hint">{ card.hint }</p> : null }
			{ code ? (
				<pre className="code-block">
					<code>{ code }</code>
				</pre>
			) : null }
		</>
	);
}

/**
 * The cleanup-policy card.
 *
 * @param {Object} props Card, transport and callbacks.
 * @return {JSX.Element} Card.
 */
function PolicyCard( { card, rest, strings, onError, onNotice, onSaved } ) {
	const [ draft, setDraft ] = useState( card.values );
	const [ saved, setSaved ] = useState( card.values );
	const [ saving, setSaving ] = useState( false );

	const dirty = ! same( draft, saved );

	const edit = ( name, value ) => setDraft( { ...draft, [ name ]: value } );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );
		onError( '' );

		try {
			const payload = await call( rest, strings, '/cards/access', { fields: draft } );

			if ( payload.values ) {
				setDraft( payload.values );
				setSaved( payload.values );

				if ( onSaved ) {
					onSaved( payload.values );
				}
			}

			if ( payload.notice ) {
				onNotice( payload.notice );
			}
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card card={ card }>
			<form className="card__form" id={ card.form.id } onSubmit={ submit } noValidate>
				<div className="field-list">
					{ card.fields.map( ( field ) => {
						const id = field.id || 'sakura_imgopt_' + field.name;

						if ( 'switch' === field.type ) {
							const value = draft[ field.name ];

							return (
								<div className={ 'field field--switch' + ( field.full ? ' field--full' : '' ) } key={ field.name }>
									<label className="check" htmlFor={ id }>
										<input
											id={ id }
											type="checkbox"
											className="is-switch"
											checked={ 1 === Number( value ) }
											onChange={ ( event ) => edit( field.name, event.target.checked ? 1 : 0 ) }
										/>
										<span className="check__text">{ field.text || field.label }</span>
									</label>
									<p className="field__hint">{ field.hint }</p>
								</div>
							);
						}

						return (
							<div className="field field--number" key={ field.name }>
								<div className="field__main">
									<label className="field__label" htmlFor={ id }>
										{ field.label }
									</label>
									<p className="field__hint" id={ id + '-hint' }>
										{ field.hint }
									</p>
								</div>
								<div className="field__control">
									<input
										id={ id }
										type="number"
										className={ field.input_class || 'small-text' }
										min={ field.min }
										step={ field.step }
										value={ draft[ field.name ] }
										aria-describedby={ id + '-hint' }
										onChange={ ( event ) => edit( field.name, event.target.value ) }
									/>
								</div>
							</div>
						);
					} ) }
				</div>
				<div className="card__savebar">
					<button
						type="submit"
						className="btn btn--primary"
						disabled={ ! dirty }
						aria-busy={ saving ? 'true' : undefined }
						aria-disabled={ saving ? 'true' : undefined }
					>
						<BusyLabel idle={ card.form.save } active={ strings.saving } busy={ saving } />
					</button>
				</div>
			</form>
		</Card>
	);
}

/**
 * Backup tab body.
 *
 * @param {Object} props Boot payload and callbacks.
 * @return {JSX.Element} Cards.
 */
export default function BackupPage( { boot, onError, onNotice, onUsage } ) {
	const { strings, rest } = boot;
	const cards = boot.cards || [];
	const [ busy, setBusy ] = useState( '' );
	const [ probe, setProbe ] = useState( null );
	const [ message, setMessage ] = useState( null );

	const usageCard = cards.find( ( card ) => 'usage' === card.id );
	const policyCard = cards.find( ( card ) => 'policy' === card.id );
	const accessCard = cards.find( ( card ) => 'access' === card.id );

	const [ usage, setUsage ] = useState( () => {
		const kpis = ( usageCard && usageCard.kpis ) || [];

		return {
			size: kpis[ 0 ] ? kpis[ 0 ].value : '',
			count: kpis[ 1 ] ? kpis[ 1 ].value : '',
		};
	} );

	const shownUsage = usageCard
		? {
				...usageCard,
				kpis: usageCard.kpis.map( ( kpi, index ) =>
					index === 0
						? { ...kpi, value: usage.size }
						: { ...kpi, value: usage.count }
				),
		  }
		: null;

	const run = async ( action ) => {
		setBusy( action );
		onError( '' );

		try {
			const payload = await call( rest, strings, '/' + action, {} );

			if ( payload.usage ) {
				setUsage( {
					size: payload.usage.size,
					count: payload.usage.count_text,
				} );

				if ( onUsage ) {
					onUsage( payload.usage );
				}
			}

			if ( payload.message ) {
				setMessage( { text: payload.message, tone: payload.message_tone || 'ok' } );
			}

			if ( payload.probe ) {
				setProbe( payload.probe );
			}
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setBusy( '' );
		}
	};

	const shownPolicy = policyCard
		? {
				...policyCard,
				kpis: probe
					? policyCard.kpis.map( ( kpi, index ) =>
							index === 0
								? kpi
								: {
										...kpi,
										value:
											'exposed' === probe.state
												? '可被公网访问'
												: 'protected' === probe.state
												? '已拦截'
												: kpi.value,
										tone:
											'exposed' === probe.state
												? 'bad'
												: 'protected' === probe.state
												? 'ok'
												: kpi.tone,
								  }
					  )
					: policyCard.kpis,
		  }
		: null;

	return (
		<div className="card-grid" data-sakura-layout="backup">
			{ shownUsage ? (
				<Card card={ shownUsage }>
					<ReadOnlyBody card={ shownUsage } message={ message } />
					<Actions card={ shownUsage } strings={ strings } onAction={ run } busy={ busy } />
				</Card>
			) : null }

			{ shownPolicy ? (
				<Card card={ shownPolicy }>
					<ReadOnlyBody
						card={ shownPolicy }
						message={ probe ? { text: probe.message, tone: probe.state, id: 'sakura-probe-state' } : shownPolicy.message }
						code={ shownPolicy.code }
					/>
					<div className="card__savebar">
						{ ( shownPolicy.buttons || [] ).map( ( button ) => (
							<button
								key={ button.action }
								type="button"
								className="btn"
								disabled={ Boolean( busy ) }
								onClick={ () => run( button.action ) }
							>
								<BusyLabel idle={ button.label } active={ strings.working } busy={ busy === button.action } />
							</button>
						) ) }
					</div>
				</Card>
			) : null }

			{ accessCard ? (
				<PolicyCard
					card={ accessCard }
					rest={ rest }
					strings={ strings }
					onError={ onError }
					onNotice={ onNotice }
				/>
			) : null }
		</div>
	);
}
