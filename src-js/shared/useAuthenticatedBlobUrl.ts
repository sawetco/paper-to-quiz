import { useEffect, useRef, useState } from '@wordpress/element';

export type AuthenticatedBlobUrlState = {
	url: string;
	loading: boolean;
	error: Error | null;
};

type RequestOptions = RequestInit & {
	headers?: HeadersInit;
};

const emptyOptions: RequestOptions = {};

function isAbortError( error: unknown, signal: AbortSignal ): boolean {
	if ( signal.aborted ) {
		return true;
	}
	if ( error instanceof Error && error.name === 'AbortError' ) {
		return true;
	}
	if ( ! error || typeof error !== 'object' || ! ( 'name' in error ) ) {
		return false;
	}
	return ( error as { name?: unknown } ).name === 'AbortError';
}

/**
 * Fetch a protected image and expose a short-lived object URL for rendering.
 *
 * The optional third argument is kept separate so callers can pass dynamic
 * headers without having to rebuild the rest of RequestInit options.
 *
 * @param sourceUrl
 * @param options
 * @param headers
 */
export function useAuthenticatedBlobUrl(
	sourceUrl: string | null | undefined,
	options: RequestOptions = emptyOptions,
	headers?: HeadersInit
): AuthenticatedBlobUrlState {
	const [ state, setState ] = useState< AuthenticatedBlobUrlState >( {
		url: '',
		loading: Boolean( sourceUrl ),
		error: null,
	} );
	const generationRef = useRef( 0 );

	useEffect( () => {
		const generation = generationRef.current + 1;
		generationRef.current = generation;
		const controller = new AbortController();
		let active = true;
		let objectUrl: string | null = null;
		let revoked = false;

		const isActive = () => active && generationRef.current === generation;
		const revokeObjectUrl = () => {
			if ( objectUrl !== null && ! revoked ) {
				revoked = true;
				URL.revokeObjectURL( objectUrl );
				objectUrl = null;
			}
		};

		setState( {
			url: '',
			loading: Boolean( sourceUrl ),
			error: null,
		} );

		if ( ! sourceUrl ) {
			return () => {
				active = false;
				controller.abort();
				revokeObjectUrl();
			};
		}

		const requestHeaders = headers ?? options.headers;
		const requestOptions: RequestInit = {
			...options,
			credentials: 'same-origin',
			...( requestHeaders === undefined
				? {}
				: { headers: requestHeaders } ),
			signal: controller.signal,
		};

		void ( async () => {
			try {
				const response = await fetch( sourceUrl, requestOptions );
				if ( ! response.ok ) {
					throw new Error(
						`Image request failed with status ${
							response.status || 'unknown'
						}.`
					);
				}
				const blob = await response.blob();
				if ( ! isActive() ) {
					return;
				}

				const nextObjectUrl = URL.createObjectURL( blob );
				if ( ! isActive() ) {
					URL.revokeObjectURL( nextObjectUrl );
					return;
				}
				objectUrl = nextObjectUrl;
				setState( {
					url: objectUrl,
					loading: false,
					error: null,
				} );
			} catch ( error ) {
				if (
					! isActive() ||
					isAbortError( error, controller.signal )
				) {
					return;
				}
				setState( {
					url: '',
					loading: false,
					error:
						error instanceof Error
							? error
							: new Error( String( error ) ),
				} );
			}
		} )();

		return () => {
			active = false;
			controller.abort();
			revokeObjectUrl();
		};
	}, [ sourceUrl, options, headers ] );

	return state;
}
