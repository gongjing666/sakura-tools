import { useActionState, useState } from "react";
import { BusyLabel, Card } from "../shared/ui.jsx";
import { post } from "../shared/transport.js";
import { isHex } from "./color.js";

const FALLBACK_COLOR = "#c8932b";

/**
 * Ink of a preset, or the colour itself when it is already a hex value.
 */
function inkFor( accent, choices ) {
	if ( isHex( accent ) ) {
		return accent;
	}

	const choice = choices.find( ( item ) => item.value === accent );

	return choice && choice.ink ? choice.ink : FALLBACK_COLOR;
}

/**
 * Folder appearance card: style preview, preset palette and a free colour.
 */
export default function Appearance( { card, rest, strings, onSection, onNotice } ) {
	const choices = card.accentChoices || [];
	const styles = card.styles || [];
	const [ saved, setSaved ] = useState( card.values );
	const [ draft, setDraft ] = useState( card.values );
	const [ hex, setHex ] = useState( () => inkFor( card.values.folder_accent, choices ) );
	const [ error, setError ] = useState( "" );
	const [ lastKey, setLastKey ] = useState( () => JSON.stringify( card.values ) );

	const valuesKey = JSON.stringify( card.values );

	/*
	 * Props to state, compared during render: the contract forbids syncing a
	 * draft from props in useEffect, and a remount would drop the focus target.
	 */
	if ( valuesKey !== lastKey ) {
		setLastKey( valuesKey );
		setSaved( card.values );
		setDraft( card.values );
		setHex( inkFor( card.values.folder_accent, choices ) );
		setError( "" );
	}

	const custom = isHex( draft.folder_accent );
	const colorValue = custom ? draft.folder_accent : ( isHex( hex ) ? hex : FALLBACK_COLOR );
	const styleMeta = styles.find( ( item ) => item.value === draft.tree_style ) || {};
	const accentMeta = choices.find( ( item ) => item.value === draft.folder_accent );
	const accentLabel = custom ? strings.customColor : ( accentMeta ? accentMeta.label : card.current.accentLabel );
	const summary = ( strings.currentSummary || "" )
		.replace( "{style}", styleMeta.label || card.current.styleLabel || "" )
		.replace( "{accent}", accentLabel || "" );
	const dirty = draft.tree_style !== saved.tree_style || draft.folder_accent !== saved.folder_accent;

	const [ failure, save, pending ] = useActionState( async ( _prev, fields ) => {
		try {
			const payload = await post( rest, rest.url, { card: "appearance", fields }, strings );
			const section = payload && payload.sections ? payload.sections.appearance : null;

			if ( section ) {
				onSection( "appearance", section );
			}

			if ( payload && payload.notice ) {
				onNotice( payload.notice, "appearance" );
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

		save( { tree_style: draft.tree_style, folder_accent: draft.folder_accent } );
	};

	const selectStyle = ( value ) => setDraft( ( current ) => ( { ...current, tree_style: value } ) );

	const selectPreset = ( value ) => {
		setDraft( ( current ) => ( { ...current, folder_accent: value } ) );
		setHex( inkFor( value, choices ) );
		setError( "" );
	};

	const selectCustom = () => {
		const value = isHex( hex ) ? hex : FALLBACK_COLOR;

		setDraft( ( current ) => ( { ...current, folder_accent: value } ) );
		setHex( value );
		setError( "" );
	};

	const onColour = ( value ) => {
		setDraft( ( current ) => ( { ...current, folder_accent: value } ) );
		setHex( value );
		setError( "" );
	};

	const onHex = ( value ) => {
		setHex( value );
		setError( "" );

		if ( isHex( value ) ) {
			setDraft( ( current ) => ( { ...current, folder_accent: value } ) );
		}
	};

	const applyHex = () => {
		if ( ! isHex( hex ) ) {
			setError( strings.hexInvalid );
			return;
		}

		setDraft( ( current ) => ( { ...current, folder_accent: hex } ) );
		setError( "" );
	};

	const previewStyle = custom ? { "--folder-ink": draft.folder_accent } : undefined;
	const rows = [
		{ name: strings.previewImages, count: 128, active: true },
		{ name: strings.previewArchive, count: 36, nested: true },
		{ name: strings.previewInbox, count: 12, nested: true },
	];

	return (
		<Card card={ card } titleProps={ { tabIndex: -1, "data-sakura-return-target": "1" } }>
			<form className="card__form" onSubmit={ submit } noValidate>
				<div className="appearance-grid">
					<div
						className="tree-preview"
						data-tree-preview
						data-style={ draft.tree_style }
						data-accent={ custom ? "custom" : ( draft.folder_accent || "auto" ) }
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
								className={ row.active ? "tree-preview__row is-active" : ( row.nested ? "tree-preview__row is-nested" : "tree-preview__row" ) }
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
											name="tree_style"
											value={ item.value }
											checked={ draft.tree_style === item.value }
											onChange={ () => selectStyle( item.value ) }
										/>
										<span className={ "style-choice__visual style-choice__visual--" + item.value } aria-hidden="true">
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
										className={ "accent-choice" + ( choice.value === draft.folder_accent ? " is-selected" : "" ) }
										key={ choice.value || "auto" }
										data-accent={ choice.value || "auto" }
										data-label={ choice.label }
									>
										<input
											type="radio"
											name="folder_accent"
											value={ choice.value }
											checked={ choice.value === draft.folder_accent }
											onChange={ () => selectPreset( choice.value ) }
										/>
										<span
											className={ "accent-choice__dot accent-choice__dot--" + ( choice.value || "auto" ) }
											aria-hidden="true"
										/>
										<span>{ choice.label }</span>
									</label>
								) ) }
								<label className={ "accent-choice" + ( custom ? " is-selected" : "" ) } data-accent="custom" data-label={ strings.customColor }>
									<input
										type="radio"
										name="folder_accent"
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
				{ failure ? (
					<p className="field__hint accent-custom__error" role="alert">{ failure }</p>
				) : null }
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary" disabled={ ! dirty } aria-busy={ pending ? "true" : "false" }>
						<BusyLabel idle={ strings.saveAppearance } active={ strings.savingAppearance } busy={ pending } />
					</button>
				</div>
			</form>
		</Card>
	);
}
