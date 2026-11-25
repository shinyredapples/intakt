import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { markBoardEdges } = await import(new URL('assets/board-edge.js', root));

// The widths the design review measured after the demo: 1,692 px of columns in a 1,136 px area.
class FakeBoard {
    scrollWidth = 1692;
    clientWidth = 1136;
    scrollLeft = 0;

    constructor(wrap) {
        this.wrap = wrap;
    }

    closest(selector) {
        assert.equal(selector, '.board-wrap');

        return this.wrap;
    }
}

class FakeWrap extends FakeElement {
    board = new FakeBoard(this);

    querySelector(selector) {
        assert.equal(selector, '.board');

        return this.board;
    }

    fades() {
        return ['more-left', 'more-right'].filter((side) => this.classList.contains(side));
    }
}

class FakeTarget {
    listeners = {};

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    dispatch(type, event = {}) {
        this.listeners[type](event);
    }
}

class FakePage extends FakeTarget {
    wrap = new FakeWrap();

    querySelectorAll(selector) {
        assert.equal(selector, '.board-wrap');

        return [this.wrap];
    }
}

function boardOnAPage() {
    const page = new FakePage();
    const view = new FakeTarget();
    markBoardEdges(page, view);

    return { page, view, wrap: page.wrap, board: page.wrap.board };
}

test('the right edge fades on load while columns are hidden to the right', () => {
    const { wrap } = boardOnAPage();

    assert.deepEqual(wrap.fades(), ['more-right']);
});

test('a board marked to start at its end scrolls to Done on load and fades only its left edge', () => {
    const page = new FakePage();
    page.wrap.setAttribute('data-scroll-to-end', '');

    markBoardEdges(page, new FakeTarget());

    assert.equal(page.wrap.board.scrollLeft, 556);
    assert.deepEqual(page.wrap.fades(), ['more-left']);
});

test('a board scrolled to its end on load keeps the scroll the person chose afterwards', () => {
    const page = new FakePage();
    page.wrap.setAttribute('data-scroll-to-end', '');
    markBoardEdges(page, new FakeTarget());
    page.wrap.board.scrollLeft = 0;

    page.dispatch('intakt:project-shown');

    assert.equal(page.wrap.board.scrollLeft, 0);
});

test('an unmarked board stays at its first column on load', () => {
    const { board } = boardOnAPage();

    assert.equal(board.scrollLeft, 0);
});

test('no edge fades when the board fits', () => {
    const page = new FakePage();
    page.wrap.board.scrollWidth = 1136;

    markBoardEdges(page, new FakeTarget());

    assert.deepEqual(page.wrap.fades(), []);
});

test('both edges fade after scrolling part of the way right', () => {
    const { page, wrap, board } = boardOnAPage();
    board.scrollLeft = 200;

    page.dispatch('scroll', { target: board });

    assert.deepEqual(wrap.fades(), ['more-left', 'more-right']);
});

test('only the left edge fades once the board is scrolled to its end', () => {
    const { page, wrap, board } = boardOnAPage();
    board.scrollLeft = 556;

    page.dispatch('scroll', { target: board });

    assert.deepEqual(wrap.fades(), ['more-left']);
});

test('a scroll of the page itself changes no edge', () => {
    const { page, wrap, board } = boardOnAPage();
    board.scrollLeft = 556;

    page.dispatch('scroll', { target: {} });

    assert.deepEqual(wrap.fades(), ['more-right']);
});

test('a window that grows until the board fits takes the fade away', () => {
    const { view, wrap, board } = boardOnAPage();
    board.clientWidth = 1692;

    view.dispatch('resize');

    assert.deepEqual(wrap.fades(), []);
});

test('opening a folded column that widens the board past its area marks the right edge', () => {
    const page = new FakePage();
    page.wrap.board.scrollWidth = 1136;
    markBoardEdges(page, new FakeTarget());
    page.wrap.board.scrollWidth = 1400;

    page.dispatch('click');

    assert.deepEqual(page.wrap.fades(), ['more-right']);
});

// Folding Backlog with Escape (assets/column-fold.js) can make the board fit without moving its scroll position, so no scroll event marks it.
test('a key such as Escape that folds the board until it fits takes the fade away', () => {
    const { page, wrap, board } = boardOnAPage();
    board.scrollWidth = 1136;

    page.dispatch('keydown', { key: 'Escape' });

    assert.deepEqual(wrap.fades(), []);
});

test('a pushed column marks the edges again once the layout has put the filter back', () => {
    const { page, wrap, board } = boardOnAPage();
    board.scrollWidth = 1136;

    page.dispatch('intakt:project-shown');

    assert.deepEqual(wrap.fades(), []);
});
