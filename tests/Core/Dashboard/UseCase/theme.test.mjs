import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Browser } from './shell-page.mjs';

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

test('every theme toggle of the page flips the same theme', () => {
    const page = new Browser('light').open();

    page.flip(1);

    assert.equal(page.theme(), 'dark');
});

test('a flipped theme survives a reload against the system theme', () => {
    const browser = new Browser('dark');
    browser.open().flip();

    assert.equal(browser.open().theme(), 'light');
});

test('flipping still works when storage refuses access', () => {
    const page = new Browser('dark', { storageRefused: true }).open();

    page.flip();

    assert.equal(page.theme(), 'light');
});
