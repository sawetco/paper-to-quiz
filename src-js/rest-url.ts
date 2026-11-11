/**
 * Keep endpoint query parameters outside WordPress's plain-permalink rest_route.
 * @param root
 * @param path
 */
export function restUrl( root: string, path: string ): string {
	return (
		root +
		path
			.replace( /^\//, '' )
			.replace( '?', root.includes( '?' ) ? '&' : '?' )
	);
}
