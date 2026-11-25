import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { Form } from './form.jsx';
import { isHex } from './color.js';

const DEFAULT_COLOR = '#c8932b';

/**
 * Folder appearance card: style preview, preset palette and a free colour.
 */
export default function Appearance( { card, config } ) {
	const strings = config.strings || {};
	const choices = config.accentChoices || [];
	const styles = config.styles || [];
	const fallbackColor = config.current.customColor || DEFAULT_COLOR;
	const [ style, setStyle ] = useState( config.current.treeStyle || 'windows' );
	const [ accent, setAccent ] = useState( config.current.folderAccent || '' );
	const [ hex, setHex ] = useState( isHex( config.current.folderAccent ) ? config.current.folderAccent : fallbackColor );
	const [ error, setError ] = useState( '' );

	const custom = isHex( accent );
	const colorValue = custom ? accent : ( isHex( hex ) ? hex : fallbackColor );
	const styleMeta = styles.find( ( item ) => item.value === style ) || {};
	const accentMeta = choices.find( ( item ) => item.value === accent );
	const accentLabel = custom ? strings.customColor : ( accentMeta ? accentMeta.label : config.current.accentLabel );
	const summary = ( strings.currentSummary || '' )
		.replace( '{style}', styleMeta.label || config.current.styleLabel || '' )
		.replace( '{accent}', accentLabel || '' );

	const selectPreset = ( value ) => {
		const choice = choices.find( ( item ) => item.value === value );
		setAccent( value );
		setHex( choice && choice.ink ? choice.ink : fallbackColor );
		setError( '' );
	};

	const selectCustom = () => {
		const value = isHex( hex ) ? hex : DEFAULT_COLOR;
		setAccent( value );
		setHex( value );
		setError( '' );
	};

	const onColour = ( value ) => {
		setAccent( value );
		setHex( value );
		setError( '' );
	};

	const onHex = ( value ) => {
		setHex( value );
		setError( '' );

		if ( isHex( value ) ) {
			setAccent( value );
		}
	};

	const applyHex = () => {
		if ( ! isHex( hex ) ) {
			setError( strings.hexInvalid );
			return;
		}

		setAccent( hex );
		setError( '' );
	};

	const previewStyle = custom ? { '--folder-ink': accent } : undefined;
	const rows = [
		{ name: strings.previewImages, count: 128, active: true },
		{ name: strings.previewArchive, count: 36, nested: true },
		{ name: strings.previewInbox, count: 12, nested: true },
	];

	return (
		<Card card={ card }>
			<Form config={ config } card="appearance">
				<div className="appearance-grid">
					<div
						className="tree-preview"
						data-tree-preview
						data-style={ style }
						data-accent={ custom ? 'custom' : ( accent || 'auto' ) }
						style={ previewStyle }
					>
						<div className="tree-preview__bar" aria-hidden="true">
							<span />
							<span />
							<span />
						</div>
						{ rows.map( ( row ) => (
							<div
								key={ row.name }
								className={ row.active ? 'tree-preview__row is-active' : ( row.nested ? 'tree-preview__row is-nested' : 'tree-preview__row' ) }
							>
								<span className="tree-folder" aria-hidden="true" />
								<span className="tree-preview__name">{ row.name }</span>
								<span className="tree-preview__count">{ row.count }</span>
							</div>
						) ) }
					</div>

					<div className="appearance-fields">
						<fieldset className="choice-field">
							<legend>{ strings.styleLegend }</legend>
							<div className="style-choices">
								{ styles.map( ( item ) => (
									<label className="style-choice" key={ item.value } data-label={ item.label }>
										<input
											type="radio"
											name="sufm_settings[tree_style]"
											value={ item.value }
											checked={ style === item.value }
											onChange={ () => setStyle( item.value ) }
										/>
										<span className={ 'style-choice__visual style-choice__visual--' + item.value } aria-hidden="true">
											<span />
											<span />
											<span />
										</span>
										<span className="style-choice__copy">
											<strong>{ item.label }</strong>
											<span>{ item.description }</span>
										</span>
									</label>
								) ) }
							</div>
							<p className="field__hint">{ strings.styleHint }</p>
						</fieldset>

						<fieldset className="choice-field">
							<legend>{ strings.accentLegend }</legend>
							<div className="accent-choices">
								{ choices.map( ( choice ) => (
									<label
										className={ 'accent-choice' + ( choice.value === accent ? ' is-selected' : '' ) }
										key={ choice.value || 'auto' }
										data-accent={ choice.value || 'auto' }
										data-label={ choice.label }
									>
										<input
											type="radio"
											name="sufm_settings[folder_accent]"
											value={ choice.value }
											checked={ choice.value === accent }
											onChange={ () => selectPreset( choice.value ) }
										/>
										<span
											className={ 'accent-choice__dot accent-choice__dot--' + ( choice.value || 'auto' ) }
											aria-hidden="true"
										/>
										<span>{ choice.label }</span>
									</label>
								) ) }
								<label className={ 'accent-choice' + ( custom ? ' is-selected' : '' ) } data-accent="custom" data-label={ strings.customColor }>
									<input
										type="radio"
										name="sufm_settings[folder_accent]"
										value={ colorValue }
										checked={ custom }
										onChange={ selectCustom }
									/>
									<span className="accent-choice__dot" style={ { background: colorValue } } aria-hidden="true" />
									<span>{ strings.customColor }</span>
								</label>
							</div>
							<p className="field__hint">{ strings.accentHint }</p>

							<div className="accent-custom">
								<span className="accent-custom__label">{ strings.customColor }</span>
								<input
									type="color"
									className="accent-custom__color"
									value={ colorValue }
									aria-label={ strings.customColor }
									onChange={ ( event ) => onColour( event.target.value ) }
								/>
								<input
									type="text"
									className="accent-custom__hex"
									value={ hex }
									placeholder={ strings.hexPlaceholder }
									aria-label={ strings.hexLabel }
									spellCheck={ false }
									onChange={ ( event ) => onHex( event.target.value ) }
									onBlur={ applyHex }
								/>
								<button type="button" className="btn btn--ghost btn--small" onClick={ applyHex }>
									{ strings.applyColor }
								</button>
							</div>
							{ error ? (
								<p className="field__hint accent-custom__error" role="alert">
									{ error }
								</p>
							) : null }
						</fieldset>
					</div>
				</div>

				<p className="field__hint style-summary" aria-live="polite">
					{ summary }
				</p>
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary">
						{ strings.saveAppearance }
					</button>
				</div>
			</Form>
		</Card>
	);
}
