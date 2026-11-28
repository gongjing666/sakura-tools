import { useRef } from 'react';

/** Shared focus target: a saved card returns Tab focus to its heading. */
export const cardTitleProps = { tabIndex: -1, 'data-sakura-return-target': '1' };

export function fmt( template, values ) {
	let index = 0;

	return String( template || '' )
		.replace( /%(\d+)\$[sd]/g, ( match, position ) => String( values[ Number( position ) - 1 ] ?? match ) )
		.replace( /%[sd]/g, () => String( values[ index++ ] ?? '' ) );
}

export function FieldRow( { label, hint, children } ) {
	return (
		<div className="field field--full">
			<div className="field__main">
				<span className="field__label">{ label }</span>
				{ hint ? <p className="field__hint">{ hint }</p> : null }
			</div>
			<div className="field__control">{ children }</div>
		</div>
	);
}

/**
 * Sliding checkbox for one boolean. Confirmation only exists on the switches
 * that change money or the front-end surface; cancelling restores the node.
 */
export function Switch( { label, checked, onChange, warn, strings } ) {
	const input = useRef( null );

	const handle = ( event ) => {
		const next = event.target.checked;

		if ( ! warn ) {
			onChange( next );

			return;
		}

		confirm( warn, {
			title: ( strings || {} ).confirmTitle || '',
			confirmText: ( strings || {} ).confirm || '',
			cancelText: ( strings || {} ).cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( ok ) => {
			if ( ok ) {
				onChange( next );
			} else if ( input.current ) {
				input.current.checked = checked;
			}
		} );
	};

	return (
		<label className="check">
			<input ref={ input } type="checkbox" checked={ checked } onChange={ handle } />
			<span className="check__box" aria-hidden="true" />
			<span className="check__text">{ label }</span>
		</label>
	);
}

export function formatMb( mb, strings ) {
	const value = Number( mb ) || 0;
	const unitMb = ( strings || {} ).unitMb || 'MB';
	const unitGb = ( strings || {} ).unitGb || 'GB';

	if ( value <= 0 ) {
		return '0 ' + unitMb;
	}

	if ( value >= 1024 ) {
		return ( Math.round( value / 1024 * 10 ) / 10 ) + ' ' + unitGb;
	}

	return ( Math.round( value * 10 ) / 10 ) + ' ' + unitMb;
}

export function toast( message, tone ) {
	const admin = window.SakuraAdmin;

	if ( message && admin && admin.ui && admin.ui.toast ) {
		admin.ui.toast( message, tone || 'info' );
	}
}

export function confirm( message, options ) {
	const admin = window.SakuraAdmin;

	if ( admin && admin.ui && admin.ui.confirm ) {
		return admin.ui.confirm( message, options || {} );
	}

	return Promise.resolve( window.confirm( [ ( options || {} ).title, message ].filter( Boolean ).join( '\n\n' ) ) );
}

export function errorText( error, fallback ) {
	if ( error && error.message ) {
		return String( error.message );
	}

	return fallback || '';
}
