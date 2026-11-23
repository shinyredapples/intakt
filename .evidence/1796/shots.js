// Shoots the built review page (main view and Full record) with the jobs docs/design/mocks/tools/screenshots.js runs for the task mock.
// The mock's own captures (task-<theme>-<width>[-log].png) come from that generator, which lists the task page since #1796.
// Each page is shot while it is open and live.php publishes scripted tool calls of a reviewer to it, so the live log holds its lines.
// Usage: CHROME_PATH=<chrome> node shots.js <dashboard address> <round> <reviewer> <out dir>
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const [address, round, reviewer, out] = process.argv.slice(2);
// Same viewports and full-page settings as the generator's task jobs: desktop and wide full page, phone a viewport capture,
// and the phone's Live log tab as a full page and scrolled to the tabs (the generator's "log" and "log-scrolled" variants).
const SIZES = [
  { w: 1440, h: 900, full: true },
  { w: 1920, h: 1080, full: true },
  { w: 2560, h: 1440, full: true },
  { w: 390, h: 844, full: false },
  { w: 390, h: 844, full: true, click: '.ttabs [data-pane="log"]', variant: '-log', only: 'built-record' },
  { w: 390, h: 844, full: false, click: '.ttabs [data-pane="log"]', scroll: '.ttabs', variant: '-log-scrolled', only: 'built-record' },
];

function publishToolCalls() {
  const said = execFileSync('php', [path.join(__dirname, 'live.php'), round, reviewer], { encoding: 'utf8' });
  const count = /published (\d+) tool calls/.exec(said);
  if (!count) throw new Error('live.php: ' + said);
  return Number(count[1]);
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
  for (const theme of ['light', 'dark']) {
    for (const s of SIZES) {
      for (const [name, url] of [['built', '/reviews/' + round], ['built-record', '/reviews/' + round + '/record']]) {
        if (s.only && s.only !== name) continue;
        const file = `${name}-${theme}-${s.w}${s.variant || ''}.png`;
        const context = await browser.newContext({ viewport: { width: s.w, height: s.h }, colorScheme: theme });
        const page = await context.newPage();
        page.on('pageerror', (e) => console.log(file, 'pageerror', e.message));
        // The page must listen on the hub before the calls go out, because an update published earlier never reaches it.
        const listening = page.waitForResponse((r) => r.url().includes('/.well-known/mercure') && r.status() === 200, { timeout: 15000 });
        await page.goto(address + url);
        await page.waitForLoadState('load');
        await page.evaluate(() => document.fonts.ready);
        await listening;
        const calls = publishToolCalls();
        if (name === 'built-record') {
          await page.waitForFunction(([id, n]) => document.querySelectorAll(`#reviewer-${id}-activity li`).length === n, [reviewer, calls], { timeout: 15000 });
        }
        if (s.click) await page.click(s.click);
        // As the generator scrolls: the element 16 px under the top of the viewport.
        if (s.scroll) await page.evaluate((selector) => window.scrollTo(0, document.querySelector(selector).getBoundingClientRect().top + window.scrollY - 16), s.scroll);
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(out, file), fullPage: s.full });
        await context.close();
      }
    }
  }
  await browser.close();
  console.log('done', out);
})().catch((e) => { console.log('ERR', e.message); process.exit(1); });
