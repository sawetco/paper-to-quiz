import { expect, test } from '@playwright/test';

test( 'loads settings and saves a PDF crop and keyboard-selected answer', async ( {
	page,
} ) => {
	test.setTimeout( 90_000 );
	const saveTimeout = 20_000;
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
	await page
		.getByLabel( 'Password', { exact: true } )
		.fill( 'paper-to-quiz-integration' );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.getByRole( 'button', { name: 'Log In' } ).click(),
	] );

	await page.goto( '/wp-admin/admin.php?page=paper-to-quiz-settings' );
	await expect(
		page.getByRole( 'heading', { name: 'Settings' } )
	).toBeVisible();

	await page.goto(
		`/wp-admin/admin.php?page=paper-to-quiz-tests&assessment=${ assessmentId }`
	);
	await expect(
		page.getByRole( 'heading', { name: 'PTQ E2E Synthetic Fixture' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Check', exact: true } )
	).toHaveCount( 0 );
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await page.getByRole( 'button', { name: 'Select questions' } ).click();

	await expect(
		page.getByRole( 'button', { name: 'Selection tool' } )
	).toBeVisible( { timeout: 60_000 } );
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
	const saveSelections = page.getByRole( 'button', {
		name: 'Save selections',
	} );
	await expect( saveSelections ).toBeEnabled();
	await saveSelections.click();

	const answerKey = page.getByRole( 'heading', {
		name: 'Answer key',
		exact: true,
	} );
	await expect( answerKey ).toBeVisible( { timeout: saveTimeout } );
	const thumbnail = page.getByRole( 'img', { name: '1. soru' } );
	await expect( thumbnail ).toBeVisible( { timeout: saveTimeout } );
	await expect
		.poll( () =>
			thumbnail.evaluate(
				( image: HTMLImageElement ) => image.naturalWidth
			)
		)
		.toBeGreaterThan( 0 );

	const answerA = page.getByRole( 'radio', { name: 'A', exact: true } );
	const answerB = page.getByRole( 'radio', { name: 'B', exact: true } );
	const saveAnswerKey = page.getByRole( 'button', {
		name: 'Save answer key',
	} );
	await expect( saveAnswerKey ).toBeDisabled();
	await page
		.getByRole( 'button', { name: 'Distribute points evenly' } )
		.focus();
	await page.keyboard.press( 'Tab' );
	await expect( answerA ).toBeFocused();
	await page.keyboard.press( 'ArrowRight' );
	await expect( answerB ).toBeChecked();
	await expect( answerB ).toBeFocused();
	await expect(
		answerB.and( page.locator( ':focus-visible' ) )
	).toBeInViewport();
	const focusShadow = await answerB.evaluate(
		( radio ) => getComputedStyle( radio ).boxShadow
	);
	expect( focusShadow ).not.toBe( 'none' );
	await page.keyboard.press( 'Tab' );
	await expect( answerB ).not.toHaveCSS( 'box-shadow', focusShadow );

	await saveAnswerKey.click();
	await expect(
		page.getByRole( 'heading', { name: 'Review and publish' } )
	).toBeVisible( { timeout: saveTimeout } );

	await page.reload();
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await page.getByRole( 'button', { name: 'Select questions' } ).click();
	await expect( stage ).toBeVisible( { timeout: 60_000 } );
	await expect(
		page.getByRole( 'button', { name: /^Question 1\b/ } )
	).toBeVisible();
	await saveSelections.click();
	await expect( answerKey ).toBeVisible( { timeout: saveTimeout } );
	await expect( answerB ).toBeChecked();
	await expect( answerA ).not.toBeChecked();
	expect( runtimeErrors ).toEqual( [] );
} );
