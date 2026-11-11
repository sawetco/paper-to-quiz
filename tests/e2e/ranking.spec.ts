import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import type { AssessmentRecord } from '../../src-js/types';

function runRankingFixture( action: 'process' | 'release' ) {
	if ( process.env.PTQ_TEST_WP_LOAD ) {
		execFileSync(
			process.env.PTQ_PHP_BINARY || 'php',
			[
				path.resolve( 'tests/sqlite/fixture.php' ),
				action === 'release' ? 'ranking-release' : 'worker',
			],
			{ env: process.env, stdio: 'pipe', timeout: 60_000 }
		);
		return;
	}
	execFileSync(
		process.execPath,
		[
			path.resolve( 'node_modules/@wordpress/env/bin/wp-env' ),
			'--config', process.env.PTQ_E2E_WP_CONFIG!,
			'run', 'cli', 'env', 'WP_ENVIRONMENT_TYPE=local',
			'PAPER_TO_QUIZ_ALLOW_E2E=1', 'PTQ_RANKING_ACTION=' + action,
			'wp', 'eval-file',
			'wp-content/plugins/paper-to-quiz/tests/ranking-e2e-fixture.php',
		],
		{ env: process.env, stdio: 'pipe', timeout: 60_000 }
	);
}

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
	const receipt = page.waitForResponse(
		( response ) =>
			response.url().endsWith( '/submit' ) &&
			response.request().method() === 'POST'
	);
	await page.locator( '.ptq-modal-actions .ptq-primary' ).click();
	const accepted = await receipt;
	expect( accepted.status() ).toBe( 202 );
	expect( await accepted.json() ).toMatchObject( { status: 'processing' } );
	await expect(
		page.getByText( 'Answers received. Your result is being prepared.', {
			exact: true,
		} )
	).toBeVisible();
}

test( 'checks and publishes settings for a public ranked exam without opening the PDF', async ( {
	page,
} ) => {
	test.setTimeout( 90_000 );
	const id = process.env.PTQ_E2E_RANKING_REVIEW;
	expect( id ).toMatch( /^\d+$/ );
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
	await page
		.getByLabel( 'Password', { exact: true } )
		.fill( 'paper-to-quiz-integration' );
	await Promise.all( [
		page.waitForURL( /wp-admin/, { waitUntil: 'domcontentloaded' } ),
		page.getByRole( 'button', { name: 'Log In' } ).click(),
	] );
	const url = `/wp-admin/admin.php?page=paper-to-quiz-exams&assessment=${ id }`;
	const assessmentPath = `/paper-to-quiz/v1/admin/assessments/${ id }`;
	const originalResponse = page.waitForResponse(
		( response ) =>
			response.url().endsWith( assessmentPath ) &&
			response.request().method() === 'GET'
	);
	const pdfRequests: string[] = [];
	page.on( 'request', ( request ) => {
		if (
			request.url().includes( '/admin/assets/' ) ||
			request.url().includes( '/pdf.worker.' )
		) {
			pdfRequests.push( request.url() );
		}
	} );
	await page.goto( url );
	const original: AssessmentRecord = await ( await originalResponse ).json();
	const ranking = page.getByLabel( 'Enable ranking', { exact: true } );
	const list = page.getByLabel( 'Show participant list', { exact: true } );
	await expect( ranking ).toBeChecked();
	await expect( list ).not.toBeChecked();
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
	const check = page.getByRole( 'button', { name: 'Check', exact: true } );
	const publish = page.getByRole( 'button', { name: 'Publish new version' } );
	const points = page.locator( '#ptq-total-points' );
	await points.fill( '90' );
	await check.click();
	await expect( publish ).toBeDisabled();
	await expect(
		page.locator( '.ptq-checklist' ).getByText( 'Points total is correct' )
	).toHaveClass( 'is-invalid' );
	await page.getByRole( 'button', { name: 'Back', exact: true } ).click();
	await expect( ranking ).toBeChecked();
	await expect( list ).toBeChecked();
	await expect( page.getByLabel( 'Access', { exact: true } ) ).toHaveValue(
		'guest_allowed'
	);
	await expect(
		page.getByLabel( 'Result release', { exact: true } )
	).not.toHaveValue( '' );
	await points.fill(
		String( Number( original.revision.total_points ) / 100 )
	);
	await check.click();
	await expect( publish ).toBeEnabled();
	const publishResponse = page.waitForResponse(
		( response ) =>
			response.url().endsWith( `${ assessmentPath }/publish` ) &&
			response.request().method() === 'POST'
	);
	await publish.click();
	const response = await publishResponse;
	expect( response.ok() ).toBe( true );
	const saved: AssessmentRecord = await response.json();
	expect( saved.revision.id ).not.toBe( original.revision.id );
	expect( saved.revision.source_asset_id ).toBe(
		original.revision.source_asset_id
	);
	expect( saved.revision.ranking_list_enabled ).toBe( '1' );
	expect( saved.questions ).toMatchObject(
		original.questions.map( ( question ) => ( {
			main_asset_id: question.main_asset_id,
			thumb_asset_id: question.thumb_asset_id,
			correct_option: question.correct_option,
			points: question.points,
		} ) )
	);
	expect( pdfRequests ).toEqual( [] );
} );

