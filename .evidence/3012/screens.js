'use strict';
// Evidence for #3012: /components on a running install, light and dark, at 1440, 1920 and 390, with bin/ui-check's axe
// report and phone floors, a keyboard walk that tabs to every bar and reads its tooltip, the hover and Escape order of
// decision 7, each table against its bars, and screenshots of #c-charts at 1440 and 390.
// Usage: NODE_PATH=<playwright and axe-core> [CHROME_PATH=<chromium>] node .evidence/3012/screens.js <address of the dashboard>
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const { chromium } = require('playwright');
const axe = require.resolve('axe-core/axe.min.js');
const { measureFloors, report, failed } = require(path.join(root, 'bin', 'ui-check'));
const address = (process.argv[2] ?? 'http://localhost:7780').replace(/\/+$/, '');
const SIZES = [{ width: 1440, height: 900 }, { width: 1920, height: 1080 }, { width: 390, height: 844 }];
const ARGS = (process.env.CHROME_ARGS ?? '').split(' ').filter(Boolean);

async function keyboardWalk(page) {
  const hits = await page.$$eval('#c-charts rect.c-hit', (all) => all.length);
  const seen = new Map();
  const wrong = [];
  for (let press = 0; press < 600 && seen.size < hits; press++) {
    await page.keyboard.press('Tab');
    const state = await page.evaluate(() => {
      const hit = document.activeElement;
      if (!hit || !hit.matches('#c-charts rect.c-hit')) return null;
      const chart = hit.closest('.chart');
      const tip = chart.querySelector('.chart-tip');
      return {
        id: chart.id + ' ' + hit.getAttribute('aria-label'),
        shown: !tip.hidden && tip.offsetParent !== null,
        text: tip.querySelector('strong').textContent + ' | ' + tip.querySelector('span').textContent,
        expected: hit.getAttribute('data-reading') + ' | ' + hit.getAttribute('data-label'),
      };
    });
    if (state === null) continue;
    seen.set(state.id, state);
    if (!state.shown || state.text !== state.expected) wrong.push(state);
  }

  return `keyboard reached ${seen.size} of ${hits} bars, ${seen.size - wrong.length} showed their own tooltip (value first)${wrong.length ? ': WRONG ' + JSON.stringify(wrong.slice(0, 3)) : ''}`;
}

async function tablesMatch(page) {
  return page.$$eval('#c-charts .chart', (charts) => charts.map((chart) => {
    const rows = [...chart.querySelectorAll('.c-table tbody tr')].map((row) => row.querySelector('th').textContent + ': ' + row.querySelector('td').textContent);
    const bars = [...chart.querySelectorAll('rect.c-hit')].map((hit) => hit.getAttribute('aria-label'));
    return `${chart.id}: ${rows.length} rows, ${JSON.stringify(rows) === JSON.stringify(bars) ? 'same as the bars' : 'DIFFERENT'}`;
  }).join('; '));
}

// Decision 7: pointer and focus held apart, Escape hides until the next trigger.
async function triggerOrder(page) {
  const tip = () => page.$eval('#chart-rounds-light .chart-tip', (t) => (t.hidden ? 'hidden' : t.textContent));
  const bar = page.locator('#chart-rounds-light rect.c-hit').nth(6);
  const steps = [];
  await bar.focus();
  steps.push('focus Nov 7: ' + await tip());
  await bar.hover();
  await page.mouse.move(0, 0);
  steps.push('pointer leaves, focus stays: ' + await tip());
  await bar.hover();
  await page.keyboard.press('Escape');
  steps.push('Escape: ' + await tip());
  await page.mouse.move(0, 0);
  steps.push('pointer leaves after Escape: ' + await tip());
  await page.locator('#chart-rounds-light rect.c-hit').nth(4).hover();
  steps.push('pointer on Nov 5: ' + await tip());
  await page.locator('#chart-rounds-light rect.c-hit').nth(4).focus();
  await page.evaluate(() => document.activeElement.blur());
  steps.push('blur while the pointer stays on Nov 5: ' + await tip());

  return steps.join('; ');
}

// The section shot unpins the sticky top bar and the phone's fixed bottom bar, which would otherwise lie over the charts.
async function shoot(page, theme, width) {
  await page.addStyleTag({ content: '.topbar { position: static !important; } @media (max-width: 760px) { .rail { display: none !important; } }' });
  await page.evaluate(() => { document.querySelector('#chart-findings-light .c-table').open = true; document.querySelector('#chart-findings-dark .c-table').open = true; });
  await page.mouse.move(0, 0);
  await page.locator(`#chart-rounds-${theme} rect.c-hit`).nth(6).focus();
  await page.locator('#c-charts').scrollIntoViewIfNeeded();
  await page.locator('#c-charts').screenshot({ path: path.join(__dirname, `charts-${theme}-${width}.png`) });
}

(async () => {
  const browser = await chromium.launch({ args: ARGS, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const runs = [];
  try {
    for (const theme of ['light', 'dark']) for (const size of SIZES) {
      const context = await browser.newContext({ viewport: size, colorScheme: theme, bypassCSP: true });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      const response = await page.goto(address + '/components', { waitUntil: 'load' });
      if (!response.ok()) errors.push(`HTTP ${response.status()}`);
      await page.evaluate(() => document.fonts.ready);
      const shown = await page.evaluate(() => document.documentElement.dataset.theme);
      if (shown !== theme) errors.push(`the page shows the ${shown} theme instead of ${theme}`);
      // The controllers come through the import map after load.
      await page.waitForLoadState('networkidle');
      await page.addScriptTag({ path: axe });
      const violations = await page.evaluate(async () => (await axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => n.target.join(' ')) })));
      const floors = size.width === 390 ? await page.evaluate(measureFloors, { text: 15, target: 44 }) : [];
      runs.push({ page: 'components', theme, width: size.width, errors, violations, floors });
      console.log(`components ${theme} ${size.width}: ${await tablesMatch(page)}`);
      console.log(`components ${theme} ${size.width}: ${await keyboardWalk(page)}`);
      if (size.width === 1440) console.log(`components ${theme} ${size.width}: ${await triggerOrder(page)}`);
      if (size.width !== 1920) await shoot(page, theme, size.width);
      await context.close();
    }
    console.log(report(runs));
    process.exitCode = failed(runs) ? 1 : 0;
  } finally {
    await browser.close();
  }
})();
