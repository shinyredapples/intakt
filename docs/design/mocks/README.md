# Dashboard mocks

Static HTML mocks of every dashboard page, with the design system they share. [DESIGN.md](DESIGN.md) is the spec: tokens, type, components, breakpoints, phone patterns, the page map, chart rules, the glossary and the rules for awkward data and states. Where a mock and DESIGN.md disagree, DESIGN.md wins.

## Opening the mocks

Open `projects.html` in a browser; the rail links to the other pages. The board (`index.html`), Waiting on you, task, settings and components pages and the logo land with the next part of the mocks: until then any link to one of those pages (in the rail or in the page itself) leads nowhere, the pages show a copy of the repository's lockup (`assets/intakt-lockup.svg` and `assets/intakt-lockup-dark.svg`, copied from `docs/assets/` so that a server rooted in this folder can serve them) in place of the small lockup and the favicon in place of the mark. Some browsers limit pages opened from disk, so a local server is the safer way. From this folder, run one of:

```sh
php -S localhost:8000
python3 -m http.server 8000
```

Then open `http://localhost:8000/projects.html`. The setup and add-project pages take `?step=2` to `?step=5`.

Every page in this folder uses `assets/intakt.css` and `assets/intakt.js`, except the review page below.

## The review page (Scoreboard)

`review-scoreboard/index.html` is the owner-approved mock of the at-a-glance review page (direction A, "Scoreboard", #3503): the verdict card, the reviewer faces, the pass path and the findings grouped by urgency, with real demo data, light and dark, phone and desktop. A finding's row opens its own view (`#f1` and so on). It is a standalone prototype: it carries its own copy of the tokens (`review-scoreboard/proto.css`, page styles in `review-scoreboard/ux3.css`) and does not use `assets/intakt.css` or `assets/intakt.js`. It loads its fonts from `docs/pages/assets/`, which a server rooted in this folder cannot reach, so serve it from `docs/` instead. From `docs/`, run one of:

```sh
php -S localhost:8000
python3 -m http.server 8000
```

Then open `http://localhost:8000/design/mocks/review-scoreboard/index.html`. Its fonts come from the repository, so it needs no network.

## Technical writer sketches

`technical-writer-sketches/index.html` offers the owner three animals for the Technical writer's character (#3563): an otter, a heron and a red panda, each with a notebook and a pen, on the light and the dark background. `technical-writer-sketches/sketches.png` is the same three rendered as one picture, for viewing without a browser. None of them is in the team strip yet: the owner's choice decides which one joins it after the Maintainer. The page is standalone, with its own styles and no fonts to load; serve it from `docs/` as above so its picture of the current cast loads, then open `http://localhost:8000/design/mocks/technical-writer-sketches/index.html`.

## Fonts

The other mocks load their fonts from Google Fonts, so opening them or running `tools/screenshots.js` needs the network; without it the browser falls back to other fonts and the screenshots differ from the approved set. The mocks are design documents, not shipped code: the dashboard serves its fonts itself.

## Screenshots

The screenshots are not committed. `tools/screenshots.js` writes all of them for the pages in this folder, light and dark, at 1440 and 390 wide, to `screens/` with an index in `screens/INDEX.md`. It needs Node.js and Playwright:

```sh
npm install --global playwright
NODE_PATH="$(npm root --global)" CHROME_PATH=/usr/bin/google-chrome node tools/screenshots.js
```

`CHROME_PATH` names a system Chrome or Chromium. Without it, the script uses Playwright's own browser (`npx playwright install chromium` downloads it).
