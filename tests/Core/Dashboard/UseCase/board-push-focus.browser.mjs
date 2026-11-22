// The board's first-day links keep the keyboard focus when an empty board is pushed to an empty board: checked in a headless
// browser with the Turbo version importmap.php pins, because only the real Turbo shows whether a morph keeps the focused node.
// BoardHeadlineLiveUpdatesTest keeps the two files in board-push-focus/ equal to what the board renders and pushes.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs, after bin/console importmap:install.
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { after, before, test } from 'node:test';

const here = new URL('./', import.meta.url);
const turboFile = new URL('../../../../assets/vendor/@hotwired/turbo/turbo.index.js', import.meta.url);
const head = readFileSync(new URL('board-push-focus/head.html', here), 'utf8');
const streams = readFileSync(new URL('board-push-focus/streams.html', here), 'utf8');
const ORIGIN = 'http://localhost';

const { chromium } = createRequire(import.meta.url)('playwright');
let browser;

before(async () => {
    browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
});

after(async () => {
    await browser?.close();
});

async function boardWithFocusOn(selector) {
    const page = await browser.newPage();
    await page.route(`${ORIGIN}/**`, (route) => {
        const path = new URL(route.request().url()).pathname;
        if ('/turbo.js' === path) {
            return route.fulfill({ contentType: 'text/javascript', body: readFileSync(turboFile, 'utf8') });
        }

        return route.fulfill({
            contentType: 'text/html',
            body: `<!doctype html><html lang="en"><head><title>Board</title><script type="module">import * as Turbo from '/turbo.js'; window.Turbo = Turbo;</script></head><body><main>${head}</main></body></html>`,
        });
    });
    await page.goto(`${ORIGIN}/`);
    await page.waitForFunction(() => undefined !== window.Turbo);
    await page.focus(selector);
    await page.evaluate((focused) => { window.focusedBefore = document.querySelector(focused); }, selector);

    return page;
}

async function pushed(page, message) {
    await page.evaluate((html) => window.Turbo.renderStreamMessage(html), message);
    await page.waitForFunction(() => 0 === document.querySelectorAll('turbo-stream').length);
}

async function focusStayedOn(page, selector) {
    return page.evaluate((focused) => {
        const now = document.querySelector(focused);

        return window.focusedBefore.isConnected && now === window.focusedBefore && document.activeElement === now;
    }, selector);
}

for (const [link, selector] of [['set up link in the head', '#board-setup'], ['first-day link in the headline', '#board-headline a']]) {
    test(`the ${link} keeps the keyboard focus when the empty board is pushed again`, async () => {
        const page = await boardWithFocusOn(selector);

        await pushed(page, streams);

        assert.equal(await focusStayedOn(page, selector), true);
        await page.close();
    });

    test(`the ${link} loses the keyboard focus to a push without a morph, so the check above can fail`, async () => {
        const page = await boardWithFocusOn(selector);

        await pushed(page, streams.replaceAll(' method="morph"', ''));

        assert.equal(await focusStayedOn(page, selector), false);
        await page.close();
    });
}
