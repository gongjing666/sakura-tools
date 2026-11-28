import { Card } from '../shared/ui.jsx';
import Form from './form.jsx';
import { FieldRow, cardTitleProps, fmt, toast } from './controls.jsx';
import { useCardSave, useDraft } from './save.js';

function copyRows( rows ) {
	return ( Array.isArray( rows ) ? rows : [] ).map( ( row ) => ( { ...row, removed: false } ) );
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

export default function Plans( { card, rest, strings, onSections, onNotice } ) {
	const data = card.data || {};
	const points = data.points || {};
	const compare = data.compare || {};
	const today = data.today || {};
	const [ cash, setCash ] = useDraft( data.packages, copyRows );
	const [ ppkgs, setPpkgs ] = useDraft( data.points_packages, copyRows );
	const [ icon, setIcon ] = useDraft( String( data.icon || '' ), String );
	const [ pricing, setPricing ] = useDraft( {
		points_per_gb: String( points.per_gb ?? '' ),
		max_gb_per_order: String( points.max_gb ?? '' ),
		daily_cap_gb: String( points.daily_cap ?? '' ),
	}, ( value ) => ( { ...value } ) );
	const [ failure, save, pending ] = useCardSave( { cardId: 'space_plans', rest, strings, onSections, onNotice } );

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

	const updateCash = ( key, field, value ) => setCash( ( rows ) => rows.map( ( row ) => ( row.key === key ? { ...row, [ field ]: value } : row ) ) );
	const updatePoints = ( key, field, value ) => setPpkgs( ( rows ) => rows.map( ( row ) => ( row.key === key ? { ...row, [ field ]: value } : row ) ) );

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

	const submit = ( event ) => {
		event.preventDefault();

		if ( pending ) {
			return;
		}

		save( {
			order_icon: icon,
			packages: cash.map( ( row ) => ( {
				id: row.id,
				label: row.label,
				gb: row.gb,
				price: row.price,
				days: row.days,
				active: row.active ? 1 : 0,
				remove: row.removed ? 1 : 0,
			} ) ),
			points_packages: ppkgs.map( ( row ) => ( {
				id: row.id,
				label: row.label,
				gb: row.gb,
				points: row.points,
				days: row.days,
				active: row.active ? 1 : 0,
				remove: row.removed ? 1 : 0,
			} ) ),
			points: pricing,
		} );
	};

	const rate = Number( points.pay_rate ) || 0;
	const perGb = Number( pricing.points_per_gb ) || 0;
	const perGbHint = rate > 0 && perGb > 0
		? fmt( strings.perGbHint, [ ( perGb / rate ).toFixed( 2 ), rate.toFixed( 2 ) ] )
		: strings.noPayRate;
	const cheapest = compare.cheapest;
	const dailyCap = pricing.daily_cap_gb || points.daily_cap || '1';

	return (
		<Card card={ card } titleProps={ cardTitleProps }>
			<Form label={ card.save } saving={ strings.saving } pending={ pending } failure={ failure } onSubmit={ submit }>
				<div className="zibll-plans">
					<div className="zibll-icon-field">
						<button type="button" className="zibll-icon-pick" onClick={ pickIcon }>
							<img src={ icon || data.default_icon } alt="" width="28" height="28" />
							<span>{ strings.chooseIcon }</span>
						</button>
						<div className="zibll-icon-field__input">
							<input
								type="url"
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
												<input className="zibll-plan-id" type="text" value={ row.id } readOnly />
											</td>
											<td data-label={ strings.planName }>
												<input
													type="text"
													value={ row.label }
													placeholder={ strings.labelPlaceholder }
													onChange={ ( event ) => updateCash( row.key, 'label', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.capacityGb }>
												<input
													type="number"
													value={ row.gb }
													min="0.1"
													step="0.1"
													onChange={ ( event ) => updateCash( row.key, 'gb', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.price }>
												<input
													type="number"
													value={ row.price }
													min="0"
													step="0.01"
													onChange={ ( event ) => updateCash( row.key, 'price', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.days }>
												<input
													type="number"
													value={ row.days }
													min="0"
													step="1"
													onChange={ ( event ) => updateCash( row.key, 'days', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.active }>
												<label className="check">
													<input
														type="checkbox"
														checked={ Boolean( row.active ) }
														onChange={ ( event ) => updateCash( row.key, 'active', event.target.checked ) }
													/>
													<span className="check__box" aria-hidden="true" />
													<span className="screen-reader-text">{ strings.active }</span>
												</label>
											</td>
											<td data-label={ strings.remove }>
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
									value={ pricing.points_per_gb }
									min="1"
									step="1"
									onChange={ ( event ) => setPricing( ( current ) => ( { ...current, points_per_gb: event.target.value } ) ) }
								/>
							</FieldRow>
							<FieldRow label={ strings.maxGb } hint={ strings.maxGbHint }>
								<input
									type="number"
									id="space-points-max-gb"
									value={ pricing.max_gb_per_order }
									min="1"
									step="1"
									onChange={ ( event ) => setPricing( ( current ) => ( { ...current, max_gb_per_order: event.target.value } ) ) }
								/>
							</FieldRow>
							<FieldRow label={ strings.dailyCap } hint={ strings.dailyCapHint }>
								<input
									type="number"
									id="space-points-daily-cap"
									value={ pricing.daily_cap_gb }
									min="1"
									step="1"
									onChange={ ( event ) => setPricing( ( current ) => ( { ...current, daily_cap_gb: event.target.value } ) ) }
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
												<input className="zibll-plan-id" type="text" value={ row.id } readOnly />
											</td>
											<td data-label={ strings.planName }>
												<input
													type="text"
													value={ row.label }
													placeholder={ strings.pointsLabelPlaceholder }
													onChange={ ( event ) => updatePoints( row.key, 'label', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.capacityGb }>
												<input
													type="number"
													value={ row.gb }
													min="0.5"
													step="0.5"
													onChange={ ( event ) => updatePoints( row.key, 'gb', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.pointsRequired }>
												<input
													type="number"
													value={ row.points }
													min="1"
													step="1"
													onChange={ ( event ) => updatePoints( row.key, 'points', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.days }>
												<input
													type="number"
													value={ row.days }
													min="0"
													step="1"
													onChange={ ( event ) => updatePoints( row.key, 'days', event.target.value ) }
												/>
											</td>
											<td data-label={ strings.active }>
												<label className="check">
													<input
														type="checkbox"
														checked={ Boolean( row.active ) }
														onChange={ ( event ) => updatePoints( row.key, 'active', event.target.checked ) }
													/>
													<span className="check__box" aria-hidden="true" />
													<span className="screen-reader-text">{ strings.active }</span>
												</label>
											</td>
											<td data-label={ strings.remove }>
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
						{ null === cheapest || undefined === cheapest || '' === cheapest ? (
							<p className="zibll-muted">{ strings.noCash }</p>
						) : (
							<>
								<p>{ fmt( strings.cheapest, [ cheapest ] ) }</p>
								{ compare.far ? <p><strong>{ strings.far }</strong></p> : null }
							</>
						) }
						<p className="zibll-muted">{ fmt( strings.today, [ today.gb, dailyCap, today.count ] ) }</p>
					</div>

					<p className="zibll-muted">{ strings.planNote }</p>
				</div>
			</Form>
		</Card>
	);
}
