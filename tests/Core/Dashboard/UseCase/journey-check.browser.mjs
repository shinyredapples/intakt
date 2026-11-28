// The journey check's runner in a headless browser, with a trivial journey that only these tests use, and the first
// journey against stand-in pages. The pages are answered by the browser's own routing, so no server is started.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';

const require = createRequire(import.meta.url);
const { STEP_TIMEOUT_MS, VERDICT_TIMEOUT_MS, JOURNEYS, runJourney, open, press, see, seeRoleSince, report, exitCode } = require('../../../../bin/journey-check');
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

// Stand-ins for /setup, /demo and the review page, with what the first journey looks for: the start's button leads to /demo,
// which follows the run to its review a moment later, and the verdict and the finding arrive a moment after the review page
// loads, as the demo run's live update brings them.
const DEMO_THAT_FOLLOWS_LATER = '<h1>The demo run</h1><script>setTimeout(() => location.assign(\'/reviews/r1\'), 300);</script>';
// The order a quick queue worker forces: the run started before the demo page rendered, so the page leaves for the review with no timer, before anything on it can be looked at.
const DEMO_THAT_FOLLOWS_AT_ONCE = '<script>location.assign(\'/reviews/r1\');</script><h1>The demo run</h1>';

function demoSite({ playLabel = 'Play the demo run', playTarget = '/demo?play=p1', demo = DEMO_THAT_FOLLOWS_LATER, verdict = 'Changes needed', finding = 'to <code>amount &gt; 100</code>.', delayMs = 500 } = {}) {
    const html = (title, body) => `<!doctype html><html lang="en"><head><title>${title}</title></head><body><main>${body}</main></body></html>`;
    const arrive = `<h2>${verdict}</h2><a href="/reviews/r1/record#f1"><span>The check changed from <code>amount &gt;= 100</code> ${finding}</span> <span>src/discount.js:3</span></a>`;

    return {
        '/setup': html('Start', '<h1>See a real review</h1>'),
        '/setup?step=5': html('Start', `<h1>See a real review</h1><p>1 minute · no key</p><button onclick="location.assign('${playTarget}')">${playLabel}</button>`),
        '/demo': html('The demo run', demo),
        '/elsewhere': html('The demo run', DEMO_THAT_FOLLOWS_LATER),
        '/reviews/r1': html('Review', '<h1>Review of sample-change in sample</h1><section id="later"></section>'
            + `<script>setTimeout(() => { document.getElementById('later').innerHTML = ${JSON.stringify(arrive)}; }, ${delayMs});</script>`),
    };
}

// Every origin answers with the same pages, so a button that leads to another install finds a working demo there. A path's first answers may have other statuses, in order, before it answers 200.
function routedSite(pages, statuses = {}) {
    return {
        newContext: async () => {
            const context = await browser.newContext();
            await context.route('**/*', (route) => {
                const { pathname: path, search } = new URL(route.request().url());
                const body = pages[path + search] ?? pages[path];

                return body === undefined ? route.fulfill({ status: 404, body: 'Not found' }) : route.fulfill({ status: statuses[path]?.shift() ?? 200, contentType: 'text/html', body });
            });

            return context;
        },
    };
}

// Turbo 8.0.23's events around a render of the whole page, as the stand-ins use them: the answer on the element that asked for it (the root element for a visit, the form for a form) with the address it came from after redirects, then the render on the root element.
const TURBO = '<script>function turboVisit(url, init, askedOn, then) { fetch(url, init).then((answer) => { (askedOn ?? document.documentElement).dispatchEvent(new CustomEvent("turbo:before-fetch-response", { bubbles: true, detail: { fetchResponse: { statusCode: answer.status, response: { url: answer.url } } } })); return answer.text().then(() => { history.pushState({}, "", answer.url); document.body.innerHTML = "<main><h1>The demo run</h1></main>"; document.documentElement.dispatchEvent(new Event("turbo:render", { bubbles: true })); then(); }); }); }</script>';

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

test('a renamed Play the demo run button on the start page fails the first journey at the step that presses it, with a screenshot', async () => {
    const folder = scratch();

    const result = await runJourney(routedSite(demoSite({ playLabel: 'Play the demo' })), ORIGIN, FIRST_RUN, folder, 1);

    assert.deepEqual([result.failure.step, FIRST_RUN.steps[1].name], ['press the button "Play the demo run"', 'press the button "Play the demo run"']);
    assert.equal(result.failure.screenshot, join(folder, '01-first-run-without-a-key.png'));
    assert.ok(existsSync(result.failure.screenshot));
    assert.equal(exitCode([result]), 1);
});

