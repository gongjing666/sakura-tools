import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, cardTitleProps } from './controls.jsx';
import { useCardSave, useDraft } from './save.js';

export function Entry( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const [ enabled, setEnabled ] = useDraft( Boolean( data.enabled ), Boolean );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_entry', rest, strings, onSections, onNotice } );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		save( { enabled: enabled ? 1 : 0 } );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<FieldRow label={ strings.entryEnabled }>
					<label className="check">
						<input type="checkbox" checked={ enabled } onChange={ ( event ) => setEnabled( event.target.checked ) } />
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.entryEnabled }</span>
					</label>
					<p className="zibll-muted">{ enabled ? strings.entryOn : strings.entryOff }</p>
				</FieldRow>

				<dl className="zibll-meta zibll-meta--wide">
					<div><dt>{ strings.currentTheme }</dt><dd>{ data.theme || strings.unknown }</dd></div>
					<div><dt>{ strings.detectedEntry }</dt><dd>{ data.label || strings.unknown }</dd></div>
					<div><dt>{ strings.frontPosition }</dt><dd>{ data.generic ? strings.generic : strings.userCenter }</dd></div>
					<div><dt>{ strings.frontUrl }</dt><dd>
						{ data.url ? <a href={ data.url } target="_blank" rel="noopener noreferrer">{ data.url }</a> : strings.unpublished }
					</dd></div>
				</dl>
				<p className="zibll-muted">{ strings.entryNote }</p>
			</Form>
		</Card>
	);
}

export function Editor( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const [ enabled, setEnabled ] = useDraft( Boolean( data.enabled ), Boolean );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_editor', rest, strings, onSections, onNotice } );

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		save( { editor_enabled: enabled ? 1 : 0 } );
	};

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<FieldRow label={ strings.editorEnabled }>
					<label className="check">
						<input type="checkbox" checked={ enabled } onChange={ ( event ) => setEnabled( event.target.checked ) } />
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.editorEnabled }</span>
					</label>
				</FieldRow>

				<dl className="zibll-meta zibll-meta--wide">
					<div><dt>{ strings.editorPages }</dt><dd>{ strings.editorPagesValue }</dd></div>
					<div><dt>{ strings.editorCondition }</dt><dd>{ strings.editorConditionValue }</dd></div>
					<div><dt>{ strings.editorPermission }</dt><dd>{ strings.editorPermissionValue }</dd></div>
				</dl>
				<p className="zibll-muted">{ strings.editorIndependent }</p>
			</Form>
		</Card>
	);
}
