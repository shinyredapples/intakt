'use strict';
// Evidence for #2410: axe on the empty-state pages against a running instance, light and dark, at 1440 and 390.
// Usage: bin/instance -- sh -c "bin/console doctrine:migrations:migrate -n -q && bin/console asset-map:compile -q && node .evidence/2410/axe-empty.js '' <label>"
// Playwright and axe-core are installed under $TMPDIR/node; CHROME_PATH names a Chromium. The dashboard's two importmap packages are read from assets/vendor.
const path = require('path');
const { chromium } = require(path.join(process.env.TMPDIR, 'node/node_modules/playwright'));
const axePath = path.join(process.env.TMPDIR, 'node/node_modules/axe-core/axe.min.js');
const address = (process.argv[2] || process.env.INTAKT_INSTANCE_URL).replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const PAGES = [['board', '/'], ['reviews', '/reviews'], ['setup', '/setup']];

(async () => {
  const axeVersion = require(path.join(process.env.TMPDIR, 'node/node_modules/axe-core/package.json')).version;
  // CHROME_TMPDIR, when set, is the browser's own temp folder: Chromium refuses a socket path over 107 characters.
  const browser = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR } });
  console.log(`axe-core ${axeVersion}, ${process.argv[3] || ''}`);
  let failing = 0;
  for (const [name, p] of PAGES) for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
    const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, colorScheme: theme, bypassCSP: true });
    // The dashboard answers only to Host localhost:7780, the instance listens on another port.
    await context.route('**/*', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
    const page = await context.newPage();
    const failed = [];
    page.on('response', (r) => { if (r.status() >= 400) failed.push(`${r.status()} ${new URL(r.url()).pathname}`); });
    page.on('requestfailed', (r) => failed.push(`failed ${new URL(r.url()).pathname} ${r.failure()?.errorText}`));
    const response = await page.goto(address + p, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    const shown = await page.evaluate(() => document.documentElement.dataset.theme);
    // Layout-shift entries reach only an observer (performance.getEntriesByType has none), so the observer sums them; buffered brings the shifts since navigation.
    // Each shift's moved elements are named, so a shift can be told apart from one the octopus would cause.
    const { cls, moved } = await page.evaluate(() => new Promise((r) => { let sum = 0; const moved = new Set(); const name = (n) => n ? `${n.nodeName.toLowerCase()}${n.id ? '#' + n.id : ''}${n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).join('.') : ''}` : 'gone'; const add = (e) => { if (e.hadRecentInput) return; sum += e.value; for (const s of e.sources || []) moved.add(name(s.node)); }; const o = new PerformanceObserver((list) => list.getEntries().forEach(add)); o.observe({ type: 'layout-shift', buffered: true }); setTimeout(() => { o.takeRecords().forEach(add); o.disconnect(); r({ cls: sum, moved: [...moved] }); }, 1000); }));
    // A control: a shift forced after the page settled must show, so a 0 above is a measurement and not a blind spot.
    const control = await page.evaluate(() => new Promise((r) => { let sum = 0; const o = new PerformanceObserver((list) => { for (const e of list.getEntries()) sum += e.value; }); o.observe({ type: 'layout-shift' }); const s = document.createElement('div'); s.style.height = '200px'; document.body.prepend(s); setTimeout(() => { s.remove(); setTimeout(() => { for (const e of o.takeRecords()) sum += e.value; o.disconnect(); r(sum); }, 300); }, 300); }));
    await page.addScriptTag({ path: axePath });
    const violations = await page.evaluate(async () => (await axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(' ')) })));
    const octopus = await page.$$eval('img[src*="octopus-"]', (imgs) => imgs.filter((i) => i.offsetParent !== null).map((i) => `${i.getAttribute('src').split('/').pop().replace(/-[0-9a-f]{6,}\./, '.')} ${Math.round(i.getBoundingClientRect().width)}x${Math.round(i.getBoundingClientRect().height)} alt="${i.getAttribute('alt')}" role=${i.getAttribute('role') ?? 'none'}`));
    const counts = ['critical', 'serious', 'moderate', 'minor'].map((impact) => `${impact} ${violations.filter((v) => v.impact === impact).length}`);
    const bad = violations.filter((v) => ['critical', 'serious'].includes(v.impact));
    failing += bad.length;
    console.log(`${name} ${theme} ${width}: HTTP ${response.status()}, theme ${shown}, axe ${counts.join(', ')}, layout shift ${cls.toFixed(3)} (forced control ${control.toFixed(3)}), octopus ${octopus.join('; ') || 'none'}`);
    if (moved.length) console.log(`  moved: ${moved.join(', ')}`);
    if (failed.length) console.log(`  failed requests: ${failed.join(', ')}`);
    for (const v of violations) console.log(`  ${v.impact} ${v.id}: ${v.nodes.join(' | ')}`);
    await context.close();
  }
  console.log(`serious or critical violations: ${failing}`);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
