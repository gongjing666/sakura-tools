/**
 * Presentational pieces for the system island.
 *
 * Every element here reproduces the markup the PHP components used to print
 * (card, table, field, badge, savebar, modal), because the stylesheet in
 * assets/ui is shared with the pages that are still server-rendered. Copy is
 * never hard-coded: it arrives translated from the payload.
 */
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export function Badge( { tone = 'neutral', children } ) {
	return <span className={ `badge badge--${ tone }` }>{ children }</span>;
}

/**
 * A button label that swaps to a shorter one while saving without changing the
 * button's width: both labels sit in the same grid cell, so the cell is as wide
 * as the longer one and the right-aligned savebar never reflows.
 */
export function BusyLabel( { idle, active, busy } ) {
	return (
		<span className="btn__stack">
			<span
				aria-hidden={ busy ? undefined : 'true' }
				style={ { visibility: busy ? 'visible' : 'hidden' } }
			>
				{ active }
			</span>
			<span
				aria-hidden={ busy ? 'true' : undefined }
				style={ { visibility: busy ? 'hidden' : 'visible' } }
			>
				{ idle }
			</span>
		</span>
	);
}

export function Card( { card, titleProps, children } ) {
	const style = {};

	if ( card.placement ) {
		const [ row, column, span ] = card.placement;

		if ( row > 0 ) {
			style[ '--g-card-row' ] = String( row );
		}

		style[ '--g-card-col' ] = String( column );
		style[ '--g-card-span' ] = String( span );
	}

	return (
		<section
			className={ card.wide ? 'card card--wide' : 'card' }
			data-sakura-card={ card.id }
			style={ style }
		>
			<header className={ card.kicker ? 'card__head card__head--kicker' : 'card__head' }>
				<div className="card__heading">
					{ card.kicker ? <p className="card__kicker">{ card.kicker }</p> : null }
					<h2 className="card__title" { ...titleProps }>{ card.title }</h2>
					{ card.description ? <p className="card__desc">{ card.description }</p> : null }
				</div>
			</header>
			<div className="card__body">{ children }</div>
		</section>
	);
}

