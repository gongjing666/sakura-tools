import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, fmt, formatMb } from './controls.jsx';

export function Level( { card, strings, config } ) {
	const data = card.data || {};
	const option = config.spaceOption;
	const [ rule, setRule ] = useState( Boolean( ( data.rule || {} ).enabled ) );
	const [ points, setPoints ] = useState( ( data.rule || {} ).points || '1000' );
	const [ bytes, setBytes ] = useState( ( data.rule || {} ).bytes_mb || '100' );
	const [ rows, setRows ] = useState( ( data.rows || [] ).map( ( row ) => ( { ...row } ) ) );
	const total = rows.reduce( ( sum, row ) => sum + ( Number( row.mb ) || 0 ), 0 );

	const update = ( level, value ) => {
		setRows( ( current ) => current.map( ( row ) => ( row.level === level ? { ...row, mb: value } : row ) ) );
	};

	return (
		<Card card={ card }>
			<Form card={ card }>
				<div className="field-list">
					<div className="field field--full">
						<div className="field__control">
							<input type="hidden" name={ option + '[level_rule][enabled]' } value="0" />
							<label className="check">
								<input
									type="checkbox"
									name={ option + '[level_rule][enabled]' }
									value="1"
									checked={ rule }
									onChange={ ( event ) => setRule( event.target.checked ) }
								/>
								<span className="check__box" aria-hidden="true" />
								<span className="check__text">{ strings.levelRule }</span>
							</label>
						</div>
					</div>
					<FieldRow label={ strings.pointsPerStep } hint={ strings.pointsPerStepHint }>
						<input
							type="number"
							id="space-level-points-step"
							name={ option + '[level_rule][points_per_step]' }
							value={ points }
							min="1"
							step="100"
							onChange={ ( event ) => setPoints( event.target.value ) }
						/>
					</FieldRow>
					<FieldRow label={ strings.bytesPerStep } hint={ strings.bytesPerStepHint }>
						<input
							type="number"
							id="space-level-bytes-step"
							name={ option + '[level_rule][bytes_per_step_mb]' }
							value={ bytes }
							min="0"
							step="1"
							onChange={ ( event ) => setBytes( event.target.value ) }
						/>
					</FieldRow>
				</div>

				<div className="table-scroll zibll-level-table">
					<table className="table table--compact table--responsive">
						<thead>
							<tr>
								<th scope="col">{ strings.level }</th>
								<th scope="col">{ strings.threshold }</th>
								<th scope="col">{ strings.bonusMb }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.level }>
									<td data-label={ strings.level }>{ 'LV' + row.level }</td>
									<td data-label={ strings.threshold }>{ null === row.threshold ? '—' : row.threshold }</td>
									<td data-label={ strings.bonusMb }>
										<input
											className="zibll-cell-field"
											type="number"
											name={ option + '[level_bonus_mb][' + row.level + ']' }
											value={ row.mb }
											min="0"
											step="1"
											disabled={ rule }
											aria-label={ 'LV' + row.level + ' ' + strings.bonusMb }
											onChange={ ( event ) => update( row.level, event.target.value ) }
										/>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>

				<p className="zibll-total">{ strings.total }<strong>{ formatMb( total, strings ) }</strong></p>
			</Form>
		</Card>
	);
}

export function Vip( { card, strings, config } ) {
	const data = card.data || {};
	const option = config.spaceOption;
	const [ rows, setRows ] = useState( ( data.rows || [] ).map( ( row ) => ( { ...row } ) ) );
	const total = rows.reduce( ( sum, row ) => sum + ( Number( row.mb ) || 0 ), 0 );

	const update = ( vip, value ) => {
		setRows( ( current ) => current.map( ( row ) => ( row.vip === vip ? { ...row, mb: value } : row ) ) );
	};

	return (
		<Card card={ card }>
			<Form card={ card }>
				<div className="zibll-vip-grid">
					{ rows.map( ( row ) => (
						<div className="zibll-vip-card" key={ row.vip }>
							<div className="zibll-vip-card__head">
								<code className="zibll-code">{ 'vip_level=' + row.vip }</code>
								<strong>{ row.label }</strong>
							</div>
							<div className="field field--full">
								<div className="field__main">
									<label className="field__label" htmlFor={ 'space-vip-' + row.vip }>{ strings.bonusMb }</label>
								</div>
								<div className="field__control">
									<input
										id={ 'space-vip-' + row.vip }
										type="number"
										name={ option + '[vip_bonus_mb][' + row.vip + ']' }
										value={ row.mb }
										min="0"
										step="1"
										onChange={ ( event ) => update( row.vip, event.target.value ) }
									/>
								</div>
							</div>
						</div>
					) ) }
				</div>
				<p className="zibll-total">{ strings.total }<strong>{ formatMb( total, strings ) }</strong></p>
			</Form>
		</Card>
	);
}
