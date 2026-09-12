'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );

function read( root, file ) {
	return fs.readFileSync( path.join( root, file ), 'utf8' );
}

function match( value, pattern, label, errors ) {
	const result = value.match( pattern );
	if ( ! result ) {
		errors.push( `Could not read ${ label }.` );
		return '';
	}
	return result[ 1 ];
}

function checkMetadata( root, expectedTag = '' ) {
	const errors = [];
	const packageJson = JSON.parse( read( root, 'package.json' ) );
	const lock = JSON.parse( read( root, 'package-lock.json' ) );
	const bootstrap = read( root, 'paper-to-quiz.php' );
	const readme = read( root, 'readme.txt' );
	const pot = read( root, 'languages/paper-to-quiz.pot' );
	const licenses = read( root, 'third-party-licenses.txt' );
	const version = packageJson.version;
	const values = {
		'package-lock.json version': lock.version,
		'package-lock.json root version': lock.packages?.[ '' ]?.version,
		'plugin header version': match(
			bootstrap,
			/^ \* Version:\s+([^\s]+)$/m,
			'plugin header version',
			errors
		),
		PAPER_TO_QUIZ_VERSION: match(
			bootstrap,
			/define\('PAPER_TO_QUIZ_VERSION', '([^']+)'\);/,
			'PAPER_TO_QUIZ_VERSION',
			errors
		),
		'readme Stable tag': match(
			readme,
			/^Stable tag:\s+([^\s]+)$/m,
			'readme Stable tag',
			errors
		),
		'POT Project-Id-Version': match(
			pot,
			/^"Project-Id-Version: Paper to Quiz ([^\\]+)\\n"$/m,
			'POT Project-Id-Version',
			errors
		),
	};

	for ( const [ label, value ] of Object.entries( values ) ) {
		if ( value && value !== version ) {
			errors.push( `${ label } is ${ value }; expected ${ version }.` );
		}
	}

	if ( ! readme.includes( `\n= ${ version } =\n` ) ) {
		errors.push( `readme.txt has no ${ version } changelog heading.` );
	}
	if ( expectedTag && expectedTag !== `v${ version }` ) {
		errors.push(
			`Release tag is ${ expectedTag }; expected v${ version }.`
		);
	}

	const pdfPackage = lock.packages?.[ 'node_modules/pdfjs-dist' ];
	if ( ! pdfPackage?.version ) {
		errors.push( 'package-lock.json has no pdfjs-dist package entry.' );
	} else if (
		! licenses.includes(
			`[pdfjs-dist](https://www.npmjs.com/package/pdfjs-dist) version ${ pdfPackage.version }`
		)
	) {
		errors.push(
			`third-party-licenses.txt does not attribute pdfjs-dist ${ pdfPackage.version }.`
		);
	}
	if ( pdfPackage?.license !== 'Apache-2.0' ) {
		errors.push(
			`pdfjs-dist license is ${
				pdfPackage?.license || 'missing'
			}; expected Apache-2.0.`
		);
	}

	return errors;
}

function parseArguments( argumentsList ) {
	let expectedTag = '';
	let root = process.cwd();
	for ( let index = 0; index < argumentsList.length; index += 1 ) {
		if ( argumentsList[ index ] === '--tag' ) {
			expectedTag = argumentsList[ ++index ] || '';
		} else if ( argumentsList[ index ] === '--root' ) {
			root = path.resolve( argumentsList[ ++index ] || '.' );
		} else {
			throw new Error( `Unknown argument: ${ argumentsList[ index ] }` );
		}
	}
	return { expectedTag, root };
}

if ( require.main === module ) {
	try {
		const { expectedTag, root } = parseArguments( process.argv.slice( 2 ) );
		const errors = checkMetadata( root, expectedTag );
		if ( errors.length ) {
			for ( const error of errors ) {
				console.error( error );
			}
			process.exitCode = 1;
		} else {
			console.log( 'Release metadata is consistent.' );
		}
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

module.exports = { checkMetadata, parseArguments };
