// Scratch: keyboard-only walk-through of the review page (D5 of #1795), with axe in each opened state.
// Every control is reached and used with the keyboard only (Tab, Shift+Tab, Enter, Space, arrows, Home, End, Escape); a page load is the only other action.
// Usage: node walk.cjs <playwright node_modules> <axe node_modules> <chrome> <address> <round>
'use strict';
const [modules, axeModules, chrome, address, round] = process.argv.slice(2);
const { chromium } = require(modules + '/playwright');
const axePath = require.resolve(axeModules + '/axe-core/axe.min.js');

const focused = (page) => page.evaluate(() => {
  const el = document.activeElement;
  const style = getComputedStyle(el);
  const name = (el.getAttribute('aria-label') ?? el.textContent).trim().replace(/\s+/g, ' ').slice(0, 50);
  return {
    what: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.classList[0] ? '.' + el.classList[0] : '') + (el.getAttribute('role') ? '[role=' + el.getAttribute('role') + ']' : '') + ` "${name}"`,
    ring: el.matches(':focus-visible') && style.outlineStyle !== 'none' ? `${style.outlineWidth} ${style.outlineStyle}` : 'NONE',
  };
});

async function tabTo(page, test, limit = 60) {
  for (let presses = 1; presses <= limit; presses++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(test)) return presses;
  }
  throw new Error('not reached with Tab: ' + test);
}

async function axe(page, label) {
  await page.addScriptTag({ path: axePath });
  const found = await page.evaluate(async () => (await window.axe.run(document, { resultTypes: ['violations'] })).violations.map((v) => `${v.impact} ${v.id}`));
  const failing = found.filter((v) => /^(serious|critical)/.test(v));
  console.log(`    axe (${label}): ${failing.length} serious or critical` + (found.length ? ` [${found.join(', ')}]` : ''));
}

async function step(page, text) {
  const f = await focused(page);
  console.log(`  ${text}\n    focus: ${f.what}, ring: ${f.ring}`);
}

