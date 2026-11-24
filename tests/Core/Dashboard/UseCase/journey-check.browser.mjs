// The journey check's runner in a headless browser, with a trivial journey that only these tests use, and the first
// journey against stand-in pages. The pages are answered by the browser's own routing, so no server is started.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';

const require = createRequire(import.meta.url);
const { STEP_TIMEOUT_MS, VERDICT_TIMEOUT_MS, JOURNEYS, runJourney, open, press, see, report, exitCode } = require('../../../../bin/journey-check');
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

// Stand-ins for /setup, /demo and the review page, with what the first journey looks for; the verdict and the finding arrive
// a moment after the review page loads, as the demo run's live update brings them.
function demoSite({ playLabel = 'Play the demo run', verdict = 'Checked, but the outcome was not recorded', finding = 'to <code>amount &gt; 100</code>.', delayMs = 500 } = {}) {
    const html = (title, body) => `<!doctype html><html lang="en"><head><title>${title}</title></head><body><main>${body}</main></body></html>`;
    const arrive = `<h2>${verdict}</h2><a href="/reviews/r1/record#f1"><span>The check changed from <code>amount &gt;= 100</code> ${finding}</span> <span>src/discount.php:3</span></a>`;

    return {
        '/setup': html('Start', '<h1>See a real review</h1><p>1 minute · no key</p><button>Play the demo run</button>'),
        '/demo': html('The demo run', `<h1>The demo run</h1><button onclick="location.assign('/reviews/r1')">${playLabel}</button>`),
        '/reviews/r1': html('Review', '<h1>Review of sample-change in sample</h1><section id="later"></section>'
            + `<script>setTimeout(() => { document.getElementById('later').innerHTML = ${JSON.stringify(arrive)}; }, ${delayMs});</script>`),
    };
}

function routedSite(pages) {
    return {
        newContext: async () => {
            const context = await browser.newContext();
            await context.route(`${ORIGIN}/**`, (route) => {
                const body = pages[new URL(route.request().url()).pathname];

                return body === undefined ? route.fulfill({ status: 404, body: 'Not found' }) : route.fulfill({ contentType: 'text/html', body });
            });

            return context;
        },
    };
}

const [FIRST_RUN] = JOURNEYS;

function scratch() {
    return mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'journey-check-'));
}

test('a journey whose steps all pass is reported as passed and the run exits 0', async () => {
    const journey = { name: 'Start a review', steps: [open('/'), press('button', 'Start review'), see('Started')] };

    const result = await runJourney(routedBrowser(), ORIGIN, journey, scratch(), 1);

    assert.equal(report([result], '/'), 'passed Start a review\nJourney check: 1 journeys, 1 passed, 0 failed.');
    assert.equal(exitCode([result]), 0);
});

test('a journey whose button was renamed fails at that step with a screenshot and exit 1', async () => {
    const folder = scratch();
    const journey = { name: 'Start a review', steps: [open('/'), press('button', 'Start'), see('Started')] };

    const result = await runJourney(routedBrowser(), ORIGIN, journey, folder, 1);

    assert.equal(result.failure.step, 'press the button "Start"');
    assert.equal(result.failure.screenshot, join(folder, '01-start-a-review.png'));
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

    const first = await runJourney(routed, ORIGIN, { name: 'first', steps: [open('/'), leaveTraces] }, scratch(), 1);
    const second = await runJourney(routed, ORIGIN, { name: 'second', steps: [open('/'), findNoTraces] }, scratch(), 2);

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

    const result = await runJourney(routed, ORIGIN, { name: 'hidden first', steps: [open('/'), see('Started')] }, scratch(), 1);

    assert.equal(result.failure, null);
});

test('the first journey passes on pages that show the start, the demo, a verdict and the recorded finding', async () => {
    const result = await runJourney(routedSite(demoSite()), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(report([result], '/'), 'passed First run without a key\nJourney check: 1 journeys, 1 passed, 0 failed.');
});

test('the verdict wait outlasts a click, as the demo run plays for a while before its verdict', async () => {
    const result = await runJourney(routedSite(demoSite({ delayMs: STEP_TIMEOUT_MS + 2_000 })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure, null);
});

test('a renamed Play the demo run button fails the first journey at the step that presses it, with a screenshot', async () => {
    const folder = scratch();

    const result = await runJourney(routedSite(demoSite({ playLabel: 'Play the demo' })), ORIGIN, FIRST_RUN, folder, 1);

    assert.equal(result.failure.step, 'press the button "Play the demo run"');
    assert.equal(result.failure.screenshot, join(folder, '01-first-run-without-a-key.png'));
    assert.ok(existsSync(result.failure.screenshot));
    assert.equal(exitCode([result]), 1);
});

test('a finding link reading to amount > 100.50. fails the first journey at step 5', async () => {
    const result = await runJourney(routedSite(demoSite({ finding: 'to <code>amount &gt; 100.50</code>.' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.deepEqual([result.failure.step, FIRST_RUN.steps[4].name], ['see the recorded finding on the review page', 'see the recorded finding on the review page']);
});

test('a verdict heading reading Checked, but the outcome was not recorded ends the verdict wait within a click\'s wait', async () => {
    const started = performance.now();

    const result = await runJourney(routedSite(demoSite({ delayMs: 0 })), ORIGIN, FIRST_RUN, scratch(), 1);

    const elapsed = performance.now() - started;
    assert.equal(result.failure, null);
    assert.ok(elapsed < STEP_TIMEOUT_MS && STEP_TIMEOUT_MS < VERDICT_TIMEOUT_MS, `the journey took ${Math.round(elapsed)} ms`);
});

test('a demo page that answers 404 fails the first journey at its second step with the status', async () => {
    const pages = demoSite();
    delete pages['/demo'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.deepEqual([result.failure.step, result.failure.error], ['open /demo and see the demo run', 'HTTP 404 for /demo']);
});
