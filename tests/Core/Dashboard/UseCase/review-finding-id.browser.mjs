// A finding id pushed to the review page's live summary stays on one line beside a long finding text in a narrow viewport:
// measured in a headless browser with the Turbo version importmap.php pins, because only a real layout shows whether "F" and "1" share a line.
// ReviewPageLiveUpdatesTest keeps the two files in review-finding-id/ equal to what the page renders (an empty live summary) and pushes.
// Usage: node --test tests/Core/Dashboard/UseCase/*.browser.mjs, after bin/console importmap:install.
// Needs Node.js 22 and Playwright (NODE_PATH names where it is installed); CHROME_PATH names a system Chrome or Chromium.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { after, before, test } from 'node:test';

const here = new URL('./', import.meta.url);
const turboFile = new URL('../../../../assets/vendor/@hotwired/turbo/turbo.index.js', import.meta.url);
const styles = ['tokens.css', 'base.css', 'components.css'].map((name) => readFileSync(new URL(`../../../../assets/styles/${name}`, import.meta.url), 'utf8')).join('\n');
const before_ = readFileSync(new URL('review-finding-id/before.html', here), 'utf8');
const streams = readFileSync(new URL('review-finding-id/streams.html', here), 'utf8');
const ORIGIN = 'http://localhost';

const { chromium } = createRequire(import.meta.url)('playwright');
let browser;

before(async () => {
    browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
});

after(async () => {
    await browser?.close();
});

async function pageWithSummary(width, css) {
    const page = await browser.newPage({ viewport: { width, height: 800 } });
    await page.route(`${ORIGIN}/**`, (route) => {
        if ('/turbo.js' === new URL(route.request().url()).pathname) {
            return route.fulfill({ contentType: 'text/javascript', body: readFileSync(turboFile, 'utf8') });
        }

        return route.fulfill({
            contentType: 'text/html',
            body: `<!doctype html><html lang="en"><head><title>Review</title><style>${css}</style><script type="module">import * as Turbo from '/turbo.js'; window.Turbo = Turbo;</script></head><body><main>${before_.replaceAll('ROUND', 'r')}</main></body></html>`,
        });
    });
    await page.goto(`${ORIGIN}/`);
    await page.waitForFunction(() => undefined !== window.Turbo);
    await page.evaluate((html) => window.Turbo.renderStreamMessage(html), streams.replaceAll('ROUND', 'r'));
    await page.waitForSelector('[data-highlight-finding="F1"]');

    return page;
}

async function linesOfTheId(page) {
    return page.evaluate(() => {
        const button = document.querySelector('[data-highlight-finding="F1"]');

        return { rects: button.getClientRects().length, height: button.getBoundingClientRect().height, lineHeight: parseFloat(getComputedStyle(button).lineHeight) || button.getBoundingClientRect().height };
    });
}

test('a pushed finding id stays on one line in a 320px viewport', async () => {
    const page = await pageWithSummary(320, styles);

    const id = await linesOfTheId(page);

    assert.equal(id.rects, 1);
    assert.ok(id.height < id.lineHeight * 1.5, `the id is ${id.height}px high for a ${id.lineHeight}px line`);
    await page.close();
});

test('a pushed finding id wraps without the nowrap rules, so the check above can fail', async () => {
    const page = await pageWithSummary(320, `${styles}\n.ls-findings .mono, .ls-findings .linkbtn { white-space: normal !important; flex: 0 1 auto !important; min-width: 0 !important; }\n.ls-findings .mono { width: 1ch; overflow-wrap: anywhere; }\n.ls-findings .linkbtn { width: 1ch; overflow-wrap: anywhere !important; }`);

    const id = await linesOfTheId(page);

    assert.ok(id.height >= id.lineHeight * 1.5, `the id is ${id.height}px high for a ${id.lineHeight}px line`);
    await page.close();
});
