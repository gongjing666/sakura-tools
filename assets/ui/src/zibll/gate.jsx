import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, Switch } from './controls.jsx';

export default function Gate( { card, strings, config } ) {
	const data = card.data || {};
	const option = config.spaceOption;
	const [ enabled, setEnabled ] = useState( Boolean( data.enabled ) );
	const [ bonuses, setBonuses ] = useState( Boolean( data.apply_bonuses ) );

	return (
		<Card card={ card }>
			<Form card={ card }>
				<FieldRow label={ strings.gateEnabled }>
					<Switch
						name={ option + '[enabled]' }
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
						name={ option + '[apply_bonuses]' }
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
							<input type="radio" name={ option + '[quota_override_mode]' } value="cap" defaultChecked={ 'replace' !== data.mode } />
							<span className="check__box" aria-hidden="true" />
							<span className="check__text">{ strings.modeCapShort }</span>
						</label>
						<label className="check">
							<input type="radio" name={ option + '[quota_override_mode]' } value="replace" defaultChecked={ 'replace' === data.mode } />
							<span className="check__box" aria-hidden="true" />
							<span className="check__text">{ strings.modeReplaceShort }</span>
						</label>
					</div>
				</FieldRow>
			</Form>
		</Card>
	);
}
