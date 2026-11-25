import { PageHead } from '../shared/ui.jsx';
import EntryGate from './entry-gate.jsx';
import Appearance from './appearance.jsx';
import Policy from './policy.jsx';

/**
 * File-space settings page. Cards are emitted in payload order so the entry
 * gate can shift the two main cards down when it is present.
 */
export default function App( { boot } ) {
	const cards = boot.cards || [];
	const config = boot.config || {};

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout="files-settings" data-sakura-group="main">
				{ cards.map( ( card ) => {
					if ( 'entry-gate' === card.id ) {
						return <EntryGate key={ card.id } card={ card } config={ config } />;
					}
					if ( 'appearance' === card.id ) {
						return <Appearance key={ card.id } card={ card } config={ config } />;
					}
					if ( 'settings' === card.id ) {
						return <Policy key={ card.id } card={ card } config={ config } />;
					}
					return null;
				} ) }
			</div>
		</>
	);
}
