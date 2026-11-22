import { studentApi } from './api';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( message: string ) => message,
} ) );

const REST_ROOT = '/site/index.php?lang=tr&rest_route=%2Fpaper-to-quiz%2Fv1%2F';
const fetchMock = jest.fn<
	Promise< Response >,
	[ RequestInfo | URL, RequestInit? ]
>();

beforeEach( () => {
	fetchMock.mockReset().mockResolvedValue( {
		ok: true,
		json: async () => ( {} ),
	} as Response );
	Object.defineProperty( globalThis, 'fetch', {
		configurable: true,
		value: fetchMock,
	} );
} );

it( 'keeps the private cache key and filters outside the plain REST route', async () => {
	await studentApi(
		REST_ROOT,
		'/assessments/7/ranking?search=A%26B&page=2',
		'test-nonce',
		''
	);

	const [ input, options ] = fetchMock.mock.calls[ 0 ];
	const params = new URL( String( input ), 'https://example.test' )
		.searchParams;
	expect( Object.fromEntries( params ) ).toEqual( {
		lang: 'tr',
		rest_route: '/paper-to-quiz/v1/assessments/7/ranking',
		search: 'A&B',
		page: '2',
		_paper_to_quiz_private: '1',
		skip_cache: '1',
	} );
	expect( options?.cache ).toBe( 'no-store' );
	expect( new Headers( options?.headers ).get( 'X-WP-Nonce' ) ).toBe(
		'test-nonce'
	);
} );

it( 'keeps the submission URL and bearer header on the write request', async () => {
	const snapshot = { answers: [] };
	await studentApi( REST_ROOT, '/attempts/test/submit', '', 'test-token', {
		method: 'POST',
		json: snapshot,
	} );

	const [ input, options ] = fetchMock.mock.calls[ 0 ];
	expect( input ).toBe( `${ REST_ROOT }attempts/test/submit` );
	expect( options?.method ).toBe( 'POST' );
	expect( options?.body ).toBe( JSON.stringify( snapshot ) );
	expect( new Headers( options?.headers ).get( 'Authorization' ) ).toBe(
		'Bearer test-token'
	);
} );
