/**
 * Which page app renders a payload.
 *
 * Every screen already exports `App({ boot })` — the islands were written that
 * way so the same component could be handed a REST refresh. The shell only has
 * to pick one; image is the only app that knows how to navigate itself, so it is
 * told to stay out of history and clicks while it is embedded.
 */

import CostApp from '../cost/app.jsx';
import DeletesApp from '../deletes/app.jsx';
import FilesApp from '../files/app.jsx';
import FilesLogApp from '../files-log/app.jsx';
import FilesQueueApp from '../files-queue/app.jsx';
import FilesUsersApp from '../files-users/app.jsx';
import ImageApp from '../image/app.jsx';
import PerformanceApp from '../performance/app.jsx';
import RewriteApp from '../rewrite/app.jsx';
import StorageApp from '../storage/app.jsx';
import SyncApp from '../sync/app.jsx';
import SystemApp from '../system/app.jsx';
import ZibllApp from '../zibll/app.jsx';

/**
 * Page id → app. Keyed by route id so a new tab is one line, and so a route
 * that forgets its app renders nothing instead of the wrong screen.
 */
const APPS = {
	'system-settings': SystemApp,
	'image-rules': ImageApp,
	'image-thumb': ImageApp,
	'image-backup': ImageApp,
	'image-stats': ImageApp,
	'storage-connection': StorageApp,
	'storage-sync': SyncApp,
	'storage-deletes': DeletesApp,
	'storage-rewrite': RewriteApp,
	'storage-cost': CostApp,
	'files-settings': FilesApp,
	'files-users': FilesUsersApp,
	'files-queue': FilesQueueApp,
	'files-log': FilesLogApp,
	'files-zibll': ZibllApp,
	'zibll-performance': PerformanceApp,
};

/**
 * Content root for one page.
 *
 * @param {Object} props Page props.
 * @return {JSX.Element|null} Page app.
 */
export function PageContent( { page, boot } ) {
	const App = APPS[ page ] || null;

	if ( ! App ) {
		return null;
	}

	if ( ImageApp === App ) {
		return <ImageApp boot={ boot } embedded />;
	}

	return <App boot={ boot } />;
}
