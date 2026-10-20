import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

async function completeExam( page: Page ) {
	await page
		.getByRole( 'button', { name: 'Start exam', exact: true } )
		.click();
	await page
		.getByLabel( 'Answer for question' )
		.getByRole( 'button', { name: 'A', exact: true } )
		.click();
	await page
		.getByRole( 'button', { name: 'Finish exam', exact: true } )
		.click();
	await page.locator( '.ptq-modal-actions .ptq-primary' ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Your answers were saved' } )
	).toBeVisible();
}

test( 'persists public ranking and independently controls list publication', async ( {
	page,
} ) => {
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
	await page
		.getByLabel( 'Password', { exact: true } )
		.fill( 'paper-to-quiz-integration' );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.getByRole( 'button', { name: 'Log In' } ).click(),
	] );
	const url = `/wp-admin/admin.php?page=paper-to-quiz-exams&assessment=${ process.env.PTQ_E2E_RANKING_PUBLIC }`;
	await page.goto( url );
	const ranking = page.getByLabel( 'Enable ranking', { exact: true } );
	const list = page.getByLabel( 'Show participant list', { exact: true } );
	await expect( ranking ).toBeChecked();
	await page
		.getByLabel( 'Access', { exact: true } )
		.selectOption( 'login_required' );
	await page
		.getByLabel( 'Access', { exact: true } )
		.selectOption( 'guest_allowed' );
	await expect( ranking ).toBeChecked();
	await ranking.uncheck();
	await expect( list ).not.toBeChecked();
	await expect( list ).toBeDisabled();
	await ranking.check();
	await list.check();
	await page
		.getByLabel( 'Show in results', { exact: true } )
		.selectOption( 'score_only' );
	await expect( list ).not.toBeChecked();
	await page
		.getByLabel( 'Show in results', { exact: true } )
		.selectOption( 'summary' );
	await list.check();
	await page
		.getByRole( 'button', { name: 'Save and continue', exact: true } )
		.click();
	await expect(
		page.getByRole( 'button', { name: 'Select questions', exact: true } )
	).toBeVisible();
	await page.goto( url );
	await expect( ranking ).toBeChecked();
	await expect( list ).toBeChecked();
} );

