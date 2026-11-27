/**
 * The confirm/choose dialog shared by every React page.
 *
 * `Prompt` reports what happened; this dialog asks something before it happens:
 * a destructive confirmation, a typed guard, a bucket switch, a space picker.
 * It reproduces the .sakura-modal markup Prompt prints, so the admin keeps one
 * dialog shape, and it owns the keyboard contract Prompt has: Esc closes, Tab
 * stays inside, focus returns to the opener.
 */
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * @param {Object}   props                Dialog props.
 * @param {string}   props.tone           ok | warn | bad | info | neutral.
 * @param {string}   props.kicker         Small label above the title.
 * @param {string}   props.title          Heading.
 * @param {string}   props.message        Optional sentence under the heading.
 * @param {string[]} props.items          Optional bullet list.
 * @param {string}   props.requireText    When set, the operator has to type it.
 * @param {*}        props.children       Extra body content (e.g. the space picker).
 * @param {string}   props.confirmLabel   Primary button.
 * @param {string}   props.cancelLabel    Secondary button; omitted hides it.
 * @param {boolean}  props.busy           Disables the primary button.
 * @param {Function} props.onConfirm      Called when the primary button is pressed.
 * @param {Function} props.onClose        Called on Escape, scrim or cancel.
 * @param {Object}   props.returnFocus    Ref of the element that opened it.
 * @return {JSX.Element|null} Dialog.
 */
export function Dialog( {
	tone = 'info',
	kicker,
	title,
	message,
	items,
	requireText,
	children,
	confirmLabel,
	confirmVariant = 'primary',
	cancelLabel,
	busy = false,
	onConfirm,
	onClose,
	returnFocus,
} ) {
	const [ typed, setTyped ] = useState( '' );
	const card = useRef( null );
	const host = document.querySelector( '.sakura-admin' );
	const opener = returnFocus && returnFocus.current;

	useEffect( () => {
		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				onClose();
				return;
			}

			if ( 'Tab' !== event.key || ! card.current ) {
				return;
			}

			const nodes = Array.prototype.filter.call(
				card.current.querySelectorAll(
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
			const inside = card.current.contains( document.activeElement );

			if ( event.shiftKey ? ( ! inside || document.activeElement === first ) : ( ! inside || document.activeElement === last ) ) {
				event.preventDefault();
				( event.shiftKey ? last : first ).focus( { preventScroll: true } );
			}
		};

		document.addEventListener( 'keydown', onKey );

		const timer = window.setTimeout( () => {
			if ( card.current ) {
				card.current.focus( { preventScroll: true } );
			}
		}, 40 );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			window.clearTimeout( timer );
		};
	}, [ onClose ] );

	if ( ! host ) {
		return null;
	}

	const locked = Boolean( requireText ) && typed.trim() !== requireText;

	const close = () => {
		onClose();

		if ( opener ) {
			window.setTimeout( () => opener.focus( { preventScroll: true } ), 0 );
		}
	};

	return createPortal(
		<div
			className="sakura-modal"
			data-sakura-modal-open="1"
			role="presentation"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					close();
				}
			} }
		>
			<div
				className="sakura-modal__card"
				role="dialog"
				aria-modal="true"
				aria-labelledby="sakura-dialog-title"
				data-modal-size="compact"
				tabIndex="-1"
				ref={ card }
				data-tone={ tone }
			>
				<div className="sakura-modal__head">
					<div className="sakura-modal__copy">
						{ kicker ? <p className="sakura-modal__kicker">{ kicker }</p> : null }
						<h2 id="sakura-dialog-title">{ title }</h2>
						{ message ? <p>{ message }</p> : null }
					</div>
				</div>

				{ items && items.length ? (
					<ul className="dialog__list">
						{ items.map( ( item, index ) => (
							<li key={ index }>{ item }</li>
						) ) }
					</ul>
				) : null }

				{ children || null }

				{ requireText ? (
					<div className="field field--text">
						<div className="field__main">
							<label className="field__label" htmlFor="sakura-dialog-guard">
								{ '输入「' + requireText + '」确认' }
							</label>
						</div>
						<div className="field__control">
							<input
								id="sakura-dialog-guard"
								type="text"
								autoComplete="off"
								value={ typed }
								onChange={ ( event ) => setTyped( event.target.value ) }
							/>
						</div>
					</div>
				) : null }

				<div className="sakura-modal__actions" data-align="end">
					{ cancelLabel ? (
						<button type="button" className="btn btn--ghost" onClick={ close }>
							{ cancelLabel }
						</button>
					) : null }
					<button
						type="button"
						className={ 'btn btn--' + confirmVariant }
						disabled={ busy || locked }
						aria-busy={ busy ? 'true' : undefined }
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>,
		host
	);
}

export default Dialog;