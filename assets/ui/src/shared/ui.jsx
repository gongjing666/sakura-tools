/**
 * Presentational pieces shared by every page that has moved into React.
 *
 * Each element reproduces the markup the PHP components used to print (card,
 * field, badge, savebar, prompt, page head, top bar), because the stylesheet in
 * assets/ui is shared with the pages that are still server-rendered. Copy is
 * never hard-coded: it arrives translated from the payload.
 *
 * The PHP side of the same description lives in src/Admin/UI/Card.php,
 * Field.php, Page.php and Shell.php. Where both sides print the same thing, a
 * PHP test locks the markup so the two cannot drift.
 */
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export function Badge( { tone = 'neutral', children } ) {
	return <span className={ `badge badge--${ tone }` }>{ children }</span>;
}


/**
 * The padlock that UI::lock_icon() prints on the server-rendered pages.
 *
 * A frozen row is the only place a card says "this cannot be changed here";
 * the drawing has to match on both sides, or a PHP page and an island show
 * two different locks.
 */
export function LockIcon() {
	return (
		<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
			<path
				fill="currentColor"
				d="M8 1.6a3.1 3.1 0 0 0-3.1 3.1V6H4.6A1.6 1.6 0 0 0 3 7.6v5.8A1.6 1.6 0 0 0 4.6 15h6.8a1.6 1.6 0 0 0 1.6-1.6V7.6A1.6 1.6 0 0 0 11.4 6h-.3V4.7A3.1 3.1 0 0 0 8 1.6Zm0 1.5a1.6 1.6 0 0 1 1.6 1.6V6H6.4V4.7A1.6 1.6 0 0 1 8 3.1Zm-.8 4.6h1.6v3.6H7.2V7.7Z"
			/>
		</svg>
	);
}
/**
 * The shared top bar: brand, module pills, status pill and extra actions.
 *
 * Mirrors Shell::header() from the same description, so the pages that are
 * still server-rendered and this island print one chrome, not two.
 */
export function Topbar( { chrome } ) {
	if ( ! chrome || ! chrome.brand ) {
		return null;
	}

	return (
		<header className="topbar sakura-topbar">
			<div className="brand">
				<span className="brand-mark" aria-hidden="true" dangerouslySetInnerHTML={ { __html: chrome.brand.mark } } />
				<strong>{ chrome.brand.label }</strong>
				<span className="path">
					<span className="slash">/</span> { chrome.brand.path }
				</span>
			</div>

			<nav className="sakura-mods" aria-label={ chrome.labels.mods }>
				{ chrome.mods.map( ( mod ) => (
					<a
						key={ mod.id }
						className={ mod.active ? 'sakura-mod is-active' : 'sakura-mod' }
						href={ mod.url }
						aria-current={ mod.active ? 'page' : undefined }
					>
						{ mod.label }
					</a>
				) ) }
			</nav>

			<div className="top-actions">
				{ chrome.status && chrome.status.text ? (
					<span
						className={ chrome.status.tone ? `conn-pill is-${ chrome.status.tone }` : 'conn-pill' }
						id={ chrome.status.id || undefined }
					>
						<span className="dot" />
						<span>{ chrome.status.text }</span>
					</span>
				) : null }
				{ chrome.actions.map( ( action ) => (
					<a key={ action.url } className="btn btn--small" href={ action.url }>
						{ action.label }
					</a>
				) ) }
			</div>
		</header>
	);
}

/**
 * The module's tab strip. A tab that cannot be opened renders as a disabled
 * pill with its note, exactly like Shell::header() does.
 */
export function Tabs( { chrome } ) {
	if ( ! chrome || ! chrome.tabs || ! chrome.tabs.length ) {
		return null;
	}

	return (
		<nav className="sakura-tabs" aria-label={ chrome.labels.tabs }>
			{ chrome.tabs.map( ( tab ) =>
				tab.enabled ? (
					<a
						key={ tab.id }
						className={ tab.active ? 'sakura-tab is-active' : 'sakura-tab' }
						href={ tab.url }
						aria-current={ tab.active ? 'page' : undefined }
					>
						{ tab.label }
					</a>
				) : (
					<span
						key={ tab.id }
						className={ tab.active ? 'sakura-tab is-disabled is-active' : 'sakura-tab is-disabled' }
						aria-disabled="true"
						tabIndex={ -1 }
						title={ tab.note }
					>
						{ tab.label }
						<span className="sakura-tab-lock" aria-hidden="true" />
						<span className="screen-reader-text">{ tab.note }</span>
					</span>
				)
			) }
		</nav>
	);
}

/**
 * The page head: eyebrow, title, tagline, lede, actions, seal and status card.
 *
 * Mirrors Page::head() from Page::head_data().
 */
