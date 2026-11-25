import { useState } from 'react';
import { Card, Field } from '../shared/ui.jsx';
import { Form } from './form.jsx';

/**
 * Upload / quota policy card. The field names and grouping mirror the old
 * server-rendered form so options.php keeps receiving the same payload.
 */
export default function Policy( { card, config } ) {
	const strings = config.strings || {};
	const policy = config.policy || {};
	const groups = policy.groups || [];
	const limits = policy.themeLimits;
	const initial = {};

	groups.forEach( ( group ) => {
		( group.fields || [] ).forEach( ( field ) => {
			initial[ field.key ] = field.value;
		} );
	} );
	if ( limits ) {
		( limits.fields || [] ).forEach( ( field ) => {
			initial[ field.key ] = field.value;
		} );
	}

	const [ values, setValues ] = useState( initial );
	const [ types, setTypes ] = useState( () => {
		const next = {};
		( policy.allowedTypes || [] ).forEach( ( type ) => {
			next[ type.mime ] = !! type.checked;
		} );
		return next;
	} );

	const change = ( key, value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const action = ( card.actions || [] )[ 0 ];

	return (
		<Card
			card={ card }
			actions={
				action ? (
					<a className="btn btn--ghost btn--small" href={ action.url }>
						{ action.label }
					</a>
				) : null
			}
		>
			<Form config={ config } card="settings" hidden={ policy.hidden || {} }>
				<p className="field__hint moved-hint">{ policy.movedHint }</p>

				{ groups.map( ( group ) => (
					<section className="policy-group" key={ group.title }>
						<div className="policy-group__head">
							<h3>{ group.title }</h3>
							{ group.hint ? <p>{ group.hint }</p> : null }
						</div>
						<div className="field-list">
							{ ( group.fields || [] ).map( ( field ) => (
								<Field
									key={ field.key }
									field={ { ...field, type: 'number' } }
									value={ values[ field.key ] }
									onChange={ change }
								/>
							) ) }
						</div>
					</section>
				) ) }

				{ limits ? (
					<fieldset className="theme-limits">
						<legend>
							{ strings.themeLimits }
							{ limits.source ? <span className="badge badge--neutral">{ limits.source }</span> : null }
						</legend>
						<p className="field__hint">{ ( strings.themeReadFrom || '' ).replace( '%s', limits.source || '' ) }</p>
						<div className="field-list">
							{ ( limits.fields || [] ).map( ( field ) => (
								<Field
									key={ field.key }
									field={ { ...field, type: 'number' } }
									value={ values[ field.key ] }
									onChange={ change }
								/>
							) ) }
						</div>
						{ limits.syncedAt ? (
							<p className="field__hint">{ ( strings.themeSyncedAt || '' ).replace( '%s', limits.syncedAt ) }</p>
						) : null }
					</fieldset>
				) : null }

				<fieldset className="type-field">
					<legend>{ strings.allowedTypes }</legend>
					<p className="field__hint">{ strings.allowedHint }</p>
					<div className="check-grid">
						{ ( policy.allowedTypes || [] ).map( ( type ) => (
							<label className="check" key={ type.mime }>
								<input
									type="checkbox"
									name={ config.option + '[allowed_types][]' }
									value={ type.mime }
									checked={ !! types[ type.mime ] }
									onChange={ ( event ) =>
										setTypes( ( current ) => ( { ...current, [ type.mime ]: event.target.checked } ) )
									}
								/>
								<span className="check__box" aria-hidden="true" />
								<span className="check__text">{ type.label }</span>
							</label>
						) ) }
					</div>
				</fieldset>

				<div className="card__savebar">
					<button type="submit" className="btn btn--primary">
						{ strings.savePolicy }
					</button>
				</div>
			</Form>
		</Card>
	);
}
