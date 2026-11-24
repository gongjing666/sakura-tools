/**
 * The three guidance dialogs the classic page mounted server-side.
 *
 * They are React now, but the markup and the endpoints stay the same: the mute
 * checkbox posts to sakura_s3_config_notice, and the primary button either
 * navigates or starts a scan through the same handler.
 */
import { useEffect, useRef, useState } from 'react';
import { ajax, errorText, toast } from './transport.js';

export default function Dialogs( { notice, onClose, config, onScan } ) {
	if ( ! notice ) {
		return null;
	}

	return <Dialog notice={ notice } onClose={ onClose } config={ config } onScan={ onScan } />;
}

function Dialog( { notice, onClose, config, onScan } ) {
	const cardRef = useRef( null );
	const [ busy, setBusy ] = useState( false );
	const [ mute, setMute ] = useState( false );

	useEffect( () => {
		const card = cardRef.current;

		if ( card ) {
			card.focus();
		}

		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				onClose();
			}
		};

		document.addEventListener( 'keydown', onKey );

		return () => document.removeEventListener( 'keydown', onKey );
	}, [ onClose ] );

	const close = () => {
		if ( mute && notice.dismiss ) {
			setBusy( true );

			ajax( 'sakura_s3_config_notice', { nonce: notice.nonce, dismiss: '1' } )
				.catch( ( error ) => toast( errorText( error, '' ), 'bad' ) )
				.finally( () => {
					setBusy( false );
					onClose();
				} );
			return;
		}

		onClose();
	};

	return (
		<div className="sakura-modal sakura-modal--config" data-sakura-modal-open="1" role="presentation" onMouseDown={ ( event ) => { if ( event.target === event.currentTarget ) { close(); } } }>
			<div
				className="sakura-modal__card"
				role="dialog"
				aria-modal="true"
				aria-labelledby="sakura-sync-dialog-title"
				tabIndex={ -1 }
				ref={ cardRef }
			>
				<button type="button" className="sakura-modal__close" aria-label="关闭" onClick={ close }>
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
						<path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
					</svg>
				</button>
				<p className="sakura-modal__kicker">{ notice.kicker }</p>
				<h2 id="sakura-sync-dialog-title" tabIndex={ -1 }>
					{ notice.title }
				</h2>
				<p>{ notice.description }</p>
				{ notice.body ? <div className="sakura-config-missing" dangerouslySetInnerHTML={ { __html: notice.body } } /> : null }
				<div className="sakura-modal__actions sakura-config-actions">
					<label className="sakura-config-remember">
						<input type="checkbox" checked={ mute } onChange={ ( event ) => setMute( event.target.checked ) } />
						<span>不再提醒我</span>
					</label>
					<div className="sakura-config-buttons">
						<button type="button" className="btn btn--ghost" onClick={ close } disabled={ busy }>
							暂不处理
						</button>
						{ 'scan' === notice.primary ? (
							<button type="button" className="btn btn--primary" onClick={ onScan } disabled={ busy }>
								{ notice.primaryLabel }
							</button>
						) : (
							<a className="btn btn--primary" href={ notice.primaryHref } onClick={ () => { try { window.sessionStorage.setItem( 'sakuraS3ConfigSkipOnce', '1' ); } catch ( error ) { /* ignore */ } } }>
								{ notice.primaryLabel }
							</a>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
