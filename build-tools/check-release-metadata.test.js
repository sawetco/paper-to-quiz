const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { checkMetadata, parseArguments } = require( './check-release-metadata' );

function fixture( version = '1.2.3', pdfVersion = '6.3.289' ) {
	const root = fs.mkdtempSync( path.join( os.tmpdir(), 'ptq-release-' ) );
	fs.mkdirSync( path.join( root, 'languages' ) );
	fs.writeFileSync(
		path.join( root, 'package.json' ),
		JSON.stringify( { version } )
	);
	fs.writeFileSync(
		path.join( root, 'package-lock.json' ),
		JSON.stringify( {
			version,
			packages: {
				'': { version },
				'node_modules/pdfjs-dist': {
					version: pdfVersion,
					license: 'Apache-2.0',
				},
			},
		} )
	);
	fs.writeFileSync(
		path.join( root, 'paper-to-quiz.php' ),
		` * Version:     ${ version }\ndefine('PAPER_TO_QUIZ_VERSION', '${ version }');\n`
	);
	fs.writeFileSync(
		path.join( root, 'readme.txt' ),
		`Stable tag: ${ version }\n\n== Changelog ==\n\n= ${ version } =\n`
	);
	fs.writeFileSync(
		path.join( root, 'languages/paper-to-quiz.pot' ),
		`"Project-Id-Version: Paper to Quiz ${ version }\\n"\n`
	);
	fs.writeFileSync(
		path.join( root, 'third-party-licenses.txt' ),
		`[pdfjs-dist](https://www.npmjs.com/package/pdfjs-dist) version ${ pdfVersion }\n`
	);
	return root;
}

afterEach( () => {
	jest.restoreAllMocks();
} );

it( 'accepts synchronized metadata and its matching tag', () => {
	const root = fixture();
	expect( checkMetadata( root, 'v1.2.3' ) ).toEqual( [] );
	fs.rmSync( root, { recursive: true, force: true } );
} );

it( 'reports version, tag, attribution, and license drift', () => {
	const root = fixture();
	const lockPath = path.join( root, 'package-lock.json' );
	const lock = JSON.parse( fs.readFileSync( lockPath, 'utf8' ) );
	lock.version = '9.9.9';
	lock.packages[ 'node_modules/pdfjs-dist' ].license = 'UNKNOWN';
	fs.writeFileSync( lockPath, JSON.stringify( lock ) );
	fs.writeFileSync(
		path.join( root, 'third-party-licenses.txt' ),
		'outdated attribution'
	);

	const errors = checkMetadata( root, 'v1.2.4' );
	expect( errors ).toEqual(
		expect.arrayContaining( [
			expect.stringContaining( 'package-lock.json version' ),
			expect.stringContaining( 'Release tag' ),
			expect.stringContaining( 'does not attribute' ),
			expect.stringContaining( 'license is UNKNOWN' ),
		] )
	);
	fs.rmSync( root, { recursive: true, force: true } );
} );

it( 'parses root and tag arguments', () => {
	expect( parseArguments( [ '--root', '.', '--tag', 'v1.2.3' ] ) ).toEqual( {
		expectedTag: 'v1.2.3',
		root: process.cwd(),
	} );
} );
