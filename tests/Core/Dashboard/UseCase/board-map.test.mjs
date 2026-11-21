import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { mapBoardColumns } = await import(new URL('assets/board-map.js', root));

const NAMES = ['waiting-on-you', 'backlog', 'ready', 'planned', 'building', 'checking', 'in-review', 'merging', 'done'];

class FakeColumn extends FakeElement {
    focusedWith = null;

    constructor(name, offsetLeft, offsetWidth, board) {
        super();
        this.id = `col-${name}`;
        this.offsetLeft = offsetLeft;
        this.offsetWidth = offsetWidth;
        this.board = board;
    }

    closest(selector) {
        assert.equal(selector, '.board');

        return this.board;
    }

    focus(options) {
        this.focusedWith = options;
    }
}

// Nine columns 200 px apart, as wide as a laptop's board of 1,136 px shows about five of.
class FakeBoard {
    isConnected = true;
    scrollLeft = 0;
    clientWidth = 1136;
    scrolledTo = null;

    constructor(inset, width = 184, gap = 16) {
        this.columns = NAMES.map((name, i) => new FakeColumn(name, inset + i * (width + gap), width, this));
        this.scrollWidth = inset * 2 + NAMES.length * (width + gap) - gap;
    }

    querySelectorAll(selector) {
        assert.equal(selector, ':scope > .col');

        return this.columns;
    }

    scrollTo(options) {
        this.scrolledTo = options;
        this.scrollLeft = options.left;
    }

    closest(selector) {
        return selector === '.board-wrap' ? {} : null;
    }
}

class FakeJump extends FakeElement {
    constructor(selector, column) {
        super();
        this.selector = selector;
        this.setAttribute('aria-controls', `col-${column}`);
    }

    closest(selector) {
        assert.equal(selector, '.sm-seg, .colswitch button');

        return this;
    }
}

class FakeMap extends FakeElement {
    text = new FakeElement();
    segments = NAMES.map((name) => new FakeJump('.sm-seg', name));

    querySelector(selector) {
        assert.equal(selector, '.sm-text');

        return this.text;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.sm-seg');

        return this.segments;
    }
}

class FakeSwitcher extends FakeElement {
    scrollLeft = 0;
    offsetLeft = 0;
    buttons = NAMES.map((name, i) => Object.assign(new FakeJump('.colswitch button', name), { offsetLeft: 16 + i * 150 }));

    querySelectorAll(selector) {
        assert.equal(selector, 'button');

        return this.buttons;
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
    constructor(board) {
        super();
        this.board = board;
        this.map = new FakeMap();
        this.map.hidden = true;
        this.switcher = new FakeSwitcher();
    }

    querySelector(selector) {
        return { '.board': this.board, '.stagemap': this.map, '.colswitch': this.switcher }[selector];
    }

    getElementById(id) {
        return this.board.columns.find((column) => column.id === id) ?? null;
    }
}

class FakeView extends FakeTarget {
    constructor(reducedMotion = false) {
        super();
        this.reducedMotion = reducedMotion;
    }

    matchMedia(query) {
        assert.equal(query, '(prefers-reduced-motion: reduce)');

        return { matches: this.reducedMotion };
    }

    setTimeout(callback) {
        callback();

        return 1;
    }

    clearTimeout() {}
}

// Holds each timer until the test runs it, as a browser does until the pause has passed.
class QueuedView extends FakeView {
    timers = new Map();
    next = 1;

    setTimeout(callback) {
        this.timers.set(this.next, callback);

        return this.next++;
    }

    clearTimeout(id) {
        this.timers.delete(id);
    }

