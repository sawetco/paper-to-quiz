import '@testing-library/jest-dom';
import { act, renderHook, waitFor } from '@testing-library/react';
import { useAuthenticatedBlobUrl } from './useAuthenticatedBlobUrl';

jest.mock( '@wordpress/element', () => jest.requireActual( 'react' ) );

type Deferred< T > = {
	promise: Promise< T >;
	resolve: ( value: T ) => void;
	reject: ( error: unknown ) => void;
};

function deferred< T >(): Deferred< T > {
	let resolve!: ( value: T ) => void;
	let reject!: ( error: unknown ) => void;
	const promise = new Promise< T >( ( nextResolve, nextReject ) => {
		resolve = nextResolve;
		reject = nextReject;
	} );
	return { promise, resolve, reject };
}

function response( text = 'image', status = 200 ): Response {
	return {
		ok: status >= 200 && status < 300,
		status,
		blob: jest.fn().mockResolvedValue( new Blob( [ text ] ) ),
	} as unknown as Response;
}

function mockFetch( implementation: jest.Mock ): jest.Mock {
	Object.defineProperty( globalThis, 'fetch', {
		configurable: true,
		writable: true,
		value: implementation,
	} );
	return implementation;
}

beforeEach( () => {
	Object.defineProperty( URL, 'createObjectURL', {
		configurable: true,
		value: jest.fn( ( blob: Blob ) => `blob:${ blob.size }` ),
	} );
	Object.defineProperty( URL, 'revokeObjectURL', {
		configurable: true,
		value: jest.fn(),
	} );
} );

describe( 'useAuthenticatedBlobUrl', () => {
	it( 'loads a blob with same-origin credentials and forwards headers', async () => {
		const fetchMock = mockFetch(
			jest.fn().mockResolvedValue( response() )
		);
		const headers = { Authorization: 'Bearer token' };
		const options = { headers };

		const { result, unmount } = renderHook( () =>
			useAuthenticatedBlobUrl( '/private/image', options )
		);

		await waitFor( () => expect( result.current.loading ).toBe( false ) );
		expect( result.current.url ).toBe( 'blob:5' );
		expect( result.current.error ).toBeNull();
		expect( fetchMock ).toHaveBeenCalledWith(
			'/private/image',
			expect.objectContaining( {
				credentials: 'same-origin',
				headers,
				signal: expect.any( AbortSignal ),
			} )
		);

		unmount();
		expect( URL.revokeObjectURL ).toHaveBeenCalledTimes( 1 );
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:5' );
	} );

	it( 'revokes each loaded object URL exactly once when the source changes', async () => {
		mockFetch(
			jest
				.fn()
				.mockResolvedValueOnce( response( 'first' ) )
				.mockResolvedValueOnce( response( 'second' ) )
		);
		const { result, rerender, unmount } = renderHook(
			( { url } ) => useAuthenticatedBlobUrl( url ),
			{ initialProps: { url: '/private/first' } }
		);

		await waitFor( () => expect( result.current.url ).toBe( 'blob:5' ) );
		rerender( { url: '/private/second' } );
		await waitFor( () => expect( result.current.url ).toBe( 'blob:6' ) );

		expect( URL.revokeObjectURL ).toHaveBeenCalledTimes( 1 );
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:5' );

		unmount();
		expect( URL.revokeObjectURL ).toHaveBeenCalledTimes( 2 );
		expect( URL.revokeObjectURL ).toHaveBeenLastCalledWith( 'blob:6' );
	} );

	it( 'reports non-OK responses and network failures', async () => {
		mockFetch(
			jest
				.fn()
				.mockResolvedValueOnce( response( 'denied', 403 ) )
				.mockRejectedValueOnce( new Error( 'offline' ) )
		);
		const { result, rerender } = renderHook(
			( { url } ) => useAuthenticatedBlobUrl( url ),
			{ initialProps: { url: '/private/denied' } }
		);

		await waitFor( () =>
			expect( result.current.error ).toBeInstanceOf( Error )
		);
		expect( result.current.url ).toBe( '' );

		rerender( { url: '/private/offline' } );
		await waitFor( () =>
			expect( result.current.error?.message ).toBe( 'offline' )
		);
	} );

	it( 'ignores aborts and stale responses while revoking each object URL once', async () => {
		const first = deferred< Response >();
		const second = deferred< Response >();
		mockFetch(
			jest
				.fn()
				.mockImplementationOnce( ( _url, init ) => {
					expect( init?.signal ).toBeInstanceOf( AbortSignal );
					return first.promise;
				} )
				.mockImplementationOnce( () => second.promise )
		);
		const { result, rerender, unmount } = renderHook(
			( { url } ) => useAuthenticatedBlobUrl( url ),
			{ initialProps: { url: '/private/first' } }
		);

		rerender( { url: '/private/second' } );
		await act( async () => {
			first.resolve( response( 'stale' ) );
			await Promise.resolve();
		} );
		expect( result.current.url ).toBe( '' );
		expect( result.current.error ).toBeNull();
		expect( URL.createObjectURL ).not.toHaveBeenCalled();

		await act( async () => {
			second.resolve( response( 'fresh-image' ) );
			await Promise.resolve();
		} );
		await waitFor( () => expect( result.current.url ).toBe( 'blob:11' ) );

		unmount();
		expect( URL.revokeObjectURL ).toHaveBeenCalledTimes( 1 );
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:11' );
	} );

	it( 'clears the previous URL and cancels on option changes and unmount', async () => {
		const first = deferred< Response >();
		const second = deferred< Response >();
		const fetchMock = mockFetch(
			jest
				.fn()
				.mockImplementationOnce( () => first.promise )
				.mockImplementationOnce( () => second.promise )
		);
		const { result, rerender, unmount } = renderHook(
			( { options } ) =>
				useAuthenticatedBlobUrl( '/private/image', options ),
			{
				initialProps: { options: { headers: { 'X-Test': 'one' } } },
			}
		);

		rerender( { options: { headers: { 'X-Test': 'two' } } } );
		expect( result.current.url ).toBe( '' );
		expect( result.current.loading ).toBe( true );
		expect(
			( fetchMock.mock.calls[ 1 ][ 1 ] as RequestInit ).headers
		).toEqual( { 'X-Test': 'two' } );

		unmount();
		await act( async () => {
			first.resolve( response() );
			second.resolve( response() );
			await Promise.resolve();
		} );
		expect( result.current.error ).toBeNull();
		expect( URL.revokeObjectURL ).not.toHaveBeenCalled();
	} );
} );
