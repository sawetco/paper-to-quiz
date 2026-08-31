import React from 'react';
import '@testing-library/jest-dom';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { StudentApp } from './StudentApp';
import { studentApi } from './api';
import {
	deleteDraft,
	deleteReceipt,
	readDraft,
	readReceipt,
	writeDraft,
	writeReceipt,
} from './draft';

jest.mock( '@wordpress/element', () => jest.requireActual( 'react' ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( message: string ) => message,
	_n: ( singular: string, plural: string, count: number ) =>
		count === 1 ? singular : plural,
	sprintf: ( message: string, ...args: unknown[] ) => {
		let next = 0;
		return message.replace( /%(\d+\$)?[sd]/g, ( _match, position ) => {
			const index = position
				? Number( position.slice( 0, -1 ) ) - 1
				: next++;
			return String( args[ index ] );
		} );
	},
} ) );

jest.mock( './api', () => ( {
	__esModule: true,
	studentApi: jest.fn(),
	StudentApiError: class StudentApiError extends Error {
		status = 0;
		data: unknown = null;
	},
} ) );

jest.mock( './draft', () => ( {
	__esModule: true,
	deleteDraft: jest.fn(),
	deleteReceipt: jest.fn(),
	readDraft: jest.fn(),
	readReceipt: jest.fn(),
	writeDraft: jest.fn(),
	writeReceipt: jest.fn(),
} ) );

const apiMock = jest.mocked( studentApi );
const deleteDraftMock = jest.mocked( deleteDraft );
const deleteReceiptMock = jest.mocked( deleteReceipt );
const readDraftMock = jest.mocked( readDraft );
const readReceiptMock = jest.mocked( readReceipt );
const writeDraftMock = jest.mocked( writeDraft );
const writeReceiptMock = jest.mocked( writeReceipt );

const ASSESSMENT_ID = 7;
const SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';
const PUBLIC_ID = 'attempt-public';
const REVISION_ID = 22;
const QUESTION_ONE = 101;
const QUESTION_TWO = 102;

function createBootstrap( overrides: Record< string, unknown > = {} ) {
	return {
		id: ASSESSMENT_ID,
		type: 'test',
		title: 'Practice test',
		description: '<p>Answer the questions.</p>',
		access_mode: 'guest_allowed',
		question_count: 2,
		duration_seconds: undefined,
		allow_repeat: true,
		ranking_enabled: false,
		participant_fields: [],
		current_user: {},
		latest_attempt_public_id: null,
		schedule: {
			state: 'open',
			server_time: '2026-08-31T09:00:00.000Z',
			starts_at: null,
			ends_at: null,
			results_release_at: null,
		},
		...overrides,
	};
}

function createAttempt( overrides: Record< string, unknown > = {} ) {
	return {
		public_id: PUBLIC_ID,
		revision_id: REVISION_ID,
		token: 'attempt-token',
		status: 'in_progress',
		started_at: '2026-08-31T09:00:00.000Z',
		deadline_at: undefined,
		server_time: '2026-08-31T09:00:00.000Z',
		title: 'Practice test',
		class_name: undefined,
		class_color: undefined,
		options: [ 'A', 'B', 'C' ],
		feedback_timing: 'after_submit',
		questions: [
			{
				id: QUESTION_ONE,
				ordinal: 1,
				imageUrl: '/question-one.png',
				correctOption: 'A',
			},
			{
				id: QUESTION_TWO,
				ordinal: 2,
				imageUrl: '/question-two.png',
				correctOption: 'C',
			},
		],
		answers: [],
		participant_type: 'guest',
		...overrides,
	};
}

function createResult( overrides: Record< string, unknown > = {} ) {
	return {
		status: 'submitted',
		submitted: true,
		visibility: 'summary',
		release_pending: false,
		server_time: '2026-08-31T09:10:00.000Z',
		score: 100,
		percentage: 50,
		correct: 1,
		wrong: 1,
		blank: 0,
		can_retry: false,
		integrity_status: 'on_time',
		ranking_eligible: false,
		score_precision: 0,
		answer_key_visible: false,
		document: {
			assessment_type: 'test',
			assessment_title: 'Practice test',
			participant_name: 'Test student',
			submitted_at: '2026-08-31 12:10',
		},
		...overrides,
	};
}

function createDraft( overrides: Record< string, unknown > = {} ) {
	return {
		assessmentId: ASSESSMENT_ID,
		publicId: PUBLIC_ID,
		revisionId: REVISION_ID,
		active: 0,
		answers: {},
		submissionId: SUBMISSION_ID,
		finishRequested: false,
		automatic: false,
		updatedAt: 1,
		expiresAt: Number.MAX_SAFE_INTEGER,
		...overrides,
	};
}

