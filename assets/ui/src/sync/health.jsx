/**
 * Connection and queue health: the nine-row definition list, plus the three
 * local-only controls that stay usable while the page is gated.
 */
import { Card } from '../shared/ui.jsx';
import { format } from './format.js';

export default function HealthCard( { card, view, strings, data, frozen, indexEnabled, onToggle, onTest } ) {
	const h = strings.health || {};
	const ui = strings.ui || {};
	const healthy = view.is_configured && view.storage_enabled;
	const healthText = ! view.is_configured ? h.unhealthyConfig : ! view.storage_enabled ? h.unhealthyOff : h.healthy;
	const d = h.dash || '—';

	return (
		<Card card={ card }>
			<div className="health">
				<div className={ 'health__status' + ( healthy ? '' : ' is-warn' ) }>
					<span className="health__dot" aria-hidden="true" />
					<strong>{ healthText }</strong>
					<small className="sakura-sync-latency">
						{ h.credReady || '已加密' }
					</small>
				</div>
				<dl className="health__list">
					<div className="health__row">
						<dt>{ h.provider }</dt>
						<dd>{ view.provider_label || d }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.bucket }</dt>
						<dd title={ view.bucket_raw || '' }>{ view.bucket_label || d }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.endpoint }</dt>
						<dd className="health__mono">{ view.endpoint || d }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.mediaUrl }</dt>
						<dd className="health__mono">{ view.media_label || h.mediaUnset }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.lastScan }</dt>
						<dd>{ view.scan_label + ' · ' + view.local_files + ' / ' + view.cloud_objects }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.retryQueue }</dt>
						<dd>
							{ ( h.retryQueueCount || '%d 个任务' ).replace( '%d', String( view.jobs ) ) }
							{ ' · ' }
							{ ( h.queueBreakdown || '动作队列 %1$d 待执行 / %2$d 死信' )
								.replace( '%1$d', String( view.queue.waiting ) )
								.replace( '%2$d', String( view.queue.dead ) ) }
						</dd>
					</div>
					<div className="health__row">
						<dt>{ h.metrics }</dt>
						<dd className="health__mono">
							{ format(
								ui.healthMetrics || '页面 %1$s · 批次 %2$s · 均 %3$s ms · 429 %4$s · 5xx %5$s',
								view.metrics.pages,
								view.metrics.batches,
								view.metrics.batch_ms,
								view.metrics.s429,
								view.metrics.s5xx
							) }
						</dd>
					</div>
					<div className="health__row">
						<dt>{ h.cron }</dt>
						<dd>{ ( view.cron_next ? h.scheduled : h.unscheduled ) + ' · ' + h.onDemand }</dd>
					</div>
					<div className="health__row">
						<dt>{ h.credentials }</dt>
						<dd>{ h.credValue }</dd>
					</div>
				</dl>
				<div className="button-row health__actions">
					<button type="button" className="btn btn--small" disabled={ frozen } onClick={ onToggle }>
						{ indexEnabled ? h.disableIndex : h.enableIndex }
					</button>
					<button type="button" className="btn btn--small" disabled={ frozen } onClick={ ( event ) => onTest( event.currentTarget ) }>
						{ h.test }
					</button>
					<a className="btn btn--ghost btn--small" href={ data.settingsUrl || '#' }>
						{ h.manage }
					</a>
				</div>
			</div>
		</Card>
	);
}
