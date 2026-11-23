/**
 * Image page fields.
 *
 * The shared <Field> only knows number/select (that is all the system page
 * needs). The rules tab has switches, radios, checkbox grids, the size table and
 * the rename rule composer, so those live here — but they reuse the same field
 * markup and the styles the server-rendered version used.
 */

import { useState } from 'react';

/**
 * Hint text can be one line or several (the size table has two paragraphs).
 *
 * @param {string|string[]|undefined} hint Hint.
 * @return {Element[]|null} Paragraphs.
 */
function Hint( { hint } ) {
	if ( ! hint ) {
		return null;
	}

	const lines = Array.isArray( hint ) ? hint : [ hint ];

	return lines
		.filter( Boolean )
		.map( ( line, index ) => (
			<p className="field__hint" key={ index }>
				{ line }
			</p>
		) );
}

/**
 * The label/control pair every field shares.
 */
export function FieldShell( { field, children } ) {
	const wide =
		field.full || [ 'size-table', 'limits', 'rule-composer', 'checkgrid' ].includes( field.type );
	const after = Boolean( field.hint_after );
	const classes = [
		'field',
		`field--${ field.type }`,
		field.input ? `field--${ field.input }` : '',
		wide ? 'field--full' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className={ classes }>
			<div className="field__main">
				<label className="field__label" htmlFor={ field.for }>
					{ field.label }
				</label>
				{ after ? null : <Hint hint={ field.hint } /> }
			</div>
			<div className="field__control">{ children }</div>
			{ after ? (
				<div className="field__notes">
					<Hint hint={ field.hint } />
				</div>
			) : null }
		</div>
	);
}

function Unit( { children } ) {
	return children ? <span className="small-text">{ children }</span> : null;
}

/**
 * One field of the rules tab.
 *
 * `value` is the raw option value: booleans for switches, arrays for the size
 * table and the checkbox grid, numbers for numbers, strings for text/selects.
 */
export function ImageField( { field, value, values, onChange, group } ) {
	switch ( field.type ) {
		case 'switch':
			return (
				<FieldShell field={ field }>
					<label className="check">
						<input
							type="checkbox"
							className="is-switch"
							id={ field.for }
							name={ field.name }
							checked={ Boolean( value ) }
							onChange={ ( event ) => onChange( field.name, event.target.checked ) }
						/>
						<span>{ field.text || field.label }</span>
					</label>
				</FieldShell>
			);

		case 'radios':
			return (
				<FieldShell field={ field }>
					<div className="check-group" role="radiogroup" aria-labelledby={ `${ field.for }-label` }>
						{ field.options.map( ( option ) => (
							<label className="check" key={ option.value }>
								<input
									type="radio"
									name={ field.name }
									value={ option.value }
									checked={ String( value ) === option.value }
									onChange={ () => onChange( field.name, option.value ) }
								/>
								<span>{ option.label }</span>
							</label>
						) ) }
					</div>
				</FieldShell>
			);

		case 'checkgrid': {
			const selected = Array.isArray( value ) ? value.map( String ) : [];

			return (
				<FieldShell field={ field }>
					<div className="check-grid">
						{ field.options.map( ( option ) => (
							<label className="check" key={ option.value }>
								<input
									type="checkbox"
									name={ `${ field.name }[]` }
									value={ option.value }
									checked={ selected.includes( option.value ) }
									onChange={ ( event ) => {
										const next = event.target.checked
											? [ ...selected, option.value ]
											: selected.filter( ( entry ) => entry !== option.value );

										onChange( field.name, next );
									} }
								/>
								<span>{ option.label }</span>
							</label>
						) ) }
					</div>
				</FieldShell>
			);
		}

		case 'limits':
			return (
				<FieldShell field={ field }>
					<div className="field__inline">
						{ field.parts.map( ( part ) => (
							<span key={ part.name }>
								<input
									type="number"
									className="small-text"
									id={ `sakura_imgopt_${ part.name }` }
									min={ part.min }
									max={ part.max }
									step={ part.step }
									value={ values ? values[ part.name ] ?? '' : '' }
									aria-label={ part.name }
									onChange={ ( event ) => onChange( part.name, event.target.value ) }
								/>
								<Unit>{ part.unit }</Unit>
							</span>
						) ) }
					</div>
				</FieldShell>
			);

		case 'select':
			return (
				<FieldShell field={ field }>
					<select
						id={ field.for }
						name={ field.name }
						value={ String( value ?? '' ) }
						onChange={ ( event ) => onChange( field.name, event.target.value ) }
					>
						{ field.options.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label }
							</option>
						) ) }
					</select>
				</FieldShell>
			);

		case 'number':
			return (
				<FieldShell field={ field }>
					<input
						type="number"
						className="small-text"
						id={ field.for }
						name={ field.name }
						min={ field.min }
						max={ field.max }
						step={ field.step }
						value={ value ?? '' }
						onChange={ ( event ) => onChange( field.name, event.target.value ) }
					/>
					<Unit>{ field.unit }</Unit>
				</FieldShell>
			);

		case 'text':
			return (
				<FieldShell field={ field }>
					<input
						type="text"
						id={ field.for }
						name={ field.name }
						maxLength={ field.maxlength }
						value={ value ?? '' }
						spellCheck="false"
						autoComplete="off"
						onChange={ ( event ) => onChange( field.name, event.target.value ) }
					/>
				</FieldShell>
			);

		default:
			return null;
	}
}

