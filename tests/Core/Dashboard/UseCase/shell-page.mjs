import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const layout = readFileSync(new URL('../../../../templates/dashboard/base.html.twig', import.meta.url), 'utf8');
const headScript = layout.match(/<head>[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];
const shellScript = layout.match(/<\/main>[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];

class FakeElement {
    attributes = new Map();
    listeners = new Map();
    title = '';
    isConnected = true;

    constructor(page, textContent = '', children = {}) {
        this.page = page;
        this.textContent = textContent;
        this.children = children;
    }

    querySelector(selector) {
        return this.children[selector] ?? null;
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    toggleAttribute(name, force) {
        if (force) {
            this.attributes.set(name, '');
        } else {
            this.attributes.delete(name);
        }
    }

    matches() {
        return false;
    }

    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    dispatch(type, event = {}) {
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
    }

    focus() {
        this.page.focused = this;
    }
}

// The project filter as the layout renders it: All projects with the empty value, then one option per project, the linked projects marked selected and the projects only the page adds marked as its own. A browser shows the last marked option when several are.
class FakeSelect extends FakeElement {
    constructor(page, projects, linked, pageProjects = []) {
        super(page);
        this.options = [{ value: '', textContent: 'All projects' }, ...projects.map((project) => ({ value: project, textContent: project }))]
            .map((option) => ({ ...option, defaultSelected: linked.includes(option.value), dataset: pageProjects.includes(option.value) ? { pageOption: '' } : {} }));
        this.value = this.options.findLast((option) => option.defaultSelected)?.value ?? '';
    }

    get selectedOptions() {
        return this.options.filter((option) => option.value === this.value);
    }

    // As in a browser, replacing the options leaves the first one chosen.
    replaceChildren(...options) {
        this.options = options;
        this.value = options[0]?.value ?? '';
    }
}

// A board column as the server renders it, so also as a push brings it: open unless it starts collapsed, never expanded, its strip saying which.
class FakeColumn extends FakeElement {
    constructor(page, id, collapsed = false) {
        super(page, '', { '.col-fold': new FakeElement(page) });
        this.cards = [Object.assign(new FakeElement(page), { id: `${id}-card` })];
        this.cards[0].setAttribute('href', '/reviews/round-1');
        // The card's glossary terms, each focusable without a link of its own.
        this.terms = [new FakeElement(page, 'reviewer'), new FakeElement(page, 'judge')];
        this.terms.forEach((term) => term.setAttribute('tabindex', '0'));
        this.cards[0].closest = (selector) => (selector === 'a[href]' ? this.cards[0] : null);
        this.cards[0].querySelectorAll = (selector) => (selector === '[tabindex]' ? this.terms : []);
        this.terms.forEach((term) => (term.closest = (selector) => (selector === 'a[href]' ? this.cards[0] : null)));
        this.children['.col-fold'].setAttribute('aria-expanded', !collapsed);
        this.id = id;
        this.classes = new Set(['col', ...(collapsed ? ['collapsed'] : [])]);
        this.classList = {
            contains: (name) => this.classes.has(name),
            toggle: (name, force) => (force ? this.classes.add(name) : this.classes.delete(name)),
        };
    }

    matches(selector) {
        return selector === '.board > .col';
    }

    contains(element) {
        return this.cards.includes(element) || this.terms.includes(element);
    }

    querySelectorAll(selector) {
        return selector === 'a[href]' ? this.cards : [];
    }
}

// The board's Working now as the server renders it, so also as a push brings it: one card link per change in progress, each with its project, none hidden.
class FakeWorkingNow extends FakeElement {
    id = 'working-now';

    constructor(page, projects) {
        super(page);
        this.cards = projects.map((project, index) => {
            const card = Object.assign(new FakeElement(page), { dataset: { project }, hidden: false });
            card.setAttribute('href', `/reviews/round-${index + 1}`);
            card.closest = (selector) => (selector === 'a[href]' ? card : null);

            return card;
        });
    }

    contains(element) {
        return this.cards.includes(element);
    }

    querySelectorAll(selector) {
        return selector === 'a[href]' ? this.cards : [];
    }
}

// The Projects page's cards as a push brings them: one per project, none hidden.
class FakeProjectCards extends FakeElement {
    id = 'project-cards';

    constructor(page, projects) {
        super(page);
        this.cards = projects.map((project) => Object.assign(new FakeElement(page), { dataset: { project }, hidden: false }));
    }
}

class FakeKey {
    defaultPrevented = false;

    constructor(key, modifiers) {
        this.key = key;
        this.ctrlKey = modifiers.ctrlKey ?? false;
        this.metaKey = modifiers.metaKey ?? false;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

// Runs the two scripts of templates/dashboard/base.html.twig against just enough of a browser: the head script before the first paint, the shell script after the shell.
export class Browser {
    storage = new Map();

    constructor(systemTheme, { storageRefused = false, platform = 'Linux x86_64', projects = ['alpha', 'beta'], rows = ['beta', 'alpha', 'beta'], address = 'http://localhost/reviews', linked = null, more = false, phone = false, pageProjects = [] } = {}) {
        this.pageProjects = pageProjects;
        this.systemTheme = systemTheme;
        this.more = more;
        this.phone = phone;
        this.storageRefused = storageRefused;
        this.platform = platform;
        this.projects = projects;
        this.rows = rows;
        this.address = address;
        this.linked = linked;
    }

    // The document element, the width query and the document's own listeners outlive a Turbo visit; the body and everything in it do not.
    open() {
        const page = { focused: null };
        const root = { dataset: {} };
        const phoneWidth = Object.assign(new FakeElement(page), { matches: this.phone });
        const documentListeners = new Map();
        const history = {
            state: { turbo: { restorationIdentifier: 'visit' } },
            replaceState: (state, unused, address) => {
                history.state = state;
                this.address = String(address);
            },
        };
        let shown = {};
        let shownAll = {};
        const document = {
            documentElement: root,
            get activeElement() {
                return page.focused;
            },
            body: null,
            addEventListener: (type, listener) => documentListeners.set(type, [...(documentListeners.get(type) ?? []), listener]),
            dispatchEvent: (event) => (documentListeners.get(event.type) ?? []).forEach((listener) => listener(event)),
            getElementById: (id) => shown[`#${id}`] ?? null,
            querySelector: (selector) => shown[selector] ?? null,
            querySelectorAll: (selector) => shownAll[selector] ?? [],
        };
        const context = {
            document,
            localStorage: this.localStorage(),
            matchMedia: (query) => (query === '(max-width: 760px)' ? phoneWidth : { matches: query === '(prefers-color-scheme: dark)' && this.systemTheme === 'dark' }),
            navigator: { platform: this.platform },
            location: { href: this.address },
            history,
            URL,
            Event,
            TextEncoder,
        };
        const window = {
            page,
            root,
            phoneWidth,
            documentListeners,
            history,
            context,
            show: (body, all) => {
                document.body = body['body'] ?? null;
                shown = body;
                shownAll = all;
            },
        };

        runInNewContext(headScript, context);

        return this.render(window);
    }

    render(window) {
        const { page, root, phoneWidth, documentListeners, history, context, show } = window;
        const kbd = new FakeElement(page, 'Ctrl K');
        const search = new FakeElement(page, '', { kbd });
        const railToggle = new FakeElement(page);
        const themeToggles = [new FakeElement(page), new FakeElement(page)];
        const body = new FakeElement(page);
        // The server marks the project of the address selected when the install lists it; a test may name the marked options itself.
        const projectFilter = new FakeSelect(page, this.projects, this.linked ?? [new URL(this.address).searchParams.get('project')], this.pageProjects);
        const rows = this.rows.map((project) => Object.assign(new FakeElement(page), { dataset: { project }, hidden: false }));
        // The layout renders the More item and its sheet only for a page that has pages under More.
        const more = this.more ? new FakeElement(page) : null;
        const sheet = this.more ? Object.assign(new FakeElement(page), { hidden: true }) : null;
        const sheetLink = new FakeElement(page);
        // A link to a project the install lists brings the breadcrumb naming it and the Projects item marked current for its section.
        const linkedProject = new URL(this.address).searchParams.get('project');
        const trail = this.projects.includes(linkedProject) ? Object.assign(new FakeElement(page), { dataset: { projectTrail: linkedProject } }) : null;
        const projectsItem = new FakeElement(page);
        const workingNow = new FakeWorkingNow(page, ['alpha', 'beta']);
        const shownElements = { body, '[data-rail-toggle]': railToggle, '.search-trigger': search, '#projfilter': projectFilter, '[data-more-toggle]': more, '[data-more-sheet]': sheet, '[data-project-trail]': trail, '#col-in-review': new FakeColumn(page, 'col-in-review'), '#col-done': new FakeColumn(page, 'col-done', true), '#working-now': workingNow, '#project-cards': new FakeProjectCards(page, []) };
        if (trail) {
            projectsItem.setAttribute('aria-current', 'page');
            trail.remove = () => {
                trail.isConnected = false;
                delete shownElements['[data-project-trail]'];
            };
        }
        // The rows' empty line as the server renders them: hidden, its container holding the rows.
        const emptyLine = Object.assign(new FakeElement(page), { hidden: true, parentElement: Object.assign(new FakeElement(page), { querySelectorAll: (selector) => (selector === '[data-project]' ? rows : []) }) });
        const allShown = { '[data-theme-toggle]': themeToggles, '[data-project]': [...rows, ...workingNow.cards], '[data-section-current]': trail ? [projectsItem] : [], '[data-project-empty]': [emptyLine] };

        show(shownElements, allShown);
        context.location.href = this.address;
        runInNewContext(shellScript, context);

        return {
            theme: () => root.dataset.theme,
            rail: () => root.dataset.rail,
            flip: (toggle = 0) => themeToggles[toggle].dispatch('click'),
            toggleRail: () => railToggle.dispatch('click'),
            railToggle,
            shortcutHint: () => kbd.textContent,
            press: (key, modifiers = {}) => {
                const event = new FakeKey(key, modifiers);
                body.dispatch('keydown', event);

                return event;
            },
            chooseProject: (value) => {
                projectFilter.value = value;
                projectFilter.dispatch('change');
            },
            projectFilter,
            breadcrumbShown: () => trail?.isConnected ?? false,
            projectsMarked: () => projectsItem.hasAttribute('aria-current'),
            history,
            shownRows: () => rows.filter((row) => !row.hidden).map((row) => row.dataset.project),
            emptyLineShown: () => !emptyLine.hidden,
            // What a script listening on the document, such as assets/column-more.js, sees of each filter run: the rows it left shown.
            listenForShownProjects: () => {
                const seen = [];
                documentListeners.set('intakt:project-shown', [...(documentListeners.get('intakt:project-shown') ?? []), () => seen.push(rows.filter((row) => !row.hidden).map((row) => row.dataset.project))]);

                return seen;
            },
            more,
            tapMore: () => more.dispatch('click'),
            tapBackdrop: () => sheet.dispatch('click', { target: sheet }),
            tapSheetLink: () => sheet.dispatch('click', { target: sheetLink }),
            sheetOpen: () => !sheet.hidden,
            moreHasFocus: () => page.focused === more,
            leave: () => {
                projectFilter.isConnected = false;
                show({}, {});
            },
            // What Turbo does on a visit: replace the body with the next page's and run the shell script again, leaving the document element and the document's listeners as they are.
            visit: () => {
                projectFilter.isConnected = false;

                return this.render(window);
            },
            listeners: () => ({
                streamRender: (documentListeners.get('turbo:before-stream-render') ?? []).length,
                widthChange: (phoneWidth.listeners.get('change') ?? []).length,
            }),
            // What Turbo does with a stream: tell the document, then run the render, which for an update replaces the select's options and leaves the value at the first one.
            pushStream: (target, projects) => {
                const stream = new FakeElement(page);
                stream.setAttribute('target', target);
                const event = {
                    target: stream,
                    detail: {
                        render: () => {
                            projectFilter.options = [{ value: '', textContent: 'All projects' }, ...projects.map((project) => ({ value: project, textContent: project }))].map((option) => ({ ...option, dataset: {} }));
                            projectFilter.value = '';
                        },
                    },
                };
                (documentListeners.get('turbo:before-stream-render') ?? []).forEach((listener) => listener(event));
                event.detail.render(stream);
            },
            column: (id) => shownElements[`#${id}`],
            focused: () => page.focused,
            focusedLink: () => page.focused?.getAttribute('href') ?? null,
            // What Turbo does with a replace stream for a board column: put the server's column, in its start state, where the shown one was.
            pushColumn: (id, collapsed = id === 'col-done') => {
                const stream = new FakeElement(page);
                stream.setAttribute('action', 'replace');
                stream.setAttribute('target', id);
                const event = {
                    target: stream,
                    detail: {
                        render: () => {
                            shownElements[`#${id}`] = new FakeColumn(page, id, collapsed);
                            show(shownElements, allShown);
                        },
                    },
                };
                (documentListeners.get('turbo:before-stream-render') ?? []).forEach((listener) => listener(event));
                event.detail.render(stream);
            },
            workingNow: () => shownElements['#working-now'],
            shownWorkingNow: () => shownElements['#working-now'].cards.filter((card) => !card.hidden).map((card) => card.dataset.project),
            // What Turbo does with the board's replace stream for Working now: put the server's section, every project's card shown, where the shown one was.
            pushWorkingNow: (projects = ['alpha', 'beta']) => {
                const stream = new FakeElement(page);
                stream.setAttribute('action', 'replace');
                stream.setAttribute('target', 'working-now');
                const event = {
                    target: stream,
                    detail: {
                        render: () => {
                            shownElements['#working-now'] = new FakeWorkingNow(page, projects);
                            show(shownElements, { ...allShown, '[data-project]': [...rows, ...shownElements['#working-now'].cards] });
                        },
                    },
                };
                (documentListeners.get('turbo:before-stream-render') ?? []).forEach((listener) => listener(event));
                event.detail.render(stream);
            },
            shownProjectCards: () => shownElements['#project-cards'].cards.filter((card) => !card.hidden).map((card) => card.dataset.project),
            // What Turbo does with the Projects page's replace stream: put the server's cards, every project's shown, where the shown ones were.
            pushProjectCards: (projects = ['alpha', 'beta']) => {
                const stream = new FakeElement(page);
                stream.setAttribute('action', 'replace');
                stream.setAttribute('target', 'project-cards');
                const event = {
                    target: stream,
                    detail: {
                        render: () => {
                            shownElements['#project-cards'] = new FakeProjectCards(page, projects);
                            show(shownElements, { ...allShown, '[data-project]': [...rows, ...shownElements['#project-cards'].cards] });
                        },
                    },
                };
                (documentListeners.get('turbo:before-stream-render') ?? []).forEach((listener) => listener(event));
                event.detail.render(stream);
            },
            resize: (phone) => {
                phoneWidth.matches = phone;
                phoneWidth.dispatch('change', phoneWidth);
            },
        };
    }

    localStorage() {
        const refuse = () => {
            if (this.storageRefused) {
                throw new Error('SecurityError');
            }
        };

        return {
            getItem: (key) => {
                refuse();

                return this.storage.get(key) ?? null;
            },
            setItem: (key, value) => {
                refuse();
                this.storage.set(key, String(value));
            },
        };
    }
}
