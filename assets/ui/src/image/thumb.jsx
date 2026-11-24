/**
 * The thumbnail tab: eight cards, five of which own a slice of one option.
 */

import { useEffect, useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';
import { isOn } from './fields.jsx';
import { call, same } from './transport.js';

/**
 * One checkbox with its hidden zero, mirroring the PHP control.
 *
 * @param {Object} props Field props.
 * @return {JSX.Element} Checkbox.
 */
function Checkbox( { id, label, checked, onChange } ) {
	return (
		<label className="check" htmlFor={ id }>
			<input type="hidden" value="0" />
			<input
				id={ id }
				type="checkbox"
				className="is-switch"
				checked={ isOn( checked ) }
				onChange={ ( event ) => onChange( event.target.checked ? 1 : 0 ) }
			/>
			<span className="check__text">{ label }</span>
		</label>
	);
}

/**
 * A plain select.
 *
 * @param {Object} props Field props.
 * @return {JSX.Element} Select.
 */
function Select( { id, value, options, onChange } ) {
	return (
		<select id={ id } value={ value } onChange={ ( event ) => onChange( event.target.value ) }>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	);
}

/**
 * The size table: one row per configured size.
 *
 * @param {Object} props Field props.
 * @return {JSX.Element} Table.
 */
function SizeGrid( { field, rows, onChange } ) {
	const edit = ( name, key, value ) => {
		onChange(
			rows.map( ( row ) => ( row.name === name ? { ...row, [ key ]: value } : row ) )
		);
	};

	return (
		<div className="table-scroll">
			<table className="table sakura-thumb-size-table">
				<thead>
					<tr>
						{ field.columns.map( ( column ) => (
							<th key={ column }>{ column }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => (
						<tr key={ row.name }>
							<td data-label="">
								<span className="sakura-thumb-size-name">
									<strong>{ row.label }</strong>
									<code>{ row.name }</code>
								</span>
							</td>
							<td data-label={ field.columns[ 1 ] }>
								<input
									type="number"
									min="0"
									className="small-text"
									value={ row.width }
									onChange={ ( event ) => edit( row.name, 'width', event.target.value ) }
								/>
							</td>
							<td data-label={ field.columns[ 2 ] }>
								<input
									type="number"
									min="0"
									className="small-text"
									value={ row.height }
									onChange={ ( event ) => edit( row.name, 'height', event.target.value ) }
								/>
							</td>
							<td data-label={ field.columns[ 3 ] }>
								<Select
									value={ row.mode }
									options={ field.modes }
									onChange={ ( value ) => edit( row.name, 'mode', value ) }
								/>
							</td>
							<td data-label={ field.columns[ 4 ] }>
								<Select
									value={ row.position }
									options={ field.positions }
									onChange={ ( value ) => edit( row.name, 'position', value ) }
								/>
							</td>
							<td data-label={ field.columns[ 5 ] }>
								<input
									type="number"
									min="0"
									max="100"
									className="small-text"
									value={ row.quality }
									onChange={ ( event ) => edit( row.name, 'quality', event.target.value ) }
								/>
							</td>
							<td data-label={ field.columns[ 6 ] }>
								<input
									type="text"
									className="regular-text"
									value={ row.style }
									onChange={ ( event ) => edit( row.name, 'style', event.target.value ) }
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="field__hint">{ field.hint }</p>
		</div>
	);
}

/**
 * Fields of one saveable card.
 *
 * @param {Object} props Card draft and setter.
 * @return {JSX.Element} Field list.
 */
function CardFields( { card, draft, setDraft } ) {
	const set = ( key, value ) => setDraft( ( current ) => ( { ...current, [ key ]: value } ) );

	return (
		<div className="field-list">
			{ card.fields.map( ( field ) => {
				const name = field.name;
				const key = field.key || name;

				if ( 'switch' === field.type ) {
					return (
						<div className="field field--switch field--full" key={ name }>
							<div className="field__main">
								<span className="field__label">{ field.label }</span>
							</div>
							<div className="field__control">
								<Checkbox
									id={ field.id || 'sakura_imgopt_' + key }
									label={ field.text }
									checked={ draft[ key ] }
									onChange={ ( value ) => set( key, value ) }
								/>
							</div>
						</div>
					);
				}

				if ( 'select' === field.type ) {
					return (
						<div className="field field--select" key={ name }>
							<div className="field__main">
								<label className="field__label" htmlFor={ field.id }>
									{ field.label }
								</label>
								{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
							</div>
							<div className="field__control">
								<Select
									id={ field.id }
									value={ draft[ key ] }
									options={ field.options }
									onChange={ ( value ) => set( key, value ) }
								/>
							</div>
						</div>
					);
				}

				if ( 'number' === field.type ) {
					return (
						<div className="field field--number" key={ name }>
							<div className="field__main">
								<label className="field__label" htmlFor={ field.id }>
									{ field.label }
								</label>
								{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
							</div>
							<div className="field__control">
								<input
									id={ field.id }
									type="number"
									className="small-text"
									min={ field.min }
									max={ field.max }
									step={ field.step }
									value={ draft[ key ] }
									onChange={ ( event ) => set( key, event.target.value ) }
								/>
							</div>
						</div>
					);
				}

				if ( 'checkgrid' === field.type ) {
					return (
						<div className="field field--checkgrid field--full" key={ name }>
							<div className="check-grid">
								{ field.items.map( ( item ) => (
									<Checkbox
										key={ item.key }
										id={ ( field.id || 'sakura_imgopt_' + key ) + '_' + item.key }
										label={ item.label }
										checked={ ( draft[ key ] || {} )[ item.key ] }
										onChange={ ( value ) =>
											set( key, { ...( draft[ key ] || {} ), [ item.key ]: value } )
										}
									/>
								) ) }
							</div>
							{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
						</div>
					);
				}

				if ( 'scene' === field.type ) {
					return (
						<div className="field field--select" key={ name }>
							<div className="field__main">
								<label className="field__label" htmlFor={ field.id }>
									{ field.label }
								</label>
								{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
							</div>
							<div className="field__control">
								<Select
									id={ field.id }
									value={ ( draft[ key ] || {} )[ field.key ] || '' }
									options={ field.options }
									onChange={ ( value ) =>
										set( key, { ...( draft[ key ] || {} ), [ field.key ]: value } )
									}
								/>
							</div>
						</div>
					);
				}

				if ( 'textarea' === field.type ) {
					return (
						<div className="field field--textarea field--full" key={ name }>
							<div className="field__main">
								<label className="field__label" htmlFor={ field.id }>
									{ field.label }
								</label>
								{ field.hint ? <p className="field__hint">{ field.hint }</p> : null }
							</div>
							<div className="field__control">
								<textarea
									id={ field.id }
									rows={ field.rows }
									className={ field.input_class }
									placeholder={ field.placeholder }
									value={ ( draft.advanced || {} ).custom_provider_template || '' }
									onChange={ ( event ) =>
										setDraft( ( current ) => ( {
											...current,
											advanced: {
												...( current.advanced || {} ),
												custom_provider_template: event.target.value,
											},
										} ) )
									}
								/>
							</div>
						</div>
					);
				}

				return null;
			} ) }
		</div>
	);
}

/**
 * One read-only card body.
 *
 * @param {Object} props Card.
 * @return {JSX.Element|null} Body.
 */
function ReadOnlyBody( { card } ) {
	if ( card.kpis ) {
		return (
			<>
				<div className="kpis">
					{ card.kpis.map( ( kpi ) => (
						<article className="kpi" key={ kpi.label }>
							<p className="kpi__label">{ kpi.label }</p>
							<p className="kpi__value">{ kpi.value }</p>
						</article>
					) ) }
				</div>
				{ card.list && card.list.length ? (
					<ul className="list">
						{ card.list.map( ( item ) => (
							<li key={ item.name }>
								<code>{ item.name }</code>
								<strong>{ item.count }</strong>
							</li>
						) ) }
					</ul>
				) : (
					<p className="field__hint">{ card.empty }</p>
				) }
			</>
		);
	}

	if ( card.lines ) {
		return (
			<>
				{ card.lines.map( ( line ) => (
					<p className="sakura-thumb-readonly" key={ line }>
						{ line }
					</p>
				) ) }
			</>
		);
	}

	if ( card.rows ) {
		return (
			<>
				<ul className="list">
					{ card.rows.map( ( row ) => (
						<li key={ row.label }>
							{ row.label }
							<strong>{ row.value }</strong>
						</li>
					) ) }
				</ul>
				{ card.notes.map( ( note ) => (
					<p className="field__hint" key={ note }>
						{ note }
					</p>
				) ) }
			</>
		);
	}

	return null;
}

/**
 * Saveable card: own draft, own dirty flag.
 *
 * @param {Object} props Card and transport.
 * @return {JSX.Element} Card.
 */
function ThumbCard( { card, rest, strings, onError, onNotice, onServerState } ) {
	const initialRows = ( card.fields.find( ( f ) => 'size-grid' === f.type ) || {} ).rows || [];
	const [ draft, setDraft ] = useState( card.values );
	const [ saved, setSaved ] = useState( card.values );
	const [ rows, setRows ] = useState( initialRows );
	const [ baseline, setBaseline ] = useState( initialRows );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		setSaved( card.values );
		setDraft( card.values );
	}, [ card.values ] );

	const dirty =
		'sizes' === card.id ? ! same( rows, baseline ) : ! same( draft, saved );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );
		onError( '' );

		let fields = draft;

		if ( 'sizes' === card.id ) {
			const sizes = {};
			rows.forEach( ( row ) => {
				sizes[ row.name ] = {
					width: row.width,
					height: row.height,
					mode: row.mode,
					position: row.position,
					quality: row.quality,
					style: row.style,
				};
			} );
			fields = { sizes };
		}

		try {
			const payload = await call( rest, strings, `/cards/${ card.id }`, { fields } );

			if ( payload.values ) {
				setSaved( payload.values );
				setDraft( payload.values );

				if ( 'sizes' === card.id ) {
					setBaseline( rows );
				}
			}

			// The head status card and the alerts are computed from the stored
			// option: take the values the endpoint just recomputed instead of
			// leaving the page head on the state it booted with.
			if ( onServerState && payload.status ) {
				onServerState( { status: payload.status, alerts: payload.alerts } );
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
			<form className="card__form" id={ card.form.id } onSubmit={ submit }>
				{ 'sizes' === card.id ? (
					<SizeGrid
						field={ card.fields.find( ( f ) => 'size-grid' === f.type ) }
						rows={ rows }
						onChange={ setRows }
					/>
				) : (
					<CardFields card={ card } draft={ draft } setDraft={ setDraft } />
				) }
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
 * Thumbnail tab body.
 *
 * @param {Object} props Boot payload and callbacks.
 * @return {JSX.Element} Cards.
 */
export default function ThumbPage( { boot, onError, onNotice, onServerState } ) {
	const { strings, rest } = boot;
	const cards = boot.cards || [];

	return (
		<div className="card-grid" data-sakura-layout="thumb">
			{ cards.map( ( card ) =>
				card.form ? (
					<ThumbCard
						key={ card.id }
						card={ card }
						rest={ rest }
						strings={ strings }
						onError={ onError }
						onNotice={ onNotice }
						onServerState={ onServerState }
					/>
				) : (
					<Card card={ card } key={ card.id }>
						<ReadOnlyBody card={ card } />
					</Card>
				)
			) }
		</div>
	);
}
