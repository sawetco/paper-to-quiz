import { feedbackTimingForRelease, normalizeExamPolicy } from './policy';

describe( 'exam policy', () => {
	it( 'makes a release date take precedence over every feedback choice', () => {
		expect(
			feedbackTimingForRelease( 'after_submit', '2026-07-29T12:00' )
		).toBe( 'scheduled' );
		expect( feedbackTimingForRelease( 'never', '2026-07-29T12:00' ) ).toBe(
			'scheduled'
		);
		expect(
			feedbackTimingForRelease( 'immediate', '2026-07-29T12:00' )
		).toBe( 'scheduled' );
		expect( feedbackTimingForRelease( 'scheduled', '' ) ).toBe(
			'after_submit'
		);
	} );

	it( 'clears schedule and ranking for repeatable exams', () => {
		expect(
			normalizeExamPolicy( {
				access_mode: 'login_required',
				allow_repeat: true,
				ranking_enabled: true,
				window_start_utc: '2026-07-29T10:00',
				window_end_utc: '2026-07-29T12:00',
				results_release_at_utc: '2026-07-29T12:00',
				feedback_timing: 'scheduled',
			} )
		).toEqual( {
			access_mode: 'login_required',
			allow_repeat: true,
			ranking_enabled: false,
			window_start_utc: '',
			window_end_utc: '',
			results_release_at_utc: '',
			feedback_timing: 'after_submit',
		} );
	} );

	it( 'disables ranking for public access', () => {
		expect(
			normalizeExamPolicy( {
				access_mode: 'guest_allowed',
				allow_repeat: false,
				ranking_enabled: true,
				window_start_utc: '2026-07-29T10:00',
				window_end_utc: '2026-07-29T12:00',
				results_release_at_utc: '',
				feedback_timing: 'after_submit',
			} ).ranking_enabled
		).toBe( false );
	} );
} );