export function RuntimeTable( { card } ) {
	return (
		<>
			<div className="table-scroll">
				<table className="table table--compact table--records">
					<thead>
						<tr>
							{ card.columns.map( ( label ) => (
								<th key={ label } scope="col">
									{ label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ card.rows.map( ( row ) => (
							<tr key={ row.label }>
								<td data-label={ card.columns[ 0 ] }>{ row.label }</td>
								<td data-label={ card.columns[ 1 ] }>
									{ row.value ? <span className="system-value">{ row.value }</span> : null }
									{ row.muted ? <span className="system-muted">{ row.muted }</span> : null }
									{ ( row.badges || [] ).map( ( badge ) => (
										<Badge key={ badge[ 0 ] } tone={ badge[ 1 ] }>
											{ badge[ 0 ] }
										</Badge>
									) ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ card.note ? <p className="system-note">{ card.note }</p> : null }
		</>
	);
}

export function ChecksTable( { card } ) {
	return (
		<div className="table-scroll">
			<table className="table table--compact table--checks">
				<thead>
					<tr>
						{ card.columns.map( ( label ) => (
							<th key={ label } scope="col">
								{ label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ card.rows.map( ( row ) => (
						<tr key={ row.label }>
							<td data-label={ card.columns[ 0 ] }>
								<span className="system-value">{ row.label }</span>
							</td>
							<td data-label={ card.columns[ 1 ] }>
								<Badge tone={ row.tone }>{ row.stateLabel }</Badge>
							</td>
							<td data-label={ card.columns[ 2 ] }>{ row.value || '—' }</td>
							<td data-label={ card.columns[ 3 ] } className="system-check-note">
								{ row.note && '—' !== row.note ? row.note : '' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

export function ExtensionGrid( { card } ) {
	return (
		<>
			<div className="system-ext-grid">
				{ card.items.map( ( item ) => (
					<Badge key={ item.name } tone={ item.loaded ? 'ok' : 'warn' }>
						{ item.name }
					</Badge>
				) ) }
			</div>
			{ card.note ? <p className="system-note">{ card.note }</p> : null }
		</>
	);
}

export function KpiBoard( { score, kpis } ) {
	return (
		<section className="kpis kpis--ticket" aria-label={ score.aria }>
			<article className={ `kpi kpi--score kpi--score-${ score.tone }` }>
				<span className="kpi__label">{ score.label }</span>
				<span className="kpi__value">
					{ score.value }
					<small>{ score.unit }</small>
				</span>
				<div className="kpi__progress">
					<progress
						className="progress"
						max="100"
						value={ score.value }
						aria-label={ score.label }
					>
						{ score.value }%
					</progress>
				</div>
				<span className="kpi__sub">{ score.note }</span>
			</article>
			{ kpis.map( ( kpi ) => (
				<article className="kpi" key={ kpi.label }>
					<span className="kpi__label">{ kpi.label }</span>
					<span className="kpi__value">
						{ kpi.value }
						<small>{ kpi.unit }</small>
					</span>
					<span className="kpi__sub">{ kpi.sub }</span>
				</article>
			) ) }
		</section>
	);
}

export function Field( { field, value, onChange, disabled } ) {
	const hintId = field.hint ? `${ field.id }-hint` : undefined;

	return (
		<div className={ `field field--${ field.type }` }>
			<div className="field__main">
				<label className="field__label" htmlFor={ field.id }>
					{ field.label }
				</label>
				{ field.hint ? (
					<p className="field__hint" id={ hintId }>
						{ field.hint }
					</p>
				) : null }
			</div>
			<div className="field__control">
				{ 'select' === field.type ? (
					<select
						id={ field.id }
						value={ value }
						disabled={ disabled }
						aria-describedby={ hintId }
						onChange={ ( event ) => onChange( field.key, event.target.value ) }
					>
						{ field.options.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label }
							</option>
						) ) }
					</select>
				) : (
					<input
						type={ field.type }
						id={ field.id }
						value={ value }
						min={ field.min }
						max={ field.max }
						step={ field.step }
						inputMode={ field.inputmode }
						disabled={ disabled }
						aria-describedby={ hintId }
						onChange={ ( event ) => onChange( field.key, event.target.value ) }
					/>
				) }
			</div>
		</div>
	);
}

/**
 * The post-save prompt, portalled to the page root so the fixed overlay is not
 * trapped by the main column's entrance transform.
 */
export function Prompt( { notice, onClose, returnFocus } ) {
	const [ closing, setClosing ] = useState( false );
	const cardRef = useRef( null );
	const closedRef = useRef( false );
	const host = document.querySelector( '.sakura-admin' );

	const requestClose = () => {
		if ( closedRef.current ) {
			return;
		}

		closedRef.current = true;
		setClosing( true );
		window.setTimeout( () => {
			onClose();

			// The save button is disabled while nothing is dirty, and a disabled
			// button cannot take focus. Fall back to the card heading (marked with
			// data-sakura-return-target) so the reader lands where they were
			// instead of at the top of the document.
			const opener = returnFocus && returnFocus.current;

			if ( opener ) {
				opener.focus( { preventScroll: true } );
			}

			if ( ! opener || document.activeElement !== opener ) {
				const fallback = host && host.querySelector( '[data-sakura-return-target]' );

				if ( fallback ) {
					fallback.focus( { preventScroll: true } );
				}
			}
		}, 150 );
	};

	useEffect( () => {
		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				requestClose();
				return;
			}

			// Keep Tab inside the prompt: the scrim blocks the pointer, and a focus
			// ring travelling under the blur reads as a broken dialog.
			if ( 'Tab' !== event.key || ! cardRef.current ) {
				return;
			}

			const nodes = Array.prototype.filter.call(
				cardRef.current.querySelectorAll(
					'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				),
				( el ) => el.offsetParent !== null || el === document.activeElement
			);

			if ( ! nodes.length ) {
				event.preventDefault();
				return;
			}

			const first = nodes[ 0 ];
			const last = nodes[ nodes.length - 1 ];
			const inside = cardRef.current.contains( document.activeElement );

			if ( event.shiftKey ? ( ! inside || document.activeElement === first ) : ( ! inside || document.activeElement === last ) ) {
				event.preventDefault();
				( event.shiftKey ? last : first ).focus( { preventScroll: true } );
			}
		};

		document.addEventListener( 'keydown', onKey );

		const timer = window.setTimeout( () => {
			const target =
				( cardRef.current && cardRef.current.querySelector( '[data-sakura-modal-focus]' ) ) ||
				cardRef.current;

			if ( target ) {
				target.focus( { preventScroll: true } );
			}
		}, 40 );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			window.clearTimeout( timer );
		};
		// The prompt lives for one notice: opening it is the whole lifecycle.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( ! host ) {
		return null;
	}

	return createPortal(
		<div
			className={ closing ? 'sakura-modal is-closing' : 'sakura-modal' }
			data-sakura-modal-open="1"
			role="presentation"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					requestClose();
				}
			} }
		>
			<div
				className="sakura-modal__card"
				role="dialog"
				aria-labelledby="sakura-system-prompt-title"
				data-modal-size="compact"
				tabIndex="-1"
				ref={ cardRef }
			>
				<button
					type="button"
					className="sakura-modal__close"
					aria-label={ notice.closeLabel }
					onClick={ requestClose }
					dangerouslySetInnerHTML={ { __html: notice.closeIcon } }
				/>
				<div className="sakura-modal__head">
					<span
						className="sakura-modal__mascot"
						aria-hidden="true"
						dangerouslySetInnerHTML={ { __html: notice.mascot } }
					/>
					<div className="sakura-modal__copy">
						<p className="sakura-modal__kicker">{ notice.kicker }</p>
						<h2 id="sakura-system-prompt-title" tabIndex="-1" data-sakura-modal-focus>
							{ notice.title }
						</h2>
						<p>{ notice.message }</p>
					</div>
				</div>
				<div className="sakura-modal__actions" data-align="end">
					<button type="button" className="btn btn--primary" onClick={ requestClose }>
						{ notice.button }
					</button>
				</div>
			</div>
		</div>,
		host
	);
}
