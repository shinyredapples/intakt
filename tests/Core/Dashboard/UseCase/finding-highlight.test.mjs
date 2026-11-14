import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeClick, FakeElement, root } from './stimulus-controller.mjs';

const { highlightFindings } = await import(new URL('assets/finding-highlight.js', root));

class FakeRow extends FakeElement {
    scrolledTo = null;

    constructor(fold) {
        super();
        this.fold = fold;
        this.classList.remove = (name) => this.classes.delete(name);
        this.classList.add = (name) => this.classes.add(name);
    }

    closest(selector) {
        assert.equal(selector, 'details');

        return this.fold;
    }

    scrollIntoView(options) {
        this.scrolledTo = options;
    }
}

class FakeButton extends FakeElement {
    closest(selector) {
        assert.equal(selector, 'button[data-highlight-finding]');

        return this.hasAttribute('data-highlight-finding') ? this : null;
    }
}

class FakePage {
    listeners = new Map();
    rows = new Map();
    cells = [];

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    querySelectorAll(selector) {
        if (selector === 'tr.selected') {
            return [...this.rows.values()].filter((row) => row.classes.has('selected'));
        }
        assert.equal(selector, 'td[data-finding]');

        return this.cells;
    }

    entry(id, fold = null) {
        const row = new FakeRow(fold);
        this.rows.set(id, row);
        this.cells.push({ getAttribute: () => id, closest: (selector) => (selector === 'tr' ? row : null) });

        return row;
    }

    choose(id) {
        const button = new FakeButton(id);
        button.setAttribute('data-highlight-finding', id);
        this.click(button);
    }

    click(target) {
        const click = new FakeClick();
        click.target = target;
        this.listeners.get('click')(click);
    }
}

function summaryOver(...ids) {
    const page = new FakePage();
    for (const id of ids) {
        page.entry(id);
    }
    highlightFindings(page);

    return page;
}

test('choosing a finding id highlights its timeline entry', () => {
    const page = summaryOver('FF1');

    page.choose('FF1');

    const row = page.rows.get('FF1');
    assert.equal(row.classes.has('selected'), true);
    assert.equal(row.getAttribute('aria-current'), 'true');
    assert.deepEqual(row.scrolledTo, { block: 'center' });
});

test('choosing a finding id opens the folded round its entry sits in', () => {
    const page = new FakePage();
    const fold = { open: false };
    page.entry('FF1', fold);
    highlightFindings(page);

    page.choose('FF1');

    assert.equal(fold.open, true);
});

test('choosing a second finding id moves the highlight off the first entry', () => {
    const page = summaryOver('FF1', 'FF2');
    page.choose('FF1');

    page.choose('FF2');

    assert.equal(page.rows.get('FF1').classes.has('selected'), false);
    assert.equal(page.rows.get('FF1').getAttribute('aria-current'), null);
    assert.equal(page.rows.get('FF2').classes.has('selected'), true);
});

test('a finding id judged in two rounds highlights the entry of the later round', () => {
    const page = new FakePage();
    const earlier = new FakeRow(null);
    const later = new FakeRow(null);
    page.rows.set('earlier', earlier);
    page.rows.set('later', later);
    page.cells.push({ getAttribute: () => 'FF1', closest: () => earlier }, { getAttribute: () => 'FF1', closest: () => later });
    highlightFindings(page);

    page.choose('FF1');

    assert.equal(earlier.classes.has('selected'), false);
    assert.equal(later.classes.has('selected'), true);
});

test('a finding id with no timeline entry yet highlights nothing and keeps the earlier highlight', () => {
    const page = summaryOver('FF1');
    page.choose('FF1');

    page.choose('FF9');

    assert.equal(page.rows.get('FF1').classes.has('selected'), true);
});

test('choosing a finding id merged into an act entry highlights that entry', () => {
    const page = summaryOver('FF1 FF2');

    page.choose('FF2');

    assert.equal(page.rows.get('FF1 FF2').classes.has('selected'), true);
});

test('a finding id that only starts another entry\'s id highlights nothing', () => {
    const page = summaryOver('FF12');

    page.choose('FF1');

    assert.equal(page.rows.get('FF12').classes.has('selected'), false);
});

test('a click outside a finding id highlights nothing', () => {
    const page = summaryOver('FF1');

    page.click(new FakeButton('Stop this reviewer'));

    assert.equal(page.rows.get('FF1').classes.has('selected'), false);
});
