import { restUrl } from './rest-url';

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
] )( 'builds REST URL %s + %s', ( root, path, expected ) => {
	expect( restUrl( root, path ) ).toBe( expected );
} );