test( 'two browser tabs submit one attempt and receive one final result', async ( { page, context } ) => {
	test.setTimeout( 90_000 );
	const id = process.env.PTQ_E2E_RANKING_PUBLIC!;
	await page.goto( '/?pagename=ptq-e2e-ranking-public' );
	await page.getByLabel( 'First name', { exact: false } ).fill( 'Twin' );
	await page.getByLabel( 'Last name', { exact: false } ).fill( 'Tabs' );
	const started = page.waitForResponse( response =>
		response.url().endsWith( '/assessments/' + id + '/attempts' ) &&
		response.request().method() === 'POST'
	);
	await page.getByRole( 'button', { name: 'Start exam', exact: true } ).click();
	const state = await ( await started ).json();
	const second = await context.newPage();
	await second.goto( '/?pagename=ptq-e2e-ranking-public' );
	const body = {
		submission_id: await page.evaluate( () => crypto.randomUUID() ),
		deferred: true,
		answers: [ { question_id: state.questions[ 0 ].id, option: 'A' } ],
	};
	const route = '/wp-json/paper-to-quiz/v1/attempts/' + state.public_id + '/submit';
	const send = ( tab: Page ) => tab.evaluate( async ( input ) => {
		const response = await fetch( input.route, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( input.body ),
		} );
		return { status: response.status, data: await response.json() };
	}, { route, body } );
	const responses = await Promise.all( [ send( page ), send( second ) ] );
	responses.forEach( response => {
		expect( response.status ).toBe( 202 );
		expect( response.data.status ).toBe( 'processing' );
	} );
	runRankingFixture( 'process' );
	const final = await page.request.get(
		'/wp-json/paper-to-quiz/v1/attempts/' + state.public_id + '/result'
	);
	expect( final.ok() ).toBe( true );
	expect( ( await final.json() ).status ).toBe( 'submitted' );
	const duplicate = await send( second );
	expect( duplicate.status ).toBe( 200 );
	expect( duplicate.data.status ).toBe( 'submitted' );
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

		runRankingFixture( 'release' );

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
		const visitorRanking = visitor.waitForResponse( ( response ) =>
			response.url().includes( `/assessments/${ publicId }/ranking?` )
		);
		await visitor
			.getByRole( 'button', { name: 'Ranking', exact: true } )
			.focus();
		await visitor.keyboard.press( 'Enter' );
		expect( ( await visitorRanking ).ok() ).toBe( true );
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
			visitor.getByText( 'No results found.', { exact: true } )
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
