import { useRef, useState } from "react";
import { PageHead, Prompt } from "../shared/ui.jsx";
import Appearance from "./appearance.jsx";
import Policy from "./policy.jsx";

/**
 * One page-level alert: persistent state, not an action result.
 */
function Alert( { alert } ) {
	const tone = alert.tone || "info";

	return (
		<div className={ "alert alert--" + tone } role="status">
			<strong className="alert__title">{ alert.title }</strong>
			<div className="alert__body">{ alert.message }</div>
			{ alert.action ? (
				<div className="button-row">
					<a className="btn btn--small" href={ alert.action.url }>{ alert.action.label }</a>
				</div>
			) : null }
		</div>
	);
}

/**
 * File-space settings page.
 *
 * The two cards own their drafts; this component only replaces a card with the
 * section the save response returned and opens the shared Prompt.
 */
export default function App( { boot } ) {
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const [ cards, setCards ] = useState( () => ( Array.isArray( boot.cards ) ? boot.cards : [] ) );
	const [ notice, setNotice ] = useState( null );
	const opener = useRef( null );

	const onNotice = ( data, cardId ) => {
		if ( ! data ) {
			return;
		}

		opener.current = cardId
			? document.querySelector( "[data-sakura-card=\"" + cardId + "\"] .card__title" )
			: document.querySelector( "[data-sakura-return-target]" );

		setNotice( {
			tone: data.tone || "ok",
			kicker: data.kicker || "",
			title: data.title || "",
			message: data.message || "",
			closeLabel: strings.close || "",
			closeIcon: strings.closeIcon || "",
			mascot: strings.mascot || "",
			button: strings.dismiss || "",
		} );
	};

	const onSection = ( id, section ) => {
		if ( ! section ) {
			return;
		}

		setCards( ( list ) => list.map( ( card ) => ( card.id === id ? { ...card, ...section } : card ) ) );
	};

	const alerts = Array.isArray( boot.alerts ) ? boot.alerts : [];

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="page-body">
				{ alerts.map( ( alert, index ) => <Alert key={ index } alert={ alert } /> ) }
				<div className="card-grid" data-sakura-layout="files-settings">
					{ cards.map( ( card ) => {
						if ( "appearance" === card.id ) {
							return <Appearance key={ card.id } card={ card } rest={ rest } strings={ strings } onSection={ onSection } onNotice={ onNotice } />;
						}

						if ( "settings" === card.id ) {
							return <Policy key={ card.id } card={ card } rest={ rest } strings={ strings } onSection={ onSection } onNotice={ onNotice } />;
						}

						return null;
					} ) }
				</div>
			</div>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ opener } /> : null }
		</>
	);
}
