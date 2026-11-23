/* Intakt dashboard mocks: shell, theme, search overlay, mock data, small interactions. */
(function () {
  "use strict";

  /* ---------- Icons ---------- */
  const P = {
    board: '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="11" rx="1.5"/><rect x="17" y="4" width="4" height="7" rx="1.5"/>',
    waiting: '<path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 8 2.5 8h-17S6 15 6 9"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/>',
    projects: '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H9l2 2h7.5A2.5 2.5 0 0 1 21 9.5v7a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 16.5z"/>',
    team: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18.5 14.5A6.5 6.5 0 0 1 21.5 20"/>',
    history: '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8.5"/><path d="M3.5 3.5v5h5"/><path d="M12 7.5V12l3 2"/>',
    settings: '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5"/>',
    collapse: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9 4v16"/><path d="m15.5 10-2 2 2 2"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    coin: '<circle cx="12" cy="12" r="8.5"/><path d="M14.5 9.2c-.5-.8-1.4-1.2-2.5-1.2-1.5 0-2.5.8-2.5 2s1 1.6 2.5 2 2.5.9 2.5 2-1 2-2.5 2c-1.1 0-2-.4-2.5-1.2M12 6.5V8M12 16v1.5"/>',
    pause: '<rect x="6.5" y="5" width="3.5" height="14" rx="1"/><rect x="14" y="5" width="3.5" height="14" rx="1"/>',
    play: '<path d="M7 5.5v13l11-6.5z"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
    broken: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5.5M12 16.3v.2"/>',
    pr: '<circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="18" r="2.2"/><path d="M6 8.2v7.6M18 15.8V10a3 3 0 0 0-3-3h-4"/><path d="m13 5-2 2 2 2"/>',
    task: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    bolt: '<path d="M13 3 5 13.5h6l-1 7.5 8-10.5h-6z"/>',
    key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16 7l2.5 2.5M18.5 4.5 21 7"/>',
    lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
    terminal: '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><path d="m7.5 9.5 3 2.5-3 2.5M13 15h4"/>',
    question: '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.5a2.5 2.5 0 0 1 4.8 1c0 1.7-2.4 2-2.4 3.5M12 16.8v.2"/>',
    link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    wallet: '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="4" y="8" width="16" height="11" rx="2.5"/><circle cx="16" cy="13.5" r="1.2"/>',
    upload: '<path d="M12 15V4.5M7.5 9 12 4.5 16.5 9"/><path d="M4.5 15v2.5A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5V15"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    send: '<path d="m4 12 16-7-6 16-2.5-6.5z"/>',
    user: '<circle cx="12" cy="8" r="3.8"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
    test: '<path d="M9 3.5h6M10 3.5v6L5 18a2 2 0 0 0 1.8 2.5h10.4A2 2 0 0 0 19 18l-5-8.5v-6"/>',
    phone: '<rect x="7" y="3" width="10" height="18" rx="2.5"/><path d="M11 18h2"/>',
    repo: '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5z"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19v-3"/>',
    more: '<circle cx="5.5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="18.5" cy="12" r="1.5"/>',
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.2"/>',
  };
  const icon = (n, cls) => `<svg class="i${cls ? " " + cls : ""}" viewBox="0 0 24 24" aria-hidden="true">${P[n] || ""}</svg>`;

  /* ---------- Mock data ---------- */
  const COLUMNS = ["Waiting on you", "Backlog", "Ready", "Planned", "Building", "Checking", "In review", "Merging", "Done"];
  const COLLAPSED = ["Backlog", "Done"];
  const TASKS = [
    { n: 640, p: "intakt", t: "Export the ledger as CSV", s: "M", col: "Backlog", age: "3 d" },
    { n: 221, p: "acme-shop", t: "Gift card balance on the account page", s: "L", col: "Backlog", age: "5 d" },
    { n: 77, p: "field-notes", t: "Offline map tiles for saved trails", s: "L", col: "Backlog", age: "1 w" },
    { n: 631, p: "intakt", t: "Show reset times in the owner's time zone", s: "S", col: "Ready", age: "6 h" },
    { n: 214, p: "acme-shop", t: "Retry failed webhook deliveries with backoff", s: "M", col: "Ready", age: "1 d", reason: ["wallet", "Waits for budget: share used up"] },
    { n: 626, p: "intakt", t: "Search settings by their label", s: "S", col: "Ready", age: "9 h", reason: ["link", "Needs #612 merged first"] },
    { n: 618, p: "intakt", t: "Pause a single lane without pausing the project", s: "M", col: "Planned", age: "2 h", cost: "0.41" },
    { n: 74, p: "field-notes", t: "Strip photo location data before upload", s: "S", col: "Planned", age: "2 d", reason: ["pause", "Project paused"] },
    { n: 612, p: "intakt", t: "Command overlay for tasks and settings", s: "M", col: "Building", age: "42 min", slot: "Slot 1", model: "Model L", cost: "3.18" },
    { n: 208, p: "acme-shop", t: "Round cart totals per line, not per order", s: "S", col: "Building", age: "18 min", slot: "Slot 2", model: "Model M", cost: "0.92" },
    { n: 603, p: "intakt", t: "Reviewer findings link to file and line", s: "S", col: "Checking", age: "4 min", cost: "1.07" },
    { n: 205, p: "acme-shop", t: "Refund emails include the order number", s: "S", col: "Checking", age: "1 h 50 min", cost: "0.66", reason: ["broken", "Waiting for CI: image build failing"], broken: true },
    { n: 598, p: "intakt", t: "Hold pushes while main is red", s: "M", col: "In review", age: "11 min", slot: "Code reviewers", model: "Model L", cost: "5.62", round: 2 },
    { n: 71, p: "field-notes", t: "Filter trails by difficulty", s: "S", col: "In review", age: "1 d", cost: "1.24", round: 1, reason: ["pause", "Project paused"] },
    { n: 589, p: "intakt", t: "Rotate the forge token without a restart", s: "M", col: "Waiting on you", age: "1 h 05 min", cost: "4.30", round: 2, needs: "Approve this version: secrets limit, ForgeTokenStore.php changed" },
    { n: 199, p: "acme-shop", t: "Gift cards: pick the stored currency", s: "M", col: "Waiting on you", age: "52 min", cost: "0.38", needs: "The Tech lead has a question" },
    { n: 594, p: "intakt", t: "Cache forge responses for 30 seconds", s: "S", col: "Waiting on you", age: "34 min", cost: "2.11", round: 1, needs: "Push on hold: it changes the CI workflow" },
    { n: 601, p: "intakt", t: "Expiry column in the history ledger", s: "S", col: "Merging", age: "1 min", cost: "1.85", round: 1 },
    { n: 596, p: "intakt", t: "Weekly share shown next to spend", s: "S", col: "Done", age: "today", cost: "2.04" },
    { n: 203, p: "acme-shop", t: "Search products by SKU", s: "S", col: "Done", age: "today", cost: "1.12" },
    { n: 69, p: "field-notes", t: "Elevation profile under each trail", s: "M", col: "Done", age: "Mon", cost: "3.70" },
  ];
  const LANES = [
    { who: "Build slot 1", role: "Developer", model: "Model L", task: 612, p: "intakt", t: "Command overlay for tasks and settings", el: "42 min" },
    { who: "Build slot 2", role: "Developer", model: "Model M", task: 208, p: "acme-shop", t: "Round cart totals per line", el: "18 min" },
    { who: "Build slot 3", idle: "Idle: acme-shop weekly share used up" },
    { who: "Code reviewer A", role: "Code reviewers", model: "Model L", task: 598, p: "intakt", t: "Hold pushes while main is red, round 2", el: "11 min" },
    { who: "Code reviewer B", role: "Code reviewers", model: "Model M", task: 598, p: "intakt", t: "Hold pushes while main is red, round 2", el: "9 min" },
    { who: "Security engineer", idle: "Idle: nothing to look at" },
  ];
  const SETTINGS = [
    ["Dashboard port", "General", "settings.html#general"],
    ["Phone access", "General", "settings.html#general"],
    ["Time zone", "General", "settings.html#general"],
    ["Build slots", "Project defaults", "settings.html#defaults"],
    ["Waiting reviews limit", "Project defaults", "settings.html#defaults"],
    ["Issue label", "Project defaults", "settings.html#defaults"],
    ["Phone push", "Notifications", "settings.html#notifications"],
    ["Email notifications", "Notifications", "settings.html#notifications"],
    ["Webhook", "Notifications", "settings.html#notifications"],
    ["Quiet hours", "Notifications", "settings.html#notifications"],
    ["Daily summary", "Notifications", "settings.html#notifications"],
    ["Pause everything", "Danger zone", "settings.html#danger"],
    ["Remove a project", "Danger zone", "settings.html#danger"],
    ["Accounts and usage", "Team", "team.html"],
    ["Infrastructure health", "Projects", "health.html"],
    ["Trends", "History", "trends.html"],
  ];
  const ACTIONS = [
    ["Pause all projects", "pause", () => toast("All projects paused", () => toast("Projects resumed"))],
    ["Add an account", "plus", () => (location.href = "add-account.html")],
    ["Add a project", "plus", () => (location.href = "add-project.html")],
    ["Resume field-notes", "play", () => toast("field-notes resumed", () => toast("field-notes paused again"))],
    ["Switch light or dark theme", "moon", () => setTheme(curTheme() === "dark" ? "light" : "dark")],
  ];
  const DEMO = document.body.dataset.page === "board" ? new URLSearchParams(location.search).get("demo") : null;
  const PROJECTS = DEMO === "stress"
    ? ["intakt", "acme-shop", "field-notes", "billing-api", "docs-site", "customer-portal-legacy-migration"]
    : ["intakt", "acme-shop", "field-notes"];
  if (DEMO === "stress") {
    const extra = ["billing-api", "docs-site", "customer-portal-legacy-migration"];
    const words = ["Retry invoice export when the storage bucket rejects multipart uploads larger than the configured chunk size",
      "Fix flaky date test", "Translate checkout errors", "Add pagination to the order history endpoint and keep the cursor stable across sorts",
      "Remove unused feature flags", "Upgrade the image library", "Show the customer's preferred language on the support ticket sidebar"];
    for (let i = 0; i < 40; i++) TASKS.push({ n: 700 + i, p: extra[i % 3], t: words[i % words.length], s: ["S", "M", "L"][i % 3], col: "Ready", age: (i % 9 + 1) + " h" });
    TASKS[0].t = "Export the ledger as CSV, including expiry, before and after values, and a stable column order that spreadsheet tools can sort without breaking dates";
    TASKS.find((t) => t.n === 612).t = "Command overlay for tasks, pull requests, projects, settings and actions with keyboard navigation and recent items";
  }
  if (DEMO === "firstday" || DEMO === "loading") { TASKS.length = 0; LANES.forEach((l) => { if (!l.idle) { l.idle = "Idle: nothing to build yet"; } }); }

  /* ---------- Theme ---------- */
  const root = document.documentElement;
  const curTheme = () => root.dataset.theme;
  function setTheme(t) { root.dataset.theme = t; localStorage.setItem("intakt-theme", t); }
  matchMedia("(prefers-color-scheme: dark)").addEventListener("change", (e) => {
    if (!localStorage.getItem("intakt-theme")) root.dataset.theme = e.matches ? "dark" : "light";
  });

  /* ---------- Shell ---------- */
  const NAV = [
    ["board", "Board", "index.html"],
    ["waiting", "Waiting on you", "waiting.html"],
    ["projects", "Projects", "projects.html"],
    ["team", "Team", "team.html"],
    ["history", "History", "history.html"],
    ["settings", "Settings", "settings.html"],
    ["more", "More", "#more"],
  ];
  const SHORT = { waiting: "Waiting" };
  const WAITING_COUNT = 4;

  function renderShell() {
    const page = document.body.dataset.page;
    const rail = document.getElementById("rail");
    if (rail) {
      rail.setAttribute("aria-label", "Main");
      rail.innerHTML = `<div class="rail-in">` +
        `<a class="brand" href="index.html" aria-label="Intakt home">
          <img class="logo-full logo-light" src="assets/intakt-lockup.svg" alt="Intakt">
          <img class="logo-full logo-dark" src="assets/intakt-lockup-dark.svg" alt="Intakt">
          <img class="mark" src="assets/favicon-32.png" alt="Intakt"></a>` +
        NAV.map(([k, l, h]) =>
          `<a class="nav" data-k="${k}" href="${h}"${k === page || (k === "more" && ["history", "settings"].includes(page)) ? ' aria-current="page"' : ""} title="${l}">${icon(k)}<span class="label">${SHORT[k] ? `<span class="l-long">${l}</span><span class="l-short">${SHORT[k]}</span>` : l}</span>${
            k === "waiting" ? `<span class="count needs" aria-label="${WAITING_COUNT} items">${WAITING_COUNT}</span>` : ""}</a>`).join("") +
        `<div class="spacer"></div><a class="nav collapse" href="#" role="button" title="Collapse menu">${icon("collapse")}<span class="label">Collapse</span></a></div>`;
      rail.querySelector('[data-k="more"]').addEventListener("click", (e) => { e.preventDefault(); toggleMenu(); });
      rail.querySelector(".collapse").addEventListener("click", (e) => {
        e.preventDefault();
        const c = root.dataset.rail === "collapsed";
        if (c) delete root.dataset.rail; else root.dataset.rail = "collapsed";
        localStorage.setItem("intakt-rail", c ? "open" : "collapsed");
      });
    }
    const top = document.getElementById("topbar");
    if (top) {
      const proj = localStorage.getItem("intakt-project") || "all";
      top.innerHTML =
        `<img class="brand-m logo-light" src="assets/intakt-lockup.svg" alt="Intakt"><img class="brand-m logo-dark" src="assets/intakt-lockup-dark.svg" alt="Intakt">
        <button class="search-trigger" type="button" aria-haspopup="dialog">${icon("search")}<span class="ph">Search tasks, pull requests, settings…</span><span class="ph-short">Search</span><kbd>${isMac() ? "⌘K" : "Ctrl K"}</kbd></button>
        <div class="grow"></div>
        <label class="sr-only" for="projfilter">Project</label>
        <select class="select" id="projfilter">
          <option value="all">All projects</option>${PROJECTS.map((p) => `<option${p === proj ? " selected" : ""}>${p}</option>`).join("")}
        </select>
        <button class="icon-btn theme-toggle" type="button" aria-label="Switch light or dark theme">${icon("moon", "moon")}${icon("sun", "sun")}</button>
        <button class="icon-btn menu-btn" type="button" aria-label="Menu" aria-expanded="false">${icon("menu")}</button>`;
      top.querySelector(".theme-toggle").addEventListener("click", () => setTheme(curTheme() === "dark" ? "light" : "dark"));
      top.querySelector(".search-trigger").addEventListener("click", openSearch);
      const sel = top.querySelector("#projfilter");
      sel.addEventListener("change", () => { localStorage.setItem("intakt-project", sel.value); applyProjectFilter(); });
      top.querySelector(".menu-btn").addEventListener("click", (e) => toggleMenu(e.currentTarget));
    }
    document.body.insertAdjacentHTML("beforeend",
      `<div class="overlay" id="overlay" role="dialog" aria-modal="true" aria-label="Search">
        <div class="palette"><div class="q">${icon("search")}<input id="q" type="text" placeholder="Search tasks, pull requests, settings…" autocomplete="off" role="combobox" aria-expanded="true" aria-controls="results"><kbd>Esc</kbd><button type="button" class="btn quiet q-cancel" id="qcancel">Cancel</button></div>
        <div class="results" id="results" role="listbox"></div>
        <footer><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></footer></div></div>
      <div class="toast" id="toast" role="status" aria-live="polite"><span></span><button type="button">Undo</button></div>
      <div class="menu" id="menu"><nav aria-label="All pages">${NAV.filter((n) => n[0] !== "more").map(([k, l, h]) => `<a href="${h}"${k === page ? ' aria-current="page"' : ""}>${icon(k)}${l}</a>`).join("")}</nav></div>`);
    document.getElementById("menu").addEventListener("click", (e) => { if (e.target.id === "menu") toggleMenu(); });
    applyProjectFilter();
  }
  function toggleMenu(btn) {
    const m = document.getElementById("menu");
    m.classList.toggle("open");
    document.querySelector(".menu-btn")?.setAttribute("aria-expanded", m.classList.contains("open"));
  }
  /* The project filter and a column's page limit are separate: the filter marks what does not match (data-filtered),
     the page limit then hides the matching cards past the first `pageLimit`, so filtering never undoes "Show more". */
  function applyProjectFilter() {
    const p = localStorage.getItem("intakt-project") || "all";
    document.querySelectorAll("[data-project]").forEach((el) => {
      const out = p !== "all" && el.dataset.project !== p && el.dataset.project !== "all";
      el.toggleAttribute("data-filtered", out);
      el.hidden = out;
    });
    document.querySelectorAll(".col").forEach((c) => {
      const n = [...c.querySelectorAll(".card")].filter((x) => !x.hasAttribute("data-filtered")).length;
      // the heading, the folded column button and its label, and the phone switcher must all change together: a reader who
      // meets the column through any one of them (or a screen reader) would otherwise see a total the filter no longer shows
      const name = c.getAttribute("aria-label"), key = (c.id || "").replace(/^col-/, "");
      const counts = [c.querySelector(".col-head .count"), c.querySelector(".col-fold .count"),
        key ? document.querySelector(`#colswitch [data-col="${key}"] .count`) : null];
      counts.forEach((cnt) => { if (!cnt) return; cnt.textContent = n; if (c.classList.contains("col-needs")) cnt.classList.toggle("needs", n > 0); });
      const fold = c.querySelector(".col-fold"); if (fold && name) fold.setAttribute("aria-label", `Show ${name}, ${n} tasks`);
      paginate(c);
    });
  }
  function paginate(c) {
    if (!c.dataset.pageLimit) return;
    const limit = +c.dataset.pageLimit, open = c.dataset.expanded === "1";
    const cards = [...c.querySelectorAll(".card")].filter((x) => !x.hasAttribute("data-filtered"));
    cards.forEach((x, i) => (x.hidden = !open && i >= limit));
    const btn = c.querySelector(".more-btn");
    if (btn) { btn.style.display = open || cards.length <= limit ? "none" : ""; btn.textContent = `Show ${cards.length - limit} more`; }
  }
  const isMac = () => /Mac|iPhone|iPad/.test(navigator.platform);

  /* ---------- Search ---------- */
  const PRN = { 598: 611, 589: 607, 594: 609, 603: 613, 205: 88, 71: 41, 601: 612, 596: 606, 203: 86, 69: 39, 199: 84 };
  const prNum = (n) => PRN[n] || n + 10;
  function index() {
    const items = [];
    TASKS.forEach((t) => items.push({ g: "Tasks", ic: "task", label: `#${t.n} ${t.t}`, sub: `${t.p}, ${t.col.toLowerCase()}`, href: "task.html" }));
    TASKS.filter((t) => ["Checking", "In review", "Waiting on you", "Merging", "Done"].includes(t.col))
      .forEach((t) => items.push({ g: "Pull requests", ic: "pr", label: `Pull request ${prNum(t.n)}: ${t.t}`, sub: `${t.p}, for #${t.n}`, href: "task.html" }));
    PROJECTS.forEach((p) => items.push({ g: "Projects", ic: "projects", label: p, sub: p === "field-notes" ? "Project, paused" : "Project, running", href: "projects.html#" + p }));
    SETTINGS.forEach(([l, g, h]) => items.push({ g: "Settings", ic: "settings", label: l, sub: `All projects, ${g.toLowerCase()}`, href: h }));
    ACTIONS.forEach(([l, ic, fn]) => items.push({ g: "Actions", ic, label: l, sub: /field-notes/.test(l) ? "field-notes" : "All projects", run: fn }));
    return items;
  }
  let ALL, shown = [], sel = 0, lastFocus;
  function openSearch(prefill) {
    ALL = ALL || index();
    lastFocus = document.activeElement;
    const o = document.getElementById("overlay");
    o.classList.add("open");
    const q = document.getElementById("q");
    q.value = typeof prefill === "string" ? prefill : "";
    q.focus();
    runSearch();
  }
  function closeSearch() { document.getElementById("overlay").classList.remove("open"); lastFocus?.focus?.(); }
  const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  function hl(text, q) {
    if (!q) return esc(text);
    const i = text.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 ? esc(text) : esc(text.slice(0, i)) + "<mark>" + esc(text.slice(i, i + q.length)) + "</mark>" + esc(text.slice(i + q.length));
  }
  function runSearch() {
    const q = document.getElementById("q").value.trim();
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    let res = ALL.filter((it) => words.every((w) => (it.label + " " + it.sub + " " + it.g).toLowerCase().includes(w)));
    const order = ["Recent", "Actions", "Tasks", "Pull requests", "Projects", "Settings"];
    if (!q) {
      const pick = (lbl) => ALL.find((x) => x.label.startsWith(lbl));
      const recent = ["#598 ", "Pull request 611", "#589 ", "acme-shop", "Build slots"].map(pick).filter(Boolean).map((x) => ({ ...x, g: "Recent" }));
      res = [...recent, ...ALL.filter((x) => x.g === "Actions").slice(0, 3)];
    }
    res.sort((a, b) => order.indexOf(a.g) - order.indexOf(b.g));
    shown = res.slice(0, 40); sel = 0;
    const box = document.getElementById("results");
    if (!shown.length) { box.innerHTML = `<div class="none"><p><strong>No results for “${esc(q)}”</strong></p><p>Try a task number such as 598, a project such as acme-shop, or a setting such as build slots.</p><button type="button" class="btn secondary sm" id="qclear">Clear the search</button></div>`;
      document.getElementById("qclear").onclick = () => { const i = document.getElementById("q"); i.value = ""; i.focus(); runSearch(); };
      return; }
    let html = "", g = "";
    shown.forEach((it, i) => {
      if (it.g !== g) { g = it.g; html += `<div class="grp" role="presentation">${g}</div>`; }
      html += `<div class="res" role="option" id="r${i}" data-i="${i}" aria-selected="${i === 0}">${icon(it.ic)}<span class="lbl">${hl(it.label, words[0] || "")}</span>${it.sub ? `<span class="sub">${esc(it.sub)}</span>` : ""}</div>`;
    });
    box.innerHTML = html;
    document.getElementById("q").setAttribute("aria-activedescendant", "r0");
  }
  function move(d) {
    if (!shown.length) return;
    sel = (sel + d + shown.length) % shown.length;
    document.querySelectorAll(".res").forEach((r) => r.setAttribute("aria-selected", +r.dataset.i === sel));
    const el = document.getElementById("r" + sel);
    el.scrollIntoView({ block: "nearest" });
    document.getElementById("q").setAttribute("aria-activedescendant", "r" + sel);
  }
  function choose(i) {
    const it = shown[i]; if (!it) return;
    closeSearch();
    if (it.run) it.run(); else location.href = it.href;
  }
  function wireSearch() {
    document.addEventListener("keydown", (e) => {
      const o = document.getElementById("overlay");
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") { e.preventDefault(); o.classList.contains("open") ? closeSearch() : openSearch(); return; }
      if (e.key === "/" && !o.classList.contains("open") && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); openSearch(); return; }
      if (!o.classList.contains("open")) return;
      if (e.key === "Escape") { e.preventDefault(); closeSearch(); }
      else if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
      else if (e.key === "Enter") { e.preventDefault(); choose(sel); }
      else if (e.key === "Tab") { e.preventDefault(); }
    });
    document.getElementById("q").addEventListener("input", runSearch);
    document.getElementById("qcancel").addEventListener("click", closeSearch);
    document.getElementById("results").addEventListener("click", (e) => { const r = e.target.closest(".res"); if (r) choose(+r.dataset.i); });
    document.getElementById("overlay").addEventListener("click", (e) => { if (e.target.id === "overlay") closeSearch(); });
  }

  /* ---------- Toast ---------- */
  let toastTimer;
  function toast(msg, undo) {
    const t = document.getElementById("toast");
    t.querySelector("span").textContent = msg;
    const b = t.querySelector("button");
    b.style.display = undo ? "" : "none";
    b.onclick = () => { t.classList.remove("show"); undo && undo(); };
    t.classList.remove("show"); void t.offsetWidth; t.classList.add("show");
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove("show"), 5000);
  }

  /* ---------- Board ---------- */
  function cardHTML(t) {
    const meta = [`<span title="Time in this column">${icon("clock", "s")}${t.age}</span>`];
    if (t.slot) meta.push(`<span>${icon("bolt", "s")}${t.slot}, ${t.model}</span>`);
    if (t.round) meta.push(`<span>Round ${t.round}</span>`);
    meta.push(`<span class="minor">Size ${t.s}</span>`);
    if (t.cost) meta.push(`<span class="minor" title="Cost so far">$${t.cost}</span>`);
    const block = t.reason ? `<p class="blocker${t.broken ? " broken" : ""}">${icon(t.reason[0], "s")}${t.reason[1]}</p>` : "";
    return `<a class="card${t.needs ? " needs" : ""}${t.broken ? " broken" : ""}" href="${t.needs ? "waiting.html" : "task.html"}" data-project="${t.p}">
      <div class="top">${t.needs ? '<span class="dot needs"></span>' : ""}<span>#${t.n}</span><span class="pname">${t.p}</span></div>
      <div class="title">${esc(t.t)}</div>${t.needs ? `<p class="blocker ask">${esc(t.needs)}</p>` : ""}${block}
      <p class="meta">${meta.join("")}</p></a>`;
  }
  /* An empty column says what lands there and when, on the first day and after (DESIGN.md, First day). */
  const EMPTY_LINES = {
    "Waiting on you": "What only you can decide appears here when Intakt asks you",
    Backlog: "Tasks appear here when Intakt takes in an issue",
    Ready: "Tasks appear here once they are ready to plan",
    Planned: "Tasks appear here once their plan is made",
    Building: "Tasks appear here while an agent builds them",
    Checking: "Tasks appear here while their checks run",
    "In review": "Reviews appear here while the Code reviewers review a change",
    Merging: "Tasks appear here while they wait to merge",
    Done: "Reviews appear here once the Code reviewers have decided them",
  };
  function renderBoard() {
    const lanes = document.getElementById("lanes");
    if (lanes) lanes.innerHTML = LANES.map((l) => l.idle
      ? `<div class="lane idle"><div class="who"><span class="dot idle"></span>${l.who}</div><div class="what">${l.idle}</div></div>`
      : `<div class="lane" data-project="${l.p}"><div class="who"><span class="dot ok"></span>${l.who}<span class="elapsed">${l.el}</span></div>
         <div class="what"><a href="task.html">#${l.task} ${esc(l.t)}</a></div>
         <p class="meta"><span>${icon("bolt", "s")}${l.model}</span><span>${l.p}</span></p></div>`).join("");
    const busy = LANES.filter((l) => !l.idle), idle = LANES.length - busy.length;
    const wd = document.getElementById("wdots");
    if (wd) {
      wd.innerHTML = LANES.map((l) => `<span class="dot ${l.idle ? "idle" : "ok"}"></span>`).join("");
      document.getElementById("wsum").innerHTML = busy.length ? `${busy.length} working, ${idle} idle<span class="w-detail">: building #612 and #208, reviewing #598</span>` : "Nobody is working yet: reviewers appear here while they review a change";
    }
    const board = document.getElementById("board");
    if (!board) return;
    board.innerHTML = COLUMNS.map((c) => {
      const ts = TASKS.filter((t) => t.col === c);
      const needs = c === "Waiting on you";
      const fold = COLLAPSED.includes(c) && innerWidth < 2200;
      return `<section class="col${needs ? " col-needs" : ""}${fold ? " collapsed" : ""}" id="col-${slug(c)}" aria-label="${c}">
        <button type="button" class="col-fold" aria-expanded="false" aria-controls="cards-${slug(c)}" aria-label="Show ${c}, ${ts.length} tasks"><span>${c}</span><span class="count">${ts.length}</span></button>
        <div class="col-head"><h3>${c}</h3><span class="count${needs && ts.length ? " needs" : ""}">${ts.length}</span>${fold ? `<button type="button" class="btn quiet sm col-hide" aria-label="Collapse ${c}">Hide</button>` : ""}</div>
        ${needs ? '<a class="also" href="waiting.html#w-login">Also waiting: 1 account login</a>' : ""}<div class="col-cards" id="cards-${slug(c)}">${ts.map(cardHTML).join("") || `<p class="col-empty">${EMPTY_LINES[c]}</p>`}</div></section>`;
    }).join("");
    const sw = document.getElementById("colswitch");
    if (sw) {
      sw.innerHTML = COLUMNS.map((c, i) => {
        const n = TASKS.filter((t) => t.col === c).length;
        return `<button type="button" data-col="${slug(c)}" aria-pressed="${i === 0}">${c}<span class="count${c === "Waiting on you" && n ? " needs" : ""}">${n}</span></button>`;
      }).join("");
      sw.addEventListener("click", (e) => {
        const b = e.target.closest("[data-col]"); if (!b) return;
        const col = document.getElementById("col-" + b.dataset.col);
        board.scrollTo({ left: col.offsetLeft - board.offsetLeft - 16, behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
      });
      let t;
      board.addEventListener("scroll", () => {
        clearTimeout(t);
        t = setTimeout(() => {
          const cols = [...board.querySelectorAll(".col")];
          const i = Math.round(board.scrollLeft / (cols[1].offsetLeft - cols[0].offsetLeft));
          sw.querySelectorAll("button").forEach((b, j) => b.setAttribute("aria-pressed", j === i));
          const btn = sw.querySelectorAll("button")[i];
          if (btn) sw.scrollLeft = Math.max(0, btn.offsetLeft - sw.offsetLeft - 16);
        }, 80);
      });
    }
    const map = document.getElementById("stagemap"), wrap = document.getElementById("boardwrap");
    if (map) {
      const bar = document.getElementById("smbar");
      bar.innerHTML = COLUMNS.map((c) => `<button type="button" class="sm-seg" data-col="${slug(c)}" title="${c}"><span class="sr-only">${c}</span></button>`).join("");
      bar.addEventListener("click", (e) => {
        const b = e.target.closest("[data-col]"); if (!b) return;
        const col = document.getElementById("col-" + b.dataset.col);
        board.scrollTo({ left: col.offsetLeft - board.offsetLeft - 8, behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
      });
      const update = () => {
        const over = board.scrollWidth > board.clientWidth + 2;
        map.hidden = !over;
        wrap.classList.toggle("more-right", over && board.scrollLeft + board.clientWidth < board.scrollWidth - 4);
        wrap.classList.toggle("more-left", over && board.scrollLeft > 4);
        if (!over) return;
        const cols = [...board.querySelectorAll(".col")], L = board.scrollLeft, R = L + board.clientWidth, x0 = board.offsetLeft;
        const vis = cols.map((c) => { const a = c.offsetLeft - x0, b = a + c.offsetWidth; return a >= L - 4 && b <= R + 4; });
        bar.querySelectorAll(".sm-seg").forEach((s, i) => s.classList.toggle("in", vis[i]));
        const first = vis.indexOf(true) + 1, last = vis.lastIndexOf(true) + 1;
        document.getElementById("smtext").textContent = `Columns ${first} to ${last} of ${cols.length}`;
      };
      board.addEventListener("scroll", update, { passive: true });
      addEventListener("resize", update);
      board.addEventListener("click", () => setTimeout(update, 0));
      update();
    }
    board.addEventListener("click", (e) => {
      const f = e.target.closest(".col-fold, .col-hide"); if (!f) return;
      const col = f.closest(".col"), open = f.classList.contains("col-fold");
      col.classList.toggle("collapsed", !open);
      col.querySelector(".col-fold").setAttribute("aria-expanded", open);
      (open ? col.querySelector(".col-hide") : col.querySelector(".col-fold")).focus();
    });
  }
  const slug = (s) => s.toLowerCase().replace(/[^a-z]+/g, "-");

  /* ---------- Small interactions ---------- */
  function wireCommon() {
    document.addEventListener("click", (e) => {
      const c = e.target.closest("[data-copy]");
      if (c) {
        navigator.clipboard?.writeText(c.dataset.copy).catch(() => {});
        const old = c.innerHTML; c.innerHTML = icon("check", "s") + "Copied";
        setTimeout(() => (c.innerHTML = old), 1600);
      }
      const cf = e.target.closest("[data-confirm]");
      if (cf && !cf.hasAttribute("data-armed")) {
        e.preventDefault(); e.stopImmediatePropagation();
        cf.dataset.label = cf.innerHTML; cf.setAttribute("data-armed", ""); cf.textContent = cf.dataset.confirm;
        setTimeout(() => { if (cf.hasAttribute("data-armed")) { cf.removeAttribute("data-armed"); cf.innerHTML = cf.dataset.label; } }, 4000);
        return;
      }
      if (cf) { cf.removeAttribute("data-armed"); cf.innerHTML = cf.dataset.label; }
      const hl = e.target.closest('a[href^="#"]');
      if (hl && hl.getAttribute("href").length > 1) {
        const tgt = document.getElementById(hl.getAttribute("href").slice(1));
        for (let d = tgt && tgt.closest("details"); d; d = d.parentElement.closest("details")) d.open = true;
      }
      const bt = e.target.closest(".banner-toggle");
      if (bt) { const open = bt.parentElement.classList.toggle("open"); bt.setAttribute("aria-expanded", open); return; }
      const fl = e.target.closest('.log-summary a[href^="#f-"]');
      if (fl) {
        const grid = document.getElementById("tgrid"); if (grid) grid.dataset.pane = "timeline";
        document.querySelectorAll(".ttabs [data-pane]").forEach((x) => x.setAttribute("aria-selected", x.dataset.pane === "timeline"));
      }
      const a = e.target.closest("[data-toast]");
      if (a) { e.preventDefault(); toast(a.dataset.toast, a.dataset.undo ? () => toast(a.dataset.undo) : null); }
    });
  }

  /* Desktop shows every Why; phones keep them closed behind the disclosure. */
  const syncWhy = () => document.querySelectorAll("details.why").forEach((d) => { d.open = innerWidth > 760 || d.dataset.user === "open"; });
  document.addEventListener("toggle", (e) => { if (e.target.matches?.("details.why") && innerWidth <= 760) e.target.dataset.user = e.target.open ? "open" : ""; }, true);
  addEventListener("resize", syncWhy);

  /* Glossary: one standard explanation per term, used the same way everywhere (DESIGN.md, Glossary). */
  const GLOSSARY = {
    version: "The exact commit Intakt built and checked. An approval covers this version only; a new push needs a new approval, unless it leaves what you approved exactly as it was (planned, for a limit like secrets: the same files, unchanged). A revised held CI push always needs its own approval.",
    hold: "Intakt stopped this push before checks ran, because it changes something only you can allow, such as CI files or protected files.",
    share: "This project's part of the week's AI budget. Intakt paces work so spend follows it.",
    round: "One build, its checks and its review. Sending work back starts the next round.",
    protected: "Files that always need your approval to change, such as secret handling or CI. You choose them per project.",
    reserve: "The smallest part of the week's capacity kept for this project, even when other projects are busy.",
    fix: "The act list: every P1 and P2 finding that passed the quote check, whatever the judge marked, and each P3 finding the judge kept, to fix before approval; a P3 gate gap goes to the follow-ups instead.",
    checked: "Judged: the judge weighed every reviewer's findings and gave the verdict.",
    pass: "A round: one review of the change, by its reviewers and then the judge.",
  };
  let termTip;
  function showTerm(t) {
    if (!termTip) { termTip = document.createElement("div"); termTip.className = "term-tip"; termTip.setAttribute("role", "tooltip"); termTip.id = "term-tip"; document.body.appendChild(termTip); }
    termTip.textContent = GLOSSARY[t.dataset.term] || "";
    termTip.style.display = "block";
    const r = t.getBoundingClientRect();
    termTip.style.left = Math.min(innerWidth - termTip.offsetWidth - 8, Math.max(8, r.left)) + scrollX + "px";
    termTip.style.top = r.bottom + scrollY + 6 + "px";
    t.setAttribute("aria-describedby", "term-tip");
  }
  const hideTerm = () => termTip && (termTip.style.display = "none");
  function wireTerms() {
    document.querySelectorAll(".term[data-term]").forEach((t) => {
      t.tabIndex = 0;
      t.addEventListener("pointerenter", () => showTerm(t)); t.addEventListener("focus", () => showTerm(t));
      t.addEventListener("pointerleave", hideTerm); t.addEventListener("blur", hideTerm);
    });
  }

  /* Example states for the board: ?demo=stress | reconnecting | firstday | loading */
  function applyDemo() {
    const main = document.getElementById("main"); if (!DEMO) return;
    const lead = main.querySelector(".page-head .lead");
    if (DEMO === "stress") {
      lead.textContent = "Six projects, 43 tasks ready. Long titles wrap to three lines; long project names truncate.";
      document.querySelectorAll(".col").forEach((c) => {
        if (c.querySelectorAll(".card").length <= 12) return;
        c.dataset.pageLimit = "12";
        c.querySelector(".col-cards").insertAdjacentHTML("beforeend", `<button type="button" class="btn secondary sm more-btn"></button>`);
        c.querySelector(".more-btn").onclick = () => { c.dataset.expanded = "1"; paginate(c); };
        paginate(c);
      });
    }
    if (DEMO === "reconnecting") {
      main.insertAdjacentHTML("afterbegin", `<div class="conn" role="status"><span class="conn-dot" aria-hidden="true"></span><p><strong>Reconnecting.</strong> The dashboard lost its connection to Intakt at 11:12:04. You are seeing what it knew then; actions wait until it is back. Next try in 5 s.</p><button class="btn secondary sm" type="button">Try now</button></div>`);
      document.body.classList.add("offline");
      main.querySelectorAll(".page-head .btn, .banner .btn").forEach((b) => { b.setAttribute("aria-disabled", "true"); b.classList.add("is-disabled"); });
      document.querySelectorAll(".lane .elapsed").forEach((e) => (e.textContent += " at 11:12"));
    }
    if (DEMO === "firstday" || DEMO === "loading") {
      document.querySelectorAll(".also").forEach((a) => a.remove());
    }
    if (DEMO === "firstday") {
      document.querySelectorAll(".rail .count.needs").forEach((c) => c.remove());
      document.querySelectorAll(".banner").forEach((b) => b.remove());
      lead.innerHTML = '<a href="setup.html">Nothing is running yet. Start the first review.</a>';
      // Phones keep the first day's actions: Set up Intakt is the only way forward from an empty board.
      const actions = main.querySelector(".page-head .actions");
      actions.classList.remove("hide-phone");
      actions.innerHTML = '<a class="btn primary" href="setup.html">Set up Intakt</a><a class="btn secondary" href="history.html">Open the review list</a>';
      const files = { light: "assets/octopus-waiting-light-144.webp", dark: "assets/octopus-waiting-dark-144.webp" };
      const octopus = (theme) => `<img class="octopus-${theme}" src="${files[theme]}" alt="" width="144" height="144">`;
      document.getElementById("working").insertAdjacentHTML("beforebegin", `<section class="firstday" aria-labelledby="firstday-h">${octopus("light")}${octopus("dark")}<div><h2 id="firstday-h">Give Intakt its first task</h2>
        <ol><li>Choose <strong>Set up Intakt</strong> and add an AI provider and a project.</li>
        <li>Start the first review there. It shows up in In review while the Code reviewers review the change, then in Done once they have decided.</li>
        <li>Reviewers show up under Who is working while they review it.</li></ol></div></section>`);
      // With no lane the strip would open to nothing, so it is a plain line without a chevron, as the built board renders it.
      document.getElementById("working").outerHTML = '<p class="working working-none" id="working">Nobody is working yet: reviewers appear here while they review a change</p>';
    }
    if (DEMO === "loading") {
      document.querySelectorAll(".banner").forEach((b) => b.remove());
      lead.innerHTML = '<span class="skel" style="width:280px"></span>';
      document.getElementById("wsum").innerHTML = '<span class="skel" style="width:220px"></span>';
      document.getElementById("wdots").innerHTML = "";
      document.querySelectorAll(".col-cards").forEach((cc, i) => {
        if (cc.closest(".collapsed")) return;
        cc.innerHTML = Array.from({ length: [2, 0, 2, 1, 2, 1, 1, 1, 0][i] || 1 }, () => '<div class="card skel-card" aria-hidden="true"><span class="skel" style="width:40%"></span><span class="skel" style="width:90%"></span><span class="skel" style="width:70%"></span><span class="skel" style="width:55%"></span></div>').join("");
      });
      document.querySelectorAll(".col-head .count, .colswitch .count").forEach((c) => (c.innerHTML = "&nbsp;"));
      document.getElementById("main").setAttribute("aria-busy", "true");
    }
  }

  window.Intakt = { icon, toast, renderBoard, openSearch, TASKS, applyProjectFilter };

  renderShell();
  syncWhy();
  wireTerms();
  wireSearch();
  wireCommon();
  if (document.getElementById("board")) { renderBoard(); applyProjectFilter(); applyDemo(); }
  document.querySelectorAll("[data-icon]").forEach((el) => el.insertAdjacentHTML("afterbegin", icon(el.dataset.icon, el.dataset.iconCls)));
})();
