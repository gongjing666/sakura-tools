/**
 * Ignore rules. A plain POST: the handler redirects back with ignored_saved=1,
 * which is also what the classic page did, so no Ajax path is needed.
 */
import { Card } from '../shared/ui.jsx';

export default function IgnoredCard( { card, view, strings, frozen } ) {
	const copy = ( strings && strings.cards ) || {};
	const saved = Boolean( view.ignored_saved );

	return (
		<Card card={ card }>
			<form
				className="card__form"
				id="sakura-s3-ignored-form"
				method="post"
				action={ window.sakuraPage && window.sakuraPage.ajaxUrl ? window.sakuraPage.ajaxUrl.replace( 'admin-ajax.php', 'admin-post.php' ) : '' }
			>
				<input type="hidden" name="action" value="sakura_s3_ignored_save" />
				<input type="hidden" name="_wpnonce" value={ ( strings && strings.cards && strings.cards.ignoredNonce ) || '' } />
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
				<p className="small-text">{ ( copy.ignoredNote || '当前 %d 条生效' ).replace( '%d', String( ( view.ignored_rules || '' ).split( '\n' ).filter( Boolean ).length ) ) }</p>
				<div className="card__savebar">
					<button type="submit" className="btn btn--primary" disabled={ frozen }>
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
