'use strict';
// Evidence for #2731: the first-day board of a running instance with an empty ledger, light and dark, at 1440 and 390,
// with bin/ui-check's own axe report and phone floors (bin/ui-check itself needs a review round, which the first day has none of),
// and the mock's ?demo=firstday example at the same sizes beside it.
// Usage: bin/instance -- sh -c "bin/console doctrine:migrations:migrate -n -q && bin/console asset-map:compile -q && NODE_PATH=$TMPDIR/node/node_modules node .evidence/2731/firstday-check.js"
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const { chromium } = require('playwright');
const axe = require.resolve('axe-core/axe.min.js');
const { measureFloors, report, failed } = require(path.join(root, 'bin', 'ui-check'));
const address = (process.argv[2] || process.env.INTAKT_INSTANCE_URL).replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const OUT = __dirname;
const SIZES = [{ width: 1440, height: 900 }, { width: 390, height: 844 }];

(async () => {
  const browser = await chromium.launch({ env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR } });
  const runs = [];
  try {
    for (const theme of ['light', 'dark']) for (const size of SIZES) {
      const context = await browser.newContext({ viewport: size, colorScheme: theme, bypassCSP: true });
      // The dashboard answers only to Host localhost:7780, the instance listens on another port.
      await context.route(address + '/**', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      const response = await page.goto(address + '/', { waitUntil: 'load' });
      if (!response.ok()) errors.push(`HTTP ${response.status()}`);
      await page.evaluate(() => document.fonts.ready);
      const shown = await page.evaluate(() => document.documentElement.dataset.theme);
      if (shown !== theme) errors.push(`the page shows the ${shown} theme instead of ${theme}`);
      const panel = await page.$$eval('#board-octopus img', (imgs) => imgs.filter((i) => i.offsetParent !== null).map((i) => `${Math.round(i.getBoundingClientRect().height)} px high alt="${i.getAttribute('alt')}"`));
      const above = await page.evaluate(() => { const img = [...document.querySelectorAll('#board-octopus img')].find((i) => i.offsetParent !== null); const h = document.getElementById('board-octopus-h'); return img.getBoundingClientRect().bottom <= h.getBoundingClientRect().top; });
      await page.addScriptTag({ path: axe });
      const violations = await page.evaluate(async () => (await axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(' ')) })));
      const floors = size.width === 390 ? await page.evaluate(measureFloors, { text: 15, target: 44 }) : [];
      console.log(`board ${theme} ${size.width}: octopus ${panel.join(', ')}, above the panel heading: ${above}`);
      await page.screenshot({ path: path.join(OUT, `board-firstday-${theme}-${size.width}.png`), fullPage: true });
      runs.push({ page: 'board (first day)', theme, width: size.width, errors, violations, floors });
      await context.close();

      const mock = await browser.newContext({ viewport: size, colorScheme: theme });
      const mockPage = await mock.newPage();
      await mockPage.goto('file://' + path.join(root, 'docs/design/mocks/index.html') + '?demo=firstday&theme=' + theme, { waitUntil: 'load' });
      await mockPage.evaluate((t) => { document.documentElement.dataset.theme = t; }, theme);
      await mockPage.screenshot({ path: path.join(OUT, `mock-firstday-${theme}-${size.width}.png`), fullPage: true });
      await mock.close();
    }
  } finally {
    await browser.close();
  }
  console.log(report(runs));
  process.exitCode = failed(runs) ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