export function PageHead( { head } ) {
	if ( ! head ) {
		return null;
	}

	const stampInAside = head.stamp && 'aside' === head.stamp.place;
	const hasStatus = head.status && ( head.status.label || head.status.value );

	return (
		<header className="page-head">
			<div className="page-head__copy">
				{ head.eyebrow ? <p className="page-head__eyebrow">{ head.eyebrow }</p> : null }
				{ head.title ? <h1 id="sakura-page-title">{ head.title }</h1> : null }
				{ head.tagline ? (
					<span className="page-head__tag" dangerouslySetInnerHTML={ { __html: head.tagline } } />
				) : null }
				{ head.lede ? <p className="page-head__lede">{ head.lede }</p> : null }
				{ head.actions && head.actions.length ? (
					<div className="page-head__actions">
						{ head.actions.map( ( action ) => (
							<a
								key={ `${ action.label }|${ action.url }` }
								className={ `btn btn--${ action.variant || 'ghost' }` }
								href={ action.url }
								id={ action.id || undefined }
							>
								{ action.label }
							</a>
						) ) }
					</div>
				) : null }
				{ stampInAside ? null : <Stamp stamp={ head.stamp } /> }
			</div>

			{ hasStatus || ( stampInAside && head.stamp && head.stamp.zh ) ? (
				<aside className="page-head__aside">
					{ hasStatus ? <StatusCard status={ head.status } /> : null }
					{ stampInAside ? <Stamp stamp={ head.stamp } /> : null }
				</aside>
			) : null }
		</header>
	);
}

/** The seal that names the page, drawn the same way Page::stamp() draws it. */
function Stamp( { stamp } ) {
	if ( ! stamp || ! stamp.zh ) {
		return null;
	}

	return (
		<svg className="page-head__stamp" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
			<circle cx="60" cy="60" r="53" fill="rgba(255,255,255,.72)" stroke="currentColor" strokeOpacity=".34" strokeWidth="1.6" strokeDasharray="4 6" />
			<circle cx="60" cy="60" r="43" fill="none" stroke="currentColor" strokeOpacity=".22" strokeWidth="1" />
			<text x="60" y="62" textAnchor="middle" fontSize="35" fontWeight="800" fill="currentColor">
				{ stamp.zh }
			</text>
			{ stamp.en ? (
				<text x="60" y="82" textAnchor="middle" fontSize="8.5" letterSpacing="2.4" fill="var(--g-faint)">
					{ stamp.en }
				</text>
			) : null }
		</svg>
	);
}

/** Small status card, same parts and the same data-* hooks as Page::status(). */
function StatusCard( { status } ) {
	const badge = status.badge && status.badge.text ? status.badge : null;

	return (
		<div className={ `status-card status-card--${ status.tone || 'neutral' }` }>
			{ badge ? (
				<span className="status-card__top">
					{ status.label ? <span className="status-card__label">{ status.label }</span> : null }
					<span className={ `status-card__badge status-card__badge--${ badge.tone || 'neutral' }` }>
						{ badge.text }
					</span>
				</span>
			) : status.label ? (
				<span className="status-card__label">{ status.label }</span>
			) : null }
			<span className="status-card__line">
				<strong className="status-card__value" id={ status.id || undefined } title={ status.title || undefined }>
					{ status.value }
				</strong>
				{ status.unit ? <span className="status-card__unit">{ status.unit }</span> : null }
			</span>
			{ status.sub ? (
				<span className="status-card__sub" id={ status.sub_id || undefined }>
					{ status.sub }
				</span>
			) : null }
			{ status.ping ? (
				<span className="status-card__ping" data-sakura-ping data-state="checking">
					<i className="status-card__dot" aria-hidden="true" />
					{ status.ping_name ? (
						<>
							<span className="status-card__ping-name">{ status.ping_name }</span>
							<span className="status-card__ping-sep" aria-hidden="true">
								·
							</span>
						</>
					) : null }
					<span className="status-card__ping-text">检测中…</span>
					<b className="status-card__ping-ms" data-sakura-ping-ms />
				</span>
			) : null }
		</div>
	);
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

/**
 * One card. Placement travels as CSS custom properties so Layout::card() stays
 * the single source of the grid, exactly like Card::render().
 */
export function Card( { card, titleProps, actions, children } ) {
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
					{ Array.isArray( card.note )
						? card.note.map( ( line, index ) => (
								<p className="card__desc" key={ index }>{ line }</p>
						  ) )
						: null }
				</div>
				{ /* Same slot as Card::head() in PHP: card__actions stays in the header,
				   so a card-level button sits top-right and the --kicker rule lines it up
				   with the title. */ }
				{ actions ? <div className="card__actions">{ actions }</div> : null }
			</header>
			<div className="card__body">{ children }</div>
		</section>
	);
}

/**
 * One labelled control. Field::render() prints the same nesting, so the shared
 * field styles keep working.
 */
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
						placeholder={ field.placeholder }
						list={ field.list }
						autoComplete={ field.autocomplete }
						spellCheck={ field.spellcheck }
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
 *
 * `titleId` lets a page give the heading its own id; the id only has to be
 * unique inside the document, and one prompt exists at a time.
 */
export function Prompt( { notice, onClose, returnFocus, titleId = 'sakura-prompt-title' } ) {
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
				aria-labelledby={ titleId }
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
						<h2 id={ titleId } tabIndex="-1" data-sakura-modal-focus>
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
