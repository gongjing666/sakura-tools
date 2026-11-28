import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, cardTitleProps } from './controls.jsx';
import { useCardSave, useDraft } from './save.js';

export default function Payment( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const methods = Array.isArray( data.methods ) ? data.methods : [];
	const [ selected, setSelected ] = useDraft( methods, ( list ) => list.filter( ( method ) => method.checked ).map( ( method ) => method.id ) );
	const [ points, setPoints ] = useDraft( Boolean( data.points_on ), Boolean );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_payment', rest, strings, onSections, onNotice } );

	const toggle = ( id, checked ) => setSelected( ( current ) => (
		checked ? [ ...current, id ] : current.filter( ( value ) => value !== id )
	) );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		save( { allowed_methods: selected, points_enabled: points ? 1 : 0 } );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<FieldRow label={ strings.themeMethods } hint={ strings.themeMethodsHint }>
					{ ! methods.length ? (
						<p className="zibll-muted">{ strings.noThemeMethods }</p>
					) : (
						<div className="zibll-method-list">
							{ methods.map( ( method ) => (
								<label className="check zibll-method" key={ method.id }>
									<input
										type="checkbox"
										value={ method.id }
										checked={ selected.includes( method.id ) }
										onChange={ ( event ) => toggle( method.id, event.target.checked ) }
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
					<label className="check">
						<input type="checkbox" checked={ points } onChange={ ( event ) => setPoints( event.target.checked ) } />
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.pointsEnabled }</span>
					</label>
					<p className="zibll-muted">{ strings.pointsNote }</p>
				</FieldRow>
			</Form>
		</Card>
	);
}
