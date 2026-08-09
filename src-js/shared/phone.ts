/**
 * Formats user input into the grouped display form used by the phone field
 * (e.g. "555 444 33 22"). Body moved verbatim from `student/domain.ts`.
 *
 * Contract note — Turkish mobile phone normalization:
 * The canonical stored form is 10 digits matching `^5\d{9}$`. This JS helper
 * only *formats* the field for display as the user types; it does not validate.
 * The authoritative *validation* lives server-side in
 * `AttemptService::validate_participant`, which normalizes the digits the same
 * way (strip non-digits, drop a leading `90` or `05`) and then asserts the same
 * `^5\d{9}$` contract before persisting. Keep both sides in lockstep.
 * @param value
 */
export function formatTurkishPhoneInput( value: string ): string {
	let digits = value.replace( /\D/g, '' );
	if ( digits.startsWith( '90' ) ) {
		digits = digits.slice( 2 );
	}
	if ( digits.startsWith( '0' ) ) {
		digits = digits.slice( 1 );
	}
	digits = digits.slice( 0, 10 );
	return [
		digits.slice( 0, 3 ),
		digits.slice( 3, 6 ),
		digits.slice( 6, 8 ),
		digits.slice( 8, 10 ),
	]
		.filter( Boolean )
		.join( ' ' );
}
