import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { loadController, root } from './stimulus-controller.mjs';

const layout = readFileSync(new URL('templates/dashboard/base.html.twig', root), 'utf8');
const headScript = layout.match(/<head>[\s\S]*?<script>([\s\S]*?)<\/script>/)[1];

const ThemeController = await loadController('theme');

class Browser {
    storage = new Map();

    constructor(systemTheme, { storageRefused = false } = {}) {
        this.systemTheme = systemTheme;
        this.storageRefused = storageRefused;
    }

    open() {
        const document = { documentElement: { dataset: {} } };
        const localStorage = {
            getItem: (key) => {
                if (this.storageRefused) {
                    throw new Error('SecurityError');
                }

                return this.storage.get(key) ?? null;
            },
            setItem: (key, value) => this.storage.set(key, String(value)),
        };
        const matchMedia = (query) => ({ matches: query === '(prefers-color-scheme: dark)' && this.systemTheme === 'dark' });

        runInNewContext(headScript, { document, localStorage, matchMedia });

        return {
            theme: () => document.documentElement.dataset.theme,
            flip: () => {
                Object.assign(globalThis, { document, localStorage });
                new ThemeController().flip();
            },
        };
    }
}

test('a first visit follows a dark system theme', () => {
    assert.equal(new Browser('dark').open().theme(), 'dark');
});

test('a first visit follows a light system theme', () => {
    assert.equal(new Browser('light').open().theme(), 'light');
});

test('a stored theme wins over the system theme', () => {
    const browser = new Browser('dark');
    browser.storage.set('intakt-theme', 'light');

    assert.equal(browser.open().theme(), 'light');
});

test('an unknown stored value falls back to the system theme', () => {
    const browser = new Browser('dark');
    browser.storage.set('intakt-theme', 'blue');

    assert.equal(browser.open().theme(), 'dark');
});

test('storage that refuses access falls back to the system theme', () => {
    assert.equal(new Browser('dark', { storageRefused: true }).open().theme(), 'dark');
});

test('flipping turns a dark page light and a light page dark', () => {
    const page = new Browser('dark').open();

    page.flip();
    assert.equal(page.theme(), 'light');
    page.flip();
    assert.equal(page.theme(), 'dark');
});

test('a flipped theme survives a reload against the system theme', () => {
    const browser = new Browser('dark');
    browser.open().flip();

    assert.equal(browser.open().theme(), 'light');
});
