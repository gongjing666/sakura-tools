/**
 * The live queue panel: counters, distribution, throughput and the stall hint.
 *
 * Every number and label comes from the server snapshot; this component only
 * arranges them. The session line is the one exception: it compares the
 * baseline captured when the page opened with the current completed count, so
 * the reader can see progress since they started watching.
 */
export default function LivePanel( { snapshot, strings, auto, busy, onToggle, onRefresh, baseline } ) {
	const counts = snapshot.counts || {};
	const activity = snapshot.activity || {};
	const live = strings.live || {};
	const format = ( value ) => Number( value || 0 ).toLocaleString();

	const total = ( counts.queued || 0 ) + ( counts.running || 0 ) + ( counts.done || 0 ) + ( counts.dead || 0 ) + ( counts.failed || 0 );
	const segments = [
		{ key: 'running', value: counts.running || 0, tone: 'running' },
		{ key: 'queued', value: counts.queued || 0, tone: 'queued' },
		{ key: 'done', value: counts.done || 0, tone: 'done' },
		{ key: 'dead', value: counts.dead || 0, tone: 'dead' },
	].filter( ( segment ) => segment.value > 0 );

	const doneSinceOpen = Math.max( 0, ( counts.done || 0 ) - ( baseline.done || 0 ) );
	const pendingAtOpen = Math.max( 0, baseline.pending || 0 );
	const sessionPercent = pendingAtOpen > 0 ? Math.min( 100, ( doneSinceOpen / pendingAtOpen ) * 100 ) : null;
	const retrying = counts.retrying || 0;
	const pendingSub = ( live.queuedShort || '排队 %s' ).replace( '%s', format( counts.queued ) ) +
		( retrying > 0 ? ' · ' + ( live.retryingShort || '其中 %s 个等待重试' ).replace( '%s', format( retrying ) ) : '' );
	const recentText = ( live.recent || '近 %1$s 分钟完成 %2$s 个' )
		.replace( '%1$s', format( activity.recent_minutes ) )
		.replace( '%2$s', format( activity.done_recent ) );
	const stall = activity.stalled ? (
		<div className="notice-inline notice-inline--warn" role="status">
			<p>
				<strong>{ live.stalledTitle }</strong>{ ' ' }
				{ activity.stalled_label }{ ' ' }
				{ live.stalledHint }
			</p>
		</div>
	) : null;

	return (
		<section className="queue-live" aria-label={ strings.aria ? strings.aria.live : '' }>
			<header className="queue-live__head">
				<div className="queue-live__title">
					<span className={ 'queue-live__dot' + ( auto ? ' is-on' : ' is-paused' ) } aria-hidden="true" />
					<strong>{ live.title }</strong>
					<span className="queue-live__state">{ auto ? live.auto : live.paused }</span>
				</div>
				<div className="queue-live__tools">
					<span className="queue-live__stamp">{ ( live.lastUpdated || '最后更新 %s' ).replace( '%s', snapshot.generated_label || '' ) }</span>
					<button type="button" className="btn btn--small" aria-pressed={ ! auto } onClick={ onToggle }>
						{ auto ? live.pause : live.resume }
					</button>
					<button type="button" className="btn btn--small" aria-busy={ busy === 'refresh' } onClick={ onRefresh }>
						{ busy === 'refresh' ? strings.actions.refreshing : live.refresh }
					</button>
				</div>
			</header>

			{ stall }

			<div className="queue-live__stats">
				<div className="queue-live__stat">
					<span className="queue-live__stat-label">{ live.pending }</span>
					<strong className="queue-live__stat-value">{ format( activity.pending ) }</strong>
					<span className="queue-live__stat-sub">{ pendingSub }</span>
				</div>
				<div className="queue-live__stat">
					<span className="queue-live__stat-label">{ live.running }</span>
					<strong className="queue-live__stat-value">{ format( counts.running ) }</strong>
					<span className="queue-live__stat-sub" title={ ( ( snapshot.running || [] )[ 0 ] || {} ).key || '' }>{ ( counts.running || 0 ) > 0 && ( snapshot.running || [] )[ 0 ] ? snapshot.running[ 0 ].key : live.noRunning }</span>
				</div>
				<div className="queue-live__stat">
					<span className="queue-live__stat-label">{ live.done }</span>
					<strong className="queue-live__stat-value">{ format( counts.done ) }</strong>
					<span className="queue-live__stat-sub">{ recentText }</span>
				</div>
				<div className="queue-live__stat">
					<span className="queue-live__stat-label">{ live.dead }</span>
					<strong className="queue-live__stat-value">{ format( counts.dead ) }</strong>
					<span className="queue-live__stat-sub">{ ( activity.retention_days || 7 ) + ' 天后清理' }</span>
				</div>
			</div>

			<div
				className="queue-live__track"
				role="img"
				aria-label={ format( counts.queued ) + ' / ' + format( counts.running ) + ' / ' + format( counts.done ) + ' / ' + format( counts.dead ) }
			>
				{ segments.map( ( segment ) => (
					<span
						key={ segment.key }
						className={ 'queue-live__seg queue-live__seg--' + segment.tone }
						style={ { width: ( total > 0 ? ( segment.value / total ) * 100 : 0 ) + '%' } }
						title={ format( segment.value ) }
					/>
				) ) }
			</div>

			<div className="queue-live__legend">
				<span className="queue-live__legend-item"><i className="queue-live__swatch queue-live__swatch--queued" />{ ( live.queuedShort || '排队 %s' ).replace( '%s', format( counts.queued ) ) }</span>
				<span className="queue-live__legend-item"><i className="queue-live__swatch queue-live__swatch--running" />{ live.running } { format( counts.running ) }</span>
				<span className="queue-live__legend-item"><i className="queue-live__swatch queue-live__swatch--done" />{ live.done } { format( counts.done ) }</span>
				<span className="queue-live__legend-item"><i className="queue-live__swatch queue-live__swatch--dead" />{ live.dead } { format( counts.dead ) }</span>
			</div>

			<div className="queue-live__meta">
				<span>{ recentText }</span>
				<span>{ ( live.rate || '%s 个/分' ).replace( '%s', activity.rate_label || '—' ) }</span>
				<span>{ ( live.eta || '预计剩余 %s' ).replace( '%s', activity.eta_label || '—' ) }</span>
				<span>{ ( live.next || '下一批：%s' ).replace( '%s', activity.next_due_label || '—' ) }</span>
			</div>

			<div className="queue-live__session">
				{ sessionPercent === null ? (
					<span>{ live.sessionEmpty }</span>
				) : (
					<>
						<span>
							{ ( live.session || '本次打开后完成 %1$s / %2$s' )
								.replace( '%1$s', format( doneSinceOpen ) )
								.replace( '%2$s', format( pendingAtOpen ) ) }
							{ ' · ' + Math.round( sessionPercent ) + '%' }
						</span>
						<div className="progress queue-live__session-bar">
							<span className="progress__bar" style={ { width: sessionPercent + '%' } } />
						</div>
					</>
				) }
			</div>

			{ ( snapshot.running || [] ).length > 0 ? (
				<ul className="queue-live__running">
					{ snapshot.running.map( ( row ) => (
						<li key={ row.id }>
							{ ( live.runningNow || '正在执行 #%1$s · %2$s（最近活动 %3$s）' )
								.replace( '%1$s', String( row.id ) )
								.replace( '%2$s', row.key )
								.replace( '%3$s', row.age_label ) }
						</li>
					) ) }
				</ul>
			) : null }

			<p className="queue-live__note">{ ( live.retention || '已完成记录保留 %s 天，可导出 CSV 归档。' ).replace( '%s', String( activity.retention_days || 7 ) ) }</p>
		</section>
	);
}
