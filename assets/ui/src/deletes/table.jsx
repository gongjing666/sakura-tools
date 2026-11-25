/**
 * Queue table: filters, rows, selection and the batch actions.
 *
 * The row's status badge, progress bar and label all come from the snapshot;
 * this file never derives a state from dates or attempt counts.
 */
import { useEffect, useRef } from 'react';
import { Badge } from '../shared/ui.jsx';

export default function QueueTable( {
	snapshot,
	strings,
	selected,
	onToggle,
	onToggleAll,
	onFilter,
	onPage,
	onRetry,
	onDrop,
	onExport,
	busy,
	exportHref,
} ) {
	const rows = snapshot.rows || [];
	const counts = snapshot.counts || {};
	const pagination = snapshot.pagination || {};
	const table = strings.table || {};
	const actions = strings.actions || {};
	const selectedMap = {};
	const selectAllRef = useRef( null );

	selected.forEach( ( id ) => {
		selectedMap[ id ] = true;
	} );

	const visibleSelected = rows.filter( ( row ) => selectedMap[ row.id ] );
	const retryable = visibleSelected.filter( ( row ) => [ 'queued', 'dead', 'failed' ].indexOf( row.state ) >= 0 );
	const allChecked = rows.length > 0 && visibleSelected.length === rows.length;
	const someChecked = visibleSelected.length > 0 && visibleSelected.length < rows.length;
	const filters = [
		{ key: 'all', label: ( strings.stateLabels || {} ).all, value: counts.all || 0 },
		{ key: 'queued', label: ( strings.stateLabels || {} ).queued, value: counts.queued || 0 },
		{ key: 'running', label: ( strings.stateLabels || {} ).running, value: counts.running || 0 },
		{ key: 'done', label: ( strings.stateLabels || {} ).done, value: counts.done || 0 },
		{ key: 'dead', label: ( strings.stateLabels || {} ).dead, value: counts.dead || 0 },
	];

	if ( ( counts.failed || 0 ) > 0 ) {
		filters.push( { key: 'failed', label: ( strings.stateLabels || {} ).failed, value: counts.failed } );
	}

	useEffect( () => {
		if ( selectAllRef.current ) {
			selectAllRef.current.indeterminate = someChecked;
		}
	}, [ someChecked ] );

	const pageItems = [];
	const pages = pagination.pages || 1;
	const current = pagination.page || 1;
	const first = Math.max( 1, current - 2 );
	const last = Math.min( pages, current + 2 );

	for ( let number = first; number <= last; number++ ) {
		pageItems.push( number );
	}

	const empty = rows.length === 0;
	const emptyTitle = 'all' === snapshot.state ? table.emptyAll : table.emptyState;
	const emptyHint = 'all' === snapshot.state ? table.emptyAllHint : table.emptyStateHint;

	return (
		<>
			<nav className="queue-stats" aria-label={ strings.aria ? strings.aria.filters : '' }>
				{ filters.map( ( item ) => (
					<button
						key={ item.key }
						type="button"
						className={ 'queue-stat' + ( snapshot.state === item.key ? ' is-active' : '' ) + ( 0 === item.value ? ' is-zero' : '' ) }
						aria-pressed={ snapshot.state === item.key }
						onClick={ () => onFilter( item.key ) }
					>
						<span className="queue-stat__label">{ item.label }</span>
						<strong className="queue-stat__value">{ Number( item.value || 0 ).toLocaleString() }</strong>
					</button>
				) ) }
			</nav>

			{ empty ? (
				<div className="empty-state queue-empty">
					<strong className="empty-state__title">{ emptyTitle }</strong>
					<p className="empty-state__message">{ emptyHint }</p>
				</div>
			) : (
				<>
					<label className="queue-select-mobile">
						<input
							type="checkbox"
							checked={ allChecked }
							onChange={ ( event ) => onToggleAll( event.target.checked ) }
						/>
						<span>{ table.selectAll }</span>
					</label>

					<div className="table-scroll">
						<table className="table table--responsive" aria-label={ strings.aria ? strings.aria.table : '' }>
							<thead>
								<tr>
									<th scope="col" className="cell-select">
										<input
											type="checkbox"
											checked={ allChecked }
											ref={ selectAllRef }
											onChange={ ( event ) => onToggleAll( event.target.checked ) }
											aria-label={ table.selectAll }
										/>
									</th>
									<th scope="col">{ table.id }</th>
									<th scope="col">{ table.object }</th>
									<th scope="col">{ table.state }</th>
									<th scope="col" className="is-num">{ table.attempts }</th>
									<th scope="col">{ table.progress }</th>
									<th scope="col">{ table.updated }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => (
									<tr key={ row.id } data-state={ row.state }>
										<td className="cell-select" data-label={ table.select }>
											<input
												type="checkbox"
												checked={ Boolean( selectedMap[ row.id ] ) }
												onChange={ ( event ) => onToggle( row.id, event.target.checked ) }
												aria-label={ table.select + ' #' + row.id }
											/>
										</td>
										<td data-label={ table.id }><span className="queue-id">#{ row.id }</span></td>
										<td data-label={ table.object }>
											<code className="queue-key" title={ row.key }>{ row.key }</code>
											{ row.attachment_id > 0 || row.profile_id ? (
												<span className="queue-ref">
													{ ( table.ref || '附件 #%1$s · 配置 %2$s' )
														.replace( '%1$s', String( row.attachment_id || '—' ) )
														.replace( '%2$s', row.profile_id || '—' ) }
												</span>
											) : null }
										</td>
										<td data-label={ table.state }>
											<Badge tone={ row.badge.tone }>{ row.badge.label }</Badge>
										</td>
										<td data-label={ table.attempts } className="is-num">
											<span className="queue-attempts__value">{ row.attempt_label }</span>
											<span className="queue-attempts__bar" aria-hidden="true">
												<i style={ { width: Math.max( 0, Math.min( 100, row.attempt_percent || 0 ) ) + '%' } } />
											</span>
										</td>
										<td data-label={ table.progress }>
											<div className="queue-progress">
												{ 'running' === row.progress.kind ? (
													<div className="progress progress--indeterminate">
														<span className="progress__bar" />
													</div>
												) : (
													<div className="progress">
														<span
															className={ 'progress__bar progress__bar--' + row.progress.kind }
															style={ { width: Math.max( 0, Math.min( 100, row.progress.percent || 0 ) ) + '%' } }
														/>
													</div>
												) }
												<span className="queue-progress__text">{ row.progress.label }</span>
												{ row.error ? <span className="queue-error" title={ row.error }>{ row.error }</span> : null }
											</div>
										</td>
										<td data-label={ table.updated }><span className="queue-time">{ row.updated_label }</span></td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }

			<div className="card__savebar queue-savebar">
				<span className="queue-savebar__note">
					{ visibleSelected.length > 0
						? ( actions.selected || '已选 %s 项' ).replace( '%s', String( visibleSelected.length ) )
						: actions.exportHint }
				</span>
				<div className="button-row">
					<button
						type="button"
						className="btn btn--primary btn--small"
						disabled={ retryable.length === 0 || busy === 'retry' }
						aria-busy={ busy === 'retry' }
						onClick={ () => onRetry( retryable.map( ( row ) => row.id ) ) }
						title={ actions.retryHint }
					>
						{ busy === 'retry' ? actions.retrying : actions.retry }
					</button>
					<button
						type="button"
						className="btn btn--danger btn--small"
						disabled={ visibleSelected.length === 0 || busy === 'drop' }
						aria-busy={ busy === 'drop' }
						onClick={ () => onDrop( visibleSelected.map( ( row ) => row.id ) ) }
						title={ actions.dropHint }
					>
						{ busy === 'drop' ? actions.dropping : actions.drop }
					</button>
					<a className="btn btn--ghost btn--small" href={ exportHref }>
						{ actions.export }
					</a>
				</div>
			</div>

			{ pages > 1 ? (
				<nav className="pagination" aria-label={ ( strings.pagination || {} ).label || '' }>
					<span className="pagination__summary">
						{ ( ( strings.pagination || {} ).summary || '第 %1$s / %2$s 页 · 共 %3$s 条' )
							.replace( '%1$s', String( current ) )
							.replace( '%2$s', String( pages ) )
							.replace( '%3$s', String( pagination.total || 0 ) ) }
					</span>
					<div className="pagination__pages">
						<button type="button" className="pagination__link" disabled={ current <= 1 } onClick={ () => onPage( current - 1 ) } aria-label={ ( strings.pagination || {} ).prev }>‹</button>
						{ first > 1 ? <button type="button" className="pagination__link" onClick={ () => onPage( 1 ) }>1</button> : null }
						{ first > 2 ? <span className="pagination__ellipsis">…</span> : null }
						{ pageItems.map( ( number ) => (
							number === current
								? <span key={ number } className="pagination__link is-current" aria-current="page">{ number }</span>
								: <button key={ number } type="button" className="pagination__link" onClick={ () => onPage( number ) }>{ number }</button>
						) ) }
						{ last < pages - 1 ? <span className="pagination__ellipsis">…</span> : null }
						{ last < pages ? <button type="button" className="pagination__link" onClick={ () => onPage( pages ) }>{ pages }</button> : null }
						<button type="button" className="pagination__link" disabled={ current >= pages } onClick={ () => onPage( current + 1 ) } aria-label={ ( strings.pagination || {} ).next }>›</button>
					</div>
				</nav>
			) : null }
		</>
	);
}
