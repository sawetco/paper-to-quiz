export type AccessMode = 'guest_allowed' | 'login_required';
export type AssessmentType = 'exam' | 'test';
export type FeedbackTiming =
	| 'never'
	| 'immediate'
	| 'after_submit'
	| 'scheduled';
export type ResultVisibility = 'hidden' | 'score_only' | 'summary' | 'detailed';

export interface Term {
	id: string;
	type: 'class' | 'subject';
	name: string;
	color?: string | null;
	status?: 'active' | 'archived' | 'trash';
	usage_count?: string;
}

export interface ListResponse< T > {
	items: T[];
	total: number;
	pages: number;
	page: number;
	counts: Record< string, number >;
}

export interface Question {
	id: string;
	revision_id: string;
	client_key?: string;
	ordinal: string;
	source_page: string;
	crop_x: string;
	crop_y: string;
	crop_width: string;
	crop_height: string;
	source_rotation: string;
	main_asset_id?: string;
	thumb_asset_id?: string;
	subject_id?: string;
	subject_name?: string;
	correct_option?: string;
	points: string;
	image_url?: string;
	thumb_url?: string;
}

export interface Revision {
	id: string;
	assessment_id: string;
	revision_no: string;
	lifecycle: 'draft' | 'published';
	title: string;
	description: string;
	class_id?: string;
	subject_id?: string;
	subject_ids?: number[];
	subject_names?: string[];
	class_name?: string;
	access_mode: AccessMode;
	options: string[];
	total_points: string;
	duration_seconds?: string;
	window_start_utc?: string;
	window_end_utc?: string;
	results_release_at_utc?: string;
	allow_repeat: string;
	ranking_enabled: string;
	feedback_timing: FeedbackTiming;
	result_visibility: ResultVisibility;
	participant_fields: Record<
		string,
		{ enabled: boolean; required: boolean }
	>;
	source_asset_id?: string;
	pdf_url?: string;
}

export interface AssessmentRecord {
	assessment: {
		id: string;
		type: AssessmentType;
		status: 'draft' | 'published' | 'archived' | 'trash';
		created_at: string;
		created_at_display?: string;
	};
	revision: Revision;
	questions: Question[];
}

export interface Selection {
	key: string;
	id?: number;
	page: number;
	x: number;
	y: number;
	width: number;
	height: number;
	rotation: number;
	ordinal: number;
	dirty: boolean;
	thumbUrl?: string;
	subjectId?: number;
}

declare global {
	interface WpMediaAttachment {
		id: number;
		filename?: string;
		mime?: string;
	}

	interface WpMediaFrame {
		on: ( event: 'select', callback: () => void ) => void;
		open: () => void;
		state: () => {
			get: ( key: 'selection' ) => {
				first: () => {
					toJSON: () => WpMediaAttachment;
				};
			};
		};
	}

	interface Window {
		wp?: {
			media?: ( options: {
				title: string;
				button: { text: string };
				library: { type: string };
				multiple: boolean;
			} ) => WpMediaFrame;
		};
		ptqAdmin: {
			restRoot: string;
			nonce: string;
			page: string;
			assessmentId?: number;
			pluginUrl: string;
			settings: {
				crop_dpi?: number;
				max_image_edge?: number;
				max_pdf_mb?: number;
			};
		};
	}
}
