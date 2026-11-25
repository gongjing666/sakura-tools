/**
 * Small helpers shared by the appearance card.
 */
export function isHex( value ) {
	return /^#[0-9a-f]{6}$/i.test( String( value || '' ) );
}

export function choiceByValue( choices, value ) {
	return ( choices || [] ).find( ( choice ) => choice.value === value ) || null;
}

export function inkFor( choices, value ) {
	const choice = choiceByValue( choices, value );

	return choice && choice.ink ? choice.ink : '#c8932b';
}
