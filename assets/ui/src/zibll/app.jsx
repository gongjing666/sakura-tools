import { useRef, useState } from 'react';
import { PageHead, Prompt } from '../shared/ui.jsx';
import Diagnostics from './diagnostics.jsx';
import Payment from './payment.jsx';
import { Editor, Entry } from './entry.jsx';
import Gate from './gate.jsx';
import { Level, Vip } from './bonus.jsx';
import Plans from './plans.jsx';
import Grants from './grants.jsx';
import Reconcile from './reconcile.jsx';

/**
 * Zibll-only file space page.
 *
 * Cards arrive in Layout::PAGES[files-space][order] order, each with its own
 * data. Every save answers with the recomputed sections plus the prompt copy, so
 * this component only replaces the changed cards and opens the shared Prompt.
 */
export default function App( { boot } ) {
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const data = boot.data || {};
	const [ cards, setCards ] = useState( () => ( Array.isArray( boot.cards ) ? boot.cards : [] ) );
	const [ notice, setNotice ] = useState( null );
	const opener = useRef( null );

	const onSections = ( sections ) => {
		if ( ! sections ) {
			return;
		}

		setCards( ( list ) => list.map( ( card ) => (
			Object.prototype.hasOwnProperty.call( sections, card.id )
				? { ...card, data: sections[ card.id ] }
				: card
		) ) );
	};

	const onNotice = ( payload, cardId ) => {
		if ( ! payload ) {
			return;
		}

		opener.current = cardId
			? document.querySelector( '[data-sakura-card="' + cardId + '"] .card__title' )
			: document.querySelector( '[data-sakura-return-target]' );

		setNotice( {
			tone: payload.tone || 'ok',
			kicker: payload.kicker || '',
			title: payload.title || '',
			message: payload.message || '',
			closeLabel: strings.close || '',
			closeIcon: strings.closeIcon || '',
			mascot: strings.mascot || '',
			button: strings.dismiss || '',
		} );
	};

	const byId = {};

	cards.forEach( ( card ) => {
		byId[ card.id ] = card;
	} );

	const gate = byId.space_gate && byId.space_gate.data ? byId.space_gate.data : {};
	const shared = { rest, strings, onSections, onNotice };

	const renderCard = ( card ) => {
		switch ( card.id ) {
			case 'space_diag':
				return <Diagnostics key={ card.id } card={ card } strings={ strings } />;
			case 'space_payment':
				return <Payment key={ card.id } card={ card } { ...shared } />;
			case 'space_entry':
				return <Entry key={ card.id } card={ card } { ...shared } />;
			case 'space_editor':
				return <Editor key={ card.id } card={ card } { ...shared } />;
			case 'space_gate':
				return <Gate key={ card.id } card={ card } { ...shared } />;
			case 'space_vip':
				return <Vip key={ card.id } card={ card } { ...shared } />;
			case 'space_level':
				return <Level key={ card.id } card={ card } { ...shared } />;
			case 'space_plans':
				return <Plans key={ card.id } card={ card } { ...shared } />;
			case 'space_grants':
				return <Grants key={ card.id } card={ card } { ...shared } />;
			case 'space_reconcile':
				return <Reconcile key={ card.id } card={ card } { ...shared } />;
			default:
				return null;
		}
	};

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout={ data.layout || 'files-space' } data-sakura-group="main">
				{ ! gate.enabled ? (
					<div className="zibll-span-all">
						<div className="notice-inline notice-inline--warn" role="alert">
							<p>{ strings.gateDisabledBanner }</p>
						</div>
					</div>
				) : null }
				{ cards.map( ( card ) => renderCard( card ) ) }
			</div>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ opener } /> : null }
		</>
	);
}
