import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI
		? [ [ 'line' ], [ 'html', { open: 'never' } ] ]
		: 'line',
	use: {
		baseURL: process.env.PTQ_BASE_URL || 'http://localhost:8888',
		browserName: 'chromium',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
