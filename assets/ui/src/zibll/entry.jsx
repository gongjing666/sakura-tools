import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow } from './controls.jsx';

export function Entry( { card, strings, config } ) {
	const data = card.data || {};
	const entry = data.status || {};

	return (
		<Card card={ card }>
			<Form card={ card }>
				<FieldRow label={ strings.entryEnabled }>
					<input type="hidden" name={ config.settingsOption + '[enabled]' } value="0" />
					<label className="check">
						<input
							type="checkbox"
							name={ config.settingsOption + '[enabled]' }
							value="1"
							defaultChecked={ Boolean( data.enabled ) }
						/>
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.entryEnabled }</span>
					</label>
					<p className="zibll-muted">{ data.enabled ? strings.entryOn : strings.entryOff }</p>
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

export function Editor( { card, strings, config } ) {
	const data = card.data || {};

	return (
		<Card card={ card }>
			<Form card={ card }>
				<FieldRow label={ strings.editorEnabled }>
					<input type="hidden" name={ config.settingsOption + '[editor_enabled]' } value="0" />
					<label className="check">
						<input
							type="checkbox"
							name={ config.settingsOption + '[editor_enabled]' }
							value="1"
							defaultChecked={ Boolean( data.enabled ) }
						/>
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
