/**
 * Ignore rules. Saved through the sync REST surface; the result is reported
 * with the shared Prompt and the page payload is refreshed, so the field and
 * the "N rules active" line both come from the server afterwards.
 */
import { useRef, useState } from 'react';
import { Card, Prompt } from '../shared/ui.jsx';
import { post, errorText } from './transport.js';
import { format } from './format.js';

export default function IgnoredCard( { card, data, strings, rest, frozen, onRefresh } ) {
	const view = data.view || {};
	const copy = strings.cards || {};
	const incoming = String( view.ignored_rules || '' );
	const [ draft, setDraft ] = useState( incoming );
	const [ known, setKnown ] = useState( incoming );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const saveRef = useRef( null );

	// A refresh replaces the saved value; the draft only follows when the server
	// value actually moved, so typing survives an unrelated refresh.
	if ( incoming !== known ) {
		setKnown( incoming );
		setDraft( incoming );
	}

	const noticeFor = ( base, message ) => ( {
		...base,
		message: message || base.message,
		closeLabel: strings.close,
		closeIcon: strings.closeIcon,
		mascot: strings.mascot,
		button: strings.dismiss,
	} );

	const submit = ( event ) => {
		event.preventDefault();

		if ( busy || frozen ) {
			return;
		}

		setBusy( true );

		post( rest, 'ignored', { raw: draft }, strings )
			.then( () => onRefresh() )
			.then( () => setNotice( noticeFor( strings.savedNotice ) ) )
			.catch( ( error ) => {
				setNotice( noticeFor( strings.failedNotice, errorText( error, strings.failedNotice.message ) ) );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card card={ card }>
			<form className="card__form" id="sakura-s3-ignored-form" onSubmit={ submit }>
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
								value={ draft }
								onChange={ ( event ) => setDraft( event.target.value ) }
								placeholder={ copy.ignoredPlaceholder || '' }
								aria-describedby="sufm-ignored-paths-hint"
							/>
						</div>
					</div>
				</div>
				<div className="field__notes">
					<p className="small-text">
						{ format( copy.ignoredNote || '当前 %d 条生效', incoming.split( '\n' ).filter( Boolean ).length ) }
					</p>
				</div>
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary" ref={ saveRef } disabled={ frozen || busy }>
						{ copy.ignoredSave || '保存规则' }
					</button>
				</div>
			</form>
			{ notice ? <Prompt notice={ notice } onClose={ () => setNotice( null ) } returnFocus={ saveRef } /> : null }
		</Card>
	);
}
