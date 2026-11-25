import { Card } from '../shared/ui.jsx';

/**
 * The optional gate card when the front-end entry is still off.
 */
export default function EntryGate( { card, config } ) {
	const entry = config.entry || {};
	const strings = config.strings || {};
	const action = ( card.actions || [] )[ 0 ];

	return (
		<Card
			card={ card }
			actions={
				action ? (
					<a className="btn btn--ghost btn--small" href={ action.url }>
						{ action.label }
					</a>
				) : null
			}
		>
			<ul className="list gate-list">
				<li>
					<span>{ strings.entryTheme }</span>
					<strong>{ entry.theme }</strong>
				</li>
				<li>
					<span>{ strings.entryDetected }</span>
					<strong>{ entry.label }</strong>
				</li>
				<li>
					<span>{ strings.entryPosition }</span>
					<strong>{ entry.position }</strong>
				</li>
			</ul>
			<p className="field__hint">{ entry.hint || strings.entryHint }</p>
		</Card>
	);
}
