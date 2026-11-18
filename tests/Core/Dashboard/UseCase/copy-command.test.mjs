import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { copyCommands } = await import(new URL('assets/copy-command.js', root));

class FakeCopyButton extends FakeElement {
    constructor(command) {
        super('Copy');
        this.dataset = { copy: command };
        this.previousElementSibling = new FakeElement(command);
    }

    closest(selector) {
        return 'button[data-copy]' === selector ? this : null;
    }
}

class FakeWhy extends FakeElement {
    summary = new FakeElement('Why');

    constructor() {
        super();
        this.open = true;
        this.setAttribute('open', '');
        this.summary.focus = () => { this.summary.focused = true; };
        this.summary.closest = (selector) => ('.wait-item details.why' === selector ? this : null);
    }

    querySelector(selector) {
        return 'summary' === selector ? this.summary : null;
    }
}

class FakePage {
    listeners = {};

    constructor(whys = []) {
        this.whys = whys;
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.wait-item details.why');

        return this.whys;
    }

    click(target) {
        return this.listeners.click({ target });
    }

    press(key, target) {
        this.listeners.keydown({ key, target });
    }
}

const writing = { written: [], async writeText(text) { this.written.push(text); } };
const refusing = { async writeText() { throw new Error('NotAllowedError'); } };
const desktop = () => ({ matches: false });
const phone = () => ({ matches: true });

function selectionSpy() {
    return { selected: null, selectAllChildren(node) { this.selected = node; } };
}

test('a click on Copy writes the command to the clipboard and says it was copied', async () => {
    const page = new FakePage();
    const clipboard = { ...writing, written: [] };
    copyCommands(page, clipboard, desktop, selectionSpy());
    const button = new FakeCopyButton('php bin/console intakt:vendor:probe');

    await page.click(button);

    assert.deepEqual(clipboard.written, ['php bin/console intakt:vendor:probe']);
    assert.equal(button.textContent, 'Copied');
});

test('the Copy label comes back two seconds after the copy', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage();
    copyCommands(page, { ...writing, written: [] }, desktop, selectionSpy());
    const button = new FakeCopyButton('cmd');

    await page.click(button);
    t.mock.timers.tick(1999);
    assert.equal(button.textContent, 'Copied');
    t.mock.timers.tick(1);

    assert.equal(button.textContent, 'Copy');
});

test('a second quick click still brings the Copy label back', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage();
    copyCommands(page, { ...writing, written: [] }, desktop, selectionSpy());
    const button = new FakeCopyButton('cmd');

    await page.click(button);
    t.mock.timers.tick(500);
    await page.click(button);
    t.mock.timers.tick(2000);

    assert.equal(button.textContent, 'Copy');
});

test('a refused clipboard write says so and selects the command for a copy by hand', async () => {
    const page = new FakePage();
    const selection = selectionSpy();
    copyCommands(page, refusing, desktop, selection);
    const button = new FakeCopyButton('cmd');

    await page.click(button);

    assert.equal(button.textContent, 'Select and copy');
    assert.equal(selection.selected, button.previousElementSibling);
});

test('a page without a clipboard says so instead of throwing', async () => {
    const page = new FakePage();
    copyCommands(page, {}, desktop, selectionSpy());
    const button = new FakeCopyButton('cmd');

    await page.click(button);

    assert.equal(button.textContent, 'Select and copy');
});

test('a click outside a Copy button copies nothing', async () => {
    const page = new FakePage();
    const clipboard = { ...writing, written: [] };
    copyCommands(page, clipboard, desktop, selectionSpy());

    await page.click(new FakeElement('Why'));

    assert.deepEqual(clipboard.written, []);
});

test('a phone closes every Why behind its summary', () => {
    const why = new FakeWhy();

    copyCommands(new FakePage([why]), writing, phone, selectionSpy());

    assert.equal(why.hasAttribute('open'), false);
});

test('a desktop keeps every Why open', () => {
    const why = new FakeWhy();

    copyCommands(new FakePage([why]), writing, desktop, selectionSpy());

    assert.equal(why.hasAttribute('open'), true);
});

test('Esc closes an open Why and gives focus back to its summary', () => {
    const page = new FakePage();
    copyCommands(page, writing, phone, selectionSpy());
    const why = new FakeWhy();

    page.press('Escape', why.summary);

    assert.equal(why.open, false);
    assert.equal(why.summary.focused, true);
});

test('on a desktop Esc leaves the Why open, because no summary there could open it again', () => {
    const page = new FakePage();
    copyCommands(page, writing, desktop, selectionSpy());
    const why = new FakeWhy();

    page.press('Escape', why.summary);

    assert.equal(why.open, true);
});

test('another key leaves the Why open', () => {
    const page = new FakePage();
    copyCommands(page, writing, phone, selectionSpy());
    const why = new FakeWhy();

    page.press('Enter', why.summary);

    assert.equal(why.open, true);
});
