// Shoots the built review page (main view and Full record) with the jobs docs/design/mocks/tools/screenshots.js runs for the task mock.
// The mock's own captures (task-<theme>-<width>.png) come from that generator, which lists the task page since #1796.
// Usage: CHROME_PATH=<chrome> node shots.js <dashboard address> <round> <out dir>
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const [address, round, out] = process.argv.slice(2);
// Same viewports and full-page settings as the generator's task jobs: desktop and wide full page, phone a viewport capture.
const SIZES = [{ w: 1440, h: 900, full: true }, { w: 1920, h: 1080, full: true }, { w: 2560, h: 1440, full: true }, { w: 390, h: 844, full: false }];

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
  for (const theme of ['light', 'dark']) {
    for (const s of SIZES) {
      for (const [name, url] of [['built', '/reviews/' + round], ['built-record', '/reviews/' + round + '/record']]) {
        const context = await browser.newContext({ viewport: { width: s.w, height: s.h }, colorScheme: theme });
        const page = await context.newPage();
        page.on('pageerror', (e) => console.log(name, theme, s.w, 'pageerror', e.message));
        await page.goto(address + url);
        await page.waitForLoadState('load');
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(out, `${name}-${theme}-${s.w}.png`), fullPage: s.full });
        await context.close();
      }
    }
  }
  await browser.close();
  console.log('done', out);
})().catch((e) => { console.log('ERR', e.message); process.exit(1); });
