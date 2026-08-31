import React from 'react';
import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import { answerStatus, ResultDetail, ResultsPage } from './ResultsPage';
import { api } from './api';
import type {
	AdminResultDetail,
	AdminResultsResponse,
	ResultAnswer,
	ResultItem,
	ResultSubject,
} from './result-types';

jest.mock( '@wordpress/element', () => jest.requireActual( 'react' ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( msgid: string ) => msgid,
	_n: ( single: string, plural: string, count: number ) =>
		count === 1 ? single : plural,
	_x: ( msgid: string ) => msgid,
	sprintf: ( format: string, ...args: unknown[] ) => {
		let next = 0;
		return format.replace( /%(?:(\d+)\$)?[sd]|%%/g, ( match, position ) => {
			if ( match === '%%' ) {
				return '%';
			}
			const index = position ? Number( position ) - 1 : next++;
			return String( args[ index ] );
		} );
	},
} ) );

jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children }: { children: React.ReactNode } ) => (
		<div role="alert">{ children }</div>
	),
	Spinner: () => <span role="status">Loading</span>,
} ) );

jest.mock( './api', () => ( {
	api: jest.fn(),
} ) );

const mockedApi = jest.mocked( api );

const listItem = {
	id: '101',
	public_id: 'guest-public-id',
	assessment_id: '7',
	revision_id: '11',
	wp_user_id: null,
	participant_type: 'guest',
	status: 'submitted',
	submission_id: 'submission-id',
	integrity_status: 'on_time',
	ranking_eligible: '0',
	finish_requested_at: '2026-08-31 10:01:00',
	started_at: '2026-08-31 10:00:00',
	deadline_at: null,
	last_activity_at: '2026-08-31 10:01:00',
	submitted_at: '2026-08-31 10:01:00',
	duration_seconds: '60',
	correct_count: '2',
	wrong_count: '1',
	blank_count: '0',
	score: '1250',
	percentage: '66.67',
	anonymized_at: null,
	title: 'Algebra assessment',
	type: 'exam',
	participant_label: 'Guest learner',
	started_at_display: '31 Aug 2026 10:00',
	submitted_at_display: '31 Aug 2026 10:01',
	score_has_fraction: '1',
} satisfies ResultItem;

const filteredResponse = {
	items: [ listItem ],
	page: 1,
	total: 1,
	pages: 1,
	counts: {
		all: 1,
		in_progress: 0,
		submitted: 1,
		auto_submitted: 0,
		expired: 0,
	},
	subject_analytics: [
		{
			subject_id: '3',
			subject_name: 'Algebra',
			participant_count: '1',
			average_score: '12.50',
			average_percentage: '66.67',
			correct_count: '2',
			wrong_count: '1',
			blank_count: '0',
		},
	],
} satisfies AdminResultsResponse;

const unfilteredResponse: AdminResultsResponse = {
	items: [],
	page: 1,
	total: 0,
	pages: 0,
	counts: {
		all: 0,
		in_progress: 0,
		submitted: 0,
		auto_submitted: 0,
		expired: 0,
	},
} satisfies AdminResultsResponse;

const blankAnswer = {
	id: null,
	question_id: '201',
	selected_option: null,
	is_flagged: null,
	is_correct: null,
	awarded_points: null,
	ordinal: '1',
	correct_option: 'B',
	thumb_asset_id: null,
	subject_id: '3',
	source_page: '2',
	question_points: '1250',
	subject_name: 'Algebra',
	thumbnail_url: null,
} satisfies ResultAnswer;

const correctAnswer = {
	id: '501',
	selected_option: 'B',
	is_flagged: '1',
	is_correct: '1',
	awarded_points: '1250',
	ordinal: '2',
	correct_option: 'B',
	thumb_asset_id: '9',
	subject_id: '3',
	source_page: '2',
	question_points: '1250',
	subject_name: 'Algebra',
	thumbnail_url: '/wp-json/paper-to-quiz/v1/admin/assets/9',
} satisfies ResultAnswer;

const wrongAnswer = {
	id: '502',
	selected_option: 'A',
	is_flagged: '0',
	is_correct: '0',
	awarded_points: '0',
	ordinal: '3',
	correct_option: 'C',
	thumb_asset_id: null,
	subject_id: null,
	source_page: '3',
	question_points: '500',
	subject_name: 'Subject not specified',
	thumbnail_url: null,
} satisfies ResultAnswer;

const detailSubject = {
	subject_id: 3,
	name: 'Algebra',
	correct: 1,
	wrong: 1,
	blank: 1,
	score: 1250,
	max_score: 2000,
	percentage: 62.5,
} satisfies ResultSubject;

