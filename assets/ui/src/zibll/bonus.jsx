import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, cardTitleProps, fmt, formatMb } from './controls.jsx';
import { useCardSave, useDraft } from './save.js';

function copyRows( rows ) {
	return ( Array.isArray( rows ) ? rows : [] ).map( ( row ) => ( { ...row } ) );
}

export function Level( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const ruleData = data.rule || {};
	const [ rule, setRule ] = useDraft( Boolean( ruleData.enabled ), Boolean );
	const [ points, setPoints ] = useDraft( String( ruleData.points ?? '1000' ), String );
	const [ bytes, setBytes ] = useDraft( String( ruleData.bytes_mb ?? '100' ), String );
	const [ rows, setRows ] = useDraft( data.rows, copyRows );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_level', rest, strings, onSections, onNotice } );
	const total = rows.reduce( ( sum, row ) => sum + ( Number( row.mb ) || 0 ), 0 );

	const update = ( level, value ) => setRows( ( current ) => current.map( ( row ) => ( row.level === level ? { ...row, mb: value } : row ) ) );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		const fields = {
			level_rule: {
				enabled: rule ? 1 : 0,
				points_per_step: points,
				bytes_per_step_mb: bytes,
			},
		};

		// The manual table is disabled while the rule is on, exactly like the
		// native form left those inputs out of the submission.
		if ( ! rule ) {
			fields.level_bonus_mb = rows.reduce( ( map, row ) => {
				map[ row.level ] = row.mb;

				return map;
			}, {} );
		}

		save( fields );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<div className="field-list">
					<div className="field field--full">
						<div className="field__control">
							<label className="check">
								<input
									type="checkbox"
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

export function Vip( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const [ rows, setRows ] = useDraft( data.rows, copyRows );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_vip', rest, strings, onSections, onNotice } );
	const total = rows.reduce( ( sum, row ) => sum + ( Number( row.mb ) || 0 ), 0 );

	const update = ( vip, value ) => setRows( ( current ) => current.map( ( row ) => ( row.vip === vip ? { ...row, mb: value } : row ) ) );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		const fields = rows.reduce( ( map, row ) => {
			map[ row.vip ] = row.mb;

			return map;
		}, {} );

		save( { vip_bonus_mb: fields } );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
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
