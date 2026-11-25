/**
 * Native settings form shared by the two cards.
 *
 * Saving deliberately stays on options.php: Card_Form's partial marker and
 * Settings::sanitize() are the existing contract, so this page does not grow a
 * second save path.
 */
export function Form( { config, card, hidden = {}, children } ) {
	const option = config.option;
	const cardName = `${ option }[${ config.cardField }]`;
	const partialName = `${ option }[${ config.partialField }]`;

	return (
		<form className="card__form" method="post" action={ config.optionsUrl }>
			<input type="hidden" name="option_page" value={ config.optionPage } />
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="_wpnonce" value={ config.nonce } />
			<input type="hidden" name={ cardName } value={ card } />
			<input type="hidden" name={ partialName } value="1" />
			{ Object.entries( hidden ).map( ( [ key, value ] ) => (
				<input key={ key } type="hidden" name={ `${ option }[${ key }]` } value={ value } />
			) ) }
			{ children }
		</form>
	);
}
