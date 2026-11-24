import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { filterHistory } = await import(new URL('assets/history-filter.js', root));

class FakeRow extends FakeElement {
    constructor(text, source, topics) {
        super(text);
        this.dataset = { source, topics };
    }

    toggleAttribute(name, force) {
        if (force) {
            this.setAttribute(name, '');
        } else {
            this.removeAttribute(name);
        }
    }
}

class FakeChip extends FakeElement {
    constructor(toolbar, filter, pressed) {
        super();
        this.toolbar = toolbar;
        this.dataset = { filter };
        this.setAttribute('aria-pressed', String(pressed));
    }

    closest(selector) {
        return { '[data-history-filter] .fchip': this, '[data-history-filter]': this.toolbar }[selector] ?? null;
    }
}

class FakeToolbar {
    chips = [];
    input = { value: '', closest: (selector) => (selector === '[data-history-filter]' ? this : null) };

    querySelectorAll(selector) {
        assert.equal(selector, '.fchip');

        return this.chips;
    }

    querySelector(selector) {
        if (selector === 'input') {
            return this.input;
        }
        assert.equal(selector, '.fchip[aria-pressed="true"]');

        return this.chips.find((chip) => chip.getAttribute('aria-pressed') === 'true') ?? null;
    }
}

class FakePage {
    listeners = {};

    constructor(toolbar, rows) {
        this.toolbar = toolbar;
        this.rows = rows;
        this.none = { hidden: true };
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    querySelector(selector) {
        return { '[data-history-filter]': this.toolbar, '.history-none': this.none }[selector] ?? null;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.ledger tbody tr');

        return this.rows;
    }
}

function historyWithAnOwnerChangeAndAnAccountCoolDown() {
    const toolbar = new FakeToolbar();
    toolbar.chips = ['', 'dashboard', 'intakt', 'limits', 'projects', 'accounts'].map((filter) => new FakeChip(toolbar, filter, filter === ''));
    const owner = new FakeRow('Owner asked owner alpha', 'dashboard', 'projects');
    const cooled = new FakeRow('Account cooled down Intakt', 'intakt', 'limits accounts');
    const page = new FakePage(toolbar, [owner, cooled]);
    filterHistory(page);

    return { page, toolbar, owner, cooled, chip: (filter) => toolbar.chips.find((chip) => chip.dataset.filter === filter) };
}

const shown = (...rows) => rows.map((row) => !row.hasAttribute('data-filtered-out'));

test('a source chip shows only the rows of that source and is the only one pressed', () => {
    const { page, toolbar, owner, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();

    page.listeners.click({ target: chip('intakt') });

    assert.deepEqual(shown(owner, cooled), [false, true]);
    assert.deepEqual(toolbar.chips.map((one) => one.getAttribute('aria-pressed')), ['false', 'false', 'true', 'false', 'false', 'false']);
});

test('a topic chip shows a row that carries the topic among others', () => {
    const { page, owner, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();

    page.listeners.click({ target: chip('accounts') });

    assert.deepEqual(shown(owner, cooled), [false, true]);
});

test('Everything shows every row again', () => {
    const { page, owner, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    page.listeners.click({ target: chip('limits') });

    page.listeners.click({ target: chip('') });

    assert.deepEqual(shown(owner, cooled), [true, true]);
});

test('the text filter keeps the rows whose text holds it, in any letter case', () => {
    const { page, toolbar, owner, cooled } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    toolbar.input.value = '  ALPHA ';

    page.listeners.input({ target: toolbar.input });

    assert.deepEqual(shown(owner, cooled), [true, false]);
});

test('the empty line shows when no row matches and goes when one does', () => {
    const { page, toolbar } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    toolbar.input.value = 'nothing like this';
    page.listeners.input({ target: toolbar.input });
    assert.equal(page.none.hidden, false);

    toolbar.input.value = '';
    page.listeners.input({ target: toolbar.input });

    assert.equal(page.none.hidden, true);
});

test('a row the project filter hid counts as not shown, so the empty line appears', () => {
    const { page, owner, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    cooled.hidden = true;

    page.listeners.click({ target: chip('intakt') });

    assert.equal(page.none.hidden, false);
    assert.equal(owner.hidden, false);
});

test('the project filter running again recounts the shown rows', () => {
    const { page, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    page.listeners.click({ target: chip('intakt') });
    cooled.hidden = true;

    page.listeners['intakt:project-shown']();

    assert.equal(page.none.hidden, false);
});

test('a click or a typed key outside the toolbar changes nothing, and a page without History is left alone', () => {
    const { page, owner, cooled } = historyWithAnOwnerChangeAndAnAccountCoolDown();

    page.listeners.click({ target: { closest: () => null } });
    page.listeners.click({ target: null });
    page.listeners.input({ target: { closest: () => null } });
    page.toolbar = null;
    page.listeners['intakt:project-shown']();

    assert.deepEqual(shown(owner, cooled), [true, true]);
    assert.equal(page.none.hidden, true);
});