const detail = {
	id: '101',
	public_id: 'guest-public-id',
	assessment_id: '7',
	revision_id: '11',
	wp_user_id: null,
	participant_type: 'guest',
	status: 'future_status',
	submission_id: null,
	integrity_status: 'on_time',
	ranking_eligible: '0',
	finish_requested_at: null,
	started_at: '2026-08-31 10:00:00',
	deadline_at: null,
	last_activity_at: '2026-08-31 10:01:00',
	submitted_at: null,
	duration_seconds: null,
	correct_count: '1',
	wrong_count: '1',
	blank_count: '1',
	score: '1250',
	percentage: '62.50',
	anonymized_at: null,
	title: 'Algebra assessment',
	type: 'exam',
	participant_label: 'Guest learner',
	started_at_display: '31 Aug 2026 10:00',
	deadline_at_display: '',
	last_activity_at_display: '31 Aug 2026 10:01',
	submitted_at_display: '',
	participant: {
		first_name: 'Guest',
		last_name: 'Learner',
		email: 'guest@example.test',
		class_section: '8-A',
	},
	diagnostics: {
		ip_address: '192.0.2.1',
		browser: 'Firefox',
		screen: '1920x1080',
		touch_supported: '0',
	},
	answers: [ blankAnswer, correctAnswer, wrongAnswer ],
	subjects: [ detailSubject ],
	score_precision: 2,
} satisfies AdminResultDetail;

const memberDetail: AdminResultDetail = {
	...detail,
	participant_type: 'member',
	wp_user_id: '42',
	deadline_at: '2026-08-31 10:30:00',
	submitted_at: '2026-08-31 10:02:00',
	deadline_at_display: '31 Aug 2026 10:30',
	submitted_at_display: '31 Aug 2026 10:02',
};

beforeEach( () => {
	window.history.pushState(
		{},
		'',
		'/wp-admin/admin.php?page=paper-to-quiz-results'
	);
	Object.defineProperty( window, 'paperToQuizAdmin', {
		configurable: true,
		value: {
			restRoot: '/wp-json/paper-to-quiz/v1/',
			nonce: 'test-nonce',
			page: 'paper-to-quiz-results',
			pluginUrl: '/plugin/',
			settings: {},
		},
	} );
	Object.defineProperty( URL, 'createObjectURL', {
		configurable: true,
		value: jest.fn( () => 'blob:test-thumbnail' ),
	} );
	Object.defineProperty( URL, 'revokeObjectURL', {
		configurable: true,
		value: jest.fn(),
	} );
	Object.defineProperty( window, 'fetch', {
		configurable: true,
		value: jest
			.fn()
			.mockReturnValue( new Promise< never >( () => undefined ) ),
	} );
	mockedApi.mockReset();
} );

describe( 'admin results response contracts', () => {
	it( 'renders filtered subject analytics and list rows', async () => {
		window.history.pushState(
			{},
			'',
			'/wp-admin/admin.php?page=paper-to-quiz-results&assessment_id=7'
		);
		mockedApi
			.mockResolvedValueOnce( { items: [] } )
			.mockResolvedValueOnce( filteredResponse );

		render( <ResultsPage /> );

		expect( unfilteredResponse.subject_analytics ).toBeUndefined();
		await waitFor( () =>
			expect( screen.getByText( 'Subject summary' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( 'Algebra assessment' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Average: 66.67%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Guest learner' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Completed' ) ).toHaveLength( 2 );
	} );

	it( 'renders detail participants, diagnostics, subjects, and answer states', async () => {
		const onClose = jest.fn();
		render( <ResultDetail detail={ detail } onClose={ onClose } /> );

		expect(
			screen.getByText( /Guest learner · Exam result/ )
		).toBeInTheDocument();
		expect( screen.getByText( 'guest@example.test' ) ).toBeInTheDocument();
		expect( screen.getByText( '192.0.2.1' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Firefox' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Algebra' ) ).not.toHaveLength( 0 );
		expect( screen.getByText( 'future_status' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Blank' ) ).not.toHaveLength( 0 );
		expect( screen.getAllByText( 'Correct' ) ).not.toHaveLength( 0 );
		expect( screen.getAllByText( 'Wrong' ) ).not.toHaveLength( 0 );
		expect( screen.getAllByText( '—' ).length ).toBeGreaterThan( 0 );
		expect( answerStatus( blankAnswer ) ).toBe( 'Blank' );
		expect( answerStatus( correctAnswer ) ).toBe( 'Correct' );
		expect( answerStatus( wrongAnswer ) ).toBe( 'Wrong' );

		await waitFor( () =>
			expect( window.fetch ).toHaveBeenCalledWith(
				'/wp-json/paper-to-quiz/v1/admin/assets/9',
				expect.objectContaining( {
					credentials: 'same-origin',
				} )
			)
		);
	} );

	it( 'renders member identity and populated date fields', () => {
		render(
			<ResultDetail detail={ memberDetail } onClose={ jest.fn() } />
		);

		expect( screen.getByText( 'Member' ) ).toBeInTheDocument();
		expect( screen.getByText( '42' ) ).toBeInTheDocument();
		expect( screen.getByText( '31 Aug 2026 10:02' ) ).toBeInTheDocument();
		expect( screen.getByText( '31 Aug 2026 10:30' ) ).toBeInTheDocument();
	} );
} );
