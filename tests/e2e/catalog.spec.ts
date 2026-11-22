import { expect as baseExpect, test } from '@playwright/test';
import { restUrl } from '../../src-js/rest-url';

const expect = baseExpect.configure( { timeout: 20_000 } );
const catalogs = [
	{ route: 'classes', heading: 'Classes', term: 'Class' },
	{ route: 'subjects', heading: 'Subjects', term: 'Subject' },
] as const;

for ( const { route, heading, term } of catalogs ) {
	test( `${ heading } show a saved record past the first page and outside the previous filter`, async ( {
		page,
	} ) => {
		test.setTimeout( 180_000 );
		await page.goto( '/wp-login.php' );
		await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( 'paper-to-quiz-integration' );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.getByRole( 'button', { name: 'Log In' } ).click(),
		] );
		const listRoute = `/admin/${ route }`;
		const firstList = page.waitForResponse( ( response ) => {
			const url = new URL( response.url() );
			return (
				response.request().method() === 'GET' &&
				(
					url.searchParams.get( 'rest_route' ) || url.pathname
				).endsWith( listRoute ) &&
				url.searchParams.get( 'status' ) === 'active'
			);
		} );
		await page.goto( `/wp-admin/admin.php?page=paper-to-quiz-${ route }` );
		await expect(
			page.getByRole( 'heading', { name: heading, exact: true } )
		).toBeVisible();
		const initial = await firstList;
		expect( initial.ok() ).toBe( true );
		expect( initial.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		expect(
			new URL( initial.url() ).searchParams.get( '_paper_to_quiz_private' )
		).toBe( '1' );
		expect( new URL( initial.url() ).searchParams.get( 'skip_cache' ) ).toBe(
			'1'
		);
		expect( Array.isArray( ( await initial.json() ).items ) ).toBe( true );

		// Use the real REST list with shorter pages to keep the fixture small.
		await page.route(
			( url ) =>
				(
					url.searchParams.get( 'rest_route' ) || url.pathname
				).endsWith( listRoute ) && url.searchParams.has( 'per_page' ),
			( requestRoute ) => {
				const url = new URL( requestRoute.request().url() );
				url.searchParams.set( 'per_page', '2' );
				return requestRoute.continue( { url: url.href } );
			}
		);

		const { root, nonce } = await page.evaluate( () => ( {
			root: window.paperToQuizAdmin.restRoot,
			nonce: window.paperToQuizAdmin.nonce,
		} ) );
		const headers = { 'X-WP-Nonce': nonce };
		const created: number[] = [];
		const catalogUrl = restUrl( root, listRoute );
		const bulkUrl = restUrl( root, `${ listRoute }/bulk` );
		const suffix = Date.now().toString();
		const name = `ZZZ PTQ ${ term } ${ suffix }`;
		const updatedName = `ZZZ PTQ ${ term } Updated ${ suffix }`;

		try {
			for ( let index = 1; index <= 3; index++ ) {
				const response = await page.request.post( catalogUrl, {
					headers,
					data: { name: `AAA PTQ ${ suffix } ${ index }` },
				} );
				expect( response.ok() ).toBe( true );
				created.push( Number( ( await response.json() ).id ) );
			}

			const active = page.getByRole( 'button', { name: /^All \(/ } );
			const archived = page.getByRole( 'button', {
				name: /^Archived \(/,
			} );
			const search = page.getByRole( 'searchbox', { name: 'Search' } );
			const searchButton = page.getByRole( 'button', {
				name: `Search ${ heading }`,
			} );
			const nameInput = page.getByRole( 'textbox', {
				name: 'Name',
				exact: true,
			} );

			await archived.click();
			await search.fill( 'no matching catalog entry' );
			await searchButton.click();
			await expect( page.getByText( 'No records found.' ) ).toBeVisible();
			await nameInput.fill( name );
			if ( route === 'classes' ) {
				await page
					.getByRole( 'textbox', { name: 'Color code' } )
					.fill( '#123456' );
			}
			const [ saved ] = await Promise.all( [
				page.waitForResponse(
					( response ) =>
						response.url() === catalogUrl &&
						response.request().method() === 'POST'
				),
				page.getByRole( 'button', { name: `Add ${ term }` } ).click(),
			] );
			expect( saved.ok() ).toBe( true );
			created.push( Number( ( await saved.json() ).id ) );
			await expect( active ).toHaveClass( 'current' );
			await expect( search ).toHaveValue( '' );
			const record = page.getByRole( 'row' ).filter( { hasText: name } );
			await expect( record ).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Previous page' } ).first()
			).toBeEnabled();
			if ( route === 'classes' ) {
				await expect( record.getByText( '#123456' ) ).toBeVisible();
			}

			await record.hover();
			await record.getByRole( 'button', { name: 'Edit' } ).click();
			await nameInput.fill( updatedName );
			await page
				.getByRole( 'button', { name: `Update ${ term }` } )
				.click();
			const updated = page
				.getByRole( 'row' )
				.filter( { hasText: updatedName } );
			await expect( updated ).toBeVisible();
			await expect( record ).toHaveCount( 0 );

			await page.reload();
			await search.fill( updatedName );
			await searchButton.click();
			await expect( updated ).toBeVisible();
			await updated.hover();
			await updated.getByRole( 'button', { name: 'Archive' } ).click();
			await page
				.getByRole( 'dialog' )
				.getByRole( 'button', { name: 'Archive' } )
				.click();
			await expect( updated ).toHaveCount( 0 );
			await archived.click();
			await expect( updated ).toBeVisible();
			await updated.hover();
			await updated.getByRole( 'button', { name: 'Restore' } ).click();
			await expect( updated ).toHaveCount( 0 );
			await active.click();
			await expect( updated ).toBeVisible();
		} finally {
			if ( created.length ) {
				for ( const action of [ 'trash', 'delete_permanently' ] ) {
					const cleaned = await page.request.post( bulkUrl, {
						headers,
						data: { action, ids: created },
					} );
					expect( cleaned.ok() ).toBe( true );
					expect( ( await cleaned.json() ).errors ).toEqual( [] );
				}
			}
		}
	} );
}
