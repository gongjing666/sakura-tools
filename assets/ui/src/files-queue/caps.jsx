import { Card, LockIcon } from '../shared/ui.jsx';

/**
 * Capability matrix. The actual save is still the native admin-post form so
 * Capability_Matrix::save() keeps owning the permission semantics.
 */
export default function CapsMatrix( { card, config } ) {
	const strings = config.strings || {};
	const caps = config.caps || { columns: [], roles: [], legend: '' };
	const form = config.capsForm || {};
	const notice = form.notice;

	return (
		<Card card={ card }>
			<form className="card__form" method="post" action={ form.action }>
				<input type="hidden" name="action" value={ form.actionName } />
				<input type="hidden" name="redirect_to" value={ form.redirectTo } />
				<input type="hidden" name="_wpnonce" value={ form.nonce } />

				{ notice ? (
					<div className={ 'notice-inline notice-inline--' + ( notice.tone || 'info' ) } role="status">
						<p>{ notice.text }</p>
					</div>
				) : null }

				<div className="table-scroll">
					<table className="table table--compact caps-matrix">
						<thead>
							<tr>
								<th scope="col">{ strings.roleColumn || '' }</th>
								{ caps.columns.map( ( column ) => (
									<th key={ column.key } scope="col" title={ column.label + '：' + column.hint }>
										<abbr title={ column.label }>{ column.abbr }</abbr>
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ caps.roles.map( ( role ) => (
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
										const column = caps.columns.find( ( item ) => item.key === cell.key );
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
														<input type="hidden" name={ 'sufm_caps[' + role.role + '][' + cell.key + ']' } value="1" />
														<span className="screen-reader-text">
															{ role.label + '：' + label + ( strings.capsFixed || '' ) }
														</span>
													</>
												) : (
													<label className="caps-matrix__toggle" title={ label }>
														<input
															type="checkbox"
															name={ 'sufm_caps[' + role.role + '][' + cell.key + ']' }
															value="1"
															defaultChecked={ cell.stored }
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

				<p className="field__hint caps-matrix__legend">{ caps.legend }</p>
				<p className="card__note">{ strings.capsNote || '' }</p>

				<div className="card__savebar">
					<button type="submit" className="btn btn--primary">{ strings.saveCaps || '' }</button>
				</div>
			</form>
		</Card>
	);
}
