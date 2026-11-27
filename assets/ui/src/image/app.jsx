/**
 * The image optimisation page.
 *
 * The shell owns the top bar, the tab strip and navigation; this file owns the
 * page head and the cards. Switching tabs is a shell navigation: the shell
 * fetches `/pages/image-*` and mounts this app with a fresh boot payload.
 */

import { Fragment, useState } from 'react';
import { PageHead, Prompt } from '../shared/ui.jsx';
import BackupPage from './backup.jsx';
import RulesPage from './rules.jsx';
import StatsPage from './stats.jsx';
import ThumbPage from './thumb.jsx';

/**
 * Page root.
 *
 * @param {Object} props Boot payload.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const [ payload, setPayload ] = useState( boot );
	const [ head, setHead ] = useState( boot.head );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );

	// Every page hands over { tone, kicker, title, message }; the icon, the close
	// label and the dismiss label only exist in strings, so merge them once here
	// instead of repeating the same four fields in four payloads.
	const showNotice = ( next ) =>
		setNotice( {
			closeIcon: payload.strings.closeIcon,
			mascot: payload.strings.mascot,
			closeLabel: payload.strings.close,
			button: payload.strings.dismiss,
			...next,
		} );

	let body = null;

	if ( 'rules' === payload.tab ) {
		body = (
			<RulesPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
			/>
		);
	}

	if ( 'thumb' === payload.tab ) {
		body = (
			<ThumbPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
				onServerState={ ( next ) => {
					if ( next.status ) {
						setHead( ( current ) => ( {
							...current,
							status: { ...current.status, ...next.status },
						} ) );
					}

					if ( Array.isArray( next.alerts ) ) {
						setPayload( ( current ) => ( { ...current, alerts: next.alerts } ) );
					}
				} }
			/>
		);
	}

	if ( 'backup' === payload.tab ) {
		body = (
			<BackupPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
				onUsage={ ( usage ) =>
					setHead( ( current ) => ( {
						...current,
						status: {
							...current.status,
							value: usage.count_text,
							sub: usage.size,
						},
					} ) )
				}
			/>
		);
	}

	if ( 'stats' === payload.tab ) {
		body = (
			<StatsPage
				key={ payload.tab }
				boot={ payload }
				onError={ setError }
				onNotice={ showNotice }
				onHead={ setHead }
			/>
		);
	}

	return (
		<Fragment>
			<PageHead head={ head } />

			<div className="page-body" data-sakura-tab={ payload.tab }>
				{ error ? (
					<div className="system-alert" data-tone="bad" role="alert">
						<p>{ error }</p>
						<button type="button" className="btn btn--ghost btn--small" onClick={ () => setError( '' ) }>
							{ payload.strings.close }
						</button>
					</div>
				) : null }
				{ payload.alerts && payload.alerts.length ? (
					<div className="system-alert">
						{ payload.alerts.map( ( alert ) => (
							<p key={ alert.text } data-tone={ alert.tone }>
								{ alert.title ? <strong>{ alert.title }：</strong> : null }
								{ alert.text }
							</p>
						) ) }
					</div>
				) : null }
				{ body }
			</div>

			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } /> : null }
		</Fragment>
	);
}