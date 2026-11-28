/**
 * Which page app renders a payload.
 *
 * Every screen exports `App({ boot })`; the shell only has to pick one. The
 * shell owns navigation, so no page app listens for tab clicks or writes
 * history by itself.
 */

import CostApp from '../cost/app.jsx';
import DeletesApp from '../deletes/app.jsx';
import FilesApp from '../files/app.jsx';
import FilesLogApp from '../files-log/app.jsx';
import FilesQueueApp from '../files-queue/app.jsx';
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

	return <App boot={ boot } />;
}
