import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, Switch, cardTitleProps } from './controls.jsx';
import { useCardSave, useDraft } from './save.js';

export default function Gate( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const [ enabled, setEnabled ] = useDraft( Boolean( data.enabled ), Boolean );
	const [ bonuses, setBonuses ] = useDraft( Boolean( data.apply_bonuses ), Boolean );
	const [ mode, setMode ] = useDraft( 'replace' === data.mode ? 'replace' : 'cap', ( value ) => ( 'replace' === value ? 'replace' : 'cap' ) );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_gate', rest, strings, onSections, onNotice } );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		save( {
			enabled: enabled ? 1 : 0,
			apply_bonuses: bonuses ? 1 : 0,
			mode,
		} );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<FieldRow label={ strings.gateEnabled }>
					<Switch
						label={ strings.gateEnabled }
						checked={ enabled }
						onChange={ setEnabled }
						warn={ enabled ? strings.gateDisableWarn : strings.gateEnableWarn }
						strings={ strings }
					/>
					<p className="zibll-muted">{ enabled ? strings.gateOn : strings.gateOff }</p>
				</FieldRow>

				<FieldRow label={ strings.applyBonuses }>
					<Switch
						label={ strings.applyBonusesLabel }
						checked={ bonuses }
						onChange={ setBonuses }
						warn={ bonuses ? strings.applyBonusesWarn : '' }
						strings={ strings }
					/>
					<p className="zibll-muted">{ strings.applyBonusesNote }</p>
				</FieldRow>

				<FieldRow label={ strings.overrideMode }>
					<div className="check-group">
						<label className="check">
							<input
								type="radio"
								name="space-quota-mode"
								value="cap"
								checked={ 'replace' !== mode }
								onChange={ () => setMode( 'cap' ) }
							/>
							<span className="check__box" aria-hidden="true" />
							<span className="check__text">{ strings.modeCapShort }</span>
						</label>
						<label className="check">
							<input
								type="radio"
								name="space-quota-mode"
								value="replace"
								checked={ 'replace' === mode }
								onChange={ () => setMode( 'replace' ) }
							/>
							<span className="check__box" aria-hidden="true" />
							<span className="check__text">{ strings.modeReplaceShort }</span>
						</label>
					</div>
				</FieldRow>
			</Form>
		</Card>
	);
}
