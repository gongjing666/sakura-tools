/**
 * The image optimisation page.
 *
 * The top bar, the tab strip and the page head come from PHP
 * (`Shell::header_data()` / `Page::head_data()`), so the first paint and the
 * island cannot disagree. Each tab owns its own body; every card saves with one
 * POST and the document never navigates.
 */

import { useState } from 'react';
import { PageHead, Prompt, Tabs, Topbar } from '../shared/ui.jsx';
import BackupPage from './backup.jsx';
import RulesPage from './rules.jsx';
import ThumbPage from './thumb.jsx';

/**
 * Page root.
 *
 * @param {Object} props Boot payload.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const { chrome } = boot;
	const [ head, setHead ] = useState( boot.head );
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( '' );

	let body = null;

	if ( 'rules' === boot.tab ) {
		body = (
			<RulesPage
				boot={ boot }
				onError={ setError }
				onNotice={ setNotice }
			/>
		);
	}

	if ( 'thumb' === boot.tab ) {
		body = (
			<ThumbPage
				boot={ boot }
				onError={ setError }
				onNotice={ setNotice }
			/>
		);
	}

	if ( 'backup' === boot.tab ) {
		body = (
			<BackupPage
				boot={ boot }
				onError={ setError }
				onNotice={ setNotice }
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

	return (
		<>
			<Topbar chrome={ chrome } />
			<Tabs chrome={ chrome } />
			<main className="sakura-admin__main">
				<PageHead head={ head } />
				<div className="page-body">
					{ error ? (
						<div className="system-note" role="alert">
							<p>{ error }</p>
						</div>
					) : null }
					{ boot.alerts && boot.alerts.length ? (
						<div className="system-note" data-tone="info">
							{ boot.alerts.map( ( alert ) => (
								<p key={ alert.text } data-tone={ alert.tone }>
									{ alert.title ? <strong>{ alert.title }：</strong> : null }
									{ alert.text }
								</p>
							) ) }
						</div>
					) : null }
					{ body }
				</div>
			</main>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } /> : null }
		</>
	);
}
