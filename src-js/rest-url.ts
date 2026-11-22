/**
 * Keep WordPress's root query parameters and the endpoint's parameters separate
 * from the route path, including on plain permalink installations.
 * @param root
 * @param path
 */
export function restUrl( root: string, path: string ): string {
	const [ rootBase, rootQuery ] = splitQuery( root.split( '#', 1 )[ 0 ] );
	const [ endpoint, endpointQuery ] = splitQuery( path.replace( /^\//, '' ) );
	const params = rootQuery ? rootQuery.split( '&' ) : [];
	const route = params.findIndex( ( param ) =>
		param.startsWith( 'rest_route=' )
	);
	let url = rootBase;
	if ( route === -1 ) {
		url = appendPath( url, endpoint );
	} else {
		params[ route ] = appendPath( params[ route ], endpoint );
	}
	if ( endpointQuery ) {
		params.push( endpointQuery );
	}
	return params.length ? `${ url }?${ params.join( '&' ) }` : url;
}

/**
 * Use a new URL for JSON reads that a proxy may have cached before this policy.
 * @param root
 * @param path
 * @param method
 */
export function restJsonUrl(
	root: string,
	path: string,
	method = 'GET'
): string {
	const url = restUrl( root, path );
	const verb = method.toUpperCase();
	if ( verb !== 'GET' && verb !== 'HEAD' ) {
		return url;
	}
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }_paper_to_quiz_private=1`;
}

function splitQuery( url: string ): [ string, string ] {
	const start = url.indexOf( '?' );
	return start === -1
		? [ url, '' ]
		: [ url.slice( 0, start ), url.slice( start + 1 ) ];
}

function appendPath( base: string, endpoint: string ): string {
	return /(?:\/|%2f)$/i.test( base )
		? base + endpoint
		: `${ base }/${ endpoint }`;
}
