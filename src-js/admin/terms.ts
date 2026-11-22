import type { ListResponse, Term } from '../types';
import { api } from './api';

export async function loadActiveTerms(
	type: 'classes' | 'subjects',
	signal: AbortSignal
): Promise< Term[] > {
	const getPage = ( page: number ) =>
		api< ListResponse< Term > >(
			`/admin/${ type }?status=active&page=${ page }&per_page=100`,
			{ signal }
		);
	const first = await getPage( 1 );
	const terms = new Map(
		first.items.map( ( term ) => [ String( term.id ), term ] )
	);
	for ( let page = 2; page <= first.pages && ! signal.aborted; page++ ) {
		const response = await getPage( page );
		if ( response.page < page ) {
			// The catalog can shrink between requests.
			break;
		}
		for ( const term of response.items ) {
			terms.set( String( term.id ), term );
		}
	}
	return [ ...terms.values() ];
}
