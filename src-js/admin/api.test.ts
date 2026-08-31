jest.mock( '@wordpress/i18n', () => ( {
	__: ( message: string ) => message,
	sprintf: ( message: string, ...args: unknown[] ) =>
		message.replace( /%s/g, () => String( args.shift() ) ),
} ) );

jest.mock( '@noble/hashes/sha2.js', () => {
	const { createHash } =
		jest.requireActual< typeof import('node:crypto') >( 'node:crypto' );
	return {
		sha256: {
			create: () => {
				const hash = createHash( 'sha256' );
				return {
					update: ( bytes: Uint8Array ) => {
						hash.update( bytes );
					},
					digest: () => new Uint8Array( hash.digest() ),
				};
			},
		},
	};
} );

const CHUNK_SIZE = 2 * 1024 * 1024;
const REST_ROOT = '/wp-json/paper-to-quiz/v1/';
const FIXTURE_CHUNK_HASH =
	'1e075c8d478ad21844e33e830a695ef03a4d2488b69ee275bd8947618bb1be1e';
const FIXTURE_LAST_CHUNK_HASH =
	'8a5edab282632443219e051e4ade2d1d5bbc671c781051bf1437897cbdfea0f1';
const FIXTURE_WHOLE_HASH =
	'72bcf8fa6c73c0a650f5c83f47e54290ba2fe60ac6cb0d8fe5b0a41dd57a0844';

type FetchImplementation = (
	input: RequestInfo | URL,
	init?: RequestInit
) => Promise< Response >;

let fetchMock: jest.MockedFunction< FetchImplementation >;
let uploadPdf: typeof import('./api').uploadPdf;

function response( body: unknown, ok = true, status = 200 ): Response {
	return {
		ok,
		status,
		json: async () => body,
	} as Response;
}

function fixtureBytes( length: number ): Uint8Array {
	const bytes = new Uint8Array( length );
	for ( let index = 0; index < length; index += 1 ) {
		bytes[ index ] = index % 251;
	}
	return bytes;
}

function createFile( bytes: Uint8Array ): File {
	const source = bytes.slice();
	return {
		name: 'fixture.pdf',
		size: source.byteLength,
		arrayBuffer: jest.fn( () =>
			Promise.reject( new Error( 'The complete File must not be read.' ) )
		),
		slice: jest.fn( ( start?: number, end?: number ) => {
			const chunk = source.slice( start || 0, end );
			const buffer = chunk.buffer.slice(
				chunk.byteOffset,
				chunk.byteOffset + chunk.byteLength
			) as ArrayBuffer;
			return { arrayBuffer: async () => buffer };
		} ),
	} as unknown as File;
}

function jsonBody( init: RequestInit | undefined ): Record< string, unknown > {
	return JSON.parse( String( init?.body ) ) as Record< string, unknown >;
}

function callsFor( path: string ) {
	return fetchMock.mock.calls.filter( ( [ input ] ) =>
		String( input ).includes( path )
	);
}

function defaultUploadResponses(): void {
	fetchMock.mockImplementation( async ( input, init ) => {
		const url = String( input );
		if ( url.endsWith( '/admin/uploads' ) && init?.method === 'POST' ) {
			return response( { id: 'upload-session' } );
		}
		if ( url.endsWith( '/complete' ) && init?.method === 'POST' ) {
			return response( { id: 99, status: 'complete' } );
		}
		return response( { ok: true } );
	} );
}

beforeAll( async () => {
	Object.defineProperty( window, 'paperToQuizAdmin', {
		configurable: true,
		value: {
			restRoot: REST_ROOT,
			nonce: 'test-nonce',
			page: 'paper-to-quiz-tests',
			pluginUrl: '/plugin/',
			settings: {},
		},
	} );
	( { uploadPdf } = await import( './api' ) );
} );

