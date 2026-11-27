/**
 * Ignore rules. Saved through the sync REST surface; a successful save clears
 * the field's dirty state by reloading with a toast, the same flow the other
 * cards use.
 */
import { useRef, useState } from 'react';
import { Card } from '../shared/ui.jsx';
import { post, errorText, toast, reloadWithToast } from './transport.js';

export default function IgnoredCard( { card, view, strings, config, frozen } ) {
	const copy = ( strings && strings.cards ) || {};
	const saved = Boolean( view.ignored_saved );
	const formRef = useRef( null );
	const [ busy, setBusy ] = useState( false );

	const submit = ( event ) => {
		event.preventDefault();

		if ( busy || frozen || ! formRef.current ) {
			return;
		}

		setBusy( true );

		const area = formRef.current.querySelector( 'textarea' );
		const raw = area ? area.value : '';

		post( config, 'ignored', { raw } )
			.then( () => {
				reloadWithToast( copy.ignoredSaved || '忽略规则已保存' );
			} )
			.catch( ( error ) => {
				toast( errorText( error, '' ), 'bad' );
				setBusy( false );
			} );
	};

	return (
		<Card card={ card }>
			<form className="card__form" id="sakura-s3-ignored-form" ref={ formRef } onSubmit={ submit }>
				<div className="field-list">
					<div className="field field--full field--textarea">
						<div className="field__main">
							<label className="field__label" htmlFor="sufm-ignored-paths">
								{ copy.ignoredLabel || '忽略路径' }
							</label>
							<p className="field__hint" id="sufm-ignored-paths-hint">{ copy.ignoredHint || '' }</p>
						</div>
						<div className="field__control">
							<textarea
								id="sufm-ignored-paths"
								name="ignored_paths"
								rows={ 6 }
								spellCheck={ false }
								defaultValue={ view.ignored_rules || '' }
								placeholder={ copy.ignoredPlaceholder || '' }
								aria-describedby="sufm-ignored-paths-hint"
							/>
						</div>
					</div>
				</div>
				<div className="field__notes">
					<p className="small-text">{ ( copy.ignoredNote || '当前 %d 条生效' ).replace( '%d', String( ( view.ignored_rules || '' ).split( '\n' ).filter( Boolean ).length ) ) }</p>
				</div>
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary" disabled={ frozen || busy }>
						{ copy.ignoredSave || '保存规则' }
					</button>
				</div>
			</form>
			{ saved ? (
				<div className="notice-inline notice-inline--ok" role="status">
					<p>
						<strong>{ copy.ignoredSaved || '忽略规则已保存' }</strong> — { copy.ignoredSavedBody || '' }
					</p>
				</div>
			) : null }
		</Card>
	);
}
