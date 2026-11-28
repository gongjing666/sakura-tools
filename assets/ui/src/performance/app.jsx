import { useRef, useState } from 'react';
import { Badge, Card, PageHead, Prompt } from '../shared/ui.jsx';

/**
 * POST JSON to a REST path. `path` is appended to rest.url.
 *
 * @param {Object} rest REST block.
 * @param {string} path Path, '' for the base endpoint.
 * @param {Object} body JSON body.
 * @return {Promise<Object>} Decoded response.
 */
async function post( rest, path, body ) {
	const base = String( rest.url || '' ).replace( /\/$/, '' );
	const response = await fetch( base + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce },
		body: JSON.stringify( body || {} ),
	} );
	const payload = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new Error( ( payload && payload.message ) || '' );
	}

	return payload || {};
}

function LicenseCache( { card, strings, rest, onSection, onHead } ) {
	const data = card.data || {};
	const [ enabled, setEnabled ] = useState( !! data.enabled );
	const [ unit, setUnit ] = useState( data.unit || 'forever' );
	const [ duration, setDuration ] = useState( String( data.duration ?? 1 ) );
	const [ ack, setAck ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ warm, setWarm ] = useState( { text: '', tone: '' } );
	const [ notice, setNotice ] = useState( null );
	const saveRef = useRef( null );
	const forever = 'forever' === unit;
	const key = JSON.stringify( [ data.enabled, data.unit, data.duration ] );
	const [ lastKey, setLastKey ] = useState( () => key );

	if ( key !== lastKey ) {
		setLastKey( key );
		setEnabled( !! data.enabled );
		setUnit( data.unit || 'forever' );
		setDuration( String( data.duration ?? 1 ) );
		setAck( false );
	}

	const openNotice = ( payload ) => {
		const fallback = 'bad' === ( payload && payload.tone ) ? strings.failedNotice : strings.savedNotice;
		const copy = fallback || {};

		setNotice( {
			...copy,
			...( payload || {} ),
			closeLabel: strings.close || '',
			closeIcon: strings.closeIcon || '',
			mascot: strings.mascot || '',
			button: strings.dismiss || '',
		} );
	};

	const apply = ( payload ) => {
		if ( payload.sections ) {
			Object.entries( payload.sections ).forEach( ( [ id, section ] ) => onSection( id, section ) );
		}

		if ( payload.head && onHead ) {
			onHead( payload.head );
		}
	};

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving ) {
			return;
		}

		setSaving( true );
		setNotice( null );
		setWarm( { text: '', tone: '' } );

		try {
			const payload = await post( rest, '', {
				card: 'license-cache',
				fields: {
					license_cache: enabled ? '1' : '',
					duration: String( duration ),
					unit,
					unsupported_ack: ack ? '1' : '',
				},
			} );

			apply( payload );
			openNotice( payload.notice );
		} catch ( error ) {
			openNotice( { tone: 'bad', message: error.message || ( strings.failedNotice && strings.failedNotice.message ) || '' } );
		} finally {
			setSaving( false );
		}
	};

	const revalidate = async () => {
		if ( saving || ! enabled ) {
			setWarm( { text: strings.disabled, tone: 'bad' } );
			return;
		}

		if ( ! rest.warm ) {
			setWarm( { text: strings.failed, tone: 'bad' } );
			return;
		}

		setSaving( true );
		setNotice( null );
		setWarm( { text: strings.checking, tone: '' } );

		try {
			const response = await fetch( rest.warm, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': rest.nonce },
			} );
			const payload = await response.json().catch( () => null );

			if ( ! response.ok ) {
				throw new Error( ( payload && payload.message ) || strings.failed );
			}

			apply( payload );
			const notice = ( payload && payload.notice ) || {};
			setWarm( { text: notice.text || strings.checked, tone: 'bad' === notice.tone ? 'bad' : 'ok' } );
		} catch ( error ) {
			setWarm( { text: error.message || strings.failed, tone: 'bad' } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<>
			<Card card={ card }>
				<form className="card__form" onSubmit={ submit } noValidate>

				<div className="zibll-switch">
					<label className="check">
						<input
							className="is-switch"
							type="checkbox"
							id="zibll-license-cache"
							checked={ enabled }
							onChange={ ( event ) => setEnabled( event.target.checked ) }
						/>
						<span className="check__box" aria-hidden="true" />
						<span className="check__text">{ strings.cacheSwitch }</span>
					</label>
					<div className="zibll-switch__actions">
						<button
							type="button"
							className="btn btn--ghost btn--small"
							id="zibll-warm"
							disabled={ ! enabled || saving }
							aria-busy={ saving ? 'true' : undefined }
							onClick={ revalidate }
						>
							{ strings.revalidate }
						</button>
						<span
							className={ 'zibll-status' + ( warm.tone ? ' is-' + warm.tone : '' ) }
							id="zibll-warm-status"
							role="status"
							aria-live="polite"
						>
							{ warm.text }
						</span>
					</div>
				</div>

				<div className="zibll-duration">
					<div className={ 'field field--number zibll-duration__count' + ( forever ? ' is-muted' : '' ) }>
						<div className="field__main">
							<label className="field__label" htmlFor="zibll-duration">{ strings.duration }</label>
						</div>
						<div className="field__control">
							<input
								id="zibll-duration"
								type="number"
								value={ duration }
								min="1"
								max={ data.max || 9999 }
								step="1"
								disabled={ forever }
								onChange={ ( event ) => setDuration( event.target.value ) }
							/>
						</div>
					</div>
					<div className="field field--select zibll-duration__unit">
						<div className="field__main">
							<label className="field__label" htmlFor="zibll-unit">{ strings.unit }</label>
						</div>
						<div className="field__control">
							<select
								id="zibll-unit"
								value={ unit }
								onChange={ ( event ) => setUnit( event.target.value ) }
							>
								{ Object.entries( data.units || {} ).map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>{ label }</option>
								) ) }
							</select>
						</div>
					</div>
				</div>

				<p className="zibll-hint">{ strings.hint }</p>

				{ ! data.supported ? (
					<>
						<div className="notice-inline notice-inline--warn" role="alert">
							<p>
								{ strings.unsupportedNotice
									.replace( '%1$s', data.version || '' )
									.replace( '%2$s', data.supported_label || '' ) }
							</p>
						</div>
						<div className="field field--checkbox">
							<div className="field__control">
								<label className="check">
									<input type="checkbox" checked={ ack } onChange={ ( event ) => setAck( event.target.checked ) } />
									<span className="check__box" aria-hidden="true" />
									<span className="check__text">{ strings.ackLabel }</span>
								</label>
							</div>
							<p className="field__hint">{ strings.ackHint }</p>
						</div>
					</>
				) : null }

				{ data.invalid_notice ? (
					<div className="notice-inline notice-inline--warn" role="alert">
						<p>{ strings.invalidNotice }</p>
					</div>
				) : null }

				<div className="card__savebar">
					<button ref={ saveRef } type="submit" className="btn btn--primary" disabled={ saving }>
						{ card.save || strings.save || '' }
					</button>
				</div>
			</form>
		</Card>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ saveRef } /> : null }
		</>
	);
}

