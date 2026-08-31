/**
 * Values returned by the admin results endpoints are intentionally kept in
 * their wire representation. WordPress database values are strings even when
 * their schema is numeric, while values explicitly cast by PHP remain
 * numbers.
 */
export type NumericString = string;

export type AttemptStatus =
	| 'in_progress'
	| 'submitted'
	| 'auto_submitted'
	| 'expired';

/** Preserve autocomplete for known statuses while tolerating future values. */
export type AttemptStatusValue = AttemptStatus | string;

export type IntegrityStatus =
	| 'pending'
	| 'on_time'
	| 'late_recovered'
	| 'expired'
	| string;

export type ParticipantType = 'member' | 'guest';
export type AssessmentType = 'exam' | 'test';
export type BinaryString = '0' | '1';

export type ParticipantValue = string | number | boolean | null;
export type DiagnosticValue = string | number | boolean | null;
export type ParticipantData = Record< string, ParticipantValue >;
export type DiagnosticData = Record< string, DiagnosticValue >;

/** The raw attempt columns common to list rows and detail responses. */
export interface AdminAttemptWireFields {
	id: NumericString;
	public_id: string;
	assessment_id: NumericString;
	revision_id: NumericString;
	wp_user_id: NumericString | null;
	participant_type: ParticipantType;
	status: AttemptStatusValue;
	submission_id: string | null;
	integrity_status: IntegrityStatus;
	ranking_eligible: BinaryString;
	finish_requested_at: string | null;
	started_at: string;
	deadline_at: string | null;
	last_activity_at: string;
	submitted_at: string | null;
	duration_seconds: NumericString | null;
	correct_count: NumericString;
	wrong_count: NumericString;
	blank_count: NumericString;
	score: NumericString;
	percentage: NumericString;
	anonymized_at: string | null;
}

export interface ResultItem extends AdminAttemptWireFields {
	title: string;
	type: AssessmentType;
	participant_label: string;
	started_at_display: string;
	submitted_at_display: string;
	score_has_fraction: BinaryString;
}

export interface SubjectAnalyticsRow {
	subject_id: NumericString;
	subject_name: string;
	participant_count: NumericString;
	average_score: NumericString;
	average_percentage: NumericString;
	correct_count: NumericString;
	wrong_count: NumericString;
	blank_count: NumericString;
}

export interface ResultCounts {
	all: number;
	in_progress: number;
	submitted: number;
	auto_submitted: number;
	expired: number;
}

export interface AdminResultsResponse {
	items: ResultItem[];
	page: number;
	total: number;
	pages: number;
	counts: ResultCounts;
	/** Present only when the request includes an assessment filter. */
	subject_analytics?: SubjectAnalyticsRow[];
}

export interface ResultAnswer {
	id: NumericString | null;
	/** Kept optional for older/extended responses; current PHP selects q.ordinal only. */
	question_id?: NumericString;
	selected_option: string | null;
	is_flagged: BinaryString | null;
	is_correct: BinaryString | null;
	awarded_points: NumericString | null;
	ordinal: NumericString;
	correct_option: string | null;
	thumb_asset_id: NumericString | null;
	subject_id: NumericString | null;
	source_page: NumericString;
	question_points: NumericString;
	subject_name: string;
	thumbnail_url?: string | null;
}

export interface ResultSubjectRanking {
	rank: number;
	total: number;
	percentile: number;
}

/** Subject rows are explicitly cast to numbers by AttemptService::subject_scores(). */
export interface ResultSubject {
	subject_id: number;
	name: string;
	correct: number;
	wrong: number;
	blank: number;
	score: number;
	max_score: number;
	percentage: number;
	ranking?: ResultSubjectRanking;
}

export interface AdminResultDetail extends AdminAttemptWireFields {
	title: string;
	type: AssessmentType;
	participant_label: string;
	started_at_display: string;
	deadline_at_display: string;
	last_activity_at_display: string;
	submitted_at_display: string;
	participant: ParticipantData;
	diagnostics?: DiagnosticData;
	answers: ResultAnswer[];
	subjects: ResultSubject[];
	/** AttemptService emits either 0 or 2 for integer/fractional scoring. */
	score_precision: number;
}
