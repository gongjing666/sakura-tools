import { useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { post } from '../shared/transport.js';
import { confirm, errorText, fmt, toast } from './controls.jsx';

export default function Reconcile( { card, rest, strings } ) {
	const [ summary, setSummary ] = useState( strings.reconcileNotChecked );
	const [ items, setItems ] = useState( [] );
	const [ missing, setMissing ] = useState( 0 );
	const [ checked, setChecked ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const request = ( apply ) => {
		if ( busy ) {
			return;
		}

		setBusy( true );
		setSummary( apply ? strings.reconcileHealing : strings.reconcileChecking );
		post( rest, rest.reconcile.url, { dry_run: apply ? 0 : 1 }, strings )
			.then( ( payload ) => {
				if ( ! payload || false === payload.ready ) {
					const message = payload && payload.error ? String( payload.error ) : strings.reconcileFailed;
					setSummary( message );
					toast( message, 'bad' );

					return;
				}

				if ( ! apply ) {
					const count = parseInt( payload.missing, 10 ) || 0;
					setChecked( true );
					setMissing( count );
					setSummary( count ? fmt( strings.reconcileMissing, [ count ] ) : strings.reconcileClean );
					setItems( Array.isArray( payload.items ) ? payload.items : [] );

					return;
				}

				setMissing( 0 );
				setSummary( fmt( strings.reconcileHealed, [ parseInt( payload.healed, 10 ) || 0 ] ) );
				setItems( [] );
				window.dispatchEvent( new CustomEvent( 'sakura:space-grants-refresh' ) );
			} )
			.catch( ( error ) => {
				const message = errorText( error, strings.reconcileFailed );
				setSummary( message );
				toast( message, 'bad' );
			} )
			.finally( () => setBusy( false ) );
	};

	const apply = () => {
		if ( ! missing ) {
			return;
		}

		confirm( fmt( strings.reconcileConfirm, [ missing ] ), {
			title: strings.confirmTitle,
			confirmText: strings.confirm,
			cancelText: strings.cancel,
			danger: true,
			tone: 'danger',
		} ).then( ( ok ) => {
			if ( ok ) {
				request( true );
			}
		} );
	};

	return (
		<Card card={ card }>
			<div className="button-row">
				<button type="button" className="btn btn--primary" onClick={ () => request( false ) } disabled={ busy }>
					{ strings.reconcileCheck }
				</button>
				<button type="button" className="btn btn--danger" onClick={ apply } disabled={ busy || ! missing }>
					{ strings.reconcileApply }
				</button>
			</div>
			<p className="zibll-reconcile-status" aria-live="polite">{ summary }</p>
			<div className="zibll-reconcile-list">
				{ checked && ! items.length ? <p className="zibll-muted">{ strings.reconcileNoItems }</p> : null }
				{ items.length ? (
					<ul className="list zibll-reconcile-items">
						{ items.map( ( item, index ) => (
							<li key={ item.order_num || index }>
								<strong>{ strings.orderNum + ' ' + ( item.order_num || '' ) }</strong>
								<span>{ strings.user + ' #' + ( item.user_id || '' ) }</span>
								<span>{ strings.amount + ' ' + ( item.price || '' ) }</span>
								<span>{ strings.payTime + ' ' + ( item.pay_time || '' ) }</span>
							</li>
						) ) }
					</ul>
				) : null }
			</div>
		</Card>
	);
}
