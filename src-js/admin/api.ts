import { __, sprintf } from '@wordpress/i18n';
import { sha256 } from '@noble/hashes/sha2.js';

const config = window.paperToQuizAdmin;
let currentContentName: string = __( 'Record', 'paper-to-quiz' );
if ( config.page === 'paper-to-quiz-exams' ) {
	currentContentName = __( 'Exam', 'paper-to-quiz' );
} else if ( config.page === 'paper-to-quiz-tests' ) {
	currentContentName = __( 'Test', 'paper-to-quiz' );
}

const fieldLabels: Record< string, string > = {
	title: sprintf(
		/* translators: %s: Content type, such as Exam or Test. */
		__( '%s title', 'paper-to-quiz' ),
		currentContentName
	),
	class_id: __( 'Class', 'paper-to-quiz' ),
	subject_id: __( 'Subject', 'paper-to-quiz' ),
	subject_ids: __( 'Subjects', 'paper-to-quiz' ),
	access_mode: __( 'Access', 'paper-to-quiz' ),
	total_points: __( 'Total points', 'paper-to-quiz' ),
	duration_seconds: __( 'Duration', 'paper-to-quiz' ),
	window_start_utc: __( 'Start date', 'paper-to-quiz' ),
	window_end_utc: __( 'End date', 'paper-to-quiz' ),
	name: __( 'Name', 'paper-to-quiz' ),
	ids: __( 'Selected records', 'paper-to-quiz' ),
};

function friendlyMessage( data: unknown, status: number ): string {
	if ( typeof data !== 'object' || ! data ) {
		return status >= 500
			? __(
					'The action could not be completed. Please try again.',
					'paper-to-quiz'
			  )
			: __( 'The request could not be completed.', 'paper-to-quiz' );
	}
	const response = data as {
		code?: string;
		message?: string;
		data?: { params?: Record< string, string > };
	};
	if (
		response.code?.startsWith( 'paper_to_quiz_chunk_' ) ||
		response.code === 'paper_to_quiz_upload_incomplete'
	) {
		return __(
			'The PDF could not be uploaded. Please try again.',
			'paper-to-quiz'
		);
	}
	if (
		[ 'rest_invalid_param', 'rest_missing_callback_param' ].includes(
			response.code || ''
		)
	) {
		const fields = Object.keys( response.data?.params || {} );
		if ( fields.includes( 'title' ) ) {
			return sprintf(
				/* translators: %s: Content type, such as Exam or Test. */
				__( 'Enter a %s title.', 'paper-to-quiz' ),
				currentContentName
			);
		}
		if ( fields.includes( 'total_points' ) ) {
			return __( 'Total points must be at least 1.', 'paper-to-quiz' );
		}
		if ( fields.length ) {
			return sprintf(
				/* translators: %s: Comma-separated field labels. */
				__( 'Check the following information: %s.', 'paper-to-quiz' ),
				fields
					.map(
						( field ) =>
							fieldLabels[ field ] ||
							__( 'A field', 'paper-to-quiz' )
					)
					.join( ', ' )
			);
		}
		return __( 'Check the information in the form.', 'paper-to-quiz' );
	}
	if (
		response.code === 'paper_to_quiz_storage_failed' &&
		typeof response.message === 'string' &&
		response.message
	) {
		return response.message;
	}
	if (
		status >= 500 ||
		/[A-Z]:\\|\/var\/|stack trace|undefined function/i.test(
			response.message || ''
		)
	) {
		return __(
			'The action could not be completed. Please try again.',
			'paper-to-quiz'
		);
	}
	return (
		response.message ||
		__( 'The request could not be completed.', 'paper-to-quiz' )
	);
}

export class ApiError extends Error {
	status: number;
	data: unknown;

	constructor( message: string, status: number, data: unknown ) {
		super( message );
		this.status = status;
		this.data = data;
	}
}

export async function api< T >(
	path: string,
	options: RequestInit & { json?: unknown } = {}
): Promise< T > {
	const headers = new Headers( options.headers );
	headers.set( 'X-WP-Nonce', config.nonce );
	if ( options.json !== undefined ) {
		headers.set( 'Content-Type', 'application/json' );
	}

	const response = await fetch(
		`${ config.restRoot }${ path.replace( /^\//, '' ) }`,
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
	if ( ! response.ok ) {
		let data: unknown;
		try {
			data = await response.json();
		} catch {
			data = null;
		}
		const message = friendlyMessage( data, response.status );
		throw new ApiError( message, response.status, data );
	}
	return response.json() as Promise< T >;
}

export async function fetchBinary( url: string ): Promise< ArrayBuffer > {
	const response = await fetch( url, {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': config.nonce },
	} );
	if ( ! response.ok ) {
		throw new ApiError(
			__( 'The file could not be downloaded.', 'paper-to-quiz' ),
			response.status,
			null
		);
	}
	return response.arrayBuffer();
}

export async function uploadPdf(
	assessmentId: number,
	file: File,
	onProgress: ( progress: number ) => void,
	questionStrategy?: 'preserve' | 'clear'
): Promise< unknown > {
	const chunkSize = 2 * 1024 * 1024;
	const chunkCount = Math.ceil( file.size / chunkSize );
	const session = await api< { id: string } >( '/admin/uploads', {
		method: 'POST',
		json: { name: file.name, size: file.size, chunk_count: chunkCount },
	} );

	const wholeHasher = sha256.create();

	const bytesToHex = ( bytes: Uint8Array ): string =>
		Array.from( bytes )
			.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
			.join( '' );

	for ( let index = 0; index < chunkCount; index += 1 ) {
		const chunk = file.slice(
			index * chunkSize,
			Math.min( file.size, ( index + 1 ) * chunkSize )
		);
		const bytes = await chunk.arrayBuffer();
		wholeHasher.update( new Uint8Array( bytes ) );
		const digest = await crypto.subtle.digest( 'SHA-256', bytes );
		const hex = bytesToHex( new Uint8Array( digest ) );
		let lastError: unknown;
		for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
			try {
				await api( `/admin/uploads/${ session.id }/chunks/${ index }`, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/octet-stream',
						'X-Paper-To-Quiz-Chunk-SHA256': hex,
					},
					body: bytes,
				} );
				lastError = undefined;
				break;
			} catch ( caught ) {
				lastError = caught;
				if ( attempt < 3 ) {
					await new Promise( ( resolve ) =>
						window.setTimeout( resolve, attempt * 750 )
					);
				}
			}
		}
		if ( lastError ) {
			throw lastError;
		}
		onProgress( Math.round( ( ( index + 1 ) / chunkCount ) * 95 ) );
	}
	const wholeHex = bytesToHex( wholeHasher.digest() );

	const result = await api( `/admin/uploads/${ session.id }/complete`, {
		method: 'POST',
		json: {
			assessment_id: assessmentId,
			sha256: wholeHex,
			question_strategy: questionStrategy,
		},
	} );
	onProgress( 100 );
	return result;
}
