'use strict';
// Evidence for #2731: on the first-day board of a running instance, the pinned Turbo applies a push of the Set up Intakt slot
// (the markup the page already shows, as a board pushed empty to an empty board carries it) while the link holds the keyboard focus.
// It is applied once as the stream is pushed now (`method="morph"`) and once as a plain replace, the old stream, as the control.
// The page loads Turbo only with live updates, which need a hub key, and bin/instance hands none on, so the app is served for this check
// with `php -S` on a scratch database and a throwaway MERCURE_JWT_SECRET (APP_ENV=test, migrated, `asset-map:compile`).
// Usage: NODE_PATH=$TMPDIR/node/node_modules node .evidence/2731/setup-focus-check.js http://127.0.0.1:<port>
const { chromium } = require('playwright');
const address = (process.argv[2] || process.env.INTAKT_INSTANCE_URL).replace(/\/+$/, '').replace('127.0.0.1', 'localhost');

(async () => {
  const browser = await chromium.launch({ env: { ...process.env, TMPDIR: process.env.CHROME_TMPDIR || process.env.TMPDIR }, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  let kept = true;
  try {
    for (const method of ['morph', null]) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, bypassCSP: true });
      // The dashboard answers only to Host localhost:7780, the instance listens on another port.
      await context.route(address + '/**', async (route) => route.fulfill({ response: await route.fetch({ headers: { ...route.request().headers(), host: 'localhost:7780' } }) }));
      const page = await context.newPage();
      await page.goto(address + '/', { waitUntil: 'load' });
      await page.waitForFunction(() => window.Turbo !== undefined);
      const shown = await page.evaluate(async (method) => {
        const link = document.getElementById('board-setup');
        link.focus();
        const stream = `<turbo-stream action="replace"${method ? ` method="${method}"` : ''} target="board-setup"><template>${link.outerHTML}</template></turbo-stream>`;
        window.Turbo.renderStreamMessage(stream);
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
        const now = document.getElementById('board-setup');
        const active = document.activeElement;
        return { stream, sameNode: now === link, focused: active === now, active: active.id ? `${active.localName}#${active.id}` : active.localName };
      }, method);
      console.log(`${method ? 'pushed stream (morph)' : 'control (plain replace)'}: ${shown.stream.replace(/<template>.*<\/template>/, '<template>...</template>')}`);
      console.log(`  the link is the same node: ${shown.sameNode}, it holds the focus: ${shown.focused}, document.activeElement: ${shown.active}`);
      if (method && !(shown.sameNode && shown.focused)) kept = false;
      await context.close();
    }
  } finally {
    await browser.close();
  }
  process.exitCode = kept ? 0 : 1;
})().catch((e) => { console.error(e); process.exit(2); });
