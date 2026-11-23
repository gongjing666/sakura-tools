/**
 * Presentational pieces for the system screen that only this screen needs.
 *
 * The generic pieces (card, field, badge, top bar, tabs, page head, save
 * prompt) live in assets/ui/src/shared/ui.jsx and are re-exported here so the
 * page keeps one import path.
 */
import { Badge } from '../shared/ui.jsx';

export {
	Badge,
	BusyLabel,
	Card,
	Field,
	PageHead,
	Prompt,
	Tabs,
	Topbar,
} from '../shared/ui.jsx';

/** The two-column readout table: one record per row on narrow screens. */
export function RuntimeTable( { card } ) {
	return (
		<>
			<div className="table-scroll">
				<table className="table table--compact table--records">
					<thead>
						<tr>
							{ card.columns.map( ( label ) => (
								<th key={ label } scope="col">
									{ label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ card.rows.map( ( row ) => (
							<tr key={ row.label }>
								<td data-label={ card.columns[ 0 ] }>{ row.label }</td>
								<td data-label={ card.columns[ 1 ] }>
									{ row.value ? <span className="system-value">{ row.value }</span> : null }
									{ row.muted ? <span className="system-muted">{ row.muted }</span> : null }
									{ ( row.badges || [] ).map( ( badge ) => (
										<Badge key={ badge[ 0 ] } tone={ badge[ 1 ] }>
											{ badge[ 0 ] }
										</Badge>
									) ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ card.note ? <p className="system-note">{ card.note }</p> : null }
		</>
	);
}

export function ChecksTable( { card } ) {
	return (
		<div className="table-scroll">
			<table className="table table--compact table--checks">
				<thead>
					<tr>
						{ card.columns.map( ( label ) => (
							<th key={ label } scope="col">
								{ label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ card.rows.map( ( row ) => (
						<tr key={ row.label }>
							<td data-label={ card.columns[ 0 ] }>
								<span className="system-value">{ row.label }</span>
							</td>
							<td data-label={ card.columns[ 1 ] }>
								<Badge tone={ row.tone }>{ row.stateLabel }</Badge>
							</td>
							<td data-label={ card.columns[ 2 ] } className="system-check-value">
								{ row.value || '—' }
								{ row.note && '—' !== row.note ? (
									<span className="system-check-note system-check-note--inline">{ row.note }</span>
								) : null }
							</td>
							<td data-label={ card.columns[ 3 ] } className="system-check-note">
								{ row.note && '—' !== row.note ? row.note : '' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

export function ExtensionGrid( { card } ) {
	return (
		<>
			<div className="system-ext-grid">
				{ card.items.map( ( item ) => (
					<Badge key={ item.name } tone={ item.loaded ? 'ok' : 'warn' }>
						{ item.name }
					</Badge>
				) ) }
			</div>
			{ card.note ? <p className="system-note">{ card.note }</p> : null }
		</>
	);
}

export function KpiBoard( { score, kpis } ) {
	return (
		<section className="kpis kpis--ticket" aria-label={ score.aria }>
			<article className={ `kpi kpi--score kpi--score-${ score.tone }` }>
				<span className="kpi__label">{ score.label }</span>
				<span className="kpi__value">
					{ score.value }
					<small>{ score.unit }</small>
				</span>
				<div className="kpi__progress">
					<progress className="progress" max="100" value={ score.value } aria-label={ score.label }>
						{ score.value }%
					</progress>
				</div>
				<span className="kpi__sub">{ score.note }</span>
			</article>
			{ kpis.map( ( kpi ) => (
				<article className="kpi" key={ kpi.label }>
					<span className="kpi__label">{ kpi.label }</span>
					<span className="kpi__value">
						{ kpi.value }
						<small>{ kpi.unit }</small>
					</span>
					<span className="kpi__sub">{ kpi.sub }</span>
				</article>
			) ) }
		</section>
	);
}
