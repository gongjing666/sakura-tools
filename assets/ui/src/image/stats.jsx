/**
 * The statistics tab: six KPI cells, the recent-history table with its own
 * pagination, and the reset button. Paging asks the server for one page; the
 * document never navigates.
 */

import { useState } from 'react';
import { Badge, BusyLabel, Card } from '../shared/ui.jsx';
import { call } from './transport.js';

/**
 * One KPI cell, optionally with a progress bar.
 *
 * @param {Object} props Kpi.
 * @return {JSX.Element} Kpi.
 */
function Kpi( { kpi } ) {
	return (
		<div className="kpi">
			<span className="kpi__label">{ kpi.label }</span>
			<strong className="kpi__value">
				{ kpi.value }
				{ kpi.unit ? <small>{ kpi.unit }</small> : null }
			</strong>
			{ kpi.sub ? <span className="kpi__sub">{ kpi.sub }</span> : null }
			{ 'number' === typeof kpi.progress ? (
				<div className="kpi__progress">
					<div className="progress">
						<span className="progress__bar" style={ { width: `${ Math.min( 100, Math.max( 0, kpi.progress ) ) }%` } } />
					</div>
				</div>
			) : null }
		</div>
	);
}

/**
 * The history table plus its pagination footer.
 *
 * @param {Object} props Card, transport and callbacks.
 * @return {JSX.Element} Table.
 */
function History( { card, rest, strings, onError } ) {
	const [ rows, setRows ] = useState( card.rows || [] );
	const [ pagination, setPagination ] = useState( card.pagination );
	const [ busy, setBusy ] = useState( false );

	const load = async ( page, perPage ) => {
		setBusy( true );
		onError( '' );

		try {
			const payload = await call(
				rest,
				strings,
				`?page=${ page }&per_page=${ perPage }`
			);

			if ( payload.history ) {
				setRows( payload.history.rows || [] );
				setPagination( payload.history.pagination );
			}
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setBusy( false );
		}
	};

	const { page, per_page: perPage, total, pages, options } = pagination;

	return (
		<>
			<div className="table-scroll">
				<table className="table table--responsive" id="sakura-stats-history">
					<thead>
						<tr>
							{ card.columns.map( ( column ) => (
								<th key={ column }>{ column }</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ rows.length === 0 ? (
							<tr className="stats-empty">
								<td colSpan={ card.columns.length }>{ card.empty }</td>
							</tr>
						) : (
							rows.map( ( row, index ) => (
								<tr key={ `${ row.time }|${ row.name }|${ index }` }>
									<td data-label={ card.columns[ 0 ] }>{ row.time }</td>
									<td data-label={ card.columns[ 1 ] }>
										{ row.url ? <a href={ row.url }>{ row.name }</a> : row.name }
									</td>
									<td data-label={ card.columns[ 2 ] }>{ row.format }</td>
									<td data-label={ card.columns[ 3 ] }>{ row.engine }</td>
									<td data-label={ card.columns[ 4 ] }>{ row.source }</td>
									<td data-label={ card.columns[ 5 ] }>{ row.size }</td>
									<td data-label={ card.columns[ 6 ] }>
										<Badge tone={ row.badge.tone } title={ row.badge.title || undefined }>
											{ row.badge.label }
										</Badge>
									</td>
								</tr>
							) )
						) }
					</tbody>
				</table>
			</div>
			<div className="pagination">
				<div className="pagination__sizes" role="group" aria-label={ strings.perPage }>
					<span>{ strings.perPage }</span>
					{ options.map( ( size ) => (
						<button
							key={ size }
							type="button"
							className={ 'pagination__link' + ( size === perPage ? ' is-current' : '' ) }
							disabled={ busy }
							onClick={ () => load( 1, size ) }
						>
							{ size }
						</button>
					) ) }
				</div>
				<span className="pagination__summary">
					{ strings.totalRows.replace( '%s', String( total ) ) }
				</span>
				<div className="pagination__pages">
					<button
						type="button"
						className="pagination__link"
						aria-label={ strings.prev }
						disabled={ busy || page <= 1 }
						onClick={ () => load( page - 1, perPage ) }
					>
						‹
					</button>
					<span>
						{ page } / { pages }
					</span>
					<button
						type="button"
						className="pagination__link"
						aria-label={ strings.next }
						disabled={ busy || page >= pages }
						onClick={ () => load( page + 1, perPage ) }
					>
						›
					</button>
				</div>
			</div>
		</>
	);
}

/**
 * Statistics tab body.
 *
 * @param {Object} props Boot payload and callbacks.
 * @return {JSX.Element} Body.
 */
export default function StatsPage( { boot, onError, onNotice, onHead } ) {
	const { strings, rest } = boot;
	const [ kpis, setKpis ] = useState( boot.kpis || [] );
	const [ history, setHistory ] = useState( boot.history );
	const [ resetting, setResetting ] = useState( false );

	const reset = async () => {
		if ( resetting ) {
			return;
		}

		if ( typeof window.confirm === 'function' && ! window.confirm( strings.resetConfirm ) ) {
			return;
		}

		setResetting( true );
		onError( '' );

		try {
			const payload = await call( rest, strings, '/reset', {} );

			if ( payload.payload ) {
				setKpis( payload.payload.kpis || [] );
				setHistory( payload.payload.history || history );

				if ( payload.payload.head && onHead ) {
					onHead( payload.payload.head );
				}
			}

			if ( payload.notice ) {
				onNotice( payload.notice );
			}
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setResetting( false );
		}
	};

	return (
		<>
			<section className="kpis" aria-label={ boot.page.title }>
				{ kpis.map( ( kpi ) => (
					<Kpi key={ kpi.label } kpi={ kpi } />
				) ) }
			</section>
			<div className="card-grid" data-sakura-layout="stats">
				<Card card={ history }>
					<History card={ history } rest={ rest } strings={ strings } onError={ onError } />
				</Card>
			</div>
			<div className="page-savebar">
				<button
					type="button"
					className="btn btn--danger"
					disabled={ resetting }
					onClick={ reset }
				>
					<BusyLabel idle={ strings.reset } active={ strings.working } busy={ resetting } />
				</button>
			</div>
		</>
	);
}