function CacheStatus( { card, strings } ) {
	const rows = ( card.data || {} ).rows || [];

	return (
		<Card card={ card }>
			<div className="table-scroll">
				<table className="table table--compact table--records zibll-status-table">
					<thead>
						<tr>
							<th scope="col">{ strings.statusProject }</th>
							<th scope="col">{ strings.statusValue }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.key }>
								<td data-label={ strings.statusProject }>{ row.label }</td>
								<td data-label={ strings.statusValue }>
									<span className="zibll-status-value">{ row.value }</span>
									{ row.badge && row.badge.text ? <Badge tone={ row.badge.tone }>{ row.badge.text }</Badge> : null }
									{ row.meta ? <span className="zibll-meta">{ row.meta }</span> : null }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<p className="zibll-note">{ strings.statusNote }</p>
		</Card>
	);
}

export default function App( { boot } ) {
	const strings = boot.strings || {};
	const rest = boot.rest || {};
	const [ head, setHead ] = useState( boot.head );
	const [ cards, setCards ] = useState( boot.cards || [] );
	const byId = {};

	cards.forEach( ( card ) => {
		byId[ card.id ] = card;
	} );

	const onSection = ( id, section ) => setCards( ( current ) => current.map( ( card ) => (
		card.id === id ? { ...card, ...section } : card
	) ) );

	return (
		<>
			<PageHead head={ head } />
			<div className="card-grid" data-sakura-layout="zibll-performance" data-sakura-group="main">
				{ byId[ 'license-cache' ] ? (
					<LicenseCache
						card={ byId[ 'license-cache' ] }
						strings={ strings }
						rest={ rest }
						onSection={ onSection }
						onHead={ setHead }
					/>
				) : null }
				{ byId[ 'cache-status' ] ? <CacheStatus card={ byId[ 'cache-status' ] } strings={ strings } /> : null }
			</div>
		</>
	);
}