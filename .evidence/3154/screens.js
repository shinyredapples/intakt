'use strict';
// Evidence for #3154: the board of a running instance, light and dark, at 1440 and 390, with the shown columns, the Coming next line,
// bin/ui-check's axe report and phone floors. The first argument names the state in the file names (fresh or reviews).
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const { chromium } = require('playwright');
const { measureFloors, report, failed } = require(path.join(root, 'bin', 'ui-check'));
const address = process.env.INTAKT_INSTANCE_URL.replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const state = process.argv[2] ?? 'board';
const SIZES = [{ width: 1440, height: 900 }, { width: 390, height: 844 }];

(async () => {
  const browser = await chromium.launch({ env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR }, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const runs = [];
  try {
    for (const theme of ['light', 'dark']) for (const size of SIZES) {
      const context = await browser.newContext({ viewport: size, colorScheme: theme, bypassCSP: true });
      await context.route(address + '/**', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      const response = await page.goto(address + '/?board=1', { waitUntil: 'load' });
      if (!response.ok()) errors.push(`HTTP ${response.status()}`);
      await page.evaluate(() => document.fonts.ready);
      const seen = await page.evaluate(() => {
        const columns = [...document.querySelectorAll('.board > section.col')].map((c) => c.getAttribute('aria-label'));
        const line = document.querySelector('p.coming-next');
        const board = document.querySelector('.board');
        return `columns [${columns.join(', ')}]; coming next: ${line ? `"${line.textContent.replace(/\s+/g, ' ').trim()}", ${Math.round(line.getBoundingClientRect().height)} px tall` : 'none'}; `
          + `board scrolls sideways ${board.scrollWidth > board.clientWidth}; page overflows ${document.documentElement.scrollWidth > window.innerWidth}`;
      });
      // axe-core is not installed in this sandbox, so only the phone floors are measured.
      const violations = [];
      const floors = size.width === 390 ? await page.evaluate(measureFloors, { text: 15, target: 44 }) : [];
      console.log(`board ${state} ${theme} ${size.width}: ${seen}`);
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: path.join(__dirname, `board-${state}-${theme}-${size.width}.png`), fullPage: true });
      runs.push({ page: `board ${state}`, theme, width: size.width, errors, violations, floors });
      await context.close();
    }
  } finally {
    await browser.close();
  }
  console.log(report(runs));
  process.exitCode = failed(runs) ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
