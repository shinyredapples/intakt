# Dashboard mocks

Static HTML mocks of every dashboard page, with the design system they share. [DESIGN.md](DESIGN.md) is the spec: tokens, type, components, breakpoints, phone patterns, the page map, chart rules, the glossary and the rules for awkward data and states. Where a mock and DESIGN.md disagree, DESIGN.md wins.

## Opening the mocks

Open `index.html` (the board) in a browser; the rail links to every other page. Some browsers limit pages opened from disk, so a local server is the safer way. From this folder, run one of:

```sh
php -S localhost:8000
python3 -m http.server 8000
```

Then open `http://localhost:8000/`. The board has example states: `index.html?demo=stress`, `?demo=reconnecting`, `?demo=firstday` and `?demo=loading`. The setup and add-project pages take `?step=2` to `?step=5`.

Every page uses `assets/intakt.css` and `assets/intakt.js`; `components.html` shows the component set.

The mocks load their fonts from Google Fonts. They are design documents, not shipped code: the dashboard serves its fonts itself.

## Screenshots

The screenshots are not committed. `tools/screenshots.js` writes all of them, light and dark, at 1440, 1920, 2560 and 390 wide, to `screens/` with an index in `screens/INDEX.md`. It needs Node.js and Playwright:

```sh
npm install --global playwright
NODE_PATH="$(npm root --global)" CHROME_PATH=/usr/bin/google-chrome node tools/screenshots.js
```

`CHROME_PATH` names a system Chrome or Chromium. Without it, the script uses Playwright's own browser (`npx playwright install chromium` downloads it).
