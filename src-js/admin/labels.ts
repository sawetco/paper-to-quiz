import { __, _n, sprintf } from '@wordpress/i18n';

export const assessmentStatusLabels: Record< string, string > = {
	published: __( 'Published', 'paper-to-quiz' ),
	draft: __( 'Draft', 'paper-to-quiz' ),
	archived: __( 'Archived', 'paper-to-quiz' ),
	trash: __( 'Trash', 'paper-to-quiz' ),
};

export const attemptStatusLabels: Record< string, string > = {
	in_progress: __( 'In progress', 'paper-to-quiz' ),
	submitted: __( 'Completed', 'paper-to-quiz' ),
	auto_submitted: __( 'Completed when time expired', 'paper-to-quiz' ),
	expired: __( 'Time expired', 'paper-to-quiz' ),
};

export function formatDuration( value: unknown ): string {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	const total = Number( value );
	if ( ! Number.isFinite( total ) || total < 0 ) {
		return '—';
	}
	const minutes = Math.floor( total / 60 );
	const seconds = Math.floor( total % 60 );
	const minuteLabel = sprintf(
		/* translators: %d: Number of minutes. */
		_n( '%d minute', '%d minutes', minutes, 'paper-to-quiz' ),
		minutes
	);
	const secondLabel = sprintf(
		/* translators: %d: Number of seconds. */
		_n( '%d second', '%d seconds', seconds, 'paper-to-quiz' ),
		seconds
	);
	if ( minutes && seconds ) {
		return sprintf(
			/* translators: 1: First formatted value. 2: Second formatted value. */
			__( '%1$s %2$s', 'paper-to-quiz' ),
			minuteLabel,
			secondLabel
		);
	}
	return minutes ? minuteLabel : secondLabel;
}