beforeEach( () => {
	fetchMock = jest.fn<
		ReturnType< FetchImplementation >,
		Parameters< FetchImplementation >
	>();
	const { createHash } =
		jest.requireActual< typeof import('node:crypto') >( 'node:crypto' );
	const webCrypto = {
		subtle: {
			digest: async (
				_algorithm: AlgorithmIdentifier,
				data: BufferSource
			) => {
				const bytes =
					data instanceof ArrayBuffer
						? new Uint8Array( data )
						: new Uint8Array(
								data.buffer as ArrayBuffer,
								data.byteOffset,
								data.byteLength
						  );
				const digest = createHash( 'sha256' ).update( bytes ).digest();
				return digest.buffer.slice(
					digest.byteOffset,
					digest.byteOffset + digest.byteLength
				) as ArrayBuffer;
			},
		},
	};
	Object.defineProperty( globalThis, 'crypto', {
		configurable: true,
		value: webCrypto,
	} );
	Object.defineProperty( window, 'crypto', {
		configurable: true,
		value: webCrypto,
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
	jest.spyOn( window, 'setTimeout' ).mockImplementation( ( handler ) => {
		if ( typeof handler === 'function' ) {
			handler();
		}
		return 0;
	} );
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

describe( 'uploadPdf incremental hashing', () => {
	it( 'streams exact chunk ranges and sends matching per-chunk and whole hashes', async () => {
		const bytes = fixtureBytes( CHUNK_SIZE + 1 );
		const file = createFile( bytes );
		const fileArrayBuffer = jest.spyOn( file, 'arrayBuffer' );
		const slice = jest.spyOn( file, 'slice' );
		const progress: number[] = [];
		defaultUploadResponses();

		await uploadPdf(
			37,
			file,
			( value ) => progress.push( value ),
			'clear'
		);

		expect( fileArrayBuffer ).not.toHaveBeenCalled();
		expect( slice ).toHaveBeenNthCalledWith( 1, 0, CHUNK_SIZE );
		expect( slice ).toHaveBeenNthCalledWith( 2, CHUNK_SIZE, bytes.length );

		const chunkCalls = callsFor( '/chunks/' );
		expect( chunkCalls ).toHaveLength( 2 );
		expect(
			new Headers( chunkCalls[ 0 ][ 1 ]?.headers ).get(
				'X-Paper-To-Quiz-Chunk-SHA256'
			)
		).toBe( FIXTURE_CHUNK_HASH );
		expect(
			new Headers( chunkCalls[ 1 ][ 1 ]?.headers ).get(
				'X-Paper-To-Quiz-Chunk-SHA256'
			)
		).toBe( FIXTURE_LAST_CHUNK_HASH );
		expect(
			new Uint8Array( chunkCalls[ 0 ][ 1 ]?.body as ArrayBuffer )
		).toEqual( bytes.slice( 0, CHUNK_SIZE ) );
		expect(
			new Uint8Array( chunkCalls[ 1 ][ 1 ]?.body as ArrayBuffer )
		).toEqual( bytes.slice( CHUNK_SIZE ) );

		const complete = callsFor( '/complete' );
		expect( complete ).toHaveLength( 1 );
		expect( jsonBody( complete[ 0 ][ 1 ] ) ).toEqual( {
			assessment_id: 37,
			sha256: FIXTURE_WHOLE_HASH,
			question_strategy: 'clear',
		} );
		expect( progress ).toEqual( [ 48, 95, 100 ] );
	} );

	it( 'updates the whole hash once per chunk when a later chunk retries', async () => {
		const bytes = fixtureBytes( CHUNK_SIZE + 1 );
		const progress: number[] = [];
		let secondChunkAttempts = 0;
		fetchMock.mockImplementation( async ( input ) => {
			const url = String( input );
			if ( url.endsWith( '/admin/uploads' ) ) {
				return response( { id: 'retry-session' } );
			}
			if ( url.endsWith( `/chunks/1` ) ) {
				secondChunkAttempts += 1;
				if ( secondChunkAttempts < 3 ) {
					return response(
						{
							code: 'paper_to_quiz_chunk_failed',
							message: 'temporary',
						},
						false,
						503
					);
				}
			}
			if ( url.endsWith( '/complete' ) ) {
				return response( { status: 'complete' } );
			}
			return response( { ok: true } );
		} );

		await uploadPdf( 8, createFile( bytes ), ( value ) =>
			progress.push( value )
		);

		expect( secondChunkAttempts ).toBe( 3 );
		expect( callsFor( '/complete' ) ).toHaveLength( 1 );
		expect( jsonBody( callsFor( '/complete' )[ 0 ][ 1 ] ).sha256 ).toBe(
			FIXTURE_WHOLE_HASH
		);
		expect( progress ).toEqual( [ 48, 95, 100 ] );
	} );

	it( 'preserves the one-chunk boundary and empty-file completion contracts', async () => {
		defaultUploadResponses();
		const exactBytes = fixtureBytes( CHUNK_SIZE );
		const exactProgress: number[] = [];
		await uploadPdf( 11, createFile( exactBytes ), ( value ) =>
			exactProgress.push( value )
		);

		const exactChunks = callsFor( '/chunks/' );
		expect( exactChunks ).toHaveLength( 1 );
		expect(
			new Uint8Array( exactChunks[ 0 ][ 1 ]?.body as ArrayBuffer )
		).toHaveLength( CHUNK_SIZE );
		expect( exactProgress ).toEqual( [ 95, 100 ] );

		fetchMock.mockClear();
		fetchMock.mockImplementationOnce( async () =>
			response(
				{
					code: 'rest_invalid_param',
					message: 'The size parameter must be at least 1.',
					data: { params: { size: 'invalid' } },
				},
				false,
				400
			)
		);
		const emptyProgress: number[] = [];
		await expect(
			uploadPdf( 12, createFile( new Uint8Array() ), ( value ) =>
				emptyProgress.push( value )
			)
		).rejects.toThrow();

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		expect( callsFor( '/chunks/' ) ).toHaveLength( 0 );
		expect( callsFor( '/complete' ) ).toHaveLength( 0 );
		expect( emptyProgress ).toEqual( [] );
	} );

	it( 'uses the trusted SHA-256 fixture for a small PDF and never reads the File wholesale', async () => {
		defaultUploadResponses();
		const file = createFile( new Uint8Array( [ 97, 98, 99 ] ) );
		const fileArrayBuffer = jest.spyOn( file, 'arrayBuffer' );

		await uploadPdf( 13, file, jest.fn() );

		expect( fileArrayBuffer ).not.toHaveBeenCalled();
		const expected =
			'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';
		const chunkCall = callsFor( '/chunks/' )[ 0 ];
		expect(
			new Headers( chunkCall[ 1 ]?.headers ).get(
				'X-Paper-To-Quiz-Chunk-SHA256'
			)
		).toBe( expected );
		expect( jsonBody( callsFor( '/complete' )[ 0 ][ 1 ] ).sha256 ).toBe(
			expected
		);
	} );

	it( 'does not complete after the third permanent chunk failure', async () => {
		let chunkAttempts = 0;
		fetchMock.mockImplementation( async ( input ) => {
			const url = String( input );
			if ( url.endsWith( '/admin/uploads' ) ) {
				return response( { id: 'failed-session' } );
			}
			if ( url.includes( '/chunks/' ) ) {
				chunkAttempts += 1;
				return response(
					{
						code: 'paper_to_quiz_chunk_failed',
						message: 'permanent',
					},
					false,
					500
				);
			}
			return response( { status: 'unexpected' } );
		} );

		await expect(
			uploadPdf(
				14,
				createFile( new Uint8Array( [ 1, 2, 3 ] ) ),
				jest.fn()
			)
		).rejects.toThrow( 'The PDF could not be uploaded. Please try again.' );
		expect( chunkAttempts ).toBe( 3 );
		expect( callsFor( '/complete' ) ).toHaveLength( 0 );
	} );
} );
