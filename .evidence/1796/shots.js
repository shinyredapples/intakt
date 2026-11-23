// Shoots the task mock and the built review page (main view and Full record) at the widths of screenshots.js, light and dark.
// Usage: node shots.js <chrome> <mocks dir> <dashboard address> <round> <out dir>
// Needs playwright and @sparticuz/chromium installed beside it, because the sandbox could not download Playwright's own browser.
// Same viewports, color schemes and full-page captures as docs/design/mocks/tools/screenshots.js (390 is a viewport capture there too).
const { chromium } = require('playwright');
const c = require('@sparticuz/chromium');
const path = require('path');
const fs = require('fs');

const [chrome, mocks, address, round, out] = process.argv.slice(2);
const SIZES = [{ w: 1440, h: 900 }, { w: 1920, h: 1080 }, { w: 390, h: 844 }];

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: chrome, args: (c.default || c).args.filter((a) => a !== '--single-process'), headless: true });
  for (const theme of ['light', 'dark']) {
    for (const s of SIZES) {
      const targets = [
        ['mock', 'file://' + path.join(mocks, 'task.html')],
        ['built', address + '/reviews/' + round],
        ['built-record', address + '/reviews/' + round + '/record'],
      ];
      for (const [name, url] of targets) {
        const context = await browser.newContext({ viewport: { width: s.w, height: s.h }, colorScheme: theme });
        const page = await context.newPage();
        page.on('pageerror', (e) => console.log(name, theme, s.w, 'pageerror', e.message));
        await page.goto(url);
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(out, `${name}-${theme}-${s.w}.png`), fullPage: s.w !== 390 });
        await context.close();
      }
    }
  }
  await browser.close();
  console.log('done', out);
})().catch((e) => { console.log('ERR', e.message); process.exit(1); });
