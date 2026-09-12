import React from 'react';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AssessmentRecord } from '../types';
import { PdfEditor } from './PdfEditor';
import { api, fetchBinary } from './api';
import { getDocument } from 'pdfjs-dist';

jest.mock( '@wordpress/element', () => jest.requireActual( 'react' ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( message: string ) => message,
	_n: ( single: string, plural: string, count: number ) =>
		count === 1 ? single : plural,
	sprintf: ( format: string, ...values: unknown[] ) => {
		let index = 0;
		return format.replace( /%d/g, () => String( values[ index++ ] ) );
	},
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		disabled,
	}: {
		children: React.ReactNode;
		onClick?: () => void;
		disabled?: boolean;
	} ) => (
		<button type="button" onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	Notice: ( {
		children,
		onRemove,
	}: {
		children: React.ReactNode;
		onRemove?: () => void;
	} ) => (
		<div role="alert">
			{ children }
			{ onRemove && (
				<button type="button" onClick={ onRemove }>
					Dismiss
				</button>
			) }
		</div>
	),
	Spinner: () => <span role="status">Loading</span>,
} ) );

jest.mock( '@dnd-kit/core', () => ( {
	DndContext: ( { children }: { children: React.ReactNode } ) => children,
	PointerSensor: function PointerSensor() {},
	useSensor: () => ( {} ),
	useSensors: () => [],
} ) );

jest.mock( '@dnd-kit/sortable', () => ( {
	arrayMove: ( values: unknown[] ) => values,
	SortableContext: ( { children }: { children: React.ReactNode } ) =>
		children,
	useSortable: () => ( {
		attributes: {},
		listeners: {},
		setNodeRef: jest.fn(),
		transform: null,
		transition: undefined,
		isDragging: false,
	} ),
	verticalListSortingStrategy: {},
} ) );

jest.mock( '@dnd-kit/utilities', () => ( {
	CSS: { Transform: { toString: () => '' } },
} ) );

jest.mock( 'react-konva', () => {
	const ReactActual = jest.requireActual( 'react' );
	return {
		Layer: ( { children }: { children: React.ReactNode } ) => children,
		Rect: ReactActual.forwardRef( function MockRect() {
			return null;
		} ),
		Stage: ( { children }: { children: React.ReactNode } ) => (
			<div data-testid="konva-stage">{ children }</div>
		),
		Transformer: ReactActual.forwardRef( function MockTransformer() {
			return null;
		} ),
	};
} );

jest.mock( 'pdfjs-dist', () => ( {
	GlobalWorkerOptions: {},
	getDocument: jest.fn(),
} ) );

jest.mock( './pdfWorker', () => ( {
	initializePdfWorker: jest.fn(),
} ) );

jest.mock( './api', () => ( {
	api: jest.fn(),
	fetchBinary: jest.fn(),
} ) );

const mockedApi = jest.mocked( api );
const mockedFetchBinary = jest.mocked( fetchBinary );
const mockedGetDocument = jest.mocked( getDocument );

function record( url = '/pdf/one' ): AssessmentRecord {
	return {
		assessment: {
			id: '7',
			type: 'test',
			status: 'draft',
			created_at: '2026-09-12 10:00:00',
		},
		revision: {
			id: '11',
			assessment_id: '7',
			revision_no: '1',
			lifecycle: 'draft',
			title: 'Synthetic assessment',
			description: '',
			subject_ids: [ 3 ],
			access_mode: 'guest_allowed',
			options: [ 'A', 'B', 'C', 'D' ],
			total_points: '10000',
			allow_repeat: '1',
			ranking_enabled: '0',
			feedback_timing: 'after_submit',
			result_visibility: 'summary',
			participant_fields: {},
			pdf_url: url,
		},
		questions: [
			{
				id: '31',
				revision_id: '11',
				client_key: '123e4567-e89b-42d3-a456-426614174000',
				ordinal: '1',
				source_page: '1',
				crop_x: '20',
				crop_y: '20',
				crop_width: '120',
				crop_height: '80',
				source_rotation: '0',
				main_asset_id: '41',
				thumb_asset_id: '42',
				subject_id: '3',
				points: '10000',
				thumb_url: '/thumb/31',
			},
		],
	};
}

