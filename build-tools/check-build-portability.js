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

if ( leakedFiles.length > 0 ) {
	console.error( 'Build contains the local workspace path:', leakedFiles );
	process.exitCode = 1;
}
