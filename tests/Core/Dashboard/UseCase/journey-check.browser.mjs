// The journey check's runner in a headless browser, with a trivial journey that only these tests use: the first real
// journey arrives with part 2 of #3677. The pages are answered by the browser's own routing, so no server is started.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';

const require = createRequire(import.meta.url);
const { runJourney, open, press, see, report, exitCode } = require('../../../../bin/journey-check');
const { chromium } = require('playwright');
const ORIGIN = 'http://localhost';
const PAGE = '<!doctype html><html lang="en"><head><title>Start</title></head><body><main><h1>Start</h1>'
    + '<button class="primary" onclick="document.querySelector(\'output\').textContent = \'Started\'">Start review</button>'
    + '<output></output></main></body></html>';
let browser;

before(async () => {
    browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
});

after(async () => {
    await browser?.close();
});

// The runner opens its own context per journey, so the routing goes onto each context it opens.
function routedBrowser() {
    return {
        newContext: async () => {
            const context = await browser.newContext();
            await context.route(`${ORIGIN}/**`, (route) => route.fulfill({ contentType: 'text/html', body: PAGE }));

            return context;
        },
    };
}

function scratch() {
    return mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'journey-check-'));
}

test('a journey whose steps all pass is reported as passed and the run exits 0', async () => {
    const journey = { name: 'Start a review', steps: [open('/'), press('button', 'Start review'), see('Started')] };

    const result = await runJourney(routedBrowser(), ORIGIN, journey, scratch());

    assert.equal(report([result], '/'), 'passed Start a review\nJourney check: 1 journeys, 1 passed, 0 failed.');
    assert.equal(exitCode([result]), 0);
});

test('a journey whose button was renamed fails at that step with a screenshot and exit 1', async () => {
    const folder = scratch();
    const journey = { name: 'Start a review', steps: [open('/'), press('button', 'Start'), see('Started')] };

    const result = await runJourney(routedBrowser(), ORIGIN, journey, folder);

    assert.equal(result.failure.step, 'press the button "Start"');
    assert.equal(result.failure.screenshot, join(folder, 'start-a-review.png'));
    assert.ok(existsSync(result.failure.screenshot));
    assert.equal(exitCode([result]), 1);
});

test('each journey starts from a fresh page without the storage and cookies of the one before', async () => {
    const leaveTraces = { name: 'leave traces', run: (page) => page.evaluate(() => { localStorage.setItem('seen', 'yes'); document.cookie = 'seen=yes'; }) };
    const findNoTraces = {
        name: 'find no traces',
        run: async (page) => {
            const traces = await page.evaluate(() => [localStorage.getItem('seen'), document.cookie]);
            if (traces[0] !== null || traces[1] !== '') throw new Error('the page still holds ' + JSON.stringify(traces));
        },
    };

    const routed = routedBrowser();

    const first = await runJourney(routed, ORIGIN, { name: 'first', steps: [open('/'), leaveTraces] }, scratch());
    const second = await runJourney(routed, ORIGIN, { name: 'second', steps: [open('/'), findNoTraces] }, scratch());

    assert.equal(first.failure, null);
    assert.equal(second.failure, null);
});

test('seeing a text passes when a hidden copy comes first and a visible copy follows', async () => {
    const hiddenFirst = '<!doctype html><html lang="en"><head><title>T</title></head><body><p hidden>Started</p><p>Started</p></body></html>';
    const routed = { newContext: async () => {
        const context = await browser.newContext();
        await context.route(`${ORIGIN}/**`, (route) => route.fulfill({ contentType: 'text/html', body: hiddenFirst }));

        return context;
    } };

    const result = await runJourney(routed, ORIGIN, { name: 'hidden first', steps: [open('/'), see('Started')] }, scratch());

    assert.equal(result.failure, null);
});
