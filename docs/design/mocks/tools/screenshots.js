// Regenerates every screenshot of the mocks into screens/ and writes screens/INDEX.md.
// Names are stable: <page>-<theme>-<width>[-<variant>].png
// Usage (from docs/design/mocks): node tools/screenshots.js
// CHROME_PATH names a system Chrome or Chromium; without it, Playwright's own browser is used.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const MOCKS = path.resolve(__dirname, '..');
const OUT = path.join(MOCKS, 'screens');

// The Waiting on you, settings and components pages land with the next part of the mocks.
// Their jobs below stay; allJobs() drops them until their page is listed here.
const PAGES = {
  task: 'Task',
  index: 'Board',
  projects: 'Projects',
  project: 'Project acme-shop',
  'add-project': 'Add a project',
  health: 'Projects: health',
  journey: 'Project acme-shop: journey',
  team: 'Team and accounts',
  'add-account': 'Add an account',
  history: 'History: ledger',
  trends: 'History: trends',
  setup: 'Setup',
};

const DESKTOP = { w: 1440, h: 900 };
const WIDE = [{ w: 1920, h: 1080 }, { w: 2560, h: 1440 }];
const PHONE = { w: 390, h: 844 };
const BOARD_EXAMPLES = ['stress', 'reconnecting', 'firstday', 'loading'];
const STEPS = [2, 3, 4, 5];

function jobsFor(theme) {
  const jobs = [];
  const add = (page, size, extra = {}) => jobs.push({ page, theme, ...size, ...extra });

  for (const page of Object.keys(PAGES)) add(page, DESKTOP, { full: true });
  for (const page of ['index', 'waiting', 'task']) for (const size of WIDE) add(page, size, { full: true });
  for (const page of Object.keys(PAGES)) add(page, PHONE);

  for (const s of STEPS) {
    const step = { query: '?step=' + s, variant: 'step' + s, note: 'step ' + s };
    add('setup', DESKTOP, { full: true, ...step });
    add('setup', PHONE, step);
    add('add-project', DESKTOP, { full: true, ...step });
  }
  add('add-project', PHONE, { query: '?step=3', variant: 'step3', note: 'step 3' });

  add('index', DESKTOP, { search: 'gift', variant: 'search', note: 'search overlay, "gift"' });
  add('index', DESKTOP, { search: '', variant: 'search-empty', note: 'search overlay, empty query (Recent)' });
  add('index', DESKTOP, { search: 'zebra', variant: 'search-none', note: 'search overlay, no results' });
  add('index', PHONE, { search: 'gift', variant: 'search', note: 'search sheet, "gift"' });
  add('index', PHONE, { click: '.banner.broken .banner-toggle', variant: 'banner-open', note: 'CI banner expanded' });
  add('waiting', PHONE, { click: '#w-login .why summary', variant: 'why-open', note: 'first item, Why expanded' });
  add('index', DESKTOP, { full: true, click: '#working summary', variant: 'working-open', note: 'Who is working expanded' });
  add('trends', DESKTOP, { full: true, click: '#c-merges summary', variant: 'table-open', note: 'merges chart as table' });

  for (const demo of BOARD_EXAMPLES) {
    const example = { query: '?demo=' + demo, variant: demo, note: 'example: ' + demo };
    add('index', DESKTOP, { full: true, ...example });
    // The first day's empty columns sit below the fold on a phone, so its capture is the full page.
    add('index', PHONE, demo === 'firstday' ? { full: true, ...example } : example);
  }
  // A full page draws the fixed phone navigation over the column selector, so the first day's Waiting on you also gets a scrolled capture.
  add('index', PHONE, { query: '?demo=firstday', variant: 'firstday-waiting', note: 'example: firstday, scrolled to Waiting on you', scroll: '#colswitch' });

  return jobs;
}

function allJobs() {
  return [
    ...jobsFor('light'),
    ...jobsFor('dark'),
    { page: 'index', theme: 'light', ...PHONE, full: true, variant: 'full', note: 'full page' },
  ].filter((job) => job.page in PAGES);
}

function fileName(job) {
  return `${job.page}-${job.theme}-${job.w}${job.variant ? '-' + job.variant : ''}.png`;
}

function indexRow(job) {
  const shape = job.full ? 'full page' : `viewport ${job.w}x${job.h}`;
  return `| ${fileName(job)} | ${PAGES[job.page]} | ${job.theme} | ${job.w} | ${shape}${job.note ? ', ' + job.note : ''} |`;
}

async function shoot(browser, job) {
  const context = await browser.newContext({ viewport: { width: job.w, height: job.h }, colorScheme: job.theme });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('file://' + path.join(MOCKS, job.page + '.html') + (job.query || ''));
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
  if (job.click) {
    await page.click(job.click);
    await page.waitForTimeout(300);
  }
  if (job.scroll) {
    await page.evaluate((selector) => window.scrollTo(0, document.querySelector(selector).getBoundingClientRect().top + window.scrollY - 16), job.scroll);
    await page.waitForTimeout(300);
  }
  if (job.search !== undefined) {
    await page.keyboard.press('Control+k');
    if (job.search) await page.keyboard.type(job.search);
    await page.waitForTimeout(400);
  }
  await page.screenshot({ path: path.join(OUT, fileName(job)), fullPage: !!job.full });
  await context.close();

  if (errors.length) console.log(fileName(job), errors);
}

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  for (const file of fs.readdirSync(OUT)) if (file.endsWith('.png')) fs.unlinkSync(path.join(OUT, file));

  const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
  const jobs = allJobs();
  for (const job of jobs) await shoot(browser, job);
  await browser.close();

  const rows = jobs.map(indexRow).sort();
  fs.writeFileSync(
    path.join(OUT, 'INDEX.md'),
    '# Screenshots\n\nGenerated by `tools/screenshots.js`. Names: `<page>-<theme>-<width>[-<variant>].png`.\n\n' +
      '| File | Page | Theme | Width | Notes |\n|---|---|---|---|---|\n' + rows.join('\n') + '\n',
  );
  console.log(rows.length, 'screenshots in', OUT);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
