/**
 * Task center: scan controls, coverage, the 7-day activity strip.
 *
 * Every button calls the same admin-ajax action the classic controller did, so
 * the full-reconcile mode, the queue hand-off and the R18 exit all stay on the
 * server where they were.
 */
import { Card, Badge } from '../shared/ui.jsx';

export default function TasksCard( { card, view, rows, scan, strings, locked, frozen, indexEnabled, onAction } ) {
	const s = strings.tasks || {};
	const running = Boolean( scan.running );
	const resumable = Boolean( scan.resumable );
	const percent = Math.round( Number( view.coverage || 0 ) );
	const activity = buildActivity( rows );

	return (
		<Card card={ card }>
			<div className="task-summary">
				<div className="task-summary__head">
					<div>
						<div className="task-summary__title">
							<strong>{ s.latest || '最近一次全量核对' }</strong>
							<Badge tone={ scan.state_tone || 'neutral' }>{ scan.state_label || '未开始' }</Badge>
						</div>
						<p className="small-text" data-state={ scan.state || '' }>
							{ scan.meta_text || '' }
						</p>
					</div>
					<div className="task-summary__percent">{ Number( view.coverage_label || 0 ) }%</div>
				</div>
				<div
					className="progress progress--soda"
					role="progressbar"
					aria-valuenow={ percent }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-label="同步覆盖进度"
				>
					<span className="progress__bar" style={ { width: Math.min( 100, Math.max( 0, Number( view.coverage || 0 ) ) ) + '%' } }>
						<span className="progress__fizz" aria-hidden="true" />
					</span>
					<span className="progress__pop" aria-hidden="true" />
				</div>
				<div className="progress-meta">
					<span>
						<strong>{ view.synced }</strong> / { view.total } 个文件 · 本地 { view.local_files } · 云端 { view.cloud_objects }
					</span>
					<span>{ indexEnabled ? s.liveRead : s.compatRead }</span>
				</div>
				<div className="task-stats">
					<span className="task-stat">
						<span>{ s.localFiles }</span>
						<strong>{ view.local_files }</strong>
					</span>
					<span className="task-stat">
						<span>{ s.cloudFiles }</span>
						<strong>{ view.cloud_objects }</strong>
					</span>
					<span className="task-stat">
						<span>{ s.syncedFiles }</span>
						<strong>{ view.synced }</strong>
					</span>
					<span className="task-stat">
						<span>{ s.pendingDiff }</span>
						<strong>{ view.difference }</strong>
					</span>
				</div>
				<div className="button-row task-actions">
					<button
						type="button"
						className="btn btn--primary btn--small"
						disabled={ frozen || ! indexEnabled }
						onClick={ onAction.scan }
					>
						{ s.startScan }
					</button>
					<button
						type="button"
						className="btn btn--small"
						disabled={ locked || ! indexEnabled }
						onClick={ ( event ) => onAction.refresh( event.currentTarget ) }
					>
						{ s.refresh }
					</button>
					{ running ? (
						<button type="button" className="btn btn--small" disabled={ frozen } onClick={ onAction.cancel }>
							{ s.cancel }
						</button>
					) : null }
					{ resumable && ! running ? (
						<button type="button" className="btn btn--small" disabled={ locked } onClick={ onAction.resume }>
							{ s.resume }
						</button>
					) : null }
					<button
						type="button"
						className="btn btn--small"
						disabled={ locked || 0 === Number( view.failed_total ) }
						onClick={ onAction[ 'retry-all' ] }
					>
						{ ( s.retryAll || '重试失败 (%d)' ).replace( '%d', String( view.failed_total ) ) }
					</button>
					<a className="btn btn--ghost btn--small" href="#logs">
						{ s.viewLogs }
					</a>
				</div>
			</div>
			<div className="activity">
				<div className="activity__head">
					<h3 className="activity__title">{ s.activityTitle }</h3>
					<div className="activity__legend">
						<span>
							<i className="activity__dot activity__dot--ok" aria-hidden="true" />
							{ s.activityOk }
						</span>
						<span>
							<i className="activity__dot activity__dot--bad" aria-hidden="true" />
							{ s.activityBad }
						</span>
						<span>{ ( s.activitySum || '共 %1$s / %2$s 个样本' ).replace( '%1$s', String( activity.ok ) ).replace( '%2$s', String( activity.bad ) ) }</span>
					</div>
				</div>
				<div className="activity__chart" aria-label={ s.activityTitle }>
					{ activity.days.map( ( day ) => (
						<div className="activity__day" key={ day.key }>
							<div className="activity__bars">
								<span className="activity__bar activity__bar--ok" style={ { height: day.okHeight + 'px' } } title={ day.label + '：正常 ' + day.ok } />
								{ day.failHeight > 0 ? (
									<span className="activity__bar activity__bar--bad" style={ { height: day.failHeight + 'px' } } title={ day.label + '：失败 ' + day.fail } />
								) : null }
							</div>
							<span className="activity__label">{ day.label }</span>
						</div>
					) ) }
				</div>
			</div>
		</Card>
	);
}

/**
 * Seven day buckets, computed from the rows the payload carries.
 *
 * The classic page built these from its first page of rows, which is what the
 * index returns for "all"; here the same rows travel in the payload so the
 * strip looks identical on first paint.
 */
function buildActivity( rows ) {
	const days = [];
	const byKey = {};
	const today = new Date();

	for ( let offset = 6; offset >= 0; offset -= 1 ) {
		const date = new Date( today.getTime() - offset * 86400000 );
		const key = date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
		const bucket = { key, label: pad( date.getMonth() + 1 ) + '/' + pad( date.getDate() ), ok: 0, fail: 0 };

		days.push( bucket );
		byKey[ key ] = bucket;
	}

	const list = Array.isArray( rows ) ? rows : [];

	list.forEach( ( row ) => {
		if ( ! row.mtime ) {
			return;
		}

		const date = new Date( Number( row.mtime ) * 1000 );
		const key = date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
		const bucket = byKey[ key ];

		if ( ! bucket ) {
			return;
		}

		if ( 'failed' === row.status ) {
			bucket.fail += 1;
		} else {
			bucket.ok += 1;
		}
	} );

	let max = 1;
	let ok = 0;
	let bad = 0;

	days.forEach( ( day ) => {
		max = Math.max( max, day.ok + day.fail );
		ok += day.ok;
		bad += day.fail;
	} );

	days.forEach( ( day ) => {
		day.okHeight = Math.max( 2, Math.round( ( day.ok / max ) * 72 ) );
		day.failHeight = day.fail > 0 ? Math.max( 2, Math.round( ( day.fail / max ) * 72 ) ) : 0;
	} );

	return { days, ok, bad };
}

function pad( value ) {
	return String( value ).padStart( 2, '0' );
}