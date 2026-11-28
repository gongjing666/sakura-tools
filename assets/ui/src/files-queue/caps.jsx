import { useState } from 'react';
import { BusyLabel, Card, LockIcon } from '../shared/ui.jsx';
import { apiPost, errorText } from '../files/shared/transport.js';

/**
 * Seed the draft from the server matrix: role → capability → stored.
 *
 * @param {Object} caps Matrix payload.
 * @return {Object} Draft map.
 */
function seed( caps ) {
	const out = {};

	( caps && caps.roles ? caps.roles : [] ).forEach( ( role ) => {
		out[ role.role ] = {};

		( role.cells || [] ).forEach( ( cell ) => {
			out[ role.role ][ cell.key ] = !! cell.stored;
		} );
	} );

	return out;
}

/**
 * Capability matrix, saved through the same REST transport as the other cards.
 *
 * @param {Object} props Card, transport and strings.
 * @return {JSX.Element} Card.
 */
export default function CapsMatrix( { card, rest, strings } ) {
	const [ matrix, setMatrix ] = useState( card.caps || { columns: [], roles: [], legend: '' } );
	const [ draft, setDraft ] = useState( () => seed( card.caps ) );
	const [ saved, setSaved ] = useState( () => seed( card.caps ) );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const dirty = JSON.stringify( draft ) !== JSON.stringify( saved );
	const toggle = ( role, cap, checked ) => setDraft( ( current ) => ( {
		...current,
		[ role ]: { ...( current[ role ] || {} ), [ cap ]: checked },
	} ) );

	const submit = async ( event ) => {
		event.preventDefault();

		if ( saving || ! dirty ) {
			return;
		}

		setSaving( true );
		setNotice( null );

		try {
			const payload = await apiPost( rest, 'admin/caps', { caps: draft } );
			const next = payload.caps || matrix;

			setMatrix( next );
			setDraft( seed( next ) );
			setSaved( seed( next ) );
			setNotice( payload.notice || null );
		} catch ( error ) {
			setNotice( { tone: 'bad', text: errorText( error, strings.capsFailed || '' ) } );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card card={ card }>
			<form className="card__form" onSubmit={ submit } noValidate>
				{ notice && notice.text ? (
					<div className={ 'notice-inline notice-inline--' + ( notice.tone || 'info' ) } role="status">
						<p>{ notice.text }</p>
					</div>
				) : null }

				<details className="caps-fold">
					<summary className="caps-fold__summary">
						<span className="caps-fold__summary-text">{ strings.capsCollapse || strings.capsSummary || '权限设置（低频）' }</span>
						{ strings.capsCollapseHint ? <span className="caps-fold__hint">{ strings.capsCollapseHint }</span> : null }
					</summary>
					<div className="table-scroll">
						<table className="table table--compact caps-matrix">
							<thead>
								<tr>
									<th scope="col">{ strings.roleColumn || '' }</th>
									{ matrix.columns.map( ( column ) => (
										<th key={ column.key } scope="col" title={ column.label + '：' + column.hint }>
											<abbr title={ column.label }>{ column.abbr }</abbr>
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ matrix.roles.map( ( role ) => (
									<tr key={ role.role }>
										<th scope="row">
											<span className="caps-matrix__role-en">{ role.english }</span>
											<span className="caps-matrix__role-zh">{ role.label }</span>
											{ role.limited ? (
												<span className="caps-matrix__badge" title={ strings.capsLimited || '' }>
													{ strings.capsLimited || '' }
												</span>
											) : null }
										</th>
										{ role.cells.map( ( cell ) => {
											const column = matrix.columns.find( ( item ) => item.key === cell.key );
											const label = column ? column.label : cell.key;
											const driftTitle = cell.stored !== cell.actual
												? ( cell.actual ? strings.capsDriftOn : strings.capsDriftOff )
												: '';

											return (
												<td
													key={ cell.key }
													className={ 'caps-matrix__cell' + ( cell.limited ? ' is-limited' : '' ) + ( cell.locked ? ' is-locked' : '' ) }
												>
													{ cell.locked ? (
														<>
															<span className="caps-matrix__fixed" title={ strings.capsLocked || '' } aria-hidden="true">
																<LockIcon />
															</span>
															<span className="screen-reader-text">
																{ role.label + '：' + label + ( strings.capsFixed || '' ) }
															</span>
														</>
													) : (
														<label className="caps-matrix__toggle" title={ label }>
															<input
																type="checkbox"
																checked={ !! ( draft[ role.role ] && draft[ role.role ][ cell.key ] ) }
																onChange={ ( event ) => toggle( role.role, cell.key, event.target.checked ) }
															/>
															<span className="screen-reader-text">{ role.label + '：' + label }</span>
														</label>
													) }
													{ cell.stored !== cell.actual ? (
														<span className="caps-matrix__drift" title={ driftTitle }>⚠</span>
													) : null }
												</td>
											);
										} ) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					{ matrix.columns.length ? (
						<ul className="caps-legend" aria-label={ strings.capsLegendTitle || '' }>
							{ matrix.columns.map( ( column ) => (
								<li className="caps-legend__item" key={ column.key }>
									<span className="caps-legend__abbr" aria-hidden="true">{ column.abbr }</span>
									<span className="caps-legend__body">
										<strong className="caps-legend__label">{ column.label }</strong>
										<span className="caps-legend__hint">{ column.hint }</span>
									</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="field__hint caps-matrix__legend">{ matrix.legend }</p>
					) }
					<p className="card__note">{ strings.capsNote || '' }</p>

					<div className="card__savebar">
						<button type="submit" className="btn btn--primary" disabled={ saving || ! dirty }>
							<BusyLabel idle={ strings.saveCaps || '' } active={ strings.saveCaps || '' } busy={ saving } />
						</button>
					</div>
				</details>
			</form>
		</Card>
	);
}