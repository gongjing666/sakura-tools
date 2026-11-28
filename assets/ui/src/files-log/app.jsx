import { useEffect, useState } from 'react';
import { Card, PageHead } from '../shared/ui.jsx';
import Pager from '../files/shared/Pager.jsx';
import EmptyState from '../files/shared/EmptyState.jsx';
import { apiGet, errorText, fill, toast } from '../files/shared/transport.js';

function resultTone( value ) {
	const text = String( value || '' ).toLowerCase();

	if ( text === 'ok' || text === 'success' || text === 'succeeded' || text === 'done' ) {
		return 'ok';
	}

	if ( text === 'failed' || text === 'error' || text === 'partial_failed' ) {
		return 'bad';
	}

	return 'neutral';
}

/**
 * Audit list. Filters intentionally apply to the current page, matching the
 * legacy controller; changing a filter does not refetch from the server.
 */
export default function App( { boot } ) {
	const config = boot.rest || {};
	const strings = boot.strings || {};
	const data = boot.data || {};
	const card = ( boot.cards || [] )[ 0 ] || {};
	const [ page, setPage ] = useState( 1 );
	const [ per, setPer ] = useState( 25 );
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ reload, setReload ] = useState( 0 );
	const [ search, setSearch ] = useState( '' );
	const [ result, setResult ] = useState( '' );
	const [ reason, setReason ] = useState( '' );

	useEffect( () => {
		const controller = new AbortController();

		setLoading( true );
		apiGet( config, 'admin/events', { page, per_page: per }, controller.signal )
			.then( ( data ) => {
				setRows( Array.isArray( data.rows ) ? data.rows : [] );
				setTotal( Number( data.total ) || 0 );
				setResult( '' );
				setLoading( false );
			} )
			.catch( ( error ) => {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				setRows( [] );
				setTotal( 0 );
				setLoading( false );
				toast( errorText( error, strings.loadFailed ), 'bad' );
			} );

		return () => controller.abort();
	}, [ page, per, reload ] );

	const query = search.trim().toLowerCase();
	const results = Array.from( new Set( rows.map( ( row ) => row.result ).filter( Boolean ) ) );
	const filtered = rows.filter( ( row ) => {
		const haystack = [ row.action || '', row.result || '', row.safe_detail || '', row.created_at || '' ].join( ' ' ).toLowerCase();

		return ( ! query || haystack.includes( query ) )
			&& ( ! result || row.result === result )
			&& ( ! reason || haystack.includes( ':' + reason ) );
	} );
	const hasFilter = Boolean( query || result || reason );
	const count = hasFilter
		? fill( strings.filterSummary || '', { shown: filtered.length, total: rows.length } )
		: filtered.length + ' / ' + rows.length;

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout="files-log" data-sakura-group="main">
				<Card card={ card }>
					<div className="toolbar" role="search">
						<label className="toolbar__field toolbar__field--grow">
							<span className="screen-reader-text">{ strings.filterLabel || '' }</span>
							<input
								type="search"
								value={ search }
								placeholder={ strings.filterPlaceholder || '' }
								onChange={ ( event ) => setSearch( event.target.value ) }
							/>
						</label>
						<label className="toolbar__field">
							<span className="screen-reader-text">{ strings.resultLabel || '' }</span>
							<select value={ result } onChange={ ( event ) => setResult( event.target.value ) }>
								<option value="">{ strings.allResults || '' }</option>
								{ results.map( ( value ) => <option key={ value } value={ value }>{ value }</option> ) }
							</select>
						</label>
						<label className="toolbar__field">
							<span className="screen-reader-text">{ strings.reasonLabel || '' }</span>
							<select value={ reason } onChange={ ( event ) => setReason( event.target.value ) }>
								{ ( data.logReasons || [] ).map( ( option ) => (
									<option key={ option.value } value={ option.value }>{ option.label }</option>
								) ) }
							</select>
						</label>
						<button type="button" className="btn btn--small btn--ghost" onClick={ () => setReload( ( value ) => value + 1 ) }>
							{ strings.refresh || '' }
						</button>
						<span className="toolbar__meta" aria-live="polite">{ rows.length ? count : '' }</span>
					</div>

					{ loading ? (
						<ul className="event-list">
							<li className="event-list__empty">{ strings.loading || '' }</li>
						</ul>
					) : rows.length ? (
						<>
							<ul className="event-list" hidden={ ! filtered.length }>
								{ filtered.map( ( row, index ) => (
									<li className="event-list__item" key={ row.id || row.created_at + ':' + index }>
										<span className="event-list__action">{ row.action || '—' }</span>
										<span className="event-list__result">
											<span className={ 'badge badge--' + resultTone( row.result ) }>{ row.result || '—' }</span>
										</span>
										<span className="event-list__detail" title={ row.safe_detail || undefined }>{ row.safe_detail || '—' }</span>
										<span className="event-list__time">{ row.created_at || '—' }</span>
									</li>
								) ) }
							</ul>
							<p className="filter-empty" hidden={ filtered.length > 0 }>{ strings.filterEmpty || '' }</p>
						</>
					) : (
						<div className="page-empty">
							<EmptyState title={ strings.emptyTitle || '' } message={ strings.emptyMessage || '' } />
						</div>
					) }

					<Pager
						page={ page }
						per={ per }
						total={ total }
						sizes={ [ 25, 50, 100 ] }
						strings={ strings }
						onChange={ ( nextPage, nextPer ) => {
							setPage( nextPage );
							setPer( nextPer );
						} }
					/>
				</Card>
			</div>
		</>
	);
}
