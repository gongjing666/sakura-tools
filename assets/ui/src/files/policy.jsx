import { useActionState, useState } from "react";
import { BusyLabel, Card, Field } from "../shared/ui.jsx";
import { post } from "../shared/transport.js";

/**
 * Everything the card edits, in form units. allowed_types is always a list.
 */
function draftFrom( card ) {
	const next = {};

	Object.entries( card.values || {} ).forEach( ( [ key, value ] ) => {
		next[ key ] = value;
	} );

	if ( ! Array.isArray( next.allowed_types ) ) {
		next.allowed_types = [];
	}

	return next;
}

/**
 * Upload and quota policy card.
 */
export default function Policy( { card, rest, strings, onSection, onNotice } ) {
	const groups = card.groups || [];
	const limits = card.themeLimits;
	const [ saved, setSaved ] = useState( () => draftFrom( card ) );
	const [ draft, setDraft ] = useState( () => draftFrom( card ) );
	const [ lastKey, setLastKey ] = useState( () => JSON.stringify( card.values ) );

	const valuesKey = JSON.stringify( card.values );

	/* Props to state, compared during render: no useEffect sync (14 section 5). */
	if ( valuesKey !== lastKey ) {
		setLastKey( valuesKey );
		setSaved( draftFrom( card ) );
		setDraft( draftFrom( card ) );
	}

	const dirty = JSON.stringify( draft ) !== JSON.stringify( saved );

	const change = ( key, value ) => setDraft( ( current ) => ( { ...current, [ key ]: value } ) );

	const toggleType = ( mime, checked ) => setDraft( ( current ) => {
		const next = new Set( current.allowed_types || [] );

		if ( checked ) {
			next.add( mime );
		} else {
			next.delete( mime );
		}

		return { ...current, allowed_types: Array.from( next ) };
	} );

	const [ failure, save, pending ] = useActionState( async ( _prev, fields ) => {
		try {
			const payload = await post( rest, rest.url, { card: "settings", fields }, strings );
			const section = payload && payload.sections ? payload.sections.settings : null;

			if ( section ) {
				onSection( "settings", section );
			}

			if ( payload && payload.notice ) {
				onNotice( payload.notice, "settings" );
			}

			return "";
		} catch ( err ) {
			return err && err.message ? String( err.message ) : ( strings.failed || "" );
		}
	}, "" );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending || ! dirty ) {
			return;
		}

		save( draft );
	};

	return (
		<Card card={ card } titleProps={ { tabIndex: -1, "data-sakura-return-target": "1" } }>
			<form className="card__form" onSubmit={ submit } noValidate>
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
									field={ { ...field, type: "number" } }
									value={ draft[ field.key ] || "" }
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
						<p className="field__hint">{ ( strings.themeReadFrom || "" ).replace( "%s", limits.source || "" ) }</p>
						<div className="field-list">
							{ ( limits.fields || [] ).map( ( field ) => (
								<Field
									key={ field.key }
									field={ { ...field, type: "number" } }
									value={ draft[ field.key ] || "" }
									onChange={ change }
								/>
							) ) }
						</div>
						{ limits.syncedAt ? (
							<p className="field__hint">{ ( strings.themeSyncedAt || "" ).replace( "%s", limits.syncedAt ) }</p>
						) : null }
					</fieldset>
				) : null }

				<fieldset className="type-field">
					<legend>{ strings.allowedTypes }</legend>
					<p className="field__hint">{ strings.allowedHint }</p>
					<div className="check-grid">
						{ ( card.allowedTypes || [] ).map( ( type ) => (
							<label className="check" key={ type.mime }>
								<input
									type="checkbox"
									name="allowed_types[]"
									value={ type.mime }
									checked={ ( draft.allowed_types || [] ).includes( type.mime ) }
									onChange={ ( event ) => toggleType( type.mime, event.target.checked ) }
								/>
								<span className="check__box" aria-hidden="true" />
								<span className="check__text">{ type.label }</span>
							</label>
						) ) }
					</div>
				</fieldset>

				{ failure ? (
					<p className="field__hint accent-custom__error" role="alert">{ failure }</p>
				) : null }
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary" disabled={ ! dirty } aria-busy={ pending ? "true" : "false" }>
						<BusyLabel idle={ strings.savePolicy } active={ strings.savingPolicy } busy={ pending } />
					</button>
				</div>
			</form>
		</Card>
	);
}
