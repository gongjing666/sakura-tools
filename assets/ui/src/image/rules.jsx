/**
 * The rules tab: four cards, each one POST away from being saved.
 */

import { useState } from 'react';
import { BusyLabel, Card } from '../shared/ui.jsx';
import { ImageField, RuleComposer, SizeTable } from './fields.jsx';
import { call, same } from './transport.js';

/**
 * One card: own draft, own dirty flag, own save button.
 *
 * @param {Object} props Card, transport and callbacks.
 * @return {JSX.Element} Card.
 */
function SettingsCard( { card, rest, strings, sections, scanned, scan, scanning, onError, onNotice, onSection } ) {
	const [ draft, setDraft ] = useState( card.values );
	const [ saved, setSaved ] = useState( card.values );
	const [ saving, setSaving ] = useState( false );

	// Props to state, compared during render: no useEffect sync (14 section 5).
	const [ lastKey, setLastKey ] = useState( () => JSON.stringify( card.values ) );
	const valuesKey = JSON.stringify( card.values );

	if ( valuesKey !== lastKey ) {
		setLastKey( valuesKey );
		setSaved( card.values );
		setDraft( card.values );
	}

	const dirty = ! same( draft, saved );
	const change = ( key, value ) => setDraft( ( current ) => ( { ...current, [ key ]: value } ) );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );
		onError( '' );

		try {
			const payload = await call( rest, strings, `/cards/${ card.id }`, { fields: draft } );
			const next = payload.values || draft;

			setSaved( next );
			setDraft( next );

			if ( payload.section && onSection ) {
				// 生成状态（thumbs）与「固定文本 · X」标签（rename）都是服务端
				// 拼好的字符串，保存后必须用刚算出来的那一份替换。
				onSection( card.id, payload.section );
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
						if ( 'size-table' === field.type ) {
							return (
								<SizeTable
									key={ field.name }
									field={ field }
									section={ sections.thumbs }
									disabled={ draft.disabled_sizes }
									onChange={ change }
									scanned={ scanned }
									stale={ !! scanned && dirty }
									onScan={ scan }
									scanning={ scanning }
									scanLabel={ strings.scan }
									scanningLabel={ strings.scanning }
								/>
							);
						}

						if ( 'rule-composer' === field.type ) {
							return (
								<RuleComposer
									key={ field.name }
									field={ field }
									section={ sections.rename }
									rule={ draft.rename_rule || '' }
									fixed={ draft.rename_fixed || '' }
									onRule={ ( value ) => change( 'rename_rule', value ) }
								/>
							);
						}

						return (
							<ImageField
								key={ field.name }
								field={ field }
								value={ draft[ field.name ] }
								values={ draft }
								onChange={ change }
							/>
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
 * Rules tab body.
 *
 * @param {Object} props Boot payload and callbacks.
 * @return {JSX.Element} Cards.
 */
export default function RulesPage( { boot, onError, onNotice } ) {
	const { strings, rest } = boot;
	const [ sections, setSections ] = useState( boot.sections || {} );
	const cards = boot.cards || [];
	const [ scanned, setScanned ] = useState( null );
	const [ scanning, setScanning ] = useState( false );

	const scan = async () => {
		setScanning( true );
		onError( '' );

		try {
			setScanned( await call( rest, strings, '/scan', {} ) );
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setScanning( false );
		}
	};

	return (
		<div className="card-grid" data-sakura-layout={ boot.page.id }>
			{ cards.map( ( card ) => (
				<SettingsCard
					key={ card.id }
					card={ card }
					rest={ rest }
					strings={ strings }
					sections={ sections }
					scanned={ scanned }
					scan={ scan }
					scanning={ scanning }
					onError={ onError }
					onNotice={ onNotice }
					onSection={ ( cardId, next ) => {
						// A scan snapshot outranks the payload rows inside SizeTable,
						// so a save has to retire it; otherwise the table keeps
						// showing the state as it was when 扫描裁剪配置 was pressed.
						if ( 'thumbs' === cardId ) {
							setScanned( null );
						}

						setSections( ( current ) => ( { ...current, [ cardId ]: next } ) );
					} }
				/>
			) ) }
		</div>
	);
}