function page() {
	return {
		rotate: 0,
		getViewport: ( { scale }: { scale: number } ) => ( {
			width: 600 * scale,
			height: 800 * scale,
		} ),
		render: () => ( {
			promise: Promise.resolve(),
			cancel: jest.fn(),
		} ),
	};
}

function pdf( numPages: number ) {
	return {
		numPages,
		getPage: jest.fn( async () => page() ),
	};
}

function putRecovery( items: unknown[] ): Promise< void > {
	return new Promise( ( resolve, reject ) => {
		const request = indexedDB.open( 'ptq-admin-recovery', 1 );
		request.onupgradeneeded = () => {
			request.result.createObjectStore( 'selections', {
				keyPath: 'revisionId',
			} );
		};
		request.onerror = () => reject( request.error );
		request.onsuccess = () => {
			const database = request.result;
			const transaction = database.transaction(
				'selections',
				'readwrite'
			);
			transaction.objectStore( 'selections' ).put( {
				revisionId: 11,
				items,
			} );
			transaction.oncomplete = () => {
				database.close();
				resolve();
			};
			transaction.onerror = () => reject( transaction.error );
		};
	} );
}

beforeEach( () => {
	Object.defineProperty( globalThis, 'structuredClone', {
		configurable: true,
		value: ( value: unknown ) => JSON.parse( JSON.stringify( value ) ),
	} );
	Object.defineProperty( globalThis, 'indexedDB', {
		configurable: true,
		value: new IDBFactory(),
	} );
	Object.defineProperty( window, 'paperToQuizAdmin', {
		configurable: true,
		value: {
			restRoot: '/wp-json/paper-to-quiz/v1/',
			nonce: 'test-nonce',
			page: 'paper-to-quiz-edit',
			pluginUrl: '/plugin/',
			settings: { page_warning: 20 },
		},
	} );
	Object.defineProperty( HTMLCanvasElement.prototype, 'getContext', {
		configurable: true,
		value: jest.fn( () => ( {
			fillRect: jest.fn(),
			drawImage: jest.fn(),
		} ) ),
	} );
	Object.defineProperty( HTMLCanvasElement.prototype, 'toDataURL', {
		configurable: true,
		value: jest.fn( () => 'data:image/jpeg;base64,test' ),
	} );
	Object.defineProperty( HTMLCanvasElement.prototype, 'toBlob', {
		configurable: true,
		value: jest.fn( ( callback: BlobCallback, type?: string ) =>
			callback( new Blob( [ 'image' ], { type } ) )
		),
	} );
	Object.defineProperty( window, 'requestAnimationFrame', {
		configurable: true,
		value: ( callback: FrameRequestCallback ) => {
			callback( 0 );
			return 1;
		},
	} );
	mockedApi.mockResolvedValue( {
		items: [
			{ id: '3', type: 'subject', name: 'Mathematics', status: 'active' },
			{ id: '4', type: 'subject', name: 'Science', status: 'active' },
		],
		total: 2,
		pages: 1,
		page: 1,
		counts: {},
	} );
	mockedFetchBinary.mockResolvedValue( new ArrayBuffer( 8 ) );
} );

afterEach( () => {
	jest.clearAllMocks();
} );

it( 'uses the configured page warning threshold and filters subjects', async () => {
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 21 ) ),
	} as unknown as ReturnType< typeof getDocument > );

	render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);

	expect(
		await screen.findByText(
			'This PDF has more than 20 pages. Performance may decrease while thumbnails are prepared.'
		)
	).toBeVisible();
	await waitFor( () =>
		expect( screen.getByTestId( 'konva-stage' ) ).toBeVisible()
	);
	expect( screen.getByText( 'Mathematics' ) ).toBeVisible();
	expect( screen.queryByText( 'Science' ) ).not.toBeInTheDocument();
} );