test('a Play the demo run button that leads to another page than /demo fails the first journey at step 3', async () => {
    const result = await runJourney(routedSite(demoSite({ playTarget: '/elsewhere' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.deepEqual([result.failure.step, FIRST_RUN.steps[2].name], ['reach /demo and see the demo run', 'reach /demo and see the demo run']);
});

test('the first journey passes when the demo page follows to the review at once, before its heading can be seen', async () => {
    const result = await runJourney(routedSite(demoSite({ demo: DEMO_THAT_FOLLOWS_AT_ONCE })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(report([result], '/'), 'passed First run without a key\nJourney check: 1 journeys, 1 passed, 0 failed.');
});

test('the first journey passes when the button fetches the demo page and pushes its address, as Turbo does, and the page follows at once', async () => {
    const pages = demoSite();
    const fetchAndPush = 'turboVisit(\'/demo?play=p1\', { headers: { \'X-Turbo-Request-Id\': \'r\' } }, null, () => location.assign(\'/reviews/r1\'))';
    pages['/setup?step=5'] = `<!doctype html><html lang="en"><head><title>Start</title>${TURBO}</head><body><main><h1>See a real review</h1><button onclick="${fetchAndPush}">Play the demo run</button></main></body></html>`;

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure, null);
});

test('a Play the demo run button that leads to /demo on another origin fails the first journey at step 3', async () => {
    const result = await runJourney(routedSite(demoSite({ playTarget: 'http://other.test/demo?play=p1' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the press did not lead to /demo with a success status within 10 s; it led to http://other.test/demo (HTTP 200), then http://other.test/reviews/r1 (HTTP 200)');
});

test('a demo page without its heading that leaves at once for /elsewhere, which shows the recorded verdict and finding, fails the first journey at step 3', async () => {
    const pages = demoSite({ delayMs: 0 });
    pages['/demo'] = pages['/setup'].replace(/<main>.*<\/main>/, '<main><script>location.assign(\'/elsewhere\');</script></main>');
    pages['/elsewhere'] = pages['/reviews/r1'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/elsewhere, not for a review page on http://localhost');
});

test('a demo page that leaves at once for a review page on another origin fails the first journey at step 3', async () => {
    const result = await runJourney(routedSite(demoSite({ demo: '<script>location.assign(\'http://other.test/reviews/r1\');</script>' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://other.test/reviews/r1, not for a review page on http://localhost');
});

test('a demo page that leaves at once for a page below a review page fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/reviews/r1/record\');</script>' });
    pages['/reviews/r1/record'] = pages['/reviews/r1'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.error, 'the page left /demo for http://localhost/reviews/r1/record, not for a review page on http://localhost');
});

test('a demo page that leaves at once for a review path whose encoded slash the router reads as a page below the review fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/reviews/r1%2Frecord\');</script>' });
    pages['/reviews/r1%2Frecord'] = pages['/reviews/r1'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/reviews/r1%2Frecord, not for a review page on http://localhost');
});

test('a demo page that leaves at once for a review path whose encoding cannot be decoded fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/reviews/r1%E0%A4%A\');</script>' });
    pages['/reviews/r1%E0%A4%A'] = pages['/reviews/r1'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/reviews/r1%E0%A4%A, not for a review page on http://localhost');
});

test('the first journey passes when the demo page leaves at once for a review whose name holds a twice-encoded slash, which the router reads as one segment', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/reviews/r1%252Frecord\');</script>' });
    pages['/reviews/r1%252Frecord'] = pages['/reviews/r1'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure, null);
});

test('a demo page that went to /elsewhere and from there to a review page before the press returned fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/elsewhere\');</script>' });
    pages['/elsewhere'] = pages['/demo'].replace('/elsewhere', '/reviews/r1');
    // Forces the order: the press returns only once the page has reached the review page, so step 3 never sees /elsewhere as the page's own address.
    const pressAndSettle = { name: FIRST_RUN.steps[1].name, run: async (page) => {
        await FIRST_RUN.steps[1].run(page);
        await page.waitForURL(`${ORIGIN}/reviews/r1`);
    } };
    const journey = { name: FIRST_RUN.name, steps: [FIRST_RUN.steps[0], pressAndSettle, ...FIRST_RUN.steps.slice(2)] };

    const result = await runJourney(routedSite(pages), ORIGIN, journey, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/elsewhere, not for a review page on http://localhost');
});

test('a demo page that went to a review page and from there to /elsewhere before the press returned fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<script>location.assign(\'/reviews/r1\');</script>' });
    pages['/elsewhere'] = pages['/reviews/r1'];
    pages['/reviews/r1'] = '<!doctype html><html lang="en"><head><title>Review</title></head><body><script>location.assign(\'/elsewhere\');</script></body></html>';
    // Forces the order: the press returns only once the page has reached /elsewhere, so the review page is the first address after /demo in the trail.
    const pressAndSettle = { name: FIRST_RUN.steps[1].name, run: async (page) => {
        await FIRST_RUN.steps[1].run(page);
        await page.waitForURL(`${ORIGIN}/elsewhere`);
    } };
    const journey = { name: FIRST_RUN.name, steps: [FIRST_RUN.steps[0], pressAndSettle, ...FIRST_RUN.steps.slice(2)] };

    const result = await runJourney(routedSite(pages), ORIGIN, journey, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/elsewhere, not for a review page on http://localhost');
});

