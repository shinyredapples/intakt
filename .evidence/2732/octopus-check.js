'use strict';
// Evidence for #2732: the board of a running instance in one state, light and dark, at 1440 and 390, with bin/ui-check's own
// axe report and phone floors. States: firstday (empty ledger), idle (after seed.php idle) and filter (after seed.php filter,
// with the idle project alpha chosen in the project filter while beta and gamma work) and components (the /components page,
// whose Characters section shows the asleep pose). A phone has no project filter, so the filter state's 390 runs load with
// alpha stored as the chosen project (key intakt-project) to show the phone ignores it and passes its floors, and an extra
// 761 px run, the narrowest width that has the filter, gets axe with alpha chosen. The selected-project state cannot be
// reached on a phone, so a forced 390 px run (page "board (filter, forced)") makes the page's phone query report no match:
// the scripts then apply the stored choice while the CSS still lays the page out for a phone, and the phone floors measure
// that state. buildonly (after seed.php buildonly)
// chooses delta, a project whose only work is a pushed build, at 1440.
// Usage: bin/instance -- sh -c "bin/console doctrine:migrations:migrate -n -q && bin/console asset-map:compile -q && node .evidence/2732/octopus-check.js firstday && php .evidence/2732/seed.php idle && node .evidence/2732/octopus-check.js idle && php .evidence/2732/seed.php filter && node .evidence/2732/octopus-check.js filter && php .evidence/2732/seed.php buildonly && node .evidence/2732/octopus-check.js buildonly && node .evidence/2732/octopus-check.js components"
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const { chromium } = require('playwright');
const axe = require.resolve('axe-core/axe.min.js');
const { measureFloors, report, failed } = require(path.join(root, 'bin', 'ui-check'));
const state = process.argv[2];
const address = process.env.INTAKT_INSTANCE_URL.replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const chosen = state === 'buildonly' ? 'delta' : 'alpha';
const SIZES = state === 'buildonly' ? [{ width: 1440, height: 900 }] : [{ width: 1440, height: 900 }, { width: 390, height: 844 }, ...(state === 'filter' ? [{ width: 761, height: 900 }, { width: 390, height: 844, forced: true }] : [])];
const route = state === 'components' ? '/components' : '/';
const name = state === 'components' ? 'components' : `board (${state})`;
const file = state === 'components' ? 'components' : `board-${state}`;

(async () => {
  // CHROME_TMPDIR gives Chromium a short folder when TMPDIR is too long for its socket path.
  const browser = await chromium.launch({ env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR }, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const runs = [];
  try {
    for (const theme of ['light', 'dark']) for (const size of SIZES) {
      const context = await browser.newContext({ viewport: size, colorScheme: theme, bypassCSP: true });
      // The dashboard answers only to Host localhost:7780, the instance listens on another port.
      await context.route(address + '/**', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
      if (state === 'filter' && size.width === 390) await context.addInitScript(() => localStorage.setItem('intakt-project', 'alpha'));
      if (size.forced) await context.addInitScript(() => {
        const real = window.matchMedia.bind(window);
        window.matchMedia = (query) => (query === '(max-width: 760px)' ? { ...real(query), matches: false, media: query, addEventListener() {}, removeEventListener() {} } : real(query));
      });
      const shown = size.forced ? 'board (filter, forced)' : name;
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      const response = await page.goto(address + route, { waitUntil: 'load' });
      if (!response.ok()) errors.push(`HTTP ${response.status()}`);
      await page.evaluate(() => document.fonts.ready);
      if ((state === 'filter' || state === 'buildonly') && await page.isVisible('#projfilter')) await page.selectOption('#projfilter', chosen);
      const seen = state === 'components'
        ? await page.evaluate(() => `poses shown ${[...document.querySelectorAll('img[src*="octopus-"]')].filter((i) => i.offsetParent !== null).map((i) => `${i.src.match(/octopus-[a-z]+-[a-z]+/)[0]} alt="${i.getAttribute('alt')}"`).join(', ')}`)
        : await page.evaluate(() => {
          const img = [...document.querySelectorAll('#board-octopus img')].find((i) => i.offsetParent !== null);
          const lead = document.querySelector('.page-head .lead').innerText.trim();
          const empties = [...document.querySelectorAll('.col-empty')].filter((l) => l.offsetParent !== null).length;
          const filter = document.querySelector('#projfilter');
          const stored = localStorage.getItem('intakt-project');
          return `headline "${lead}", filter ${filter && filter.offsetParent !== null ? `"${filter.value || 'All projects'}"` : 'not shown'}${stored ? ` (stored choice "${stored}")` : ''}, `
            + `octopus ${img ? `${img.src.match(/octopus-[a-z]+-[a-z]+/)[0]} ${Math.round(img.getBoundingClientRect().height)} px high alt="${img.getAttribute('alt')}"` : 'none'}, empty-column lines shown ${empties}`;
        });
      await page.addScriptTag({ path: axe });
      const violations = await page.evaluate(async () => (await axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(' ')) })));
      const floors = size.width === 390 ? await page.evaluate(measureFloors, { text: 15, target: 44 }) : [];
      const hiddenCards = size.forced ? await page.evaluate(() => `, filter value "${document.querySelector('#projfilter').value}", items of other projects hidden ${document.querySelectorAll('[data-project][hidden]').length}`) : '';
      console.log(`${shown} ${theme} ${size.width}: ${seen}${hiddenCards}`);
      await page.screenshot({ path: path.join(__dirname, `${file}${size.forced ? '-forced' : ''}-${theme}-${size.width}.png`), fullPage: true });
      runs.push({ page: shown, theme, width: size.width, errors, violations, floors });
      await context.close();
    }
  } finally {
    await browser.close();
  }
  console.log(report(runs));
  process.exitCode = failed(runs) ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
