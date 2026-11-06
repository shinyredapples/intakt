# Dashboard mocks

Static HTML mocks of every dashboard page, with the design system they share. [DESIGN.md](DESIGN.md) is the spec: tokens, type, components, breakpoints, phone patterns, the page map, chart rules, the glossary and the rules for awkward data and states. Where a mock and DESIGN.md disagree, DESIGN.md wins.

## Opening the mocks

Open `projects.html` in a browser; the rail links to the other pages. The board (`index.html`), Waiting on you, task, settings and components pages and the logo land with the next part of the mocks: until then any link to one of those pages (in the rail or in the page itself) leads nowhere, the pages show a copy of the repository's lockup (`assets/intakt-lockup.svg` and `assets/intakt-lockup-dark.svg`, copied from `docs/assets/` so that a server rooted in this folder can serve them) in place of the small lockup and the favicon in place of the mark. Some browsers limit pages opened from disk, so a local server is the safer way. From this folder, run one of:

```sh
php -S localhost:8000
python3 -m http.server 8000
```

Then open `http://localhost:8000/projects.html`. The setup and add-project pages take `?step=2` to `?step=5`.

Every page uses `assets/intakt.css` and `assets/intakt.js`.

The mocks load their fonts from Google Fonts, so opening them or running `tools/screenshots.js` needs the network; without it the browser falls back to other fonts and the screenshots differ from the approved set. The mocks are design documents, not shipped code: the dashboard serves its fonts itself.

## Screenshots

The screenshots are not committed. `tools/screenshots.js` writes all of them for the pages in this folder, light and dark, at 1440 and 390 wide, to `screens/` with an index in `screens/INDEX.md`. It needs Node.js and Playwright:

```sh
npm install --global playwright
NODE_PATH="$(npm root --global)" CHROME_PATH=/usr/bin/google-chrome node tools/screenshots.js
```

`CHROME_PATH` names a system Chrome or Chromium. Without it, the script uses Playwright's own browser (`npx playwright install chromium` downloads it).
