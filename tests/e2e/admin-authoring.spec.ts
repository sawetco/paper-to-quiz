import { expect, test } from '@playwright/test';

test( 'loads settings and mounts the PDF selection workflow', async ( {
	page,
} ) => {
	const assessmentId = process.env.PTQ_E2E_ASSESSMENT_ID;
	expect( assessmentId ).toMatch( /^\d+$/ );
	const runtimeErrors: string[] = [];
	page.on( 'pageerror', ( error ) => runtimeErrors.push( error.message ) );
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			runtimeErrors.push( message.text() );
		}
	} );

	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
	await page.getByLabel( 'Password', { exact: true } ).fill(
		'paper-to-quiz-integration'
	);
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.getByRole( 'button', { name: 'Log In' } ).click(),
	] );

	await page.goto( '/wp-admin/admin.php?page=paper-to-quiz-settings' );
	await expect( page.getByRole( 'heading', { name: 'Settings' } ) ).toBeVisible();

	await page.goto(
		`/wp-admin/admin.php?page=paper-to-quiz-tests&assessment=${ assessmentId }`
	);
	await expect(
		page.getByRole( 'heading', { name: 'PTQ E2E Synthetic Fixture' } )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await page.getByRole( 'button', { name: 'Select questions' } ).click();

	await expect(
		page.getByRole( 'button', { name: 'Selection tool' } )
	).toBeVisible( { timeout: 30_000 } );
	const stage = page.locator( '.konvajs-content' ).first();
	await expect( stage ).toBeVisible();
	const box = await stage.boundingBox();
	expect( box ).not.toBeNull();
	if ( box ) {
		await page.mouse.move( box.x + 30, box.y + 30 );
		await page.mouse.down();
		await page.mouse.move( box.x + 180, box.y + 130, { steps: 5 } );
		await page.mouse.up();
	}
	await expect( page.getByRole( 'button', { name: 'Save selections' } ) ).toBeEnabled();
	expect( runtimeErrors ).toEqual( [] );
} );