test( 'guest and member submissions respect release, access, masking, search, and pagination', async ( {
	page,
	browser,
}, testInfo ) => {
	test.setTimeout( 180_000 );
	const publicId = process.env.PTQ_E2E_RANKING_PUBLIC!;
	const memberId = process.env.PTQ_E2E_RANKING_MEMBER!;
	expect( publicId ).toMatch( /^\d+$/ );
	expect( memberId ).toMatch( /^\d+$/ );
	const runtimeErrors: string[] = [];
	page.on( 'pageerror', ( error ) => runtimeErrors.push( error.message ) );
	await page.goto( '/?pagename=ptq-e2e-ranking-public' );
	await page.getByLabel( 'First name', { exact: false } ).fill( 'Can Ali' );
	await page.getByLabel( 'Last name', { exact: false } ).fill( 'Demir' );
	await completeExam( page );
	const pending = await page.request.get(
		`/wp-json/paper-to-quiz/v1/assessments/${ publicId }/ranking`
	);
	expect( pending.status() ).toBe( 403 );
	expect( await pending.json() ).not.toHaveProperty( 'items' );
	expect( pending.headers()[ 'cache-control' ] ).toContain( 'no-store' );

	const memberContext = await browser.newContext( {
		baseURL: process.env.PTQ_BASE_URL,
	} );
	const visitorContext = await browser.newContext( {
		baseURL: process.env.PTQ_BASE_URL,
		viewport: { width: 375, height: 812 },
	} );
	try {
		const memberPage = await memberContext.newPage();
		memberPage.on( 'pageerror', ( error ) =>
			runtimeErrors.push( error.message )
		);
		await memberPage.goto( '/wp-login.php' );
		await memberPage
			.getByLabel( 'Username or Email Address' )
			.fill( 'ptq_e2e_ranking_member' );
		await memberPage
			.getByLabel( 'Password', { exact: true } )
			.fill( 'paper-to-quiz-integration' );
		await Promise.all( [
			memberPage.waitForURL( /wp-admin/ ),
			memberPage.getByRole( 'button', { name: 'Log In' } ).click(),
		] );
		await memberPage.goto( '/?pagename=ptq-e2e-ranking-member' );
		await completeExam( memberPage );

		execFileSync(
			process.execPath,
			[
				path.resolve( 'node_modules/@wordpress/env/bin/wp-env' ),
				'--config',
				process.env.PTQ_E2E_WP_CONFIG!,
				'run',
				'cli',
				'env',
				'WP_ENVIRONMENT_TYPE=local',
				'PAPER_TO_QUIZ_ALLOW_E2E=1',
				'PTQ_RANKING_ACTION=release',
				'wp',
				'eval-file',
				'wp-content/plugins/paper-to-quiz/tests/ranking-e2e-fixture.php',
			],
			{ env: process.env, stdio: 'pipe', timeout: 60_000 }
		);

		await page.reload();
		await expect(
			page.getByRole( 'heading', { name: 'Overall ranking' } )
		).toBeVisible( { timeout: 30_000 } );
		await page
			.getByRole( 'button', { name: 'Ranking', exact: true } )
			.click();
		await page.getByRole( 'searchbox' ).fill( 'Can' );
		await expect(
			page.getByRole( 'cell', { name: 'Can A. D.', exact: true } )
		).toBeVisible();
		await memberPage.reload();
		await expect(
			memberPage.getByRole( 'heading', { name: 'Overall ranking' } )
		).toBeVisible( { timeout: 30_000 } );
		await memberPage
			.getByRole( 'button', { name: 'Ranking', exact: true } )
			.click();
		await expect(
			memberPage.getByRole( 'cell', { name: 'Deniz Y.', exact: true } )
		).toBeVisible();
		const nonce = await memberPage
			.locator( '.ptq-student-root[data-nonce]' )
			.getAttribute( 'data-nonce' );
		const forbiddenEdit = await memberPage.request.put(
			`/wp-json/paper-to-quiz/v1/admin/assessments/${ publicId }`,
			{
				headers: { 'X-WP-Nonce': nonce || '' },
				data: { ranking_list_enabled: true },
			}
		);
		expect( forbiddenEdit.status() ).toBe( 403 );

		const visitor = await visitorContext.newPage();
		visitor.on( 'pageerror', ( error ) =>
			runtimeErrors.push( error.message )
		);
		await visitor.goto( '/?pagename=ptq-e2e-ranking-public' );
		await visitor
			.getByRole( 'button', { name: 'Ranking', exact: true } )
			.focus();
		await visitor.keyboard.press( 'Enter' );
		await expect( visitor.getByRole( 'table' ) ).toBeVisible();
		await visitor.screenshot( {
			path: testInfo.outputPath( 'ranking-mobile.png' ),
			fullPage: true,
		} );
		await visitor
			.getByRole( 'button', { name: 'Next', exact: true } )
			.click();
		await expect( visitor.getByText( 'Page 2 of 2' ) ).toBeVisible();
		await visitor.getByRole( 'searchbox' ).fill( 'ipek' );
		await expect(
			visitor.getByRole( 'cell', { name: 'İpek M. Y.', exact: true } )
		).toBeVisible();
		await visitor.getByRole( 'searchbox' ).fill( 'Yılmaz' );
		await expect(
			visitor.getByText( 'No matching participants.' )
		).toBeVisible();
		const blocked = await visitor.request.get(
			`/wp-json/paper-to-quiz/v1/assessments/${ memberId }/ranking`
		);
		expect( blocked.status() ).toBe( 401 );
		expect( await blocked.json() ).not.toHaveProperty( 'items' );
		await visitor.goto( '/?pagename=ptq-e2e-ranking-member' );
		await expect(
			visitor.getByRole( 'link', { name: 'Log in', exact: true } )
		).toBeVisible();
		expect( runtimeErrors ).toEqual( [] );
	} finally {
		await memberContext.close();
		await visitorContext.close();
	}
} );
