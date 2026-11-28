// Keyboard-only walk of the D5 flow of #3836 (open History, filter by project, switch to Trends and back) at 1440 and 390,
// plus a Tab sweep of every control on History and Trends that checks each one shows a whole, unclipped focus ring.
// Only keyboard input drives the pages; page.evaluate only reads state. Usage: node keyboard-walk.js <address>
'use strict';
const { chromium } = require('playwright');

const address = (process.argv[2] ?? 'http://localhost:7780').replace(/\/+$/, '');
const lines = [];
let failures = 0;
const log = (ok, text) => { if (!ok) failures++; lines.push(`${ok ? 'ok  ' : 'FAIL'} ${text}`); };

// Reads the focused element: its description, whether it draws a focus ring and whether an overflow ancestor or the viewport cuts it.
function focusState() {
  const el = document.activeElement;
  if (!el || el === document.body) return { none: true };
  const style = getComputedStyle(el);
  const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
  // An SVG chart bar draws its focus as a stroke, since outline does not follow an SVG shape.
  const shadow = style.boxShadow !== 'none' || (el instanceof SVGElement && style.stroke !== 'none' && parseFloat(style.strokeWidth) > 0);
  const reach = outline ? Math.max(0, parseFloat(style.outlineWidth) + (parseFloat(style.outlineOffset) || 0)) : (shadow ? 2 : 0);
  const box = el.getBoundingClientRect();
  const ring = { top: box.top - reach, left: box.left - reach, bottom: box.bottom + reach, right: box.right + reach };
  let clippedBy = null;
  for (let node = el.parentElement; node && node !== document.documentElement; node = node.parentElement) {
    const s = getComputedStyle(node);
    if ([s.overflowX, s.overflowY].some((o) => o !== 'visible')) {
      const r = node.getBoundingClientRect();
      const inner = { top: r.top + node.clientTop, left: r.left + node.clientLeft, bottom: r.top + node.clientTop + node.clientHeight, right: r.left + node.clientLeft + node.clientWidth };
      if (ring.top < inner.top - 0.5 || ring.left < inner.left - 0.5 || ring.bottom > inner.bottom + 0.5 || ring.right > inner.right + 0.5) {
        clippedBy = node.tagName.toLowerCase() + (node.className && typeof node.className === 'string' ? '.' + node.className.split(' ')[0] : '');
        break;
      }
    }
  }
  if (!clippedBy && (ring.left < 0 || ring.right > innerWidth)) clippedBy = 'viewport';
  // A fixed bar drawn over the ring hides it as surely as a clip does.
  const cx = Math.min(Math.max(box.left + box.width / 2, 0), innerWidth - 1);
  const cy = Math.min(Math.max(box.top + box.height / 2, 0), innerHeight - 1);
  const top = document.elementFromPoint(cx, cy);
  // A scroll region taller than the screen can never show all four sides at once; its ring scrolls with it, so it is not judged covered.
  const covered = box.height < innerHeight / 2 && top &&!el.contains(top) && !top.contains(el) && !(el.labels && [...el.labels].some((l) => l.contains(top)));
  const name = (el.getAttribute('aria-label') || el.textContent || el.value || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  return {
    desc: `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.classList[0] ? '.' + el.classList[0] : ''} "${name}"`,
    ring: outline || shadow, clippedBy, covered: covered ? top.tagName.toLowerCase() + (top.className && typeof top.className === 'string' ? '.' + top.className.split(' ')[0] : '') : null,
  };
}

async function tabTo(page, test, label, max = 80) {
  for (let i = 1; i <= max; i++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(test)) {
      const state = await page.evaluate(focusState);
      const ringOk = state.ring && !state.clippedBy && !state.covered;
      log(ringOk, `${label}: reached with Tab x${i} (${state.desc}), focus ring ${state.ring ? 'shown' : 'MISSING'}${state.clippedBy ? ', clipped by ' + state.clippedBy : ''}${state.covered ? ', covered by ' + state.covered : ''}`);
      return true;
    }
  }
  log(false, `${label}: not reached in ${max} Tabs`);
  return false;
}

