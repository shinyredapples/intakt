// Shoots the History and Trends mocks the way docs/design/mocks/tools/screenshots.js does (same viewport sizes, colorScheme,
// fonts wait), adds the 1920x1080 size the generator only uses for other pages, and puts each mock capture beside the built
// page's capture from bin/ui-check (var/ui-check/<page>-<theme>-<width>.png) in one JPEG.
// Usage: node mock-pairs.js <worktree> <out folder>
'use strict';
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const [root, out] = process.argv.slice(2);
const MOCKS = path.join(root, 'docs/design/mocks');
const SIZES = [{ w: 1440, h: 900 }, { w: 1920, h: 1080 }, { w: 390, h: 844 }];
const PAGES = ['history', 'trends'];
const THEMES = ['light', 'dark'];

(async () => {
  fs.mkdirSync(path.join(out, 'mock'), { recursive: true });
  const browser = await chromium.launch();
  for (const page of PAGES) for (const theme of THEMES) for (const size of SIZES) {
    const name = `${page}-${theme}-${size.w}`;
    const context = await browser.newContext({ viewport: { width: size.w, height: size.h }, colorScheme: theme });
    const tab = await context.newPage();
    await tab.goto('file://' + path.join(MOCKS, page + '.html'));
    await tab.waitForLoadState('networkidle');
    await tab.evaluate(() => document.fonts.ready);
    const mock = path.join(out, 'mock', name + '.png');
    await tab.screenshot({ path: mock, fullPage: true });
    await context.close();

    const built = path.join(root, 'var/ui-check', name + '.png');
    const pair = await browser.newContext({ viewport: { width: 2 * size.w + 48, height: 400 }, deviceScaleFactor: size.w > 1000 ? 0.5 : 1 });
    const sheet = await pair.newPage();
    const img = (file) => 'data:image/png;base64,' + fs.readFileSync(file).toString('base64');
    await sheet.setContent(`<body style="margin:0;font:20px sans-serif;background:#888;display:flex;gap:16px;padding:8px;align-items:flex-start">
      <figure style="margin:0"><figcaption>mock ${name}</figcaption><img src="${img(mock)}" style="width:${size.w}px"></figure>
      <figure style="margin:0"><figcaption>built ${name}</figcaption><img src="${img(built)}" style="width:${size.w}px"></figure></body>`);
    await sheet.screenshot({ path: path.join(out, name + '.jpg'), fullPage: true, type: 'jpeg', quality: 70 });
    await pair.close();
    console.log('pair', name);
  }
  await browser.close();
})().catch((error) => { console.error(error); process.exitCode = 1; });
