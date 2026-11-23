// The first-run journey of bin/journey-check walked in a headless browser over a small stand-in of /setup, /demo and a review page,
// once as the dashboard renders them and once with the Play button renamed, because only a real browser shows that a renamed
// button fails the right journey at the right step. The real pages are walked by bin/journey-check against a running install.
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
function pages(playLabel) {
    return {
        '/setup': html('Start', `<section aria-labelledby="see-review-h"><h1 id="see-review-h">See a real review</h1><form method="post" action="/demo"><button type="submit">${playLabel}</button></form></section>`),
        '/demo': html('The demo run', `<h1>The demo run</h1><script>setTimeout(() => location.assign('/reviews/round-1'), 200);</script>`),
        '/reviews/round-1': html('Review', `<h1>Review of sample-change</h1><aside aria-label="Demo tour">Step 5 of 5</aside><div id="verdict"></div>
            <section aria-label="To fix"><section aria-labelledby="urgent"><h2 id="urgent">Urgent 1</h2><ul><li><a href="/reviews/round-1/record">The check changed.</a></li></ul></section></section>
            <script>setTimeout(() => { document.getElementById('verdict').outerHTML = '<section aria-labelledby="verdict-title"><h2 id="verdict-title">Changes needed</h2></section>'; }, 300);</script>`),
    };
}

async function dashboard(playLabel) {
    const served = pages(playLabel);
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

test('the first-run journey passes over pages that render its controls', async () => {
    const site = await dashboard('Play the demo run');

    const result = await runJourney(browser, site.address, FIRST_RUN, folder);

    await site.close();
    assert.deepEqual(result, { journey: 'first run without a key', failure: null });
});

test('a renamed Play the demo run button fails the first-run journey at its step, with a screenshot', async () => {
    const site = await dashboard('Play the demo');

    const result = await runJourney(browser, site.address, FIRST_RUN, folder);

    await site.close();
    assert.equal(result.failure.number, 2);
    assert.equal(result.failure.step, 'Play the demo run under See a real review leads to /demo');
    assert.match(result.failure.error, /Play the demo run/);
    assert.ok(existsSync(join(folder, 'first-run-without-a-key-step-2.png')));
});
