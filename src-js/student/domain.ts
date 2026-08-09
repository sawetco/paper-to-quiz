import type { DraftAnswer } from './draft';

// Shared formatters (plan 017): single source of truth for score + phone-input
// formatting, reused by the admin results table via src-js/shared.
export { formatScore } from '../shared/format';
export { formatTurkishPhoneInput } from '../shared/phone';

export function normalizeTurkishPhone( value: string ): string | null {
	let digits = value.replace( /\D/g, '' );
	if ( digits.startsWith( '90' ) && digits.length === 12 ) {
		digits = digits.slice( 2 );
	} else if ( digits.startsWith( '05' ) && digits.length === 11 ) {
		digits = digits.slice( 1 );
	}
	return /^5\d{9}$/.test( digits ) ? digits : null;
}

export function scorePrecision( points: number[] ): 0 | 2 {
	return points.some( ( point ) => point % 100 !== 0 ) ? 2 : 0;
}

export function reduceDraftAnswer(
	answers: Record< number, DraftAnswer >,
	questionId: number,
	option: string | null,
	flagged: boolean
): Record< number, DraftAnswer > {
	return { ...answers, [ questionId ]: { option, flagged } };
}

export function submissionAnswers(
	questionIds: number[],
	answers: Record< number, DraftAnswer >
) {
	return questionIds.map( ( questionId ) => ( {
		question_id: questionId,
		option: answers[ questionId ]?.option || null,
		flagged: answers[ questionId ]?.flagged || false,
	} ) );
}

export function calculateSubjectTotals(
	questions: Array< {
		subjectId: number;
		points: number;
		correctOption: string;
	} >,
	answers: Record< number, string | null >
) {
	return questions.reduce<
		Record<
			number,
			{
				correct: number;
				wrong: number;
				blank: number;
				score: number;
				maxScore: number;
			}
		>
	>( ( totals, question, index ) => {
		const current = totals[ question.subjectId ] || {
			correct: 0,
			wrong: 0,
			blank: 0,
			score: 0,
			maxScore: 0,
		};
		const selected = answers[ index + 1 ] || null;
		current.maxScore += question.points;
		if ( ! selected ) {
			current.blank += 1;
		} else if ( selected === question.correctOption ) {
			current.correct += 1;
			current.score += question.points;
		} else {
			current.wrong += 1;
		}
		totals[ question.subjectId ] = current;
		return totals;
	}, {} );
}
