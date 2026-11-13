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
        assert.equal(selector, '.col-fold, .col-hide');

        return this;
    }

    focus() {
        this.focused = true;
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
        assert.equal(type, 'click');
        this.click = listener;
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