// Forces the order: the review page leaves only once the step before has passed, so the wrong address can only be met by the step under test.
function leaveAfter(position, target = '/elsewhere') {
    const step = FIRST_RUN.steps[position];
    const stepThenLeave = { name: step.name, run: async (page, address) => {
        await step.run(page, address);
        await page.waitForURL(`${ORIGIN}/reviews/r1`);
        await page.evaluate((path) => { setTimeout(() => location.assign(path), 100); }, target);
        await page.waitForURL(ORIGIN + target);
    } };

    return { name: FIRST_RUN.name, steps: FIRST_RUN.steps.with(position, stepThenLeave) };
}

test('a review page that leaves for /elsewhere, the only page with the verdict and the finding, after step 3 passed fails the first journey at step 4', async () => {
    const pages = demoSite({ delayMs: 0 });
    pages['/elsewhere'] = pages['/reviews/r1'];
    pages['/reviews/r1'] = '<!doctype html><html lang="en"><head><title>Review</title></head><body><h1>Review of sample-change in sample</h1></body></html>';

    const result = await runJourney(routedSite(pages), ORIGIN, leaveAfter(2), scratch(), 1);

    assert.equal(result.failure.step, 'wait for the verdict of the demo run');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/elsewhere, not for a review page on http://localhost');
});

test('a review page that shows the verdict and then leaves for /elsewhere, which shows the finding, fails the first journey at step 5', async () => {
    const pages = demoSite({ delayMs: 0 });
    pages['/elsewhere'] = pages['/reviews/r1'];
    pages['/reviews/r1'] = '<!doctype html><html lang="en"><head><title>Review</title></head><body><h1>Review of sample-change in sample</h1><h2>Changes needed</h2></body></html>';

    const result = await runJourney(routedSite(pages), ORIGIN, leaveAfter(3), scratch(), 1);

    assert.equal(result.failure.step, 'see the recorded finding on the review page');
    assert.equal(result.failure.error, 'the page left /demo for http://localhost/elsewhere, not for a review page on http://localhost');
});

test('a review page that leaves for another round, which shows the verdict and the finding, after step 3 passed fails the first journey at step 4', async () => {
    const pages = demoSite({ delayMs: 0 });
    pages['/reviews/r2'] = pages['/reviews/r1'];
    pages['/reviews/r1'] = '<!doctype html><html lang="en"><head><title>Review</title></head><body><h1>Review of sample-change in sample</h1></body></html>';

    const result = await runJourney(routedSite(pages), ORIGIN, leaveAfter(2, '/reviews/r2'), scratch(), 1);

    assert.equal(result.failure.step, 'wait for the verdict of the demo run');
    assert.equal(result.failure.error, 'the page went from the review page http://localhost/reviews/r1 to the review page http://localhost/reviews/r2 of another round');
});

test('a demo page that shows the verdict and the finding itself and never follows to the review fails the first journey at step 4', async () => {
    const shown = '<h1>The demo run</h1><h2>Changes needed</h2><a href="/reviews/r1/record#f1">The check changed from <code>amount &gt;= 100</code> to <code>amount &gt; 100</code>.</a>';

    // The step's own wait for the review page is as long as its wait for the verdict, so it is cut short here.
    const verdict = seeRoleSince('/demo', 'heading', 'Changes needed', 1_000);
    const journey = { name: FIRST_RUN.name, steps: FIRST_RUN.steps.with(3, { name: FIRST_RUN.steps[3].name, run: verdict.run }) };

    const result = await runJourney(routedSite(demoSite({ demo: shown })), ORIGIN, journey, scratch(), 1);

    assert.equal(result.failure.step, 'wait for the verdict of the demo run');
    assert.equal(result.failure.error, 'the page stayed at http://localhost/demo, not on a review page');
});

