import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow } from './controls.jsx';

export default function Payment( { card, strings, config } ) {
	const data = card.data || {};
	const option = config.spaceOption;

	return (
		<Card card={ card }>
			<Form card={ card }>
				<FieldRow label={ strings.themeMethods } hint={ strings.themeMethodsHint }>
					{ ! ( data.methods || [] ).length ? (
						<p className="zibll-muted">{ strings.noThemeMethods }</p>
					) : (
						<div className="zibll-method-list">
							{ ( data.methods || [] ).map( ( method ) => (
								<label className="check zibll-method" key={ method.id }>
									<input
										type="checkbox"
										name={ option + '[allowed_methods][]' }
										value={ method.id }
										defaultChecked={ method.checked }
									/>
									<span className="check__box" aria-hidden="true" />
									<span className="check__text">
										<code className="zibll-code">{ method.id }</code>
										<strong>{ method.name }</strong>
										<em>{ 'balance' === method.note ? strings.balanceNote : strings.externalNote }</em>
									</span>
								</label>
							) ) }
						</div>
					) }
				</FieldRow>

				<FieldRow label={ strings.points } hint={ strings.pointsHint }>
					<input type="hidden" name={ option + '[points][enabled]' } value="0" />
					<label className="check">
						<input
							type="checkbox"
							name={ option + '[points][enabled]' }
							value="1"
							defaultChecked={ Boolean( data.points_on ) }
						/>
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.pointsEnabled }</span>
					</label>
					<p className="zibll-muted">{ strings.pointsNote }</p>
				</FieldRow>
			</Form>
		</Card>
	);
}
