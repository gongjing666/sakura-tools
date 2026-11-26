/**
 * Native options.php form used by every editable Zibll card.
 */
export default function Form( { card, children } ) {
	const form = card.form || {};
	const hidden = form.hidden || {};

	return (
		<form className="card__form" method="post" action={ form.action }>
			<input type="hidden" name="option_page" value={ form.group } />
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="_wpnonce" value={ form.nonce } />
			{ 'option_fields' === form.markers ? (
				<>
					<input type="hidden" name={ form.option + '[_sakura_card]' } value={ form.markerCard } />
					<input type="hidden" name={ form.option + '[_sakura_partial]' } value="1" />
				</>
			) : null }
			{ Object.entries( hidden ).map( ( [ name, value ] ) => (
				<input key={ name } type="hidden" name={ name } value={ value } />
			) ) }
			{ children }
			<div className="card__savebar">
				<button type="submit" className="btn btn--primary">{ card.save || '保存更改' }</button>
			</div>
		</form>
	);
}
