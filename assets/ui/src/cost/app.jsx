/**
 * The cloud-storage cost island.
 *
 * Everything that is part of the pricing model (bounds, labels, notes, the
 * first result) comes from the PHP payload; the browser only edits the demand
 * and swaps in the server's normalized result.
 */
import { useRef, useState } from 'react';
import { Card, Field, PageHead, Prompt } from '../shared/ui.jsx';
import { estimate } from './transport.js';

const NUMBER_FORMAT = new Intl.NumberFormat( 'zh-CN', {
	minimumFractionDigits: 2,
	maximumFractionDigits: 2,
} );

function money( value ) {
	const parsed = Number( value );

	return '¥' + NUMBER_FORMAT.format( Number.isFinite( parsed ) ? parsed : 0 );
}

function valuesFrom( data ) {
	const input = ( data.result || {} ).input || {};
	const next = {};

	( data.fields || [] ).forEach( ( field ) => {
		next[ field.key ] = String( input[ field.key ] !== undefined ? input[ field.key ] : field.value );
	} );

	return next;
}

function sourceList( sources, strings ) {
	const entries = Object.entries( sources || {} );

	if ( ! entries.length ) {
		return null;
	}

	return (
		<p className="cost-sources">
			{ strings.priceSource }
			{ entries.map( ( [ url, name ], index ) => (
				<span key={ url }>
					{ index > 0 ? ' · ' : null }
					<a href={ url } target="_blank" rel="noopener noreferrer">
						{ name || url }
					</a>
				</span>
			) ) }
		</p>
	);
}

