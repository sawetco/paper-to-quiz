import {
	calculateSubjectTotals,
	formatTurkishPhoneInput,
	normalizeTurkishPhone,
	reduceDraftAnswer,
	scorePrecision,
	submissionAnswers,
} from './domain';

describe( 'student domain', () => {
	it( 'formats and normalizes Turkish mobile numbers', () => {
		expect( formatTurkishPhoneInput( '05554443322' ) ).toBe(
			'555 444 33 22'
		);
		expect( normalizeTurkishPhone( '+90 555 444 33 22' ) ).toBe(
			'5554443322'
		);
		expect( normalizeTurkishPhone( '455 444 33 22' ) ).toBeNull();
	} );

	it( 'selects score precision from question points', () => {
		expect( scorePrecision( [ 500, 1200, 1500 ] ) ).toBe( 0 );
		expect( scorePrecision( [ 333, 333, 334 ] ) ).toBe( 2 );
	} );

	it( 'builds one complete submission snapshot from the draft reducer', () => {
		const answers = reduceDraftAnswer( {}, 2, 'C', true );
		expect( submissionAnswers( [ 1, 2 ], answers ) ).toEqual( [
			{ question_id: 1, option: null, flagged: false },
			{ question_id: 2, option: 'C', flagged: true },
		] );
	} );

	it( 'keeps subject totals equal to question components', () => {
		const totals = calculateSubjectTotals(
			[
				{ subjectId: 1, points: 500, correctOption: 'A' },
				{ subjectId: 1, points: 500, correctOption: 'B' },
				{ subjectId: 2, points: 1000, correctOption: 'C' },
			],
			{ 1: 'A', 2: 'A', 3: null }
		);
		expect( totals[ 1 ] ).toEqual( {
			correct: 1,
			wrong: 1,
			blank: 0,
			score: 500,
			maxScore: 1000,
		} );
		expect( totals[ 2 ].blank ).toBe( 1 );
	} );
} );
