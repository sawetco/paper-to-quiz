'use strict';

const assert = require('node:assert/strict');
const {chromium} = require('@playwright/test');

(async () => {
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.setContent('<!doctype html><meta charset="utf-8"><p id="probe">Paper to Quiz: Çığ ŞİÖÜ</p>');
		await page.evaluate(() => document.fonts.ready);
		const probe = page.locator('#probe');
		assert.equal(await probe.innerText(), 'Paper to Quiz: Çığ ŞİÖÜ');
		const box = await probe.boundingBox();
		assert.ok(box && box.width > 0 && box.height > 0, 'Browser text must render.');
		assert.ok((await page.screenshot()).length > 100, 'Browser graphics must render.');
		console.log('Chromium native dependency, launch and render preflight passed: ' + browser.version());
	} finally {
		await browser.close();
	}
})().catch(error => {console.error(error); process.exitCode = 1;});
