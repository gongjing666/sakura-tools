import { useActionState, useState } from 'react';
import { post } from '../shared/transport.js';

/**
 * One REST save per card.
 *
 * The reply carries the recomputed sections and the prompt copy, so the card
 * is replaced with the values that were actually stored instead of guessing.
 */
export function useCardSave( { cardId, rest, strings, onSections, onNotice } ) {
	return useActionState( async ( _previous, fields ) => {
		try {
			const payload = await post( rest, rest.settings.url, { card: cardId, fields }, strings );

			if ( payload && payload.sections ) {
				onSections( payload.sections );
			}

			if ( payload && payload.notice ) {
				onNotice( payload.notice, cardId );
			}

			return '';
		} catch ( error ) {
			return error && error.message ? String( error.message ) : ( strings.failed || '' );
		}
	}, '' );
}

/**
 * A local draft that resets when the server section changes.
 *
 * The reset is compared during render (React's documented "adjust state when
 * props change"); the page contract forbids syncing drafts from props in an
 * effect, and a remount would drop the focus target.
 */
export function useDraft( source, make ) {
	const key = JSON.stringify( source );
	const [ state, setState ] = useState( () => ( { key, value: make( source ) } ) );

	const setValue = ( next ) => setState( ( current ) => ( {
		key: current.key,
		value: typeof next === 'function' ? next( current.value ) : next,
	} ) );

	if ( state.key !== key ) {
		const value = make( source );
		setState( { key, value } );

		return [ value, ( next ) => setState( { key, value: typeof next === 'function' ? next( value ) : next } ) ];
	}

	return [ state.value, setValue ];
}
