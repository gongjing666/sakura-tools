import { useState } from 'react';
import { Badge, Card, PageHead } from '../shared/ui.jsx';

function NativeForm( { card, children } ) {
	const form = card.form || {};

	return (
		<form className="card__form" method="post" action={ form.action }>
			<input type="hidden" name="option_page" value={ form.group } />
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="_wpnonce" value={ form.nonce } />
			<input type="hidden" name={ form.option + '[_sakura_card]' } value={ form.markerCard } />
			<input type="hidden" name={ form.option + '[_sakura_partial]' } value="1" />
			{ children }
			<div className="card__savebar">
				<button type="submit" className="btn btn--primary">{ card.save || '保存更改' }</button>
			</div>
		</form>
	);
}

function LicenseCache( { card, strings, config } ) {
	const data = card.data || {};
	const [ enabled, setEnabled ] = useState( !! data.enabled );
	const [ unit, setUnit ] = useState( data.unit || 'forever' );
	const [ duration, setDuration ] = useState( String( data.duration ?? 1 ) );
	const [ busy, setBusy ] = useState( false );
	const [ warm, setWarm ] = useState( { text: '', tone: '' } );
	const forever = 'forever' === unit;
	const option = data.option || 'sakura_imgopt_zibll';

	const revalidate = () => {
		if ( busy ) {
			return;
		}

		if ( ! enabled ) {
			setWarm( { text: strings.disabled, tone: 'bad' } );
			return;
		}

		const admin = window.SakuraAdmin;

		if ( ! admin || ! admin.api || ! config.action || ! config.nonce ) {
			setWarm( { text: strings.failed, tone: 'bad' } );
			return;
		}

		setBusy( true );
		setWarm( { text: strings.checking, tone: '' } );

		admin.api.ajax( config.action, { nonce: config.nonce }, { timeout: 60000 } )
			.then( ( payload ) => {
				const message = ( payload && payload.message ) || strings.checked;
				setWarm( { text: message, tone: 'ok' } );
				if ( admin.ui ) {
					admin.ui.toast( message, 'ok' );
				}
			} )
			.catch( ( error ) => {
				let message = strings.failed;

				if ( error && error.payload && error.payload.data && error.payload.data.message ) {
					message = error.payload.data.message;
				}

				setWarm( { text: message, tone: 'bad' } );
				if ( admin.ui ) {
					admin.ui.toast( message, 'bad', { duration: 6000 } );
				}
			} )
			.then( () => setBusy( false ) );
	};

	return (
		<Card card={ card }>
			<NativeForm card={ card }>
				<div className="zibll-switch">
					<input type="hidden" name={ option + '[license_cache]' } value="0" />
					<label className="check">
						<input
							className="is-switch"
							type="checkbox"
							id="zibll-license-cache"
							name={ option + '[license_cache]' }
							value="1"
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
							disabled={ ! enabled || busy }
							aria-busy={ busy ? 'true' : undefined }
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
								name={ option + '[duration]' }
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
								name={ option + '[unit]' }
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
									<input type="checkbox" name={ option + '[' + data.ack + ']' } value="1" />
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
			</NativeForm>
		</Card>
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
	const config = boot.config || {};
	const strings = config.strings || {};
	const byId = {};

	( boot.cards || [] ).forEach( ( card ) => {
		byId[ card.id ] = card;
	} );

	return (
		<>
			<PageHead head={ boot.head } />
			<div className="card-grid" data-sakura-layout="zibll-performance" data-sakura-group="main">
				{ byId[ 'license-cache' ] ? <LicenseCache card={ byId[ 'license-cache' ] } strings={ strings } config={ config } /> : null }
				{ byId[ 'cache-status' ] ? <CacheStatus card={ byId[ 'cache-status' ] } strings={ strings } /> : null }
			</div>
		</>
	);
}
