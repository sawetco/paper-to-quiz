import { restJsonUrl, restUrl } from './rest-url';

it.each( [
	[
		'/wp-json/paper-to-quiz/v1/',
		'/admin/classes?status=active&page=1',
		'/wp-json/paper-to-quiz/v1/admin/classes?status=active&page=1',
	],
	[
		'/wp-json/paper-to-quiz/v1/',
		'attempts/abc/submit',
		'/wp-json/paper-to-quiz/v1/attempts/abc/submit',
	],
	[
		'/?rest_route=/paper-to-quiz/v1/',
		'/admin/classes?status=active&page=1',
		'/?rest_route=/paper-to-quiz/v1/admin/classes&status=active&page=1',
	],
	[
		'/?rest_route=/paper-to-quiz/v1/',
		'/assessments/7/ranking?search=A%26B%3F&page=2',
		'/?rest_route=/paper-to-quiz/v1/assessments/7/ranking&search=A%26B%3F&page=2',
	],
	[
		'https://example.test/index.php?rest_route=/paper-to-quiz/v1/',
		'attempts/abc/submit',
		'https://example.test/index.php?rest_route=/paper-to-quiz/v1/attempts/abc/submit',
	],
	[
		'/site/wp-json/paper-to-quiz/v1?lang=tr',
		'/admin/classes?status=active',
		'/site/wp-json/paper-to-quiz/v1/admin/classes?lang=tr&status=active',
	],
	[
		'/site/index.php?lang=tr&rest_route=%2Fpaper-to-quiz%2Fv1%2F&locale=tr_TR',
		'/admin/subjects?search=A%3FB&page=2',
		'/site/index.php?lang=tr&rest_route=%2Fpaper-to-quiz%2Fv1%2Fadmin/subjects&locale=tr_TR&search=A%3FB&page=2',
	],
] )( 'builds REST URL %s + %s', ( root, path, expected ) => {
	expect( restUrl( root, path ) ).toBe( expected );
} );

it( 'puts the JSON cache marker outside a plain permalink route', () => {
	const root = '/index.php?rest_route=/paper-to-quiz/v1/&lang=tr';
	const path = '/admin/subjects?search=A%26B';
	expect( restJsonUrl( root, path, 'get' ) ).toBe(
		'/index.php?rest_route=/paper-to-quiz/v1/admin/subjects&lang=tr&search=A%26B&_paper_to_quiz_private=1'
	);
	expect( restJsonUrl( root, path, 'POST' ) ).toBe( restUrl( root, path ) );
} );

it( 'uses the same cache marker for GET and HEAD without changing binary URLs', () => {
	const root = '/site/wp-json/paper-to-quiz/v1/';
	const path = '/attempts/example/result';
	expect( restJsonUrl( root, path ) ).toBe(
		'/site/wp-json/paper-to-quiz/v1/attempts/example/result?_paper_to_quiz_private=1'
	);
	expect( restJsonUrl( root, path, 'HEAD' ) ).toBe(
		restJsonUrl( root, path )
	);
	expect( restUrl( root, '/attempts/example/questions/1/image' ) ).toBe(
		'/site/wp-json/paper-to-quiz/v1/attempts/example/questions/1/image'
	);
} );
