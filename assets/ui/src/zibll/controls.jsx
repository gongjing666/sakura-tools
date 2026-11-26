import { useState } from 'react';
import { confirmAction } from '../files/shared/transport.js';

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

export function Check( { name, label, checked, onChange, extra } ) {
	return (
		<label className="check">
			<input type="checkbox" name={ name } value="1" checked={ checked } onChange={ onChange } />
			<span className="check__box" aria-hidden="true" />
			<span className="check__text">{ label }</span>
			{ extra }
		</label>
	);
}

/**
 * Checkbox switch with the legacy zero fallback and optional confirmation.
 */
export function Switch( { name, label, checked, onChange, warn, disabled, strings } ) {
	const [ current, setCurrent ] = useState( checked );

	const handle = ( event ) => {
		const next = event.target.checked;

		if ( ! warn ) {
			setCurrent( next );
			onChange( next );
			return;
		}

		confirmAction( warn, {
			title: ( strings || {} ).confirmTitle || '',
			confirmText: ( strings || {} ).confirm || '',
			cancelText: ( strings || {} ).cancel || '',
			danger: true,
			tone: 'danger',
		} ).then( ( ok ) => {
			if ( ok ) {
				setCurrent( next );
				onChange( next );
			}
		} );
	};

	return (
		<>
			<input type="hidden" name={ name } value="0" />
			<label className="check">
				<input type="checkbox" name={ name } value="1" checked={ current } disabled={ disabled } onChange={ handle } />
				<span className="check__box" aria-hidden="true" />
				<span className="check__text">{ label }</span>
			</label>
		</>
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
