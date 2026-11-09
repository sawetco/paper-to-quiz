'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const workspacePath = process.cwd().replaceAll( '\\', '/' );
const buildFiles = fs
	.readdirSync( 'build' )
	.filter( ( fileName ) => /\.(?:js|css|php)$/.test( fileName ) );
const leakedFiles = buildFiles.filter( ( fileName ) =>
	fs
		.readFileSync( path.join( 'build', fileName ), 'utf8' )
		.replaceAll( '\\', '/' )
		.includes( workspacePath )
);
const anonymousChunks = buildFiles.filter( ( fileName ) =>
	/^\d+(?:\.[a-f0-9]+)?\.js$/.test( fileName )
);
const requiredStableChunks = [ 'admin-wizard.js', 'admin-pdf-editor.js' ];
const missingStableChunks = requiredStableChunks.filter(
	( fileName ) => ! buildFiles.includes( fileName )
);
const vendorChunks = buildFiles.filter( ( fileName ) =>
	/^admin-pdf-editor-vendors\.[a-f0-9]+\.js$/.test( fileName )
);
const pdfWorkers = buildFiles.filter( ( fileName ) =>
	/^pdf\.worker(?:\..+)?\.min\.js$/.test( fileName )
);

if ( leakedFiles.length > 0 ) {
	console.error( 'Build contains the local workspace path:', leakedFiles );
	process.exitCode = 1;
}

if ( anonymousChunks.length > 0 ) {
	console.error( 'Build contains anonymous numeric chunks:', anonymousChunks );
	process.exitCode = 1;
}

if ( missingStableChunks.length > 0 ) {
	console.error( 'Build is missing stable admin chunks:', missingStableChunks );
	process.exitCode = 1;
}

if ( vendorChunks.length !== 1 ) {
	console.error(
		'Build must contain one content-hashed PDF editor vendor chunk:',
		vendorChunks
	);
	process.exitCode = 1;
}

if (
	pdfWorkers.length !== 1 ||
	! /^pdf\.worker\.[a-f0-9]+\.min\.js$/.test( pdfWorkers[ 0 ] )
) {
	console.error(
		'Build must contain one content-hashed PDF worker:',
		pdfWorkers
	);
	process.exitCode = 1;
}