test('a demo page that answers HTTP 500 and then fetches its own address with a success fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<h1>The demo run</h1><script>fetch(location.href).then(() => location.assign(\'/reviews/r1\'));</script>' });
    // Forces the order: the success is answered only after the page that failed to load has asked for it.
    const result = await runJourney(routedSite(pages, { '/demo': [500] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /it led to http:\/\/localhost\/demo \(HTTP 500\), then /);
});

test('the first journey passes when the button posts as Turbo does, the answer redirects to the demo page and the page follows', async () => {
    const server = createServer((request, response) => {
        if (request.method === 'POST') return response.writeHead(303, { location: '/demo?play=p1' }).end();

        return response.writeHead(200, { 'content-type': 'text/html' }).end(pages[request.url] ?? pages[request.url.split('?')[0]] ?? '');
    });
    const pages = demoSite();
    const post = 'event.preventDefault(); turboVisit(\'/demo\', { method: \'POST\', headers: { \'X-Turbo-Request-Id\': \'r\' } }, this, () => location.assign(\'/reviews/r1\'))';
    pages['/setup?step=5'] = `<!doctype html><html lang="en"><head><title>Start</title>${TURBO}</head><body><main><h1>See a real review</h1><form method="post" action="/demo" onsubmit="${post}"><button>Play the demo run</button></form></main></body></html>`;
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    try {
        const result = await runJourney(browser, `http://localhost:${server.address().port}`, FIRST_RUN, scratch(), 1);

        assert.equal(result.failure, null);
    } finally {
        server.closeAllConnections();
        server.close();
    }
});