/**
 * The crop-size table: one row per registered/known size, plus the 2560px row.
 */
export function SizeTable( {
	field,
	section,
	disabled,
	onChange,
	scanned,
	onScan,
	scanning,
	scanLabel,
	scanningLabel,
} ) {
	const rows = scanned && scanned.rows ? scanned.rows : section.rows;
	const summary = scanned ? scanned.scan_summary : section.scan_summary;
	const wpConfig = scanned ? scanned.wp_config : section.wp_config;
	const warnings = scanned ? scanned.warnings : section.warnings;
	const set = new Set( ( disabled || [] ).map( String ) );

	return (
		<FieldShell field={ field }>
			<div className="sakura-imgopt-scan-bar">
				<button
					type="button"
					className="btn btn--ghost btn--small"
					disabled={ scanning }
					aria-busy={ scanning ? 'true' : undefined }
					onClick={ onScan }
				>
					<span className="btn__stack">
						<span aria-hidden={ scanning ? undefined : 'true' } style={ { visibility: scanning ? 'visible' : 'hidden' } }>
							{ scanningLabel }
						</span>
						<span aria-hidden={ scanning ? 'true' : undefined } style={ { visibility: scanning ? 'hidden' : 'visible' } }>
							{ scanLabel }
						</span>
					</span>
				</button>
				<span className="sakura-imgopt-scan-summary">{ summary }</span>
			</div>
			<table className="sakura-imgopt-size-table">
				<thead>
					<tr>
						<th className="sakura-imgopt-col-check">{ section.columns[ 0 ] }</th>
						<th>{ section.columns[ 1 ] }</th>
						<th>{ section.columns[ 2 ] }</th>
						<th>{ section.columns[ 3 ] }</th>
						<th>{ section.columns[ 4 ] }</th>
						<th>{ section.columns[ 5 ] }</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => {
						const checked = set.has( row.name );
						const sources = ( row.sources || [] )
							.map( ( source ) =>
								source.detail ? `${ source.label }：${ source.detail }` : source.label
							)
							.join( '；' );

						return (
							<tr key={ row.name } data-size={ row.name }>
								<td className="sakura-imgopt-col-check">
									<input
										type="checkbox"
										name="sakura_imgopt_disabled_sizes[]"
										value={ row.name }
										checked={ checked }
										aria-label={ `${ section.columns[ 0 ] } ${ row.name }` }
										onChange={ ( event ) => {
											const next = event.target.checked
												? [ ...( disabled || [] ).map( String ), row.name ]
												: ( disabled || [] )
														.map( String )
														.filter( ( entry ) => entry !== row.name );

											onChange( 'disabled_sizes', Array.from( new Set( next ) ) );
										} }
									/>
								</td>
								<td>
									<code>{ 'big' === row.kind ? '2560px 缩放版' : row.name }</code>
								</td>
								<td>
									{ 'big' === row.kind
										? '2560px+'
										: `${ row.width || 0 } × ${ row.height || 0 }` }
								</td>
								<td>
									<span
										className={ `sakura-imgopt-status ${ row.tone }` }
										title={ row.reason || undefined }
									>
										{ row.status_label }
									</span>
								</td>
								<td>{ row.crop ? '硬裁剪' : '等比缩放' }</td>
								<td>{ sources }</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ warnings && warnings.length ? (
				<div className="sakura-imgopt-scan-warnings">
					<strong>扫描提示</strong>
					<ul>
						{ warnings.map( ( warning, index ) => (
							<li key={ index }>{ warning }</li>
						) ) }
					</ul>
				</div>
			) : null }
			{ wpConfig ? <p className="description">{ wpConfig }</p> : null }
			{ section.notes.map( ( note, index ) => (
				<p className="description" key={ index }>
					{ note }
				</p>
			) ) }
		</FieldShell>
	);
}

/**
 * Client-side mirror of Upload_Renamer::preview_filename().
 *
 * Kept deliberately simple: the server value is authoritative and is what the
 * payload ships; this only keeps the preview honest while typing.
 */
function previewName( rule, samples ) {
	let text = ( rule || '' ).trim() || '{date}-{time}-{rand}';

	Object.entries( samples ).forEach( ( [ token, value ] ) => {
		if ( '' === value ) {
			text = text.split( token ).join( '' );
		} else {
			text = text.split( token ).join( value );
		}
	} );

	text = text.replace( /^[._-]+|[._-]+$/g, '' ).replace( /[^A-Za-z0-9._-]+/g, '-' );

	return /\.webp$/i.test( text ) ? text : `${ text || 'sakura' }.webp`;
}

/**
 * The rename rule: text input, removable token buttons, live preview.
 */
export function RuleComposer( { field, section, rule, fixed, onRule } ) {
	const [ more, setMore ] = useState( false );

	// Mirrors assets/ui/js/pages/image-rules.js: every token is joined with a
	// hyphen ({ext} with a dot) and only inserted once. Appending the bare token
	// produced names like "{date}{time}" — no separators at all.
	const insert = ( token ) => {
		const value = rule.trim();

		if ( value.includes( token ) ) {
			return value;
		}

		if ( '' === value ) {
			return token;
		}

		const separator = '{ext}' === token ? '.' : '-';

		return /[-_.]$/.test( value ) ? value + token : value + separator + token;
	};

	const toggleToken = ( token ) => {
		if ( rule.includes( token ) ) {
			const next = rule
				.split( token )
				.join( '' )
				.replace( /([-_.])[-_.]+/g, '$1' )
				.replace( /^[-_.]+|[-_.]+$/g, '' );

			onRule( next );

			return;
		}

		onRule( insert( token ) );
	};

	return (
		<FieldShell field={ field }>
			<div className="rule-composer">
			<div className="rule-composer__row">
				<input
					type="text"
					className="rule-composer__input"
					id="sakura_imgopt_rename_rule"
					value={ rule }
					spellCheck="false"
					autoComplete="off"
					onChange={ ( event ) => onRule( event.target.value ) }
				/>
				<button
					type="button"
					className="btn btn--ghost btn--small"
					onClick={ () => onRule( section.default_rule ) }
				>
					{ section.labels.reset }
				</button>
			</div>
			<div className="rule-composer__tokens" role="group" aria-label="重命名占位符">
				{ section.tokens.map( ( entry ) => {
					const active = rule.includes( entry.token );

					return (
						<button
							type="button"
							key={ entry.token }
							className={ `rule-token${ '{fixed}' === entry.token ? ' rule-token--fixed' : '' }${
								'{fixed}' === entry.token && '' === fixed ? ' is-empty' : ''
							}` }
							aria-pressed={ active }
							title={ active ? section.labels.remove : section.labels.insert }
							onClick={ () => toggleToken( entry.token ) }
						>
							<code>{ entry.token }</code>
							<span>{ entry.label }</span>
						</button>
					);
				} ) }
			</div>
			<button
				type="button"
				className="rule-composer__more"
				aria-expanded={ more }
				onClick={ () => setMore( ! more ) }
			>
				{ more ? section.labels.less : section.labels.more }
			</button>
			<div
				className="rule-composer__tokens rule-composer__tokens--more"
				role="group"
				aria-label="更多占位符"
				hidden={ ! more }
			>
				{ section.more_tokens.map( ( entry ) => {
					const active = rule.includes( entry.token );

					return (
						<button
							type="button"
							key={ entry.token }
							className="rule-token"
							aria-pressed={ active }
							title={ active ? section.labels.remove : section.labels.insert }
							onClick={ () => toggleToken( entry.token ) }
						>
							<code>{ entry.token }</code>
							<span>{ entry.label }</span>
						</button>
					);
				} ) }
			</div>
			<p className="rule-composer__preview">
				{ section.labels.preview }{ ' ' }
				<code>{ previewName( rule, section.samples ) }</code>
			</p>
		</div>
		</FieldShell>
	);
}
