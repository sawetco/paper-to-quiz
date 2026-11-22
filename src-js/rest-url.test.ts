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