it( 'does not warn at the threshold and clears a prior document warning', async () => {
	mockedGetDocument
		.mockReturnValueOnce( {
			promise: Promise.resolve( pdf( 21 ) ),
		} as unknown as ReturnType< typeof getDocument > )
		.mockReturnValueOnce( {
			promise: Promise.resolve( pdf( 20 ) ),
		} as unknown as ReturnType< typeof getDocument > );

	const rendered = render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);
	await screen.findByText( /more than 20 pages/ );

	rendered.rerender(
		<PdfEditor
			record={ record( '/pdf/two' ) }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);
	await waitFor( () =>
		expect(
			screen.queryByText( /more than 20 pages/ )
		).not.toBeInTheDocument()
	);
} );

it( 'falls back to 200 for a missing warning setting', async () => {
	window.paperToQuizAdmin.settings.page_warning = undefined;
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 201 ) ),
	} as unknown as ReturnType< typeof getDocument > );

	render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);

	expect( await screen.findByText( /more than 200 pages/ ) ).toBeVisible();
} );

it( 'does not report a stale page load after unmount', async () => {
	let resolvePage: ( value: ReturnType< typeof page > ) => void = () =>
		undefined;
	const pendingPage = new Promise< ReturnType< typeof page > >(
		( resolve ) => {
			resolvePage = resolve;
		}
	);
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( {
			numPages: 1,
			getPage: jest.fn( () => pendingPage ),
		} ),
	} as unknown as ReturnType< typeof getDocument > );
	const onError = jest.fn();
	const rendered = render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ onError }
		/>
	);
	await waitFor( () => expect( mockedGetDocument ).toHaveBeenCalled() );
	rendered.unmount();
	resolvePage( page() );
	await Promise.resolve();
	await Promise.resolve();
	expect( onError ).not.toHaveBeenCalled();
} );

it.each( [
	[
		'PasswordException',
		'Encrypted PDF files are not supported. Remove the password and upload again.',
	],
	[
		'InvalidPDFException',
		'The PDF file is corrupt or invalid. Check the file and try again.',
	],
	[
		'MissingPDFException',
		'The PDF file could not be reached. Refresh the page and try again.',
	],
] )( 'maps %s while opening a PDF', async ( name, message ) => {
	mockedFetchBinary.mockRejectedValueOnce( { name } );
	const onError = jest.fn();
	render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ onError }
		/>
	);
	await waitFor( () => expect( onError ).toHaveBeenCalledWith( message ) );
} );

it( 'restores dirty recovery and removes its beforeunload handler', async () => {
	await putRecovery( [
		{
			key: '123e4567-e89b-42d3-a456-426614174000',
			id: 31,
			page: 1,
			x: 0.1,
			y: 0.1,
			width: 0.2,
			height: 0.2,
			rotation: 0,
			ordinal: 1,
			subjectId: 3,
			dirty: true,
		},
	] );
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 1 ) ),
	} as unknown as ReturnType< typeof getDocument > );
	const removeListener = jest.spyOn( window, 'removeEventListener' );
	const rendered = render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);

	expect(
		await screen.findByText( 'The unsaved selection draft was restored.' )
	).toBeVisible();
	const event = new Event( 'beforeunload', { cancelable: true } );
	window.dispatchEvent( event );
	expect( event.defaultPrevented ).toBe( true );
	rendered.unmount();
	expect( removeListener ).toHaveBeenCalledWith(
		'beforeunload',
		expect.any( Function )
	);
} );

