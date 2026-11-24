/** Reference card: what each status means. Copy comes from the payload. */
import { Card } from '../shared/ui.jsx';

export default function NotesCard( { card, notes } ) {
	const list = Array.isArray( notes ) ? notes : [];

	return (
		<Card card={ card }>
			<ul className="notes-list">
				{ list.map( ( item ) => (
					<li key={ item.label }>
						<strong>{ item.label }：</strong>
						{ item.text }
					</li>
				) ) }
			</ul>
		</Card>
	);
}
