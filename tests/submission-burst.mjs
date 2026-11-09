import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';

const container = process.env.PTQ_TEST_CLI_CONTAINER ?? '';
assert.match(container, /^wp-env-paper-to-quiz-[a-f0-9]+-cli-1$/);
const fixture = 'wp-content/plugins/paper-to-quiz/tests/submission-burst.php';

function wp(...args) {
	const result = spawnSync('docker', ['exec', '-e', 'WP_ENVIRONMENT_TYPE=local', '-e', 'PAPER_TO_QUIZ_ALLOW_REGRESSION=1', container, 'wp', ...args], { encoding: 'utf8' });
	assert.equal(result.status, 0, result.stderr || result.error?.message);
	return result.stdout.trim();
}

const database = (mode) => JSON.parse(wp('eval-file', fixture, mode));

async function request(attempt, action, payload) {
	const started = performance.now();
	const response = await fetch(`http://127.0.0.1:8888/?rest_route=/paper-to-quiz/v1/attempts/${attempt.public_id}/${action}`, {
		method: payload ? 'POST' : 'GET',
		headers: { Authorization: `Bearer ${attempt.token}`, 'Content-Type': 'application/json' },
		body: payload ? JSON.stringify(payload) : undefined,
		signal: AbortSignal.timeout(45_000),
	});
	const data = await response.json();
	return { http: response.status, data, ms: performance.now() - started };
}

async function releaseBurst(exams) {
	const totals = database('fill-rankings').exams.map((exam) => Number(exam.ranked));
	assert.deepEqual(totals, [1015, 1012, 1035]);
	const reads = exams.flatMap(({ id, attempts }, index) => [
		...attempts.map(async (attempt) => {
			const { http, data, ms } = await request(attempt, 'result');
			assert.equal(http, 200);
			assert.equal(data.ranking.total, totals[index]);
			assert.equal(data.answers.length, index === 0 ? 40 : 50);
			return ms;
		}),
		...Array.from({ length: 60 }, async (_, i) => {
			const started = performance.now();
			const response = await fetch(`http://127.0.0.1:8888/?rest_route=/paper-to-quiz/v1/assessments/${id}/ranking&page=${i % 12 === 0 ? 2 : 1}`, { signal: AbortSignal.timeout(60_000) });
			assert.equal(response.status, 200);
			assert.match(response.headers.get('cache-control') ?? '', /private.*no-store/);
			const ranking = await response.json();
			assert.equal(ranking.participant_total, totals[index]);
			assert.equal(ranking.items.length, 20);
			assert(ranking.items.every((row, position) => position === 0 || row.rank >= ranking.items[position - 1].rank));
			return performance.now() - started;
		}),
	]);
	const timings = (await Promise.all(reads)).sort((a, b) => a - b);
	console.log(`Result release: ${timings.length} concurrent reads for ${totals.reduce((a, b) => a + b)} rankings, p95 ${Math.round(timings[Math.ceil(timings.length * .95) - 1])} ms`);
}

async function burst(deferred) {
	const { deadline, exams } = database('seed');
	const participants = exams.flatMap(({ questions, attempts }) => attempts.map((attempt) => ({
		attempt,
		payload: { deferred, automatic: true, submission_id: randomUUID(), answers: questions.map((id) => ({ question_id: id, option: 'A' })) },
	})));
	assert.equal(participants.length, 62);
	await delay(Math.max(0, deadline * 1_000 - Date.now()));
	const replies = await Promise.all(participants.map(({ attempt, payload }) => request(attempt, 'submit', payload)));
	const failed = replies.filter(({ http }) => http !== (deferred ? 202 : 200));
	assert.equal(failed.length, 0, JSON.stringify(failed.slice(0, 2)));
	const timings = replies.map(({ ms }) => Math.round(ms)).sort((a, b) => a - b);
	console.log(`${deferred ? 'Deferred receipt' : 'Synchronous grading'}: p50 ${timings[30]} ms, p95 ${timings[58]} ms, max ${timings.at(-1)} ms`);
	const { attempt, payload } = participants[0];
	if (deferred) {
		assert(replies.every(({ data }) => data.status === 'processing' && !Object.hasOwn(data, 'score')));
		const ungraded = database('state');
		assert.equal(ungraded.answers, 0);
		assert.deepEqual(ungraded.exams.map((row) => Number(row.pending)), [15, 12, 35]);
		assert(ungraded.exams.every((row) => Number(row.ranked) === 0));
		const duplicate = await request(attempt, 'submit', { ...payload, answers: [] });
		assert.equal(duplicate.http, 202);
		assert.equal(duplicate.data.received_at, replies[0].data.received_at);
		assert.equal((await request(attempt, 'result')).http, 202);
		await delay(Math.max(0, (deadline + 32) * 1_000 - Date.now()));
	}
	const result = database(deferred ? 'drain' : 'state');
	assert.equal(result.answers, 2950);
	assert.deepEqual(result.exams.map((row) => Number(row.completed)), [15, 12, 35]);
	assert.deepEqual(result.exams.map((row) => Number(row.ranked)), [15, 12, 35]);
	assert(result.exams.every((row) => Number(row.pending) === 0 && Number(row.receipt_times) === Number(row.total) && Number(row.on_time) === Number(row.total)));
	assert.equal(result.exams.reduce((sum, row) => sum + Number(row.score), 0), 590_000);
	const completed = await request(attempt, 'result');
	assert.equal(completed.http, 200);
	assert.equal(completed.data.score, 8000);
	assert.equal(completed.data.ranking.total, 15);
	if (deferred) await releaseBurst(exams);
	wp('eval-file', fixture, 'cleanup');
}

assert.match(wp('option', 'get', 'home'), /^http:\/\/(?:localhost|127\.0\.0\.1):8888\/?$/);
const existingCron = wp('eval', 'echo defined("DISABLE_WP_CRON") ? (DISABLE_WP_CRON ? "true" : "false") : "unset";');
wp('config', 'set', 'DISABLE_WP_CRON', 'true', '--raw');
try {
	await burst(false);
	await burst(true);
	console.log('PASS: three simultaneous deadlines, 62 receipts, delayed grading, retry idempotency and all rankings.');
} finally {
	wp('eval-file', fixture, 'cleanup');
	if (existingCron === 'unset') wp('config', 'delete', 'DISABLE_WP_CRON');
	else wp('config', 'set', 'DISABLE_WP_CRON', existingCron, '--raw');
}