function configureDefaultApi() {
	apiMock.mockImplementation( ( ...args: any[] ) => {
		const path = String( args[ 1 ] );
		const options = ( args[ 4 ] || {} ) as { method?: string };

		if ( path.endsWith( '/bootstrap' ) ) {
			return Promise.resolve( createBootstrap() );
		}
		if ( path.endsWith( '/attempts' ) && options.method === 'POST' ) {
			return Promise.resolve( createAttempt() );
		}
		if ( path.endsWith( '/submit' ) ) {
			return Promise.resolve( createResult() );
		}
		if ( /\/attempts\/[^/]+$/.test( path ) ) {
			return Promise.resolve( createAttempt() );
		}
		if ( path.endsWith( '/result' ) ) {
			return Promise.resolve( createResult() );
		}
		return Promise.reject(
			new Error( `Unhandled student API request: ${ path }` )
		);
	} );
}

function renderStudentApp() {
	const mountElement = document.createElement( 'div' );
	document.body.appendChild( mountElement );
	const view = render(
		<StudentApp
			assessmentId={ ASSESSMENT_ID }
			restRoot="/wp-json/paper-to-quiz/v1/"
			nonce="test-nonce"
			mountElement={ mountElement }
		/>
	);
	return { ...view, mountElement };
}

async function startAttempt() {
	await screen.findByRole( 'heading', { name: 'Practice test' } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Start test' } ) );
	await screen.findByRole( 'heading', { name: 'Question 1' } );
}

function submitCalls() {
	return apiMock.mock.calls.filter( ( call ) =>
		String( call[ 1 ] ).endsWith( '/submit' )
	);
}

beforeEach( () => {
	apiMock.mockReset();
	deleteDraftMock.mockReset();
	deleteReceiptMock.mockReset();
	readDraftMock.mockReset();
	readReceiptMock.mockReset();
	writeDraftMock.mockReset();
	writeReceiptMock.mockReset();

	readDraftMock.mockResolvedValue( null );
	readReceiptMock.mockResolvedValue( null );
	deleteDraftMock.mockResolvedValue();
	deleteReceiptMock.mockResolvedValue();
	writeDraftMock.mockResolvedValue();
	writeReceiptMock.mockResolvedValue();
	configureDefaultApi();

	Object.defineProperty( navigator, 'onLine', {
		configurable: true,
		value: true,
	} );
	Object.defineProperty( globalThis.crypto, 'randomUUID', {
		configurable: true,
		value: jest.fn( () => SUBMISSION_ID ),
	} );
	Object.defineProperty( document.documentElement, 'requestFullscreen', {
		configurable: true,
		value: jest.fn().mockResolvedValue( undefined ),
	} );
	Object.defineProperty( URL, 'createObjectURL', {
		configurable: true,
		value: jest.fn( () => 'blob:student-question' ),
	} );
	Object.defineProperty( URL, 'revokeObjectURL', {
		configurable: true,
		value: jest.fn(),
	} );
	const fetchMock = jest.fn().mockResolvedValue( {
		ok: true,
		blob: async () => new Blob( [ 'question' ], { type: 'image/png' } ),
	} );
	Object.defineProperty( globalThis, 'fetch', {
		configurable: true,
		writable: true,
		value: fetchMock,
	} );
	Object.defineProperty( window, 'fetch', {
		configurable: true,
		writable: true,
		value: fetchMock,
	} );
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'StudentApp offline recovery lifecycle', () => {
	it( 'bootstraps the assessment without unhandled rendering errors', async () => {
		const consoleError = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => undefined );

		const view = renderStudentApp();
		await screen.findByRole( 'heading', { name: 'Practice test' } );

		expect(
			screen.getByRole( 'button', { name: 'Start test' } )
		).toBeEnabled();
		expect( consoleError ).not.toHaveBeenCalled();

		view.unmount();
		consoleError.mockRestore();
	} );

	it( 'persists active answers and finish intent before submitting one stable snapshot', async () => {
		const view = renderStudentApp();
		await startAttempt();

		fireEvent.click( screen.getByRole( 'button', { name: 'Next →' } ) );
		await screen.findByRole( 'heading', { name: 'Question 2' } );
		await waitFor( () =>
			expect(
				writeDraftMock.mock.calls.some(
					( [ draft ] ) => draft.active === 1
				)
			).toBe( true )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Question 1, blank' } )
		);
		await screen.findByRole( 'heading', { name: 'Question 1' } );
		const answerGroup = screen.getByLabelText( 'Answer for question 1' );
		fireEvent.click(
			within( answerGroup ).getByRole( 'button', { name: 'B' } )
		);
		await waitFor( () =>
			expect(
				within( answerGroup ).getByRole( 'button', { name: 'B' } )
			).toHaveAttribute( 'aria-pressed', 'true' )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Review later' } )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Review later' } )
			).toHaveAttribute( 'aria-pressed', 'true' )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Finish test' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Finish test',
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Confirm and finish',
			} )
		);
		await screen.findByRole( 'heading', { name: 'Test result document' } );

		const finishCallIndex = writeDraftMock.mock.calls.findIndex(
			( [ draft ] ) => draft.finishRequested
		);
		expect( finishCallIndex ).toBeGreaterThanOrEqual( 0 );
		const finishDraft = writeDraftMock.mock.calls[ finishCallIndex ][ 0 ];
		expect( finishDraft ).toEqual(
			expect.objectContaining( {
				assessmentId: ASSESSMENT_ID,
				publicId: PUBLIC_ID,
				revisionId: REVISION_ID,
				active: 0,
				answers: {
					[ QUESTION_ONE ]: { option: 'B', flagged: true },
				},
				submissionId: SUBMISSION_ID,
				finishRequested: true,
				automatic: false,
			} )
		);

		const submits = submitCalls();
		expect( submits ).toHaveLength( 1 );
		const submitOptions = submits[ 0 ][ 4 ] as {
			json: Record< string, unknown >;
		};
		expect( submitOptions.json ).toEqual( {
			submission_id: SUBMISSION_ID,
			automatic: false,
			answers: [
				{ question_id: QUESTION_ONE, option: 'B', flagged: true },
				{ question_id: QUESTION_TWO, option: null, flagged: false },
			],
		} );
		expect(
			writeDraftMock.mock.calls[ finishCallIndex ][ 0 ].submissionId
		).toBe( SUBMISSION_ID );
		expect(
			writeDraftMock.mock.invocationCallOrder[ finishCallIndex ]
		).toBeLessThan(
			apiMock.mock.invocationCallOrder[
				apiMock.mock.calls.indexOf( submits[ 0 ] )
			]
		);

		view.unmount();
	} );

	it( 'keeps a failed finish draft and retries it once when online', async () => {
		let submitCount = 0;
		let resolveRetry!: ( value: unknown ) => void;
		const retry = new Promise( ( resolve ) => {
			resolveRetry = resolve;
		} );
		apiMock.mockImplementation( ( ...args: any[] ) => {
			const path = String( args[ 1 ] );
			const options = ( args[ 4 ] || {} ) as { method?: string };
			if ( path.endsWith( '/bootstrap' ) ) {
				return Promise.resolve( createBootstrap() );
			}
			if ( path.endsWith( '/attempts' ) && options.method === 'POST' ) {
				return Promise.resolve( createAttempt() );
			}
			if ( path.endsWith( '/submit' ) ) {
				submitCount += 1;
				return submitCount === 1
					? Promise.reject( new Error( 'Network offline' ) )
					: retry;
			}
			return Promise.reject(
				new Error( `Unhandled student API request: ${ path }` )
			);
		} );

		const view = renderStudentApp();
		await startAttempt();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Finish test' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Finish test',
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Confirm and finish',
			} )
		);

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Network offline'
		);
		const failedDraft = writeDraftMock.mock.calls.find(
			( [ draft ] ) => draft.finishRequested
		);
		expect( failedDraft?.[ 0 ] ).toEqual(
			expect.objectContaining( {
				finishRequested: true,
				submissionId: SUBMISSION_ID,
			} )
		);

		act( () => {
			window.dispatchEvent( new Event( 'offline' ) );
		} );
		act( () => {
			window.dispatchEvent( new Event( 'online' ) );
			window.dispatchEvent( new Event( 'online' ) );
		} );
		expect( submitCount ).toBe( 2 );
		expect( writeReceiptMock ).not.toHaveBeenCalled();

		await act( async () => {
			resolveRetry( createResult() );
			await retry;
		} );
		await screen.findByRole( 'heading', { name: 'Test result document' } );
		expect( submitCount ).toBe( 2 );
		expect( writeReceiptMock ).toHaveBeenCalledTimes( 1 );
		expect( deleteDraftMock ).toHaveBeenCalledTimes( 1 );
		expect( deleteDraftMock ).toHaveBeenCalledWith( ASSESSMENT_ID );

		view.unmount();
	} );

	it( 'auto-retries a pending finish draft after reload without starting a new attempt', async () => {
		readDraftMock.mockResolvedValue(
			createDraft( {
				active: 1,
				answers: {
					[ QUESTION_ONE ]: { option: 'A', flagged: true },
				},
				finishRequested: true,
				automatic: true,
			} ) as any
		);

		const view = renderStudentApp();
		await waitFor( () => expect( submitCalls() ).toHaveLength( 1 ) );
		await screen.findByRole( 'heading', { name: 'Test result document' } );

		expect(
			apiMock.mock.calls.some(
				( call ) =>
					String( call[ 1 ] ).endsWith( '/attempts' ) &&
					( call[ 4 ] as { method?: string } | undefined )?.method ===
						'POST'
			)
		).toBe( false );
		expect( ( submitCalls()[ 0 ][ 4 ] as any ).json.submission_id ).toBe(
			SUBMISSION_ID
		);
		expect( ( submitCalls()[ 0 ][ 4 ] as any ).json.automatic ).toBe(
			true
		);
		expect( readReceiptMock ).not.toHaveBeenCalled();
		expect( writeReceiptMock ).toHaveBeenCalledTimes( 1 );
		expect( deleteDraftMock ).toHaveBeenCalledWith( ASSESSMENT_ID );

		view.unmount();
	} );

	it( 'loads a completed receipt result without creating another attempt', async () => {
		readReceiptMock.mockResolvedValue( {
			assessmentId: ASSESSMENT_ID,
			publicId: PUBLIC_ID,
			revisionId: REVISION_ID,
			completedAt: 1,
			expiresAt: Number.MAX_SAFE_INTEGER,
		} );
		apiMock.mockImplementation( ( ...args: any[] ) => {
			const path = String( args[ 1 ] );
			if ( path.endsWith( '/bootstrap' ) ) {
				return Promise.resolve(
					createBootstrap( { latest_attempt_public_id: null } )
				);
			}
			if ( /\/attempts\/[^/]+$/.test( path ) ) {
				return Promise.resolve(
					createAttempt( { status: 'submitted' } )
				);
			}
			if ( path.endsWith( '/result' ) ) {
				return Promise.resolve( createResult() );
			}
			return Promise.reject(
				new Error( `Unexpected request: ${ path }` )
			);
		} );

		const view = renderStudentApp();
		await screen.findByRole( 'heading', { name: 'Test result document' } );
		expect(
			apiMock.mock.calls.some(
				( call ) =>
					String( call[ 1 ] ).endsWith( '/attempts' ) &&
					( call[ 4 ] as { method?: string } | undefined )?.method ===
						'POST'
			)
		).toBe( false );
		expect( readDraftMock ).toHaveBeenCalledWith( ASSESSMENT_ID );
		expect( readReceiptMock ).toHaveBeenCalledWith( ASSESSMENT_ID );

		view.unmount();
	} );

	it( 'deletes an invalid receipt when its completed attempt cannot be restored', async () => {
		readReceiptMock.mockResolvedValue( {
			assessmentId: ASSESSMENT_ID,
			publicId: 'missing-attempt',
			revisionId: REVISION_ID,
			completedAt: 1,
			expiresAt: Number.MAX_SAFE_INTEGER,
		} );
		apiMock.mockImplementation( ( ...args: any[] ) => {
			const path = String( args[ 1 ] );
			if ( path.endsWith( '/bootstrap' ) ) {
				return Promise.resolve( createBootstrap() );
			}
			if ( /\/attempts\/[^/]+$/.test( path ) ) {
				return Promise.reject(
					new Error( 'Attempt no longer exists' )
				);
			}
			return Promise.reject(
				new Error( `Unexpected request: ${ path }` )
			);
		} );

		const view = renderStudentApp();
		expect(
			await screen.findByText( 'Attempt no longer exists' )
		).toBeInTheDocument();
		expect( deleteReceiptMock ).toHaveBeenCalledTimes( 1 );
		expect( deleteReceiptMock ).toHaveBeenCalledWith( ASSESSMENT_ID );
		view.unmount();
	} );

	it( 'retains the completed draft when receipt storage fails', async () => {
		writeReceiptMock.mockRejectedValue(
			new Error( 'Receipt storage unavailable' )
		);
		const view = renderStudentApp();
		await startAttempt();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Finish test' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Finish test',
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Confirm and finish',
			} )
		);
		await screen.findByRole( 'heading', { name: 'Test result document' } );

		expect( writeReceiptMock ).toHaveBeenCalledTimes( 1 );
		expect( deleteDraftMock ).not.toHaveBeenCalled();
		expect(
			writeDraftMock.mock.calls.some(
				( [ draft ] ) => draft.finishRequested
			)
		).toBe( true );

		view.unmount();
	} );

	it( 'removes online/offline listeners when unmounted', async () => {
		const removeListener = jest.spyOn( window, 'removeEventListener' );
		const view = renderStudentApp();
		await screen.findByRole( 'heading', { name: 'Practice test' } );
		view.unmount();

		expect( removeListener ).toHaveBeenCalledWith(
			'online',
			expect.any( Function )
		);
		expect( removeListener ).toHaveBeenCalledWith(
			'offline',
			expect.any( Function )
		);
		removeListener.mockRestore();
	} );
} );
