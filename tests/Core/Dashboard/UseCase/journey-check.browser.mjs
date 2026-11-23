// The first-run journey of bin/journey-check walked in a headless browser over a small stand-in of /setup, /demo and a review page,
// once as the dashboard renders them and then with one thing broken each time, because only a real browser shows that a broken
// control or outcome fails the right journey at the right step. The real pages are walked by bin/journey-check against a running install.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, before, test } from 'node:test';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const { JOURNEYS, runJourney } = require('../../../../bin/journey-check');

const FIRST_RUN = JOURNEYS.find((journey) => journey.name === 'first run without a key');
const AS_RENDERED = {
    play: 'Play the demo run',
    verdict: 'Changes needed',
    finding: 'The check changed from <code>amount &gt;= 100</code> to <code>amount &gt; 100</code>.',
};
let browser;
let folder;

before(async () => {
    browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
    folder = mkdtempSync(join(process.env.TMPDIR ?? tmpdir(), 'journey-check-browser-'));
});

after(async () => {
    await browser?.close();
    rmSync(folder, { recursive: true, force: true });
});

function html(title, body) {
    return `<!doctype html><html lang="en"><head><title>${title}</title></head><body><main>${body}</main></body></html>`;
}

// The same roles and names the dashboard's templates give these controls; the review page's verdict arrives later, as a live update does.
function pages({ play, verdict, finding }) {
    return {
        '/setup': html('Start', `<section aria-labelledby="see-review-h"><h1 id="see-review-h">See a real review</h1><form method="post" action="/demo"><button type="submit">${play}</button></form></section>`),
        '/demo': html('The demo run', `<h1>The demo run</h1><script>setTimeout(() => location.assign('/reviews/round-1'), 200);</script>`),
        '/reviews/round-1': html('Review', `<h1>Review of sample-change</h1><aside aria-label="Demo tour">Step 5 of 5</aside><div id="verdict"></div>
            <section aria-label="To fix"><section aria-labelledby="urgent"><h2 id="urgent">Urgent 1</h2><ul><li><a href="/reviews/round-1/record"><span>${finding}</span> <span>src/discount.js:3</span></a></li></ul></section></section>
            <script>setTimeout(() => { document.getElementById('verdict').outerHTML = '<section aria-labelledby="verdict-title"><h2 id="verdict-title">${verdict}</h2></section>'; }, 300);</script>`),
    };
}

async function dashboard(changes = {}) {
    const served = pages({ ...AS_RENDERED, ...changes });
    const server = createServer((request, response) => {
        if (request.method === 'POST' && request.url === '/demo') {
            response.writeHead(303, { location: '/demo?play=0123' }).end();

            return;
        }
        const body = served[new URL(request.url, 'http://localhost').pathname];
        response.writeHead(body === undefined ? 404 : 200, { 'content-type': 'text/html' }).end(body ?? 'not found');
    });
    await new Promise((resolve) => server.listen(0, 'localhost', resolve));

    return { address: `http://localhost:${server.address().port}`, close: () => new Promise((resolve) => server.close(resolve)) };
}

async function walk(changes) {
    const site = await dashboard(changes);
    try {
        return await runJourney(browser, site.address, FIRST_RUN, folder);
    } finally {
        await site.close();
    }
}

test('the first-run journey passes over pages that render its controls', async () => {
    assert.deepEqual(await walk({}), { journey: 'first run without a key', failure: null });
});

test('a renamed Play the demo run button fails the first-run journey at its step, with a screenshot', async () => {
    const result = await walk({ play: 'Play the demo' });

    assert.equal(result.failure.number, 2);
    assert.equal(result.failure.step, 'Play the demo run under See a real review leads to /demo');
    assert.match(result.failure.error, /Play the demo run/);
    assert.ok(existsSync(join(folder, 'first-run-without-a-key-step-2.png')));
});

test('a renamed button that still contains Play the demo run fails the first-run journey at the same step', async () => {
    const result = await walk({ play: 'Start: Play the demo run' });

    assert.equal(result.failure.number, 2);
    assert.equal(result.failure.step, 'Play the demo run under See a real review leads to /demo');
});

test('a replay that ends as failed fails the first-run journey at the verdict step', async () => {
    const result = await walk({ verdict: 'Failed: every reviewer failed, so nobody checked this change' });

    assert.equal(result.failure.number, 4);
    assert.equal(result.failure.step, 'the run reaches its verdict, Changes needed');
});

test('a To fix list without the recorded finding fails the first-run journey at the findings step, whatever else it links to', async () => {
    const result = await walk({ finding: 'Open the full record' });

    assert.equal(result.failure.number, 5);
    assert.equal(result.failure.step, 'the review page shows the recorded finding to fix');
});