function CostTable( { result, strings, open, onToggle } ) {
	const rows = ( result || {} ).rows || [];
	const columns = strings.columns || {};

	return (
		<>
			<p className="cost-meta">
				{ ( strings.meta || '' )
					.replace( '%1$s', 'cdn' === ( result || {} ).mode ? strings.modeCdn : strings.modeDirect )
					.replace( '%2$s', String( rows.length ) ) }
			</p>
			<div className="table-scroll cost-table-wrap">
				<table className="table table--compact table--responsive cost-table">
					<thead>
						<tr>
							<th scope="col">{ columns.name }</th>
							<th scope="col">{ columns.storage }</th>
							<th scope="col">{ columns.traffic }</th>
							<th scope="col">{ columns.requests }</th>
							<th scope="col">{ columns.image }</th>
							<th scope="col">{ columns.total }</th>
							<th scope="col"><span className="screen-reader-text">{ columns.detail }</span></th>
						</tr>
					</thead>
					<tbody>
						{ rows.length ? (
							rows.map( ( row ) => {
								const key = String( row.key || '' );
								const detailId = `cost-detail-${ key.replace( /[^a-z0-9_-]/gi, '' ) }`;
								const traffic = ( Number( row.egress ) || 0 ) + ( Number( row.origin ) || 0 );

								return (
									<FragmentRow
										key={ key }
										row={ row }
										rowKey={ key }
										detailId={ detailId }
										traffic={ traffic }
										string={ strings }
										open={ !! open[ key ] }
										onToggle={ onToggle }
									/>
								);
							} )
						) : (
							<tr>
								<td colSpan="7">
									<div className="empty-state">
										<strong className="empty-state__title">{ strings.empty }</strong>
									</div>
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>
		</>
	);
}

function FragmentRow( { row, rowKey, detailId, traffic, string, open, onToggle } ) {
	return (
		<>
			<tr className="cost-row">
				<td data-label={ string.columns.name }>
					<span className="cost-name">
						{ row.name }
						{ row.cheapest ? <span className="badge badge--ok">{ string.cheapest }</span> : null }
						{ row.usd ? <span className="badge badge--neutral">{ string.usd }</span> : null }
					</span>
					<span className="cost-tag">{ row.tag }</span>
				</td>
				<td data-label={ string.columns.storage }>{ money( row.storage ) }</td>
				<td data-label={ string.columns.traffic }>{ money( traffic ) }</td>
				<td data-label={ string.columns.requests }>{ money( row.requests ) }</td>
				<td data-label={ string.columns.image }>{ money( row.image ) }</td>
				<td data-label={ string.columns.total }><strong className="cost-total">{ money( row.total ) }</strong></td>
				<td>
					<button
						type="button"
						className="btn btn--quiet btn--small"
						aria-expanded={ open ? 'true' : 'false' }
						aria-controls={ detailId }
						onClick={ () => onToggle( rowKey ) }
					>
						{ string.detail }
					</button>
				</td>
			</tr>
			<tr className="cost-detail" id={ detailId } hidden={ ! open }>
				<td colSpan="7">
					<div className="cost-detail__inner">
						<ul className="cost-detail__lines">
							{ ( row.billing || [] ).map( ( line, index ) => (
								<li key={ index }><span>{ line.label }</span> { line.value }</li>
							) ) }
						</ul>
						<p className="cost-detail__note">{ row.note }</p>
						<p className="cost-detail__sources">
							{ row.source ? (
								<a href={ row.source } target="_blank" rel="noopener noreferrer">{ string.storagePrice }</a>
							) : null }
							{ row.cdn_source && row.cdn_source !== row.source ? (
								<a href={ row.cdn_source } target="_blank" rel="noopener noreferrer">{ string.cdnPrice }</a>
							) : null }
						</p>
					</div>
				</td>
			</tr>
		</>
	);
}

/**
 * Page root.
 *
 * @param {Object} props Boot payload.
 * @return {JSX.Element} Page.
 */
export default function App( { boot } ) {
	const data = boot.data || {};
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const [ values, setValues ] = useState( () => valuesFrom( data ) );
	const [ result, setResult ] = useState( data.result || {} );
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ open, setOpen ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const opener = useRef( null );

	const notify = ( message, tone ) => {
		if ( ! message ) {
			return;
		}

		opener.current = document.querySelector( '[data-sakura-return-target]' );

		setNotice( {
			tone,
			kicker: strings.noticeKicker,
			title: 'bad' === tone ? strings.noticeErrorTitle : strings.noticeTitle,
			message,
			closeLabel: strings.close,
			closeIcon: strings.closeIcon,
			mascot: strings.mascot,
			button: strings.dismiss,
		} );
	};

	const onChange = ( key, value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const onEstimate = async () => {
		if ( busy ) {
			return;
		}

		setBusy( true );
		setStatus( strings.estimating );

		try {
			const next = await estimate( rest, values, strings );

			setResult( next || {} );
			setValues( valuesFrom( { ...data, result: next || {} } ) );
			setStatus( strings.done );
			notify( strings.done, 'ok' );
		} catch ( error ) {
			const message = error && error.message ? String( error.message ) : strings.unknown;

			setStatus( message );
			notify( message, 'bad' );
		} finally {
			setBusy( false );
		}
	};

	const cards = Array.isArray( boot.cards ) ? boot.cards : [];
	const byId = {};

	cards.forEach( ( card ) => {
		byId[ card.id ] = card;
	} );

	return (
		<>
			<PageHead head={ boot.head } />

			<div className="page-body">
				<Card card={ byId.demand } titleProps={ { tabIndex: -1, 'data-sakura-return-target': '1' } }>
					<div className="cost-form">
						{ ( data.fields || [] ).map( ( field ) => (
							<Field
								key={ field.key }
								field={ field }
								value={ values[ field.key ] || '' }
								onChange={ onChange }
								disabled={ busy }
							/>
						) ) }
					</div>
					<div className="cost-actions">
						<button type="button" className="btn btn--primary" onClick={ onEstimate } disabled={ busy }>
							{ busy ? strings.estimating : strings.estimate }
						</button>
						<span className="cost-status" role="status" aria-live="polite">{ status }</span>
					</div>
					<div className="alert alert--info" role="status">
						<div className="alert__body">
							{ ( strings.alert || '' ).replace( '%s', data.pricedAt || '' ) }
						</div>
					</div>
				</Card>

				<Card card={ byId.table }>
					<CostTable
						result={ result }
						strings={ strings }
						open={ open }
						onToggle={ ( key ) => setOpen( ( current ) => ( { ...current, [ key ]: ! current[ key ] } ) ) }
					/>
				</Card>

				<Card card={ byId.notes }>
					<ul className="notes-list">
						{ ( data.notes || [] ).map( ( note, index ) => <li key={ index }>{ note }</li> ) }
					</ul>
					{ sourceList( data.sources, strings ) }
				</Card>
			</div>

			{ notice ? (
				<Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ opener } />
			) : null }
		</>
	);
}
