import { _n, sprintf } from '@wordpress/i18n';

/**
 * Formats a cent-based score using Turkish locale formatting.
 *
 * Single source of truth shared by the student app and the admin results
 * table. The score is stored as an integer number of cents (e.g. 1250 = 12.50).
 * `precision` follows the server `score_precision` convention: a truthy value
 * renders 2 decimals, otherwise 0 — so a plain `number` (as returned by the
 * REST API) is accepted and coerced, matching both call sites' previous behavior.
 * @param value
 * @param precision
 */
export function formatScore( value: number, precision: number ): string {
	const digits = precision ? 2 : 0;
	return new Intl.NumberFormat( 'tr-TR', {
		minimumFractionDigits: digits,
		maximumFractionDigits: digits,
	} ).format( value / 100 );
}

/**
 * Formats an elapsed duration (in seconds) using the abbreviated unit strings
 * ("hr/min/sec") used by the student result screen.
 *
 * Body moved verbatim from `StudentApp.tsx::formatResultDuration`.
 * @param seconds
 */
export function formatDuration( seconds: number ): string {
	const safe = Math.max( 0, Math.round( seconds ) );
	const hours = Math.floor( safe / 3600 );
	const minutes = Math.floor( ( safe % 3600 ) / 60 );
	const remainingSeconds = safe % 60;
	return [
		hours
			? sprintf(
					/* translators: %d: Number of hours. */
					_n( '%d hr', '%d hr', hours, 'paper-to-quiz' ),
					hours
			  )
			: '',
		minutes
			? sprintf(
					/* translators: %d: Number of minutes. */
					_n( '%d min', '%d min', minutes, 'paper-to-quiz' ),
					minutes
			  )
			: '',
		remainingSeconds || ( ! hours && ! minutes )
			? sprintf(
					/* translators: %d: Number of seconds. */
					_n( '%d sec', '%d sec', remainingSeconds, 'paper-to-quiz' ),
					remainingSeconds
			  )
			: '',
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * Formats a countdown (seconds remaining) for the pre-start banner.
 *
 * Body moved verbatim from `StudentApp.tsx::formatCountdown`.
 * @param seconds
 */
export function formatCountdown( seconds: number ): string {
	if ( seconds > 0 && seconds < 60 ) {
		return sprintf(
			/* translators: %d: Number of seconds. */
			_n( '%d sec', '%d sec', seconds, 'paper-to-quiz' ),
			seconds
		);
	}
	const days = Math.floor( seconds / 86400 );
	const hours = Math.floor( ( seconds % 86400 ) / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );
	const parts = [];
	if ( days ) {
		parts.push(
			sprintf(
				/* translators: %d: Number of days. */
				_n( '%d day', '%d days', days, 'paper-to-quiz' ),
				days
			)
		);
	}
	if ( hours || days ) {
		parts.push(
			sprintf(
				/* translators: %d: Number of hours. */
				_n( '%d hour', '%d hours', hours, 'paper-to-quiz' ),
				hours
			)
		);
	}
	parts.push(
		sprintf(
			/* translators: %d: Number of minutes. */
			_n( '%d min', '%d min', minutes, 'paper-to-quiz' ),
			minutes
		)
	);
	return parts.join( ' ' );
}
