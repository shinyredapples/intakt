# Intakt dashboard design system

Mocks live in this folder. Every page uses `assets/intakt.css` and `assets/intakt.js`. Change tokens there, never per page. The one exception is the review page mock in `review-scoreboard/` (#3503), a standalone prototype with its own copy of the tokens; see the Amber audit for where it departs from this spec.

## Concept

The octopus conducts and points its baton at the lit amber i-dot. In the interface amber means one thing: this needs you. Amber appears only on things waiting on the owner (the Waiting on you count, those cards and rows, the one action they need), in the logo, and on the one exception the Amber audit names (the ring on the current step of a Working now path, #3326). No amber for warnings, hover, charts or focus.

Everything else stays calm: green for the product and for selection, ink and muted text for content, red only for something broken.

## Tokens

| Token | Light | Dark | Use |
|---|---|---|---|
| `--page` | #F7FAF9 | #121816 | page background |
| `--raised` | #FFFFFF | #18201E | rail, top bar, overlay, inputs |
| `--tint` | #EEF5F2 | #1E2826 | cards, hover, quiet fills |
| `--tint-2` | #E3EEEA | #252F2D | card hover, progress track |
| `--hairline` | #DCE8E4 | #2A3532 | dividers, input borders |
| `--ink` | #0A2B23 | #E6ECEA | body text |
| `--muted` | #4F6B64 | #9AA8A4 | secondary text |
| `--primary` | #1E9A7A | #3CC29C | bars, toggles on, icons, focus ring |
| `--deep` | #136B55 | #1E9A7A | primary button fill (light), link text (light) |
| `--selected` | #C4EFE2 | #1D3B34 | current nav item, selected rows, active chips |
| `--amber` | #F2A541 | #F2A541 | needs-you only |
| `--amber-tint` | #FDF1DF | #332816 | needs-you cards and rows |
| `--red` | #C2410C | #F07B4F | broken things only |

Derived: `--link` is `--deep` in light and `--primary` in dark (AA on every surface). `--on-btn` is #FFFFFF on the light button (deep fill) and #121816 on the dark button (primary fill). Dark mode is a near-neutral dark with a faint green cast; brand green is kept for primary actions, selection and live dots. Text on amber fills is ink-fixed (#0A2B23 in both themes); text on amber-tint cards uses the theme's text color.

Contrast (checked with a WCAG script): every text pair used is at least 4.5:1. Primary #1E9A7A is 3.6:1 on white, so it is never used for small text in light mode; links and buttons use deep green there.

Theme: `data-theme` on `<html>`, set by an inline script in `<head>` from localStorage, else `prefers-color-scheme`. The top bar toggle flips and stores it.

## Type

- Outfit: headings, navigation, buttons, column titles, the rail.
- Atkinson Hyperlegible Next (fallback Atkinson Hyperlegible, then system sans): body, tables, numbers. Numbers, issue numbers and times use `font-variant-numeric: tabular-nums` in this font. Its zero is slashed by design; that is not the monospace font.
- JetBrains Mono: only literal copyable things (shas, commands, `approve <sha>`, file paths, log lines).

Scale: 13 (meta, chips; the smallest size anywhere on desktop, including monospace shas and paths, kbd hints and severity labels; always `--muted` or stronger, AA on every surface), 15 (body), 17 (card titles, lead), 21 (section headings), 28 (page title). Line height 1.45 body, 1.2 headings. Weights 400/500/600. Sentence case everywhere.

## Spacing and radius

Spacing steps 4, 8, 12, 16, 24, 32, 48. Radius by hierarchy: chips 6, buttons and inputs 8, cards 12, overlay 18, count badges fully round.

## Components

- **Card**: flat `--tint`, no border, no shadow, radius 12, padding 12/14. Title 15/600 Outfit, meta row of chips beneath. Needs-you card uses `--amber-tint` with a 4 px amber dot before the number. Hover fills an ordinary card with `--tint-2`; a needs-you card keeps `--amber-tint` on hover and only darkens slightly (`filter: brightness(.98)`, the `.card.needs.is-hover` state). Focus shows the ring.
- **Chip**: 13 px, radius 6, `--raised` fill on cards, `--tint` fill on the page. Only for state and reason badges (Budget, Needs #612, CI broken, Project paused, a task's state).
- **Card emphasis**: a blocker or next step ("Waiting for CI: image build failing", "Needs #612 merged first", the needs-you ask) is a 13 px weight-500 ink line with an icon right under the title (red only when something is broken). The meta line follows with time, slot and round first; size and cost come last.
- **Account names**: accounts carry owner-chosen names ("Work subscription", "Overflow key") with a Rename link; the kind (subscription or API key) is secondary text.
- **Meta line** (used for all ordinary metadata: cards, task header, timeline build and check rows, Waiting items, project and role facts): plain facts (size, slot and model, round, time in column, cost) as one line of 13 px muted text, each with a small icon, spaced by gaps, never by middle dots.
- **Reason badge**: chip with an icon and a word for why a card is parked: budget, overlap (built after the head in flight that touches the same files), dependency, CI. Neutral colors, except CI red when CI is broken.
- **Count badge**: fully round, 13 px. Neutral (`--tint`) everywhere except the Waiting on you count, which is amber.
- **Buttons**: `primary` (deep/primary fill), `secondary` (tint fill), `quiet` (text only), `danger` (tint fill like secondary; first click arms it and asks to click again, never red, because red means broken), `needs` (amber fill, ink text; only the one action a needs-you item asks for). Radius 8, 36 px tall, 32 px small.
- **List row**: full-width, hairline between rows, no box. Title, one line of explanation, actions on the right.
- **Setting row**: label (15/600), one-line explanation (13 muted), control on the right. Groups are separated by a heading and whitespace.
- **Bars**: 8 px track in `--tint`; used segment `--primary`, reserved segment striped primary, forecast as a hollow outline segment. Labels in plain text beside, not inside.
- **Banner**: full-width line with an icon, sentence and one action. Paused uses `--tint`; broken uses red text and a red icon on `--tint`.
- **Session summary** (under the live log): quiet label-sm headings, right-aligned tabular values. Tool calls by kind, cost, tests with result, files read (mono), and findings this round; each finding id links to its entry in the timeline, which highlights with `--selected`. The log scrolls above it; the summary stays pinned at the bottom of the panel.
- **Phone banner**: below 761 px a banner collapses to one 48 px line (icon, short title, chevron); tapping it shows the full text and its action. Desktop shows the full banner.
- **Waiting item** (UX2, #3327): one card with the role who waits (a character with its name in words), one lead line saying what is wrong, the meta line, one line of consequence, then one tap with a line under it saying what happens next. No Copy button and no Why disclosure. The card edge is amber; the card has no amber tint.
- **Who is working**: superseded by the UX2 frame (#3325): the board shows one quiet status line ("Running smoothly · 1 reviewer working") that links to the Status page, which lists the lane cards; nothing expands.
- **Needs you** (UX2 home, #3326): while something waits on the owner, one `--amber-tint` card above the board with the waiting octopus, the label "Needs you" in sentence case, the count of decisions and the time of the oldest wait; it opens Waiting on you.
- **Working now** (UX2 home, #3326): below the status line, no disclosure. One card per change somebody works on now (a build task in flight, or a review whose latest round is still open), each with its title, project, a state chip (Building or Reviewing) and a five-step path (Plan, Build, Check, Review, Merge): done steps filled in primary, the current step with an amber ring and a bold label and the role doing the work standing on it, later steps outlined. A review card opens its review page. With no card it is one muted line saying who will appear there: "Nobody is working yet: reviewers appear here while they review a change".
- **Board edge and stage map**: when the board is wider than the screen, the scrolled-off edge fades and a small map of the nine columns ("Columns 1 to 6 of 9") marks the visible ones in green. The map uses no amber. Clicking a segment scrolls there. Hidden when everything fits and on phones.
- **Current state block** (task): a tinted block directly under the header for the active round: each reviewer's status, the open findings (linking to the timeline), and a checklist of what merge still needs. Completed rounds in the timeline collapse to one-line summaries (outcome, finding count, time span) that expand; the active round stays open. The live log is secondary, beside or behind a tab.
- **Setting row state**: right of the control, "Saving…", a green check with "Saved", or red "Not saved, still <active value>. Retry", so the owner knows which value is in force. Page copy says "Most changes save as you make them". Settings that change how you reach the dashboard (port) need an explicit Apply, with the proposed result beside it ("Will move to intakt.local:8471").
- **Search results**: every result names its project; pull requests are named by their own number ("Pull request 611: …"); an empty query shows Recent and a few actions; no results shows suggestions and a Clear button.
- **Paused state**: shown with a Paused badge, plain text and a prominent Resume. Never by fading the content.
- **Toast**: bottom center, `--ink` fill, page text color, with Undo. Slides 8 px in; no motion under reduced-motion.
- **Overlay (search)**: opaque `--raised` panel, radius 18, hairline border, over a scrim (ink at 55% in light, near-black at 72% in dark). The opening motion moves the panel only; it never fades, so it is readable from the first frame. Groups of results with an icon each; arrows move, Enter opens, Esc closes.

## Layout shell

Left rail (240 px, collapsible to 64 px icons, remembered in localStorage): small lockup, then Board, Waiting on you, Projects, Team, History, Settings. Current page uses `--selected`. Top bar: search button styled as a field with the ⌘K / Ctrl K hint, project filter, theme toggle.

## Breakpoints

Two first-class layouts, plus a bridge.

| Width | Layout |
|---|---|
| up to 760 | Phone (below) |
| 761 to 1099 | Bridge: rail, single-column task, accounts stacked |
| 1100 and up | Settings get a sticky group index on the left; text column stays 760 px |
| 1500 and up | Split pages: Waiting on you (list plus "Meanwhile" and "You handled today"), Team (accounts plus roles). Task detail puts the live log beside the timeline; the panel is capped to the viewport and the log to 520 px, with the session summary under it. Content up to 1720 px |
| 1700 and up | The Status page's lanes in one row of six (Working now cards fill each row at 320 px or more at every width, UX2 home, #3326); page padding 48 |
| 2200 and up | Content up to 2000 px; Backlog and Done start expanded; the task panel splits into log (up to 640 px tall) and a 360 px summary column beside it |

Board columns size to the space (`flex: 1 1 0`, min 184 px, Waiting on you 224 px and 1.25 share). At 1920 the whole board fits without sideways scrolling; below that it scrolls, with Waiting on you always first. Long text keeps a readable measure (timeline at most 78ch, settings 760 px, setup 760 px centred).

## Phone patterns (390)

- Bottom bar, 68 px: Waiting (first, amber count), Board, Projects, Team, More. More opens a sheet with History and Settings and is shown as current on those pages. The top bar holds the logo, a Search button and the theme toggle.
- Waiting on you is the phone's home: each item is one card with the role, the lead line, meta, the consequence, then one full-width tap with a line saying what happens next (UX2, #3327). No Copy and no Why.
- Board: banners are single lines that expand; Who is working is superseded by the status line, which links to the Status page instead of expanding (#3325); the Working now cards stack one per row (#3326). One column at a time. A sticky segmented switcher lists every column with its count (Waiting on you first); swiping the board snaps column by column and updates the switcher. Backlog and Done are not collapsed on phones.
- Task: Stop and Continue side by side, Send back full width, then a sticky Timeline / Live log tab switch.
- Search: a full-screen sheet with a Cancel button and 52 px rows.
- Setup: progress bars plus "Step 3 of 5: AI account"; the step's buttons stay at the bottom.
- Floors: no text under 15 px (`--t13` becomes 15 px), every control at least 44 px tall (switches 52 by 32 with a 44 px hit area, stepper buttons 48 by 46, inputs 48).

## Amber audit

Amber appears only on: the Waiting on you nav count and column count, needs-you cards and the board's Needs you card (amber tint), Waiting items (the amber card edge, no tint), their one action button, the ring on the current step of a Working now path (it marks where the work stands, the one step to look at; UX2 home, #3326), the needs dot, an expired account login (it is a Waiting item) with its Log in button, the "wait on you" counts on Projects, and the logo. Nothing else.

Named exception: the amber props of the characters (the code reviewer's magnifying glass, the verdict step's gavel, the tip of the octopus's baton) are part of the drawings, not a signal. They never stand for "needs you", and the characters stay off every amber-tint surface (see Characters) but one: the waiting octopus on the board's Needs you card, because it conducts the page from there and the board then hides its own octopus, so the page still shows one octopus and the card's words, not the drawing, say what waits.

Named exception, the at-a-glance review page (direction A, "Scoreboard", chosen by the owner on 2026-11-22, #3503; mock in `review-scoreboard/`): while the verdict is changes needed, the verdict card is `--amber-tint` with an amber edge and carries the owl and the reviewer faces, each face's cross sits on an amber dot, and the "Should fix" urgency uses amber (its shape, its bar segments and its highlighted code line). There the verdict is what waits on the owner's next step, and the urgency is always shown by a shape and a word as well, never by the colour alone. Every other rule of this audit and of Characters holds on that page.

## Characters

The Code reviewers, their verdict step, the maintainer, the developer and the security engineer each have one character, drawn in a light and a dark version (role names as in [the team](../05-architecture/01-overview.md#the-team); the `Character` values keep their code names until the code is renamed):

| Role | `Character` | Drawing |
|---|---|---|
| Code reviewer (every reviewer setting of the Code reviewers, whatever its lens) | `CodeReviewer` (`code_reviewer`) | Meerkat with a magnifying glass |
| The Code reviewers' verdict step (formerly the judge) | `Judge` (`judge`) | Owl with a gavel |
| Maintainer (the role who waits on the owner on a Waiting card, UX2, #3327) | `Maintainer` (`maintainer`) | Tortoise with a toolbox |
| Developer (the build, on a Working now card, #3326) | `Developer` (`developer`) | Beaver with a wrench |
| Security engineer (a reviewer setting named `security`, on a Working now card, #3326) | `SecurityEngineer` (`security_engineer`) | Hedgehog with a shield |

No Working now card reaches Plan, Check or Merge today, so the tech lead (Plan) and the AI engineer (Check) have no drawing in the code yet, and the tortoise stands on no Merge step.

The octopus has four poses (`MascotPose`): `waiting`, `conducting`, `bowing`, `asleep`, for empty, idle and approved states. The enum value is the file stem: `assets/images/roles/<value>-{light,dark}-{48,96,192}.webp` for a role and `assets/images/mascot/octopus-<value>-{light,dark}-{96,144,192,288}.webp` for a pose, plus `-72` for `bowing`, which sits at 72 px beside an approved newest round (scaled from `octopus-<value>-{light,dark}.webp`). Every place uses the one partial `templates/dashboard/components/character.html.twig` (`character`, `size`, and an optional `alt` that only a Working now card sets); the components page shows each character and pose in both themes.

Places (UX2, #3327 replaces the old rule that kept characters off Waiting on you): the waiting octopus once at the top of the Waiting on you page, the role who waits on each Waiting card (with its name as visible text), a reviewer's row and the verdict step's row on the review page, the live log and the round heading (24 or 48 px), the Code reviewer beside the role of each lane of the Status page (24 px, one per lane; the board's Who is working strip is superseded by the status line, #3325), and an octopus pose on an empty, idle or approved state. The UX2 home (#3326) adds two: the role standing on the current step of each Working now card (48 px: the beaver on Build, the meerkat on Review, the hedgehog beside the meerkat while the security reviewer runs), and the waiting octopus on the board's Needs you card (96 px, 144 px from 1024 px wide).

- **Decoration only.** Every image has `alt=""` (except on a Working now card, where the role standing on the step is the only sign of the step's role, so its alt names role and step, "Code reviewer, now on Review"; the partial takes an optional `alt`), no `role`, a fixed `width` and `height` (no layout shift), a 1x/2x `srcset` (24 px: the 48 and 96 files; 48 px: 96 and 192; an octopus at 96 px: 96 and 192; at 144 px: 144 and 288) and `loading="lazy"`. The text beside it names the actor; no text is removed or shortened for a character.
- **Theme.** The light and the dark image switch by the nearest `[data-theme]`, like the logo.
- **Calm.** No animation and no hover effect, except that the role on a Working now step fades in once over 0.4 s, and not at all under reduced motion (UX2 home, #3326). One character per row or heading, except that the meerkat and the hedgehog share the Review step of a Working now card while the security reviewer runs (#3326), and one octopus per page (the components page, a catalog for the people who build pages, is the exception).
- **Never** on board cards, table rows, chips or amber-tint surfaces, except the waiting octopus on the board's Needs you card (UX2 home, #3326, see the Amber audit); on Waiting on you only the octopus once at the top and a role on each card (UX2, #3327), and the page's hero uses a neutral surface. The outcome chip and the state dot stay the only carriers of outcome and state.
- **Contrast.** A character's silhouette is at least 3:1 against `--page`, `--raised` and `--tint` in both themes. The dark drawings carry a light halo of their own; the light ones get a 1 px `--muted` ring from the style sheet (5.2:1 or more on those surfaces), because their own edge falls under 3:1.

## Screenshots

`tools/screenshots.js` writes every screenshot to `screens/` (not committed) with `screens/INDEX.md`, which lists each file, page, theme and width. Names are stable: `<page>-<theme>-<width>[-<variant>].png`. How to run it is in the [README](README.md).

## Information architecture

Rail: Board, Waiting on you, Projects, Team, History, Settings. New pages live under these, never as new rail items:

| Page | Lives under | How you get there |
|---|---|---|
| `trends.html` | History | Sub-tab "Trends" beside "Ledger"; until History is built, the dashboard lists Trends as a rail item after Projects (decided in #2858) |
| `project.html` | Projects | Click a project name; breadcrumb back |
| `add-project.html` | Projects | "Add a project"; search action |
| `health.html` | Projects | Sub-tab "Health" (shows the broken count); board's CI banner |
| `add-account.html` | Team | "Add an account"; search action |
| Notifications | Settings | Its own group: channels, quiet hours, the fixed rule |
| `components.html` | none | Link at the bottom of Settings, for the people who build pages |

Sub-tabs (`.subtabs`) are links with `aria-current="page"`, a 2 px green underline on the current one.

## Charts (trends)

- One hue: `--primary` for every mark. No amber, no status colors, no categorical palette. Comparisons that would need two colors become small multiples on a shared scale (created vs completed, the four cycle-time stages, the three projects).
- Each chart: a question or plain title, a one-line takeaway with the numbers, the chart, and "Show as table" (a real table) under it.
- Recessive grid (hairline), three y labels, three x labels, direct label on the last point of a line. Bars 4 px rounded at the top, capped width. Every mark has a hover and keyboard-focus tooltip (value first, label second).
- Missing data is a gap in the line and "No data" in the table, never zero.

## Glossary

Use the plain wording in UI copy. Where the term must stay, mark it with `.term` (`data-term`): a dotted underline that shows the one standard explanation on hover and focus. The explanations live in `GLOSSARY` in `intakt.js`; never write a different one inline.

| Term (internal) | Say in the UI | Standard explanation |
|---|---|---|
| head | version (with its short sha) | The exact commit Intakt built and checked. An approval covers this version only; a new push needs a new approval, unless it leaves what you approved exactly as it was (planned, for a limit like secrets: the same files, unchanged). A revised held CI push always needs its own approval. |
| held push | push on hold | Intakt stopped this push before checks ran, because it changes something only you can allow, such as CI files or protected files. |
| share | weekly share | This project's part of the week's AI budget. Intakt paces work so spend follows it. |
| round | round (review round in running text) | One build, its checks and its review. Sending work back starts the next round. |
| protected path | protected files | Files that always need your approval to change, such as secret handling or CI. You choose them per project. |
| floor | reserve | The smallest part of the week's capacity kept for this project, even when other projects are busy. |
| act list | to fix | The act list: every P1 and P2 finding that passed the quote check, whatever the judge marked, and each P3 finding the judge kept, to fix before approval. |
| judged | checked | Judged: the judge weighed every reviewer's findings and gave the verdict. |
| round (on the review page) | review pass | A round: one review of the change, by its reviewers and then the judge. |

## Awkward data and states

- **Long titles**: cards clamp to three lines and break long words; the full title is on the task page. Lanes, search results, list rows and table cells wrap (never clip mid-word); page titles wrap. Project names in card corners, chips and the project filter truncate with an ellipsis at 60% of the row; the full name is in the tooltip and on the project page. Shas, paths and commands never truncate in the middle; they wrap or scroll inside their copy box.
- **Crowded column (40 tasks)**: a column shows 12 cards, then "Show 28 more". The column count always shows the true total. The stage map and phone switcher show totals too.
- **5+ projects**: the project filter is a select (scales to any number). Projects stacks blocks; Trends small multiples wrap to rows of three; the board identifies projects by name, never by color.
- **Vendor out of credit**: the account shows the Test result in red ("Out of credit: the vendor refused the last request at 10:41"), Intakt stops using it, builds move to other accounts. If no account can serve a role, that becomes a Waiting item ("Add credit or another account"), because only the owner can fix it.
- **Loading**: static skeleton blocks in the shape of the content (cards, Working now cards, lead line), with `aria-busy` on the main region. No spinners on whole pages and no pulsing (motion only follows the user). Buttons that wait show their verb in progress ("Saving…", "Approving…").
- **Errors**: say what failed, the cause in plain words, and what is still true ("Not saved, still 22:00 to 07:00", "Nothing was saved"). Red only for the failed part.
- **Offline or reconnecting**: a neutral line at the top of the page ("Reconnecting. You are seeing what Intakt knew at 11:12:04; actions wait until it is back. Next try in 5 s.") with Try now, an "Offline" marker in the top bar, action buttons disabled, elapsed times frozen with their timestamp. Content stays at full contrast; never fade the page. After two minutes the line turns red ("Can't reach Intakt").
- **First day**: no banners, the lead says "Nothing is running yet. Start the first review." and links the setup page, as does the first head action, **Set up Intakt**; the second, **Open the review list**, stays as on every day. Phones show both. A tinted block, "Give Intakt its first task", gives the three steps to the first review, with the waiting octopus above its words (at most 144 px high, 120 px on a phone). It has no link to a repository's issues while Intakt takes in no issues. Each empty column, Working now's line while no change is in progress ("Nobody is working yet: reviewers appear here while they review a change", UX2 home, #3326), and the Status page's line while no reviewer works (it superseded the board's Who is working line, #3325), says in one line what lands there and when (such as "Reviews appear here while the Code reviewers review a change"), on the first day and after.

Examples: `index.html?demo=stress`, `?demo=reconnecting`, `?demo=firstday`, `?demo=loading`.

## Rules

- No all-caps, tracked labels, eyebrows above headings, middle-dot meta strings, labels that join a word and a fragment with a long dash, arrows appended to links or buttons, gradients, card shadows.
- Numbered markers only for real sequences (setup steps).
- An arrow is fine only for a literal value change (history: before → after).
- Board columns are open space: heading and count, no box. Waiting on you is pinned as the first column (amber count), then the flow columns. Backlog and Done start collapsed to a 108 px chip-like strip with the name and count side by side; click expands, Hide collapses. Above the Waiting on you cards, a line names waiting items that are not tasks ("Also waiting: 1 account login"), so the column count and the nav count reconcile.
- Working now cards (UX2 home, #3326) are cards, and the Status page's lanes use the same flat tint as cards.
- Sections are separated by whitespace and the occasional hairline.
- Motion only in response to the user, except the role's one fade-in on its Working now step (UX2 home, #3326); respect `prefers-reduced-motion`.
- Visible focus everywhere: 2 px `--primary` outline, 2 px offset.
