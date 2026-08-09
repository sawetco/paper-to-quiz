import { __, sprintf } from '@wordpress/i18n';

export class StudentApiError extends Error {
	status: number;
	data: any;

	constructor( message: string, status: number, data: any ) {
		super( message );
		this.status = status;
		this.data = data;
	}
}

export async function studentApi< T >(
	restRoot: string,
	path: string,
	nonce: string,
	token = '',
	options: RequestInit & { json?: unknown } = {}
): Promise< T > {
	const headers = new Headers( options.headers );
	if ( nonce ) {
		headers.set( 'X-WP-Nonce', nonce );
	}
	if ( token ) {
		headers.set( 'Authorization', `Bearer ${ token }` );
	}
	if ( options.json !== undefined ) {
		headers.set( 'Content-Type', 'application/json' );
	}
	const response = await fetch(
		`${ restRoot }${ path.replace( /^\//, '' ) }`,
		{
			credentials: 'same-origin',
			...options,
			headers,
			body:
				options.json !== undefined
					? JSON.stringify( options.json )
					: options.body,
		}
	);
	let data: any = null;
	try {
		data = await response.json();
	} catch {
		// Binary/empty errors are normalized below.
	}
	if ( ! response.ok ) {
		throw new StudentApiError(
			data?.message ||
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'Request failed (%d)', 'paper-to-quiz' ),
					response.status
				),
			response.status,
			data
		);
	}
	return data as T;
}
