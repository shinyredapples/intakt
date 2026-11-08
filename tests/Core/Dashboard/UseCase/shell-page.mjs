import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const layout = readFileSync(new URL('../../../../templates/dashboard/base.html.twig', import.meta.url), 'utf8');
const headScript = layout.match(/<head>[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];
const shellScript = layout.match(/<\/main>[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];

class FakeElement {
    attributes = new Map();
    listeners = new Map();
    title = '';

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

    constructor(systemTheme, { storageRefused = false, platform = 'Linux x86_64' } = {}) {
        this.systemTheme = systemTheme;
        this.storageRefused = storageRefused;
        this.platform = platform;
    }

    open() {
        const page = { focused: null };
        const root = { dataset: {} };
        const kbd = new FakeElement(page, 'Ctrl K');
        const search = new FakeElement(page, '', { kbd });
        const railToggle = new FakeElement(page);
        const themeToggles = [new FakeElement(page), new FakeElement(page)];
        const body = new FakeElement(page);
        const document = {
            documentElement: root,
            body,
            querySelector: (selector) => ({ '[data-rail-toggle]': railToggle, '.search-trigger': search })[selector] ?? null,
            querySelectorAll: (selector) => (selector === '[data-theme-toggle]' ? themeToggles : []),
        };
        const context = {
            document,
            localStorage: this.localStorage(),
            matchMedia: (query) => ({ matches: query === '(prefers-color-scheme: dark)' && this.systemTheme === 'dark' }),
            navigator: { platform: this.platform },
        };

        runInNewContext(headScript, context);
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
            searchHasFocus: () => page.focused === search,
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