async function fresh(browser, width, height, path) {
  const context = await browser.newContext({ viewport: { width, height }, colorScheme: 'light' });
  const page = await context.newPage();
  await page.goto(address + path, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

const shownRows = () => [...document.querySelectorAll('.ledger tbody tr')].filter((r) => !r.hasAttribute('data-filtered-out') && !r.hidden && r.offsetParent !== null).length;
const heading = () => `${location.pathname}, current tab "${document.querySelector('.subtabs [aria-current="page"]')?.textContent.trim()}"`;

async function walk(browser, width, height) {
  const phone = width <= 760;
  lines.push('', `== D5 flow at ${width}x${height} (${phone ? 'phone' : 'desktop'}) ==`);
  const { context, page } = await fresh(browser, width, height, '/history');
  log(true, `open /history: ${await page.evaluate(heading)}, ${await page.evaluate(shownRows)} rows shown`);

  const picker = phone ? '[data-history-filter] select' : '#projfilter';
  await tabTo(page, phone ? () => document.activeElement?.matches('[data-history-filter] select') : () => document.activeElement?.id === 'projfilter', `project picker (${picker})`);
  const projects = await page.evaluate((sel) => [...document.querySelector(sel).options].map((o) => o.value), picker);
  await page.keyboard.press('ArrowDown');
  const picked = await page.evaluate((sel) => ({ value: document.querySelector(sel).value, shared: document.querySelector('#projfilter').value, focused: document.activeElement === document.querySelector(sel) }), picker);
  const rowsOfProject = await page.evaluate(shownRows);
  const projectRows = await page.evaluate((p) => [...document.querySelectorAll('.ledger tbody tr')].filter((r) => r.dataset.project === undefined || r.dataset.project === p).length, picked.value);
  log(picked.value === projects[1] && rowsOfProject === projectRows && picked.focused, `filter by project: ArrowDown picks "${picked.value}" (options ${JSON.stringify(projects)}), shared filter "${picked.shared}", ${rowsOfProject} rows shown (${projectRows} rows of that project or of none), focus stays on the picker`);
  await page.keyboard.press('ArrowUp');
  log(await page.evaluate((sel) => document.querySelector(sel).value === '', picker), `back to All projects: ArrowUp, ${await page.evaluate(shownRows)} rows shown`);

  await tabTo(page, () => document.activeElement?.matches('.subtabs a') && document.activeElement.textContent.trim() === 'Trends', 'Trends sub-tab');
  await Promise.all([page.waitForURL('**/trends'), page.keyboard.press('Enter')]);
  log(page.url().endsWith('/trends'), `switch to Trends: Enter -> ${await page.evaluate(heading)}`);
  await tabTo(page, () => document.activeElement?.matches('.subtabs a') && document.activeElement.textContent.trim() === 'Ledger', 'Ledger sub-tab');
  await Promise.all([page.waitForURL('**/history'), page.keyboard.press('Enter')]);
  log(page.url().endsWith('/history'), `back to the Ledger: Enter -> ${await page.evaluate(heading)}, ${await page.evaluate(shownRows)} rows shown`);

  // The toolbar's chips are buttons: Space and Enter both press them.
  await tabTo(page, () => document.activeElement?.matches('.fchip') && document.activeElement.textContent.trim() === 'By Intakt', 'chip "By Intakt"');
  await page.keyboard.press('Space');
  log(await page.evaluate(() => document.activeElement.getAttribute('aria-pressed') === 'true'), `chip "By Intakt": Space presses it, ${await page.evaluate(shownRows)} rows shown`);
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Enter');
  log(await page.evaluate(() => document.activeElement.textContent.trim() === 'Everything' && document.activeElement.getAttribute('aria-pressed') === 'true'), `chip "Everything": Shift+Tab x2, Enter presses it, ${await page.evaluate(shownRows)} rows shown`);
  await tabTo(page, () => document.activeElement?.matches('[data-history-filter] input'), 'text filter');
  await page.keyboard.type('verdict');
  log(true, `text filter: typing "verdict" leaves ${await page.evaluate(shownRows)} rows shown`);
  for (let i = 0; i < 7; i++) await page.keyboard.press('Backspace');
  log(true, `text filter cleared with Backspace: ${await page.evaluate(shownRows)} rows shown`);

  // Esc on what the flow can open: the search window and, on a phone, the More sheet.
  await page.keyboard.press('Shift+Tab');
  await tabTo(page, () => document.activeElement?.matches('.search-trigger'), 'search button', 120);
  await page.keyboard.press('Enter');
  const opened = await page.evaluate(() => !document.querySelector('#search').hidden);
  await page.keyboard.press('Escape');
  const after = await page.evaluate(() => ({ closed: document.querySelector('#search').hidden, back: document.activeElement?.matches('.search-trigger') }));
  log(opened && after.closed && after.back, `search window: Enter opens it (${opened}), Esc closes it (${after.closed}), focus back on the search button (${after.back})`);
  if (phone) {
    await tabTo(page, () => document.activeElement?.matches('[data-more-toggle]'), 'More button', 120);
    await page.keyboard.press('Enter');
    const sheetOpen = await page.evaluate(() => !document.querySelector('[data-more-sheet]').hidden);
    const firstItem = await page.evaluate(() => document.activeElement?.textContent.trim().replace(/\s+/g, ' '));
    await page.keyboard.press('Escape');
    const sheet = await page.evaluate(() => ({ closed: document.querySelector('[data-more-sheet]').hidden, back: document.activeElement?.matches('[data-more-toggle]') }));
    log(sheetOpen && sheet.closed && sheet.back, `More sheet: Enter opens it (${sheetOpen}, focus on "${firstItem}"), Esc closes it (${sheet.closed}), focus back on More (${sheet.back})`);
  }
  await context.close();
}

async function sweep(browser, width, height, path) {
  lines.push('', `== Tab sweep of ${path} at ${width}x${height}: every control, its focus ring ==`);
  const { context, page } = await fresh(browser, width, height, path);
  const seen = new Set();
  let count = 0;
  for (let i = 0; i < 200; i++) {
    await page.keyboard.press('Tab');
    const state = await page.evaluate(focusState);
    if (state.none) break;
    const key = state.desc + '@' + (await page.evaluate(() => [...document.querySelectorAll('*')].indexOf(document.activeElement)));
    if (seen.has(key)) break;
    seen.add(key);
    count++;
    const ok = state.ring && !state.clippedBy && !state.covered;
    if (!ok) log(false, `Tab ${count}: ${state.desc}, ring ${state.ring ? 'shown' : 'MISSING'}${state.clippedBy ? ', clipped by ' + state.clippedBy : ''}${state.covered ? ', covered by ' + state.covered : ''}`);
    else lines.push(`ok   Tab ${count}: ${state.desc}`);
  }
  const unreachable = await page.evaluate(() => [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary')]
    .filter((el) => el.offsetParent !== null && el.tabIndex < 0 && !el.closest('[inert],[hidden]')).map((el) => el.tagName.toLowerCase() + ' "' + el.textContent.trim().slice(0, 30) + '"'));
  log(unreachable.length === 0, `${count} controls reached with Tab; visible controls out of the tab order: ${unreachable.length ? unreachable.join(', ') : 'none'}`);
  await context.close();
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      await walk(browser, w, h);
      await sweep(browser, w, h, '/history');
      await sweep(browser, w, h, '/trends');
    }
  } finally {
    await browser.close();
  }
  lines.push('', `Keyboard walk: ${failures} failed steps.`);
  console.log(lines.join('\n'));
  process.exitCode = failures ? 1 : 0;
})().catch((error) => { console.log(lines.join('\n')); console.error(error); process.exitCode = 2; });
