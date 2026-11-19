'use strict';
// Evidence for #2659: the board of a running instance seeded with six projects (seed.php), light and dark, at 1440, 1920 and
// 390, with bin/ui-check's axe report and phone floors, plus the project filter's width and the card corners' truncation.
// Also screenshots the mock's stress example (six projects) at 1920, which tools/screenshots.js does not take.
// Usage: bin/instance -- sh -c "bin/console doctrine:migrations:migrate -n -q && bin/console asset-map:compile -q && php .evidence/2659/seed.php && node .evidence/2659/screens.js"
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const { chromium } = require('playwright');
const axe = require.resolve('axe-core/axe.min.js');
const { measureFloors, report, failed } = require(path.join(root, 'bin', 'ui-check'));
const address = process.env.INTAKT_INSTANCE_URL.replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const SIZES = [{ width: 1440, height: 900 }, { width: 1920, height: 1080 }, { width: 390, height: 844 }];

(async () => {
  const browser = await chromium.launch({ env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR }, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
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
      const seen = await page.evaluate(() => {
        const filter = document.querySelector('#projfilter');
        const shown = filter && filter.offsetParent !== null;
        const bar = document.querySelector('.topbar, header');
        const names = [...document.querySelectorAll('.card .pname')].filter((n) => n.offsetParent !== null);
        const clipped = names.filter((n) => n.scrollWidth > n.clientWidth);
        const projects = new Set([...document.querySelectorAll('[data-project]')].map((e) => e.dataset.project));
        return `filter ${shown ? `${filter.options.length - 1} projects, ${Math.round(filter.getBoundingClientRect().width)} px of a ${Math.round(bar.getBoundingClientRect().width)} px bar` : 'not shown'}, `
          + `projects on the page ${projects.size}, card project names ${names.length}, cut with an ellipsis ${clipped.length}${clipped.length ? ` (${[...new Set(clipped.map((n) => `"${n.textContent.trim()}" title "${n.getAttribute('title') ?? ''}"`))].join(', ')})` : ''}, `
          + `banners ${document.querySelectorAll('.banner[data-project]').length}`;
      });
      await page.addScriptTag({ path: axe });
      const violations = await page.evaluate(async () => (await axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(' ')) })));
      const floors = size.width === 390 ? await page.evaluate(measureFloors, { text: 15, target: 44 }) : [];
      console.log(`board (six projects) ${theme} ${size.width}: ${seen}`);
      await page.screenshot({ path: path.join(__dirname, `board-six-${theme}-${size.width}.png`), fullPage: size.width !== 390 });
      runs.push({ page: 'board (six projects)', theme, width: size.width, errors, violations, floors });
      await context.close();
    }
    for (const theme of ['light', 'dark']) {
      const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, colorScheme: theme });
      const page = await context.newPage();
      await page.goto('file://' + path.join(root, 'docs', 'design', 'mocks', 'index.html') + '?demo=stress', { waitUntil: 'load' });
      await page.screenshot({ path: path.join(__dirname, `mock-stress-${theme}-1920.png`), fullPage: true });
      await context.close();
    }
  } finally {
    await browser.close();
  }
  console.log(report(runs));
  process.exitCode = failed(runs) ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
