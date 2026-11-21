'use strict';
// Evidence for #2543: on the board of a running instance with three lanes, whether each lane's role line stays inside its lane, and
// the layout shift of the lanes from the moment the Who is working strip opens until every lane image has loaded.
// Needs Playwright and @sparticuz/chromium installed under $TMPDIR/pw (the sandbox cannot download Playwright's own browser).
// With --old, the lane line rule from before #2543 is put back on the page, to show the overflow the change removes.
const path = require('path');
const lib = path.join(process.env.TMPDIR, 'pw', 'node_modules');
const { chromium } = require(path.join(lib, 'playwright'));
const address = process.env.INTAKT_INSTANCE_URL.replace(/\/+$/, '').replace('127.0.0.1', 'localhost');
const old = process.argv.includes('--old');
const WIDTHS = [390, 760, 761, 780, 800, 1024, 1440, 1700];
const OLD_RULE = '.lane { grid-template-columns: none; } .lane .meta span { white-space: nowrap; overflow-wrap: normal; } .lane .who { flex-wrap: nowrap; gap: var(--s8); min-width: auto; overflow-wrap: normal; }';

(async () => {
  const bundled = (await import(path.join(lib, '@sparticuz', 'chromium', 'build', 'index.js'))).default;
  const executablePath = await bundled.executablePath();
  let failures = 0;
  for (const theme of ['light', 'dark']) for (const width of WIDTHS) {
    // A browser per run, because the bundled build runs in one process and does not survive a closed context.
    const browser = await chromium.launch({ executablePath, args: bundled.args });
    try {
      const context = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: theme, bypassCSP: true });
      await context.route(address + '/**', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
      await context.route(/\.(png|webp|svg|avif)(\?.*)?$/, async (route) => { await new Promise((r) => setTimeout(r, 400)); await route.fallback(); });
      const page = await context.newPage();
      const response = await page.goto(address + '/', { waitUntil: 'load' });
      if (old) await page.addStyleTag({ content: OLD_RULE });
      await page.evaluate(() => document.fonts.ready);
      const opened = await page.evaluate(() => new Promise((resolve) => {
        const shifts = [];
        new PerformanceObserver((list) => shifts.push(...list.getEntries())).observe({ type: 'layout-shift' });
        const strip = document.querySelector('details.working');
        const snapshot = () => [...strip.querySelectorAll('.lane')].map((lane) => {
          const b = lane.getBoundingClientRect(); const w = lane.querySelector('.who').getBoundingClientRect();
          // The rendered line boxes of every text node, so the role, tier and start time moving inside a steady container still counts.
          const texts = [];
          const walker = document.createTreeWalker(lane, NodeFilter.SHOW_TEXT);
          for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            if (node.data.trim() === '') continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            texts.push(...[...range.getClientRects()].map((r) => [r.left, r.top, r.width, r.height]));
          }
          return [b.left, b.top, b.width, b.height, w.left, w.top, w.width, w.height, ...texts.flat()].map((n) => Math.round(n * 100) / 100).join(',');
        }).join('|');
        const openedAt = performance.now();
        strip.open = true;
        const first = snapshot();
        let moved = 0;
        const track = () => { if (snapshot() !== first) moved++; requestAnimationFrame(track); };
        requestAnimationFrame(track);
        // Only the theme's own image is shown; the other one is never fetched, because it is lazy and hidden.
        const images = [...strip.querySelectorAll('.lane img')].filter((img) => img.getClientRects().length > 0);
        const loaded = () => images.every((img) => img.complete && img.naturalWidth > 0);
        const settle = () => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(() => {
          const loadedAt = performance.now();
          const inLanes = (s) => s.sources.some((src) => src.node && src.node.nodeType === 1 ? src.node.closest('.lanes') : src.node && src.node.parentElement && src.node.parentElement.closest('.lanes'));
          const after = shifts.filter((s) => s.startTime >= openedAt);
          resolve({
            openedAt: Math.round(openedAt), loadedAt: Math.round(loadedAt), images: `${images.filter((img) => img.complete && img.naturalWidth > 0).length} of ${images.length}`,
            lanes: after.filter(inLanes).reduce((sum, s) => sum + s.value, 0),
            moved,
            page: after.reduce((sum, s) => sum + s.value, 0),
          });
        }, 200)));
        // Bounded, so an image that never loads ends the run with a count instead of hanging it.
        const wait = () => (loaded() || performance.now() - openedAt > 5000 ? settle() : setTimeout(wait, 20));
        wait();
      }));
      const fit = await page.evaluate(() => [...document.querySelectorAll('.lane')].map((lane) => {
        const box = lane.getBoundingClientRect();
        const right = box.right - parseFloat(getComputedStyle(lane).paddingRight);
        const who = lane.querySelector('.who');
        // Per visible character, because a line's trailing space hangs past the edge without being seen.
        const glyphs = [];
        const walker = document.createTreeWalker(lane, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
          for (let i = 0; i < node.length; i++) {
            if (/\s/.test(node.data[i])) continue;
            const range = document.createRange();
            range.setStart(node, i);
            range.setEnd(node, i + 1);
            glyphs.push(...[...range.getClientRects()].map((r) => r.right));
          }
        }
        const images = [...lane.querySelectorAll('img')].filter((img) => img.getClientRects().length > 0).map((img) => img.getBoundingClientRect().right);
        const edge = Math.max(...glyphs, ...images);
        return { lane: Math.round(box.width), over: Math.max(0, Math.round((edge - right) * 10) / 10), lines: Math.round(who.getBoundingClientRect().height) };
      }));
      const columns = await page.evaluate(() => getComputedStyle(document.querySelector('.lanes')).gridTemplateColumns.split(' ').length);
      const over = fit.filter((l) => l.over > 0);
      failures += over.length;
      console.log(`board ${theme} ${width}${old ? ' (old rule)' : ''}: HTTP ${response.status()}, ${columns} lane columns, strip opened at ${opened.openedAt} ms, ${opened.images} lane images loaded by ${opened.loadedAt} ms, `
        + `lane shift from opening through image load ${opened.lanes.toFixed(3)} (whole page ${opened.page.toFixed(3)}), frames where a lane, its role line or any of its text moved from the first open frame ${opened.moved}, `
        + `lanes ${fit.map((l) => `${l.lane} px wide, role line ${l.lines} px tall, ${l.over > 0 ? `text spills ${l.over} px` : 'text fits'}`).join('; ')}`);
      if (width === 780 && !old) await page.screenshot({ path: path.join(__dirname, `board-lanes-${theme}-780.png`), fullPage: false });
    } finally {
      await browser.close();
    }
  }
  console.log(`lanes spilling out: ${failures}`);
  process.exitCode = failures > 0 ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