async function walk(browser, theme, width) {
  const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, colorScheme: theme });
  const page = await context.newPage();
  page.on('pageerror', (e) => console.log('    page error: ' + e.message));
  console.log(`\n== ${theme} ${width} ==`);
  await page.goto(`${address}/reviews/${round}`);

  console.log('1. Open the task: /reviews/<round 2> loaded; ' + await page.evaluate(() => document.title));
  console.log('2. Read the current state: ' + (await page.textContent('.state-block h2')).trim().replace(/\s+/g, ' '));
  let n = await tabTo(page, () => document.activeElement.matches('a.sb-finding'));
  await step(page, `3. Follow a finding: Tab x${n} reaches the first open finding of the Now block`);
  if (width === 390) {
    n = await tabTo(page, () => document.activeElement.id === 'timeline-tab');
    await page.keyboard.press('ArrowRight');
    console.log(`    (Tab x${n} to the tabs, Right: Live log chosen first, to show the finding brings the timeline back; pane ${await page.getAttribute('.task-grid', 'data-pane')})`);
    let back = 0;
    while (!(await page.evaluate(() => document.activeElement.matches('a.sb-finding'))) && back++ < 20) await page.keyboard.press('Shift+Tab');
    await step(page, `    Shift+Tab x${back} back to the finding`);
  }
  await page.keyboard.press('Enter');
  await page.waitForTimeout(400);
  console.log('    Enter: address ' + new URL(page.url()).hash + ', round 1 open: ' + await page.evaluate(() => document.querySelector('details.ev').open)
    + ', its card in view: ' + await page.evaluate(() => { const card = document.getElementById(location.hash.slice(1)); const box = card?.getBoundingClientRect(); return card ? `${card.tagName.toLowerCase()}.${card.className} at y=${Math.round(box.top)} of ${innerHeight}` : 'none'; })
    + (width === 390 ? ', pane shown: ' + await page.getAttribute('.task-grid', 'data-pane') : ''));
  await axe(page, 'round 1 opened by the finding');

  let up = 0;
  while (!(await page.evaluate(() => document.activeElement.matches('details.ev > summary'))) && up++ < 20) await page.keyboard.press('Shift+Tab');
  await step(page, `4. Fold and expand a round: Shift+Tab x${up} from the followed finding reaches the round heading`);
  await page.keyboard.press('Tab');
  await page.keyboard.press('Escape');
  await step(page, '    Escape inside the open round 1 folds it, open: ' + await page.evaluate(() => document.querySelector('details.ev').open));
  await page.keyboard.press('Enter');
  console.log('    Enter on its heading: open: ' + await page.evaluate(() => document.querySelector('details.ev').open));
  await page.keyboard.press(' ');
  console.log('    Space on its heading: open: ' + await page.evaluate(() => document.querySelector('details.ev').open));
  await page.keyboard.press('Enter');

  if (width === 390) {
    await page.goto(`${address}/reviews/${round}`);
    n = await tabTo(page, () => document.activeElement.id === 'timeline-tab');
    await step(page, `5. Switch to the live log: Tab x${n} reaches the chosen tab`);
    for (const key of ['ArrowRight', 'ArrowLeft', 'End', 'End', 'Home', 'Home', 'ArrowLeft', 'ArrowRight']) {
      await page.keyboard.press(key);
      const f = await focused(page);
      console.log(`    ${key}: focus ${f.what}, ring ${f.ring}, aria-selected ${await page.evaluate(() => document.activeElement.getAttribute('aria-selected'))}, pane ${await page.getAttribute('.task-grid', 'data-pane')}`);
    }
    await page.keyboard.press('Enter');
    console.log(`    Enter on the focused tab: aria-selected ${await page.evaluate(() => document.activeElement.getAttribute('aria-selected'))}, pane ${await page.getAttribute('.task-grid', 'data-pane')}`);
    await page.keyboard.press(' ');
    console.log(`    Space on the focused tab: aria-selected ${await page.evaluate(() => document.activeElement.getAttribute('aria-selected'))}, pane ${await page.getAttribute('.task-grid', 'data-pane')}`);
    await page.keyboard.press('Tab');
    const panel = await page.evaluate(() => { const p = document.activeElement; return `${p.id} role=${p.getAttribute('role')} labelledby=${p.getAttribute('aria-labelledby')}`; });
    await step(page, `    Tab: the chosen pane (${panel})`);
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('Tab');
    const timelinePanel = await page.evaluate(() => { const p = document.activeElement; return `${p.id} role=${p.getAttribute('role')} labelledby=${p.getAttribute('aria-labelledby')}`; });
    await step(page, `    Shift+Tab, Left (Live log), Tab: the chosen pane (${timelinePanel})`);
    await page.keyboard.press('Tab');
    await step(page, '    Tab again: the Live log pane holds no control, so focus leaves it (still inside the pane: ' + await page.evaluate(() => !!document.activeElement.closest('#live-log-pane')) + ')');
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('ArrowRight');
    await axe(page, 'Live log chosen');
  }

  await page.goto(`${address}/reviews/${round}`);
  n = await tabTo(page, () => document.activeElement.matches('.stop-round button'));
  await step(page, `6. Stop: Tab x${n} reaches Stop (class ${await page.evaluate(() => document.activeElement.className)})`);
  await page.keyboard.press('Enter');
  console.log('    Enter: label "' + (await page.textContent('.stop-round button')).trim() + '", still on ' + new URL(page.url()).pathname.slice(0, 9) + '...');
  await axe(page, 'Stop armed');
  await page.keyboard.press('Escape');
  await step(page, '    Escape: label "' + (await page.textContent('.stop-round button')).trim() + '"');
  await page.keyboard.press(' ');
  console.log('    Space: label "' + (await page.textContent('.stop-round button')).trim() + '"');
  await page.keyboard.press('Escape');
  if (theme === 'dark' && width === 390) {
    await page.keyboard.press('Enter');
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    console.log('    Enter, Enter: confirmed; the page says: ' + (await page.evaluate(() => [...document.querySelectorAll('.notice, .flash, [role=status], .banner')].filter((e) => e.checkVisibility()).map((e) => e.textContent.trim().replace(/\s+/g, ' ')).filter(Boolean).join(' | '))));
  }
  await context.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: chrome });
  try {
    for (const [theme, width] of [['light', 1440], ['light', 390], ['dark', 1920], ['dark', 390]]) await walk(browser, theme, width);
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
