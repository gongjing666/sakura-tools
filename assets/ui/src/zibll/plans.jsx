import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, fmt } from './controls.jsx';
import { toast } from '../files/shared/transport.js';

function nameFor( option, group, key, field ) {
	return option + '[' + group + '][' + key + '][' + field + ']';
}

function nextId( kind, rows ) {
	const prefix = 'cash' === kind ? 'A' : 'P';
	let max = 0;

	rows.forEach( ( row ) => {
		const match = /^([AP])(\d+)$/i.exec( String( row.id || '' ) );
		if ( match && match[ 1 ].toUpperCase() === prefix ) {
			max = Math.max( max, parseInt( match[ 2 ], 10 ) || 0 );
		}
	} );

	return prefix + ( max + 1 );
}

function newKey() {
	return 'new' + Date.now() + Math.floor( Math.random() * 1000 );
}

export default function Plans( { card, strings, config } ) {
	const data = card.data || {};
	const option = config.spaceOption;
	const points = data.points || {};
	const [ cash, setCash ] = useState( ( data.packages || [] ).map( ( row ) => ( { ...row, removed: false } ) ) );
	const [ ppkgs, setPpkgs ] = useState( ( data.points_packages || [] ).map( ( row ) => ( { ...row, removed: false } ) ) );
	const [ icon, setIcon ] = useState( data.icon || '' );
	const [ themeRate ] = useState( points.pay_rate );

	const add = ( kind ) => {
		if ( 'cash' === kind ) {
			setCash( ( rows ) => [ ...rows, {
				key: newKey(),
				id: nextId( 'cash', rows ),
				label: '',
				gb: '',
				price: '',
				days: '365',
				active: true,
				removed: false,
			} ] );
			return;
		}

		setPpkgs( ( rows ) => [ ...rows, {
			key: newKey(),
			id: nextId( 'points', rows ),
			label: '',
			gb: '',
			points: '',
			days: '365',
			active: true,
			removed: false,
		} ] );
	};

	const pickIcon = () => {
		if ( ! window.wp || typeof window.wp.media !== 'function' ) {
			toast( strings.mediaUnavailable, 'bad' );
			return;
		}

		const frame = window.wp.media( {
			title: strings.chooseIcon,
			library: { type: 'image' },
			button: { text: strings.chooseIcon },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( attachment && attachment.url ) {
				setIcon( attachment.url );
			}
		} );
		frame.open();
	};

	const updateCash = ( key, field, value ) => {
		setCash( ( rows ) => rows.map( ( row ) => ( row.key === key ? { ...row, [ field ]: value } : row ) ) );
	};
	const updatePoints = ( key, field, value ) => {
		setPpkgs( ( rows ) => rows.map( ( row ) => ( row.key === key ? { ...row, [ field ]: value } : row ) ) );
	};

	const removeCash = ( key, isNew ) => {
		if ( isNew ) {
			setCash( ( rows ) => rows.filter( ( row ) => row.key !== key ) );
			return;
		}
		updateCash( key, 'removed', true );
	};
	const removePoints = ( key, isNew ) => {
		if ( isNew ) {
			setPpkgs( ( rows ) => rows.filter( ( row ) => row.key !== key ) );
			return;
		}
		updatePoints( key, 'removed', true );
	};

	const cheapest = data.compare && data.compare.cheapest;
	const dailyCap = points.daily_cap || '1';
	const perGbHint = themeRate
		? fmt( strings.perGbHint, [
			( Number( points.per_gb ) / Number( themeRate ) ).toFixed( 2 ),
			Number( themeRate ).toFixed( 2 ),
		] )
		: strings.noPayRate;

	return (
		<Card card={ card }>
			<Form card={ card }>
				<input type="hidden" name={ option + '[packages][_present]' } value="1" />
				<input type="hidden" name={ option + '[points_packages][_present]' } value="1" />

				<div className="zibll-plans">
					<div className="zibll-icon-field">
						<button type="button" className="zibll-icon-pick" onClick={ pickIcon }>
							<img src={ icon || data.default_icon } alt="" width="28" height="28" />
							<span>{ strings.chooseIcon }</span>
						</button>
						<div className="zibll-icon-field__input">
							<input
								type="url"
								name={ option + '[order_icon]' }
								value={ icon }
								placeholder={ strings.iconPlaceholder }
								onChange={ ( event ) => setIcon( event.target.value ) }
							/>
							{ icon ? (
								<button type="button" className="btn btn--quiet btn--small" onClick={ () => setIcon( '' ) }>
									{ strings.removeIcon }
								</button>
							) : null }
						</div>
						<p className="field__hint">{ strings.iconHint }</p>
					</div>

					<section className="zibll-subsection" aria-labelledby="zibll-cash-title">
						<header className="zibll-subhead">
							<h3 id="zibll-cash-title">{ strings.cashPackages }</h3>
							<p>{ strings.cashHint }</p>
						</header>
						<div className="table-scroll">
							<table className="table table--compact table--responsive zibll-plan-table">
								<thead>
									<tr>
										<th scope="col">{ strings.planId }</th>
										<th scope="col">{ strings.planName }</th>
										<th scope="col">{ strings.capacityGb }</th>
										<th scope="col">{ strings.price }</th>
										<th scope="col">{ strings.days }</th>
										<th scope="col">{ strings.active }</th>
										<th scope="col">{ strings.remove }</th>
									</tr>
								</thead>
								<tbody>
									{ cash.map( ( row ) => (
										<tr className={ 'zibll-plan-row' + ( row.removed ? ' is-removed' : '' ) } key={ row.key }>
											<td data-label={ strings.planId }>
												<input
													className="zibll-plan-id"
													type="text"
													name={ nameFor( option, 'packages', row.key, 'id' ) }
													value={ row.id }
													readOnly
												/>
											</td>
											<td data-label={ strings.planName }>
												<input
													type="text"
													name={ nameFor( option, 'packages', row.key, 'label' ) }
													value={ row.label }
													placeholder={ strings.labelPlaceholder }
													onChange={ ( event ) => updateCash( row.key, 'label', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.capacityGb }>
												<input
													type="number"
													name={ nameFor( option, 'packages', row.key, 'bytes_gb' ) }
													value={ row.gb }
													min="0.1"
													step="0.1"
													onChange={ ( event ) => updateCash( row.key, 'gb', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.price }>
												<input
													type="number"
													name={ nameFor( option, 'packages', row.key, 'price' ) }
													value={ row.price }
													min="0"
													step="0.01"
													onChange={ ( event ) => updateCash( row.key, 'price', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.days }>
												<input
													type="number"
													name={ nameFor( option, 'packages', row.key, 'days' ) }
													value={ row.days }
													min="0"
													step="1"
													onChange={ ( event ) => updateCash( row.key, 'days', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.active }>
												<input type="hidden" name={ nameFor( option, 'packages', row.key, 'active' ) } value="0" />
												<label className="check">
													<input
														type="checkbox"
														name={ nameFor( option, 'packages', row.key, 'active' ) }
														value="1"
														defaultChecked={ Boolean( row.active ) }
													/>
													<span className="check__box" aria-hidden="true" />
													<span className="screen-reader-text">{ strings.active }</span>
												</label>
											</td>
											<td data-label={ strings.remove }>
												<input type="hidden" name={ nameFor( option, 'packages', row.key, 'remove' ) } value={ row.removed ? '1' : '0' } />
												<button
													type="button"
													className="btn btn--quiet btn--small"
													onClick={ () => removeCash( row.key, String( row.key ).startsWith( 'new' ) ) }
												>
													{ strings.remove }
												</button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<p className="zibll-row-actions">
							<button type="button" className="btn btn--ghost btn--small" onClick={ () => add( 'cash' ) }>{ strings.addCash }</button>
						</p>
					</section>

					<section className="zibll-subsection" aria-labelledby="zibll-points-title">
						<header className="zibll-subhead">
							<h3 id="zibll-points-title">{ strings.pointsPackages }</h3>
							<p>{ strings.pointsPackagesHint }</p>
						</header>
						<div className="field-list">
							<FieldRow label={ strings.perGb } hint={ perGbHint }>
								<input
									type="number"
									id="space-points-per-gb"
									name={ option + '[points][points_per_gb]' }
									defaultValue={ points.per_gb }
									min="1"
									step="1"
								/>
							</FieldRow>
							<FieldRow label={ strings.maxGb } hint={ strings.maxGbHint }>
								<input
									type="number"
									id="space-points-max-gb"
									name={ option + '[points][max_gb_per_order]' }
									defaultValue={ points.max_gb }
									min="1"
									step="1"
								/>
							</FieldRow>
							<FieldRow label={ strings.dailyCap } hint={ strings.dailyCapHint }>
								<input
									type="number"
									id="space-points-daily-cap"
									name={ option + '[points][daily_cap_gb]' }
									defaultValue={ points.daily_cap }
									min="1"
									step="1"
								/>
							</FieldRow>
						</div>

						<div className="table-scroll">
							<table className="table table--compact table--responsive zibll-plan-table">
								<thead>
									<tr>
										<th scope="col">{ strings.planId }</th>
										<th scope="col">{ strings.planName }</th>
										<th scope="col">{ strings.capacityGb }</th>
										<th scope="col">{ strings.pointsRequired }</th>
										<th scope="col">{ strings.days }</th>
										<th scope="col">{ strings.active }</th>
										<th scope="col">{ strings.remove }</th>
									</tr>
								</thead>
								<tbody>
									{ ppkgs.map( ( row ) => (
										<tr className={ 'zibll-plan-row' + ( row.removed ? ' is-removed' : '' ) } key={ row.key }>
											<td data-label={ strings.planId }>
												<input
													className="zibll-plan-id"
													type="text"
													name={ nameFor( option, 'points_packages', row.key, 'id' ) }
													value={ row.id }
													readOnly
												/>
											</td>
											<td data-label={ strings.planName }>
												<input
													type="text"
													name={ nameFor( option, 'points_packages', row.key, 'label' ) }
													value={ row.label }
													placeholder={ strings.pointsLabelPlaceholder }
													onChange={ ( event ) => updatePoints( row.key, 'label', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.capacityGb }>
												<input
													type="number"
													name={ nameFor( option, 'points_packages', row.key, 'gb' ) }
													value={ row.gb }
													min="0.5"
													step="0.5"
													onChange={ ( event ) => updatePoints( row.key, 'gb', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.pointsRequired }>
												<input
													type="number"
													name={ nameFor( option, 'points_packages', row.key, 'points' ) }
													value={ row.points }
													min="1"
													step="1"
													onChange={ ( event ) => updatePoints( row.key, 'points', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.days }>
												<input
													type="number"
													name={ nameFor( option, 'points_packages', row.key, 'days' ) }
													value={ row.days }
													min="0"
													step="1"
													onChange={ ( event ) => updatePoints( row.key, 'days', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.active }>
												<input type="hidden" name={ nameFor( option, 'points_packages', row.key, 'active' ) } value="0" />
												<label className="check">
													<input
														type="checkbox"
														name={ nameFor( option, 'points_packages', row.key, 'active' ) }
														value="1"
														defaultChecked={ Boolean( row.active ) }
													/>
													<span className="check__box" aria-hidden="true" />
													<span className="screen-reader-text">{ strings.active }</span>
												</label>
											</td>
											<td data-label={ strings.remove }>
												<input type="hidden" name={ nameFor( option, 'points_packages', row.key, 'remove' ) } value={ row.removed ? '1' : '0' } />
												<button
													type="button"
													className="btn btn--quiet btn--small"
													onClick={ () => removePoints( row.key, String( row.key ).startsWith( 'new' ) ) }
												>
													{ strings.remove }
												</button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<p className="zibll-row-actions">
							<button type="button" className="btn btn--ghost btn--small" onClick={ () => add( 'points' ) }>{ strings.addPoints }</button>
						</p>
					</section>

					<div className="zibll-compare">
						{ null === cheapest || undefined === cheapest ? (
							<p className="zibll-muted">{ strings.noCash }</p>
						) : (
							<>
								<p>{ fmt( strings.cheapest, [ cheapest ] ) }</p>
								{ data.compare.far ? <p><strong>{ strings.far }</strong></p> : null }
							</>
						) }
						<p className="zibll-muted">{ fmt( strings.today, [ data.today.gb, dailyCap, data.today.count ] ) }</p>
					</div>

					<p className="zibll-muted">{ strings.planNote }</p>
				</div>
			</Form>
		</Card>
	);
}