test('a background fetch of the demo page that answers HTTP 404 before the navigation answers HTTP 200 leaves the first journey passing', async () => {
    const pages = demoSite();
    // Forces the order: the button navigates only once the fetch was answered.
    pages['/setup?step=5'] = pages['/setup?step=5'].replace('location.assign(\'/demo?play=p1\')', 'fetch(\'/demo?play=p1\').then(() => location.assign(\'/demo?play=p1\'))');

    const result = await runJourney(routedSite(pages, { '/demo': [404] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure, null);
});

test('a demo page that stays and does not show its heading fails the first journey at step 3', async () => {
    const result = await runJourney(routedSite(demoSite({ demo: '<h1>Something else</h1>' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /Timeout 10000ms exceeded/);
});

test('a verdict other than the recorded Changes needed fails the first journey at step 4', async () => {
    const result = await runJourney(routedSite(demoSite({ verdict: 'Approved', delayMs: 0 })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.deepEqual([result.failure.step, FIRST_RUN.steps[3].name], ['wait for the verdict of the demo run', 'wait for the verdict of the demo run']);
});

test('a finding link reading to amount > 100.50. fails the first journey at step 5', async () => {
    const result = await runJourney(routedSite(demoSite({ finding: 'to <code>amount &gt; 100.50</code>.' })), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.deepEqual([result.failure.step, FIRST_RUN.steps[4].name], ['see the recorded finding on the review page', 'see the recorded finding on the review page']);
});

test('a verdict heading reading Checked, but the outcome was not recorded ends the verdict wait and fails step 4 after a click\'s wait', async () => {
    const started = performance.now();

    const result = await runJourney(routedSite(demoSite({ verdict: 'Checked, but the outcome was not recorded', delayMs: 0 })), ORIGIN, FIRST_RUN, scratch(), 1);

    const elapsed = performance.now() - started;
    assert.equal(result.failure.step, 'wait for the verdict of the demo run');
    assert.match(result.failure.error, /Timeout 10000ms exceeded/);
    assert.ok(elapsed < 2 * STEP_TIMEOUT_MS && 2 * STEP_TIMEOUT_MS < VERDICT_TIMEOUT_MS, `the journey took ${Math.round(elapsed)} ms`);
});

test('a demo page that is missing fails the first journey at step 3', async () => {
    const pages = demoSite();
    delete pages['/demo'];

    const result = await runJourney(routedSite(pages), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the press did not lead to /demo with a success status within 10 s; it led to http://localhost/demo (HTTP 404)');
});

test('a demo page that answers HTTP 500 and then navigates to its own address again, answered HTTP 204, fails the first journey at step 3', async () => {
    // A 204 leaves the failed document in place, so its status must not stand for the page.
    const pages = demoSite({ demo: '<h1>The demo run</h1><script>setTimeout(() => location.assign(location.href), 50);</script>' });

    const result = await runJourney(routedSite(pages, { '/demo': [500, 204] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /it led to http:\/\/localhost\/demo \(HTTP 500\)/);
});

test('the wait for the review page and the look on it share one deadline, so a verdict later than the step\'s time fails', async () => {
    // Either wait alone fits in the step's 1500 ms; both together do not.
    const pages = demoSite({ demo: '<h1>The demo run</h1><script>setTimeout(() => location.assign(\'/reviews/r1\'), 1000);</script>', delayMs: 1000 });
    const journey = { name: 'Shared deadline', steps: [open('/setup?step=5'), press('button', 'Play the demo run'), seeRoleSince('/demo', 'heading', 'Changes needed', 1500)] };

    const result = await runJourney(routedSite(pages), ORIGIN, journey, scratch(), 1);

    assert.equal(result.failure.step, 'see the heading "Changes needed" on the review page reached from /demo');
    assert.match(result.failure.error, /Timeout \d+ms exceeded/);
});

test('a last setup step that redirects to another step showing the same heading and button fails the first journey at step 1', async () => {
    const pages = demoSite();
    const server = createServer((request, response) => {
        if (request.url === '/setup?step=5') return response.writeHead(302, { location: '/setup?step=1' }).end();

        return response.writeHead(200, { 'content-type': 'text/html' }).end(request.url === '/setup?step=1' ? pages['/setup?step=5'] : '');
    });
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    try {
        const address = `http://localhost:${server.address().port}`;
        const result = await runJourney(browser, address, FIRST_RUN, scratch(), 1);

        assert.equal(result.failure.step, 'open the last setup step and see the start');
        assert.equal(result.failure.error, `/setup?step=5 led to ${address}/setup`);
    } finally {
        server.closeAllConnections();
        server.close();
    }
});

test('a visit of /demo cancelled before any answer arrived leaves the earlier HTTP 500 and fails the first journey at step 3', async () => {
    const pages = demoSite({ demo: '<h1>The demo run</h1><script>setTimeout(() => { location.assign(location.href); setTimeout(() => window.stop(), 200); }, 50);</script>' });
    const site = routedSite(pages, { '/demo': [500] });
    let demoVisits = 0;
    const result = await runJourney({
        newContext: async () => {
            const context = await site.newContext();
            // The second visit of /demo is never answered, so only the cancel ends it.
            await context.route((url) => url.pathname === '/demo', (route) => (++demoVisits === 1 ? route.fallback() : undefined));

            return context;
        },
    }, ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.equal(result.failure.error, 'the press did not lead to /demo with a success status within 10 s; it led to http://localhost/demo (HTTP 500)');
});

test('the first journey passes when /demo answers HTTP 200 and follows to the review before its body has finished', async () => {
    const pages = demoSite();
    const server = createServer((request, response) => {
        const [path] = request.url.split('?');
        if (path !== '/demo') return response.writeHead(200, { 'content-type': 'text/html' }).end(pages[request.url] ?? pages[path] ?? '');
        response.writeHead(200, { 'content-type': 'text/html' });
        // The rest of the body would come later; the page has left before it does.
        response.write('<!doctype html><html lang="en"><head><title>The demo run</title></head><body><main><h1>The demo run</h1><script>location.assign(\'/reviews/r1\');</script>' + ' '.repeat(4096));
        setTimeout(() => response.end('</main></body></html>'), 5000).unref();
    });
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    try {
        const result = await runJourney(browser, `http://localhost:${server.address().port}`, FIRST_RUN, scratch(), 1);

        assert.equal(result.failure, null);
    } finally {
        server.closeAllConnections();
        server.close();
    }
});

test('a /demo document that answered HTTP 500 and a Turbo fetch of /demo answered HTTP 200 that nothing renders fails the first journey at step 3', async () => {
    const turboFetch = 'fetch(location.href, { headers: { \'X-Turbo-Request-Id\': \'r\' } }).then((answer) => { document.documentElement.dispatchEvent(new CustomEvent(\'turbo:before-fetch-response\', { bubbles: true, detail: { fetchResponse: { statusCode: answer.status, response: { url: answer.url } } } })); return answer.text(); }).then(() => location.assign(\'/reviews/r1\'));';
    const pages = demoSite({ demo: `<h1>The demo run</h1><script>${turboFetch}</script>` });

    const result = await runJourney(routedSite(pages, { '/demo': [500] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /it led to http:\/\/localhost\/demo \(HTTP 500\), then /);
});

// A page answer of the root element, as Turbo 8.0.23 dispatches it, and a render of the whole page without a fetch of its own.
const TURBO_EVENTS = '<script>function turboAnswer(code, url) { document.documentElement.dispatchEvent(new CustomEvent("turbo:before-fetch-response", { bubbles: true, detail: { fetchResponse: { statusCode: code, response: { url: new URL(url, location.href).href } } } })); }'
    + ' function turboRender() { document.documentElement.dispatchEvent(new Event("turbo:render", { bubbles: true })); }'
    + ' function turboRestore() { document.documentElement.dispatchEvent(new CustomEvent("turbo:visit", { bubbles: true, detail: { action: "restore", url: location.href } })); }</script>';

test('a /demo document answered HTTP 500, an unrendered Turbo answer of another address and a later render of /demo without a fetch fail the first journey at step 3', async () => {
    const pages = demoSite({ demo: `${TURBO_EVENTS}<h1>The demo run</h1><script>turboAnswer(200, '/elsewhere'); turboRender(); setTimeout(() => location.assign('/reviews/r1'), 100);</script>` });

    const result = await runJourney(routedSite(pages, { '/demo': [500] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /it led to http:\/\/localhost\/demo \(no answer seen\), then /);
});

test('a /demo document answered HTTP 500, a Turbo answer of /demo and a restoration of /demo from the cache fail the first journey at step 3', async () => {
    const pages = demoSite({ demo: `${TURBO_EVENTS}<h1>The demo run</h1><script>turboAnswer(200, location.href); turboRestore(); turboRender(); setTimeout(() => location.assign('/reviews/r1'), 100);</script>` });

    const result = await runJourney(routedSite(pages, { '/demo': [500] }), ORIGIN, FIRST_RUN, scratch(), 1);

    assert.equal(result.failure.step, 'reach /demo and see the demo run');
    assert.match(result.failure.error, /it led to http:\/\/localhost\/demo \(no answer seen\), then /);
});

test('a demo page that shows the verdict and the finding and pushes a review address without rendering it fails the first journey at step 4', async () => {
    const shown = '<h1>The demo run</h1><h2>Changes needed</h2><a href="/reviews/r1/record#f1">The check changed from <code>amount &gt;= 100</code> to <code>amount &gt; 100</code>.</a>'
        + '<script>setTimeout(() => history.pushState({}, \'\', \'/reviews/r1\'), 300);</script>';
    // The step's own wait for the review page is as long as its wait for the verdict, so it is cut short here.
    const verdict = seeRoleSince('/demo', 'heading', 'Changes needed', 1_000);
    const journey = { name: FIRST_RUN.name, steps: FIRST_RUN.steps.with(3, { name: FIRST_RUN.steps[3].name, run: verdict.run }) };

    const result = await runJourney(routedSite(demoSite({ demo: shown })), ORIGIN, journey, scratch(), 1);

    assert.equal(result.failure.step, 'wait for the verdict of the demo run');
    assert.equal(result.failure.error, 'the review page http://localhost/reviews/r1 was not shown with a success status within 1 s; the press led to http://localhost/demo (HTTP 200), then http://localhost/reviews/r1 (no answer seen)');
});

test('a last setup step that redirects to a page answered HTTP 404 fails the first journey at step 1 naming where it led', async () => {
    const server = createServer((request, response) => {
        if (request.url === '/setup?step=5') return response.writeHead(302, { location: '/gone' }).end();

        return response.writeHead(404, { 'content-type': 'text/html' }).end('Not found');
    });
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));
    try {
        const address = `http://localhost:${server.address().port}`;
        const result = await runJourney(browser, address, FIRST_RUN, scratch(), 1);

        assert.equal(result.failure.step, 'open the last setup step and see the start');
        assert.equal(result.failure.error, `/setup?step=5 led to ${address}/gone`);
    } finally {
        server.closeAllConnections();
        server.close();
    }
});
