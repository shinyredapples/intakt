import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { filterHistory } = await import(new URL('assets/history-filter.js', root));

class FakeRow extends FakeElement {
    constructor(text, source, topics, project = undefined) {
        super(text);
        this.dataset = { source, topics, project };
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

// A select keeps only a value one of its options carries, as in a browser.
class FakeSelect {
    #value = '';

    constructor(toolbar, values) {
        this.toolbar = toolbar;
        this.options = values.map((value) => ({ value }));
    }

    get value() {
        return this.#value;
    }

    set value(value) {
        this.#value = this.options.some((option) => option.value === value) ? value : '';
    }

    closest(selector) {
        return { '[data-history-filter] select': this, '[data-history-filter]': this.toolbar }[selector] ?? null;
    }
}

// The shell's project filter: a change it hears stores the choice and runs the shell's project filter, which tells the page.
class FakeSharedFilter {
    value = '';
    changes = 0;
    options = ['', 'Alpha', 'Beta'].map((value) => ({ value }));

    constructor(page) {
        this.page = page;
    }

    dispatchEvent(event) {
        assert.equal(event.type, 'change');
        this.changes++;
        this.page.listeners['intakt:project-shown']();
    }
}

class FakeToolbar {
    chips = [];
    picker = null;
    input = { value: '', closest: (selector) => (selector === '[data-history-filter]' ? this : null) };

    querySelectorAll(selector) {
        assert.equal(selector, '.fchip');

        return this.chips;
    }

    querySelector(selector) {
        if (selector === 'input') {
            return this.input;
        }
        if (selector === 'select') {
            return this.picker;
        }
        assert.equal(selector, '.fchip[aria-pressed="true"]');

        return this.chips.find((chip) => chip.getAttribute('aria-pressed') === 'true') ?? null;
    }
}

class FakeStatus {
    #text = '';
    writes = 0;

    constructor(none) {
        this.dataset = { none };
    }

    get textContent() {
        return this.#text;
    }

    set textContent(text) {
        this.#text = text;
        this.writes++;
    }
}

class FakePage {
    listeners = {};

    constructor(toolbar, rows) {
        this.toolbar = toolbar;
        this.rows = rows;
        this.none = new FakeStatus('No entries match.');
        this.shared = null;
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    querySelector(selector) {
        return { '[data-history-filter]': this.toolbar, '.history-none': this.none, '#projfilter': this.shared }[selector] ?? null;
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
    filterHistory(page, { matches: false });

    return { page, toolbar, owner, cooled, chip: (filter) => toolbar.chips.find((chip) => chip.dataset.filter === filter) };
}

function historyOfAlphaAndBeta(phone, stored = '') {
    const toolbar = new FakeToolbar();
    toolbar.chips = [new FakeChip(toolbar, '', true)];
    toolbar.picker = new FakeSelect(toolbar, ['', 'Alpha', 'Beta']);
    const alpha = new FakeRow('Owner asked Alpha', 'dashboard', 'projects', 'Alpha');
    const beta = new FakeRow('Owner asked Beta', 'dashboard', 'projects', 'Beta');
    const intakt = new FakeRow('Account cooled down', 'intakt', 'limits accounts');
    const page = new FakePage(toolbar, [alpha, beta, intakt]);
    page.shared = new FakeSharedFilter(page);
    page.shared.value = stored;
    filterHistory(page, { matches: phone });

    return { page, picker: toolbar.picker, alpha, beta, intakt };
}

const shown = (...rows) => rows.map((row) => !row.hasAttribute('data-filtered-out'));

test('on a phone the toolbar picker hides the other projects and hands the choice to the shared filter', () => {
    const { page, picker, alpha, beta } = historyOfAlphaAndBeta(true);

    picker.value = 'Beta';
    page.listeners.input({ target: picker });
    page.listeners.change({ target: picker });

    assert.equal(page.shared.value, 'Beta');
    assert.equal(page.shared.changes, 1);
    assert.deepEqual(shown(alpha, beta), [false, true]);
});

test('on a phone a row of no project stays shown under the chosen project, as the shell keeps it', () => {
    const { page, picker, intakt } = historyOfAlphaAndBeta(true);

    picker.value = 'Beta';
    page.listeners.change({ target: picker });

    assert.deepEqual(shown(intakt), [true]);
});

test('on a phone a project kept from an earlier page is shown in the picker and narrows the rows from the start', () => {
    const { picker, alpha, beta } = historyOfAlphaAndBeta(true, 'Alpha');

    assert.equal(picker.value, 'Alpha');
    assert.deepEqual(shown(alpha, beta), [true, false]);
});

test('on a wider screen the picker leaves the project to the shell, which hides rows itself', () => {
    const { page, alpha, beta, intakt } = historyOfAlphaAndBeta(false, 'Alpha');

    page.listeners['intakt:project-shown']();

    assert.deepEqual(shown(alpha, beta, intakt), [true, true, true]);
});

test('a project the picker does not list reads as All projects, so no row is hidden under it', () => {
    const { page, picker, alpha, beta } = historyOfAlphaAndBeta(true);
    page.shared.value = 'Gamma';

    page.listeners['intakt:project-shown']();

    assert.equal(picker.value, '');
    assert.deepEqual(shown(alpha, beta), [true, true]);
});

test('a project a live update took off the shared filter is handed on as All projects, so the shared filter keeps a selected option', () => {
    const { page, picker, alpha, beta } = historyOfAlphaAndBeta(true);
    page.shared.options = [{ value: '' }, { value: 'Alpha' }];

    picker.value = 'Beta';
    page.listeners.change({ target: picker });

    assert.deepEqual([page.shared.value, picker.value], ['', '']);
    assert.deepEqual(shown(alpha, beta), [true, true]);
});

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
    assert.equal(page.none.textContent, 'No entries match.');

    toolbar.input.value = '';
    page.listeners.input({ target: toolbar.input });

    assert.equal(page.none.textContent, '');
});

test('the status region stays on the page and takes the no-match text once while nothing matches', () => {
    const { page, toolbar } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    const status = page.none;

    for (const text of ['nothing', 'nothing like', 'nothing like this']) {
        toolbar.input.value = text;
        page.listeners.input({ target: toolbar.input });
    }

    assert.equal(page.none, status);
    assert.equal(status.hidden, undefined);
    assert.deepEqual([status.textContent, status.writes], ['No entries match.', 1]);
});

test('a row the project filter hid counts as not shown, so the empty line appears', () => {
    const { page, owner, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    cooled.hidden = true;

    page.listeners.click({ target: chip('intakt') });

    assert.equal(page.none.textContent, 'No entries match.');
    assert.equal(owner.hidden, false);
});

test('the project filter running again recounts the shown rows', () => {
    const { page, cooled, chip } = historyWithAnOwnerChangeAndAnAccountCoolDown();
    page.listeners.click({ target: chip('intakt') });
    cooled.hidden = true;

    page.listeners['intakt:project-shown']();

    assert.equal(page.none.textContent, 'No entries match.');
});

test('a click or a typed key outside the toolbar changes nothing, and a page without History is left alone', () => {
    const { page, owner, cooled } = historyWithAnOwnerChangeAndAnAccountCoolDown();

    page.listeners.click({ target: { closest: () => null } });
    page.listeners.click({ target: null });
    page.listeners.input({ target: { closest: () => null } });
    page.toolbar = null;
    page.listeners['intakt:project-shown']();

    assert.deepEqual(shown(owner, cooled), [true, true]);
    assert.equal(page.none.textContent, '');
});

function focusableIn(toolbar) {
    const scrolls = [];

    return { scrolls, closest: (selector) => (selector === '[data-history-filter]' ? toolbar : null), scrollIntoView: (options) => scrolls.push(options) };
}

test('on a phone a toolbar control taking focus is scrolled fully into the sideways bar', () => {
    const { page } = historyOfAlphaAndBeta(true);
    const chip = focusableIn(page.toolbar);

    page.listeners.focusin({ target: chip });

    assert.deepEqual(chip.scrolls, [{ block: 'nearest', inline: 'nearest' }]);
});

test('on a desktop, or outside the toolbar, focus scrolls nothing', () => {
    const { page } = historyOfAlphaAndBeta(false);
    const chip = focusableIn(page.toolbar);
    const outside = focusableIn(null);

    page.listeners.focusin({ target: chip });
    historyOfAlphaAndBeta(true).page.listeners.focusin({ target: outside });
    page.listeners.focusin({ target: null });

    assert.deepEqual([chip.scrolls, outside.scrolls], [[], []]);
});