    runTimers() {
        const callbacks = [...this.timers.values()];
        this.timers.clear();
        callbacks.forEach((callback) => callback());
    }
}

function boardOnAPage({ inset = 0, reducedMotion = false, board = new FakeBoard(inset), view = new FakeView(reducedMotion) } = {}) {
    const page = new FakePage(board);
    mapBoardColumns(page, view);

    return { page, view, board, map: page.map, switcher: page.switcher };
}

function phoneBoardOnAPage() {
    const board = new FakeBoard(16, 358, 16);
    board.clientWidth = 390;

    return boardOnAPage({ board, view: new QueuedView() });
}

const shownSegments = (map) => map.segments.map((segment) => segment.classList.contains('in'));
const pressed = (switcher) => switcher.buttons.map((button) => button.getAttribute('aria-pressed'));

test('a board wider than its space shows the map, which marks the columns fully in view and counts them', () => {
    const { map } = boardOnAPage();

    assert.equal(map.hidden, false);
    assert.deepEqual(shownSegments(map), [true, true, true, true, true, false, false, false, false]);
    assert.equal(map.text.textContent, 'Columns 1 to 5 of 9');
});

test('a board that fits its space hides the map', () => {
    const board = new FakeBoard(0);
    board.clientWidth = board.scrollWidth;

    const { map } = boardOnAPage({ board });

    assert.equal(map.hidden, true);
});

test('scrolling the board marks the columns that came into view', () => {
    const { page, board, map } = boardOnAPage();

    board.scrollLeft = 664;
    page.dispatch('scroll', { target: board });

    assert.deepEqual(shownSegments(map), [false, false, false, false, true, true, true, true, true]);
    assert.equal(map.text.textContent, 'Columns 5 to 9 of 9');
});

test('a segment scrolls its column to the start of the board and hands it the focus without a second scroll', () => {
    const { page, board, map } = boardOnAPage();

    page.dispatch('click', { target: map.segments[6] });

    assert.deepEqual(board.scrolledTo, { left: 1200, behavior: 'smooth' });
    assert.deepEqual(board.columns[6].focusedWith, { preventScroll: true });
});

test('under reduced motion a jump moves the board at once', () => {
    const { page, board, map } = boardOnAPage({ reducedMotion: true });

    page.dispatch('click', { target: map.segments[2] });

    assert.equal(board.scrolledTo.behavior, 'auto');
});

test('a phone switcher button snaps its column into view past the board inset', () => {
    const { page, board, switcher } = boardOnAPage({ inset: 16 });

    page.dispatch('click', { target: switcher.buttons[3] });

    assert.equal(board.scrolledTo.left, 600);
});

test('swiping the phone board presses the column it settled on and scrolls the switcher to it', () => {
    const board = new FakeBoard(16, 358, 16);
    board.clientWidth = 390;
    const { page, switcher } = boardOnAPage({ board });

    board.scrollLeft = 2 * 374;
    page.dispatch('scroll', { target: board });

    assert.deepEqual(switcher.buttons.map((button) => button.getAttribute('aria-pressed')), ['false', 'false', 'true', 'false', 'false', 'false', 'false', 'false', 'false']);
    assert.equal(switcher.scrollLeft, 316 - 16);
});

test('a second scroll before the pause replaces the first one\'s timer, so only where the board settled is pressed', () => {
    const { page, view, board, switcher } = phoneBoardOnAPage();

    board.scrollLeft = 2 * 374;
    page.dispatch('scroll', { target: board });
    board.scrollLeft = 3 * 374;
    page.dispatch('scroll', { target: board });

    assert.equal(view.timers.size, 1);
    view.runTimers();
    assert.deepEqual(pressed(switcher), ['false', 'false', 'false', 'true', 'false', 'false', 'false', 'false', 'false']);
});

test('a board replaced before the swipe settles presses nothing on the new board', () => {
    const { page, view, board, switcher } = phoneBoardOnAPage();
    board.scrollLeft = 2 * 374;
    page.dispatch('scroll', { target: board });

    board.isConnected = false;
    page.board = new FakeBoard(16, 358, 16);
    view.runTimers();

    assert.deepEqual(pressed(switcher), NAMES.map(() => null));
});

test('a page visit about to render clears the swipe timer', () => {
    const { page, view, board } = phoneBoardOnAPage();
    board.scrollLeft = 2 * 374;
    page.dispatch('scroll', { target: board });

    page.dispatch('turbo:before-render');

    assert.equal(view.timers.size, 0);
});

test('a key such as Escape that folds a column measures the map again', () => {
    const { page, board, map } = boardOnAPage();

    // Backlog folds to its 108 px strip, so every column after it moves 76 px to the left.
    board.columns[1].offsetWidth = 108;
    board.columns.slice(2).forEach((column) => { column.offsetLeft -= 76; });
    board.scrollWidth -= 76;
    page.dispatch('keydown', { key: 'Escape' });

    assert.deepEqual(shownSegments(map), [true, true, true, true, true, true, false, false, false]);
    assert.equal(map.text.textContent, 'Columns 1 to 6 of 9');
});

test('a click elsewhere on the page jumps nowhere', () => {
    const { page, board } = boardOnAPage();

    page.dispatch('click', { target: { closest: () => null } });
    page.dispatch('click', { target: null });

    assert.equal(board.scrolledTo, null);
});

test('a resize and a change of the project filter measure the map again', () => {
    const { page, view, board, map } = boardOnAPage();

    board.clientWidth = board.scrollWidth;
    view.dispatch('resize');
    assert.equal(map.hidden, true);

    board.clientWidth = 1136;
    page.dispatch('intakt:project-shown');
    assert.equal(map.hidden, false);
});

test('a page without a board, such as a review page, is left alone', () => {
    const elsewhere = new FakeBoard(0);
    const page = new FakePage(elsewhere);
    page.querySelector = (selector) => (selector === '.stagemap' ? page.map : null);
    page.getElementById = () => null;

    mapBoardColumns(page, new FakeView());
    page.dispatch('click', { target: page.map.segments[6] });
    page.dispatch('scroll', { target: elsewhere });
    page.dispatch('keydown', { key: 'Escape' });

    assert.equal(elsewhere.scrolledTo, null);
    assert.equal(elsewhere.columns[6].focusedWith, null);
    assert.equal(page.map.hidden, true);
    assert.deepEqual(shownSegments(page.map), NAMES.map(() => false));
    assert.equal(page.map.text.textContent, '');
});
