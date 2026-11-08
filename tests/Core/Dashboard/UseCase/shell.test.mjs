import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Browser } from './shell-page.mjs';

test('a first visit shows the rail open', () => {
    const page = new Browser('light').open();

    assert.equal(page.rail(), undefined);
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'true');
    assert.equal(page.railToggle.title, 'Collapse menu');
});

test('collapsing the rail marks it collapsed and offers to expand it', () => {
    const page = new Browser('light').open();

    page.toggleRail();

    assert.equal(page.rail(), 'collapsed');
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'false');
    assert.equal(page.railToggle.title, 'Expand menu');
});

test('a collapsed rail opens again', () => {
    const page = new Browser('light').open();

    page.toggleRail();
    page.toggleRail();

    assert.equal(page.rail(), undefined);
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'true');
});

test('a collapsed rail stays collapsed after a reload', () => {
    const browser = new Browser('light');
    browser.open().toggleRail();

    const page = browser.open();

    assert.equal(page.rail(), 'collapsed');
    assert.equal(page.railToggle.getAttribute('aria-expanded'), 'false');
});

test('a reopened rail stays open after a reload', () => {
    const browser = new Browser('light');
    const page = browser.open();
    page.toggleRail();
    page.toggleRail();

    assert.equal(browser.storage.get('intakt-rail'), 'open');
    assert.equal(browser.open().rail(), undefined);
});

test('an unknown stored rail value shows the rail open', () => {
    const browser = new Browser('light');
    browser.storage.set('intakt-rail', 'sideways');

    assert.equal(browser.open().rail(), undefined);
});

test('the rail still collapses when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true }).open();

    page.toggleRail();

    assert.equal(page.rail(), 'collapsed');
});

test('ctrl k moves the focus to the search field instead of the browser', () => {
    const page = new Browser('light').open();

    const key = page.press('k', { ctrlKey: true });

    assert.ok(page.searchHasFocus());
    assert.ok(key.defaultPrevented);
});

test('cmd k moves the focus to the search field', () => {
    const page = new Browser('light', { platform: 'MacIntel' }).open();

    page.press('K', { metaKey: true });

    assert.ok(page.searchHasFocus());
});

test('k without a modifier is left to the page', () => {
    const page = new Browser('light').open();

    const key = page.press('k');

    assert.ok(!page.searchHasFocus());
    assert.ok(!key.defaultPrevented);
});

test('another shortcut with ctrl is left to the browser', () => {
    const page = new Browser('light').open();

    const key = page.press('l', { ctrlKey: true });

    assert.ok(!page.searchHasFocus());
    assert.ok(!key.defaultPrevented);
});

test('a mac shows the command key in the search hint', () => {
    assert.equal(new Browser('light', { platform: 'MacIntel' }).open().shortcutHint(), '⌘K');
});

test('other systems keep the ctrl hint', () => {
    assert.equal(new Browser('light').open().shortcutHint(), 'Ctrl K');
});
