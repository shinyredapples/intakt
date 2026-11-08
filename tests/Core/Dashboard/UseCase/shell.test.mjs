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

test('a first visit shows every project', () => {
    const page = new Browser('light').open();

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('choosing a project hides the rows of every other project', () => {
    const page = new Browser('light').open();

    page.chooseProject('beta');

    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('choosing all projects shows every row again', () => {
    const page = new Browser('light').open();
    page.chooseProject('beta');

    page.chooseProject('');

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('the chosen project stays chosen after a reload', () => {
    const browser = new Browser('light');
    browser.open().chooseProject('alpha');

    const page = browser.open();

    assert.equal(browser.storage.get('intakt-project'), 'alpha');
    assert.equal(page.projectFilter.value, 'alpha');
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a stored project the install no longer lists shows every project', () => {
    const browser = new Browser('light', { projects: ['alpha'], rows: ['alpha'] });
    browser.storage.set('intakt-project', 'gone');

    const page = browser.open();

    assert.equal(page.projectFilter.value, '');
    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('a project named all is filtered like any other project', () => {
    const page = new Browser('light', { projects: ['all', 'beta'], rows: ['all', 'beta'] }).open();

    page.chooseProject('all');

    assert.deepEqual(page.shownRows(), ['all']);
});

test('the project filter names the chosen project in its tooltip', () => {
    const page = new Browser('light', { projects: ['a-project-with-a-name-too-long-for-the-top-bar'], rows: [] }).open();

    assert.equal(page.projectFilter.title, 'All projects');
    page.chooseProject('a-project-with-a-name-too-long-for-the-top-bar');

    assert.equal(page.projectFilter.title, 'a-project-with-a-name-too-long-for-the-top-bar');
});

test('a project link chooses its project over the stored one', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=beta' });
    browser.storage.set('intakt-project', 'alpha');

    const page = browser.open();

    assert.equal(page.projectFilter.value, 'beta');
    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
    assert.equal(browser.storage.get('intakt-project'), 'beta');
});

test('another project can be chosen after following a project link', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('beta');

    assert.deepEqual(page.shownRows(), ['beta', 'beta']);
});

test('all projects can be chosen after following a project link', () => {
    const page = new Browser('light', { address: 'http://localhost/reviews?project=alpha' }).open();

    page.chooseProject('');

    assert.deepEqual(page.shownRows(), ['beta', 'alpha', 'beta']);
});

test('choosing a project drops the project link from the address, so a reload keeps the choice', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=alpha&page=2' });
    const page = browser.open();

    page.chooseProject('beta');

    assert.equal(browser.address, 'http://localhost/reviews?page=2');
    assert.deepEqual(page.history.state, { turbo: { restorationIdentifier: 'visit' } });
    assert.equal(browser.open().projectFilter.value, 'beta');
});

test('choosing a project leaves an address without a project link alone', () => {
    const browser = new Browser('light');
    const page = browser.open();
    page.history.replaceState = () => assert.fail('the address was replaced');

    page.chooseProject('beta');

    assert.equal(browser.address, 'http://localhost/reviews');
});

test('a project link to a project the install does not list leaves the stored project chosen', () => {
    const browser = new Browser('light', { address: 'http://localhost/reviews?project=gone' });
    browser.storage.set('intakt-project', 'alpha');

    const page = browser.open();

    assert.equal(page.projectFilter.value, 'alpha');
});

test('a page that marks two options selected shows, filters and stores one and the same project', () => {
    const browser = new Browser('light', { projects: ['01', '1'], rows: ['01', '1'], address: 'http://localhost/reviews?project=1', linked: ['01', '1'] });

    const page = browser.open();

    assert.equal(page.projectFilter.value, browser.storage.get('intakt-project'));
    assert.deepEqual(page.shownRows(), [page.projectFilter.value]);
    assert.equal(page.projectFilter.title, page.projectFilter.value);
});

test('a project link still chooses its project when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true, address: 'http://localhost/reviews?project=alpha' }).open();

    assert.deepEqual(page.shownRows(), ['alpha']);
});

test('the project filter still filters when storage refuses access', () => {
    const page = new Browser('light', { storageRefused: true }).open();

    page.chooseProject('alpha');

    assert.deepEqual(page.shownRows(), ['alpha']);
});
