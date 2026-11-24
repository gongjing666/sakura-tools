/**
 * A small dialog for the decisions the cards cannot make on their own: confirm
 * a delete, open the bucket-switch reminder, pick a 多吉云 space, or read a probe
 * result.
 *
 * It reproduces the .sakura-modal markup the shared Prompt prints, so it looks
 * like every other dialog in the admin. The Prompt itself only carries a
 * sentence and one button, which is not enough for a confirmation that has a
 * typed guard.
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
 * @param {*}        props.children       Extra body content (the space picker).
 * @param {string}   props.confirmLabel   Primary button.
 * @param {string}   props.cancelLabel    Secondary button; omitted hides it.
 * @param {boolean}  props.busy           Disables the primary button.
 * @param {Function} props.onConfirm      Called when the primary button is pressed.
 * @param {Function} props.onClose        Called on Escape, scrim or cancel.
 * @return {JSX.Element|null} Dialog.
 */
export default function Dialog( {
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
} ) {
	const [ typed, setTyped ] = useState( '' );
	const card = useRef( null );
	const host = document.querySelector( '.sakura-admin' );

	useEffect( () => {
		if ( card.current ) {
			card.current.focus( { preventScroll: true } );
		}

		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				onClose();
			}
		};

		document.addEventListener( 'keydown', onKey );

		return () => document.removeEventListener( 'keydown', onKey );
	}, [ onClose ] );

	if ( ! host ) {
		return null;
	}

	const locked = Boolean( requireText ) && typed.trim() !== requireText;

	return createPortal(
		<div
			className="sakura-modal"
			data-sakura-modal-open="1"
			role="presentation"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				className="sakura-modal__card"
				role="dialog"
				aria-modal="true"
				aria-labelledby="sakura-storage-dialog-title"
				data-modal-size="compact"
				tabIndex="-1"
				ref={ card }
				data-tone={ tone }
			>
				<div className="sakura-modal__head">
					<div className="sakura-modal__copy">
						{ kicker ? <p className="sakura-modal__kicker">{ kicker }</p> : null }
						<h2 id="sakura-storage-dialog-title">{ title }</h2>
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
							<label className="field__label" htmlFor="sakura-storage-dialog-guard">
								{ '输入「' + requireText + '」确认' }
							</label>
						</div>
						<div className="field__control">
							<input
								id="sakura-storage-dialog-guard"
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
						<button type="button" className="btn btn--ghost" onClick={ onClose }>
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
