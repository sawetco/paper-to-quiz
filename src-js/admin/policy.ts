import type { AccessMode, FeedbackTiming } from '../types';

type ExamPolicy = {
	access_mode: AccessMode;
	allow_repeat: boolean;
	ranking_enabled: boolean;
	window_start_utc: string;
	window_end_utc: string;
	results_release_at_utc: string;
	feedback_timing: FeedbackTiming;
};

export function feedbackTimingForRelease(
	current: FeedbackTiming,
	release: string
): FeedbackTiming {
	if ( release ) {
		return 'scheduled';
	}
	if ( ! release && current === 'scheduled' ) {
		return 'after_submit';
	}
	return current;
}

export function normalizeExamPolicy< T extends ExamPolicy >( draft: T ): T {
	const normalized = { ...draft };
	if ( normalized.allow_repeat ) {
		normalized.ranking_enabled = false;
		normalized.window_start_utc = '';
		normalized.window_end_utc = '';
		normalized.results_release_at_utc = '';
		normalized.feedback_timing = feedbackTimingForRelease(
			normalized.feedback_timing,
			''
		);
		return normalized;
	}
	if ( normalized.access_mode !== 'login_required' ) {
		normalized.ranking_enabled = false;
	}
	normalized.feedback_timing = feedbackTimingForRelease(
		normalized.feedback_timing,
		normalized.results_release_at_utc
	);
	return normalized;
}
