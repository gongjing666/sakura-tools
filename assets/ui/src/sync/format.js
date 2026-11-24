/**
 * Small formatting helpers the legacy controller had inline.
 *
 * format() honours the same %1$s / %2$d placeholders the PHP copy uses, so the
 * strings in the payload can stay word-for-word identical to the classic page.
 */
const admin = window.SakuraAdmin;

export function format( template, ...values ) {
	return String( template == null ? '' : template ).replace( /%(\d+)\$[sd]|%[sd]/g, ( match, index ) => {
		const value = index ? values[ Number( index ) - 1 ] : values.shift();

		return value == null ? '' : String( value );
	} );
}

export function number( value ) {
	const n = Number( value ) || 0;

	return admin && admin.format ? admin.format.number( n ) : String( n );
}

export function fileSize( value ) {
	const n = Number( value ) || 0;

	return admin && admin.format ? admin.format.fileSize( n ) : String( n );
}