it( 'saves questions before the answer key and awaits refresh once', async () => {
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 1 ) ),
	} as unknown as ReturnType< typeof getDocument > );
	const calls: string[] = [];
	mockedApi.mockImplementation( async ( path: string ) => {
		if ( path.startsWith( '/admin/subjects' ) ) {
			return {
				items: [
					{
						id: '3',
						type: 'subject',
						name: 'Mathematics',
						status: 'active',
					},
				],
				total: 1,
				pages: 1,
				page: 1,
				counts: {},
			} as never;
		}
		calls.push( path );
		if ( path.endsWith( '/questions' ) ) {
			return record().questions[ 0 ] as never;
		}
		return {} as never;
	} );
	const onSaved = jest.fn( async () => {
		calls.push( 'onSaved' );
	} );
	render(
		<PdfEditor
			record={ record() }
			onSaved={ onSaved }
			onError={ jest.fn() }
		/>
	);
	await screen.findByTestId( 'konva-stage' );
	fireEvent.click(
		screen.getByRole( 'button', {
			name: 'Regenerate all images at high quality',
		} )
	);
	const saveButton = screen.getByRole( 'button', {
		name: 'Save selections',
	} );
	fireEvent.click( saveButton );
	fireEvent.click( saveButton );
	await waitFor( () => expect( onSaved ).toHaveBeenCalledTimes( 1 ) );
	expect( calls ).toEqual( [
		'/admin/revisions/11/questions',
		'/admin/revisions/11/answer-key',
		'onSaved',
	] );
} );

it( 'stops save sequencing after a question failure', async () => {
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 1 ) ),
	} as unknown as ReturnType< typeof getDocument > );
	const onError = jest.fn();
	mockedApi.mockImplementation( async ( path: string ) => {
		if ( path.startsWith( '/admin/subjects' ) ) {
			return {
				items: [
					{
						id: '3',
						type: 'subject',
						name: 'Mathematics',
						status: 'active',
					},
				],
				total: 1,
				pages: 1,
				page: 1,
				counts: {},
			} as never;
		}
		throw new Error( 'Synthetic save failure' );
	} );
	render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ onError }
		/>
	);
	await screen.findByTestId( 'konva-stage' );
	fireEvent.click(
		screen.getByRole( 'button', {
			name: 'Regenerate all images at high quality',
		} )
	);
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Save selections' } )
	);
	await waitFor( () =>
		expect( onError ).toHaveBeenCalledWith( 'Synthetic save failure' )
	);
	expect(
		mockedApi.mock.calls.filter( ( [ path ] ) =>
			String( path ).endsWith( '/answer-key' )
		)
	).toHaveLength( 0 );
} );

it( 'keeps delete single-flight while its request is pending', async () => {
	mockedGetDocument.mockReturnValue( {
		promise: Promise.resolve( pdf( 1 ) ),
	} as unknown as ReturnType< typeof getDocument > );
	let resolveDelete: () => void = () => undefined;
	const pendingDelete = new Promise< void >( ( resolve ) => {
		resolveDelete = resolve;
	} );
	mockedApi.mockImplementation( async ( path: string ) => {
		if ( path.startsWith( '/admin/subjects' ) ) {
			return {
				items: [
					{
						id: '3',
						type: 'subject',
						name: 'Mathematics',
						status: 'active',
					},
				],
				total: 1,
				pages: 1,
				page: 1,
				counts: {},
			} as never;
		}
		await pendingDelete;
		return {} as never;
	} );
	render(
		<PdfEditor
			record={ record() }
			onSaved={ async () => undefined }
			onError={ jest.fn() }
		/>
	);
	await screen.findByTestId( 'konva-stage' );
	const deleteButton = screen.getByRole( 'button', { name: 'Delete' } );
	fireEvent.click( deleteButton );
	fireEvent.click( deleteButton );
	expect(
		mockedApi.mock.calls.filter( ( [ path ] ) =>
			String( path ).includes( '/admin/questions/31' )
		)
	).toHaveLength( 1 );
	resolveDelete();
	await waitFor( () =>
		expect( screen.queryByText( 'Question 1' ) ).not.toBeInTheDocument()
	);
} );
