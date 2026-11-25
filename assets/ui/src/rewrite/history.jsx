import { numberLabel } from './status.js';

export default function History( { runs, strings, busy, onRevert } ) {
	const columns = strings.columns || {};

	return (
		<>
			<div className="table-scroll">
				<table className="table table--compact table--responsive rewrite-runs">
					<thead>
						<tr>
							<th scope="col">{ columns.time }</th>
							<th scope="col">{ columns.rewrite }</th>
							<th scope="col">{ columns.ledger }</th>
							<th scope="col">{ columns.pending }</th>
							<th scope="col">{ columns.conflict }</th>
							<th scope="col">{ columns.action }</th>
						</tr>
					</thead>
					<tbody>
						{ runs.length ? runs.map( ( run ) => (
							<tr key={ run.runId } data-run={ run.runId }>
								<td data-label={ columns.time }>{ run.createdAt }</td>
								<td data-label={ columns.rewrite }><code>{ `${ run.from } → ${ run.to }` }</code></td>
								<td data-label={ columns.ledger }>{ numberLabel( run.total ) }</td>
								<td data-label={ columns.pending }>{ numberLabel( run.pending ) }</td>
								<td data-label={ columns.conflict }>{ numberLabel( run.conflicts ) }</td>
								<td data-label={ columns.action }>
									<button type="button" className="btn btn--ghost btn--small" onClick={ () => onRevert( run.runId ) } disabled={ busy || ! ( Number( run.pending ) > 0 ) }>
										{ strings.revertAction }
									</button>
								</td>
							</tr>
						) ) : (
							<tr className="rewrite-runs__empty">
								<td colSpan="6" data-label="" className="rewrite-empty-cell">
									<div className="empty-state">
										<strong className="empty-state__title">{ strings.emptyRunsTitle }</strong>
										<p className="empty-state__message">{ strings.emptyRunsBody }</p>
									</div>
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>
			<p className="rewrite-runs__note">{ strings.historyNote }</p>
		</>
	);
}
