import { PageHead } from '../shared/ui.jsx';
import { fmt } from './controls.jsx';
import Diagnostics from './diagnostics.jsx';
import Payment from './payment.jsx';
import { Editor, Entry } from './entry.jsx';
import Gate from './gate.jsx';
import { Level, Vip } from './bonus.jsx';
import Plans from './plans.jsx';
import Grants from './grants.jsx';
import Reconcile from './reconcile.jsx';

export default function App( { boot } ) {
	const config = boot.config || {};
	const strings = config.strings || {};
	const byId = {};

	( boot.cards || [] ).forEach( ( card ) => {
		byId[ card.id ] = card;
	} );

	const levelCard = byId.space_level ? {
		...byId.space_level,
		description: fmt( strings.levelDescription, [ ( byId.space_level.data || {} ).max || 0 ] ),
	} : null;

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout="files-space" data-sakura-group="main">
				{ ! ( byId.space_gate && byId.space_gate.data && byId.space_gate.data.enabled ) ? (
					<div className="zibll-span-all">
						<div className="notice-inline notice-inline--warn" role="alert">
							<p>{ strings.gateDisabledBanner }</p>
						</div>
					</div>
				) : null }

				{ byId.space_diag ? <Diagnostics card={ byId.space_diag } strings={ strings } /> : null }
				{ byId.space_payment ? <Payment card={ byId.space_payment } strings={ strings } config={ config } /> : null }
				{ byId.space_entry ? <Entry card={ byId.space_entry } strings={ strings } config={ config } /> : null }
				{ byId.space_editor ? <Editor card={ byId.space_editor } strings={ strings } config={ config } /> : null }
				{ byId.space_gate ? <Gate card={ byId.space_gate } strings={ strings } config={ config } /> : null }
				{ levelCard ? <Level card={ levelCard } strings={ strings } config={ config } /> : null }
				{ byId.space_vip ? <Vip card={ byId.space_vip } strings={ strings } config={ config } /> : null }
				{ byId.space_plans ? <Plans card={ byId.space_plans } strings={ strings } config={ config } /> : null }
				{ byId.space_grants ? <Grants card={ byId.space_grants } strings={ strings } config={ config } /> : null }
				{ byId.space_reconcile ? <Reconcile card={ byId.space_reconcile } strings={ strings } config={ config } /> : null }
			</div>
		</>
	);
}
