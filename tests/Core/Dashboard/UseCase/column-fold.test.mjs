import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { foldColumns } = await import(new URL('assets/column-fold.js', root));

class FakeButton extends FakeElement {
    focused = false;

    constructor(className, column) {
        super();
        this.classes.add(className);
        this.column = column;
    }

    closest(selector) {
        if (selector === '.col') {
            return this.column;
        }
        if (selector === 'details.working') {
            return null;
        }
        assert.equal(selector, '.col-fold, .col-hide');

        return this;
    }

    focus() {
        this.focused = true;
    }

    // A phone hides Hide, so it has no box there.
    getClientRects() {
        return this.column.onAPhone ? [] : [{}];
    }
}

class FakeColumn extends FakeElement {
    constructor() {
        super();
        this.classes.add('collapsed');
        this.strip = new FakeButton('col-fold', this);
        this.strip.setAttribute('aria-expanded', 'false');
        this.hide = new FakeButton('col-hide', this);
    }

    querySelector(selector) {
        return { '.col-fold': this.strip, '.col-hide': this.hide }[selector];
    }
}

class FakePage {
    addEventListener(type, listener) {
        assert.ok(['click', 'keydown'].includes(type));
        this[type] = listener;
    }
}

class FakeKey {
    defaultPrevented = false;

    constructor(target, key = 'Escape') {
        this.target = target;
        this.key = key;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

class FakeWorking {
    open = true;
    summary = { focused: false, focus() { this.focused = true; } };

    querySelector(selector) {
        assert.equal(selector, 'summary');

        return this.summary;
    }
}

function collapsedColumnOnABoard() {
    const page = new FakePage();
    foldColumns(page);

    return { page, column: new FakeColumn() };
}

test('the strip opens its column, says it is expanded and hands the focus to Hide', () => {
    const { page, column } = collapsedColumnOnABoard();

    page.click({ target: column.strip });

    assert.equal(column.classList.contains('collapsed'), false);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'true');
    assert.equal(column.hide.focused, true);
});

test('Hide collapses the column again, says it is collapsed and hands the focus back to the strip', () => {
    const { page, column } = collapsedColumnOnABoard();
    page.click({ target: column.strip });

    page.click({ target: column.hide });

    assert.equal(column.classList.contains('collapsed'), true);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'false');
    assert.equal(column.strip.focused, true);
});

test('a click outside a strip or Hide changes no column', () => {
    const { page, column } = collapsedColumnOnABoard();

    page.click({ target: { closest: () => null } });
    page.click({ target: null });

    assert.equal(column.classList.contains('collapsed'), true);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'false');
});

test('Escape in a column opened from its strip folds it again and hands the focus back to the strip', () => {
    const { page, column } = collapsedColumnOnABoard();
    page.click({ target: column.strip });
    const key = new FakeKey(column.hide);

    page.keydown(key);

    assert.equal(column.classList.contains('collapsed'), true);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'false');
    assert.equal(column.strip.focused, true);
    assert.equal(key.defaultPrevented, true);
});

// Done starts open when every other column is empty (Board::startsCollapsed); the reader did not open it, so Escape is not theirs to undo.
function doneTheBoardOpened() {
    const { page, column } = collapsedColumnOnABoard();
    column.classList.toggle('collapsed', false);
    column.strip.setAttribute('aria-expanded', 'true');

    return { page, column };
}

test('Escape on Hide in a Done the board opened by itself leaves it open', () => {
    const { page, column } = doneTheBoardOpened();
    const key = new FakeKey(column.hide);

    page.keydown(key);

    assert.equal(column.classList.contains('collapsed'), false);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'true');
    assert.equal(key.defaultPrevented, false);
});

test('Hide still folds a Done the board opened by itself', () => {
    const { page, column } = doneTheBoardOpened();

    page.click({ target: column.hide });

    assert.equal(column.classList.contains('collapsed'), true);
    assert.equal(column.strip.getAttribute('aria-expanded'), 'false');
});

test('Escape after Hide and the strip again folds the column the reader opened', () => {
    const { page, column } = doneTheBoardOpened();
    page.click({ target: column.hide });
    page.click({ target: column.strip });

    page.keydown(new FakeKey(column.hide));

    assert.equal(column.classList.contains('collapsed'), true);
});

test('Escape in a folded column, another key, or Escape on a phone changes nothing', () => {
    const { page, column } = collapsedColumnOnABoard();
    const folded = new FakeKey(column.strip);
    page.keydown(folded);
    assert.equal(folded.defaultPrevented, false);

    page.click({ target: column.strip });
    page.keydown(new FakeKey(column.hide, 'Enter'));
    column.onAPhone = true;
    page.keydown(new FakeKey(column.hide));

    assert.equal(column.classList.contains('collapsed'), false);
});

test('Escape in an open Who is working closes it and hands the focus to its summary', () => {
    const { page } = collapsedColumnOnABoard();
    const working = new FakeWorking();
    const key = new FakeKey({ closest: (selector) => (selector === 'details.working' ? working : null) });

    page.keydown(key);

    assert.equal(working.open, false);
    assert.equal(working.summary.focused, true);
    assert.equal(key.defaultPrevented, true);
});

test('Escape outside the board closes nothing', () => {
    const { page } = collapsedColumnOnABoard();
    const key = new FakeKey({ closest: () => null });

    page.keydown(key);
    page.keydown(new FakeKey(null));

    assert.equal(key.defaultPrevented, false);
});
