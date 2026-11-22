import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { switchTaskPanes } = await import(new URL('assets/task-panes.js', root));

const TAB = '.ttabs [data-pane]';
const FINDING_BUTTON = '[data-highlight-finding]';
const FINDING_LINK = 'a.sb-finding';
const TIMELINE_ENTRY = 'li[data-finding]';
const ROUND = 'details.ev';
const PHONE = '(max-width: 760px)';

class FakeTarget extends FakeElement {
    constructor(matches) {
        super();
        this.matches = matches;
    }

    closest(selector) {
        return selector.split(', ').some((part) => this.matches.includes(part)) ? this : null;
    }

    focus() {
        this.page.focused = this;
    }
}

class FakeKey {
    defaultPrevented = false;

    constructor(target, key, modifiers = {}) {
        Object.assign(this, { target, key, altKey: false, ctrlKey: false, metaKey: false, shiftKey: false }, modifiers);
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

class FakeView {
    constructor(phone) {
        this.matches = phone;
    }

    matchMedia(query) {
        assert.equal(query, PHONE);

        return this;
    }

    addEventListener(type, listener) {
        assert.equal(type, 'change');
        this.change = listener;
    }

    resize(phone) {
        this.matches = phone;
        this.change({ matches: phone });
    }
}

class FakeRound extends FakeElement {
    open = true;

    constructor(page) {
        super();
        this.summary = new FakeTarget([]);
        this.summary.page = page;
    }

    querySelector(selector) {
        assert.equal(selector, 'summary');

        return this.summary;
    }
}

class FakePage {
    grid = new FakeElement();
    tabs = [];
    panes = new Map();
    entries = [];
    focused = null;

    constructor() {
        this.grid.setAttribute('data-pane', 'timeline');
        for (const [pane, selected, tabindex, id, paneId, heading] of [
            ['timeline', 'true', '0', 'timeline-tab', 'timeline-pane', 'timeline-title'],
            ['log', 'false', '-1', 'live-log-tab', 'live-log-pane', 'live-log-title'],
        ]) {
            const tab = new FakeTarget([TAB]);
            tab.page = this;
            tab.setAttribute('data-pane', pane);
            tab.setAttribute('aria-selected', selected);
            tab.setAttribute('tabindex', tabindex);
            tab.setAttribute('id', id);
            tab.setAttribute('aria-controls', paneId);
            this.tabs.push(tab);
            const panel = new FakeElement();
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', id);
            panel.setAttribute('tabindex', '0');
            panel.setAttribute('data-heading', heading);
            this.panes.set(paneId, panel);
        }
        const entry = new FakeElement();
        entry.setAttribute('id', 'round-1-finding-F1');
        entry.setAttribute('data-finding', 'F1');
        this.entries.push(entry);
    }

    addEventListener(type, listener) {
        assert.ok(['click', 'keydown', 'turbo:load', 'turbo:render'].includes(type));
        this[type] = listener;
    }

    getElementById(id) {
        return this.panes.get(id) ?? null;
    }

    pane(id) {
        const pane = this.panes.get(id);

        return ['role', 'aria-labelledby', 'tabindex'].map((name) => pane.getAttribute(name));
    }

    inRound(round) {
        const target = new FakeTarget([]);
        target.closest = (selector) => (selector === ROUND ? round : null);

        return target;
    }

    press(target, key, modifiers = {}) {
        const event = new FakeKey(target, key, modifiers);
        this.keydown(event);

        return event;
    }

    tabStops() {
        return this.tabs.map((tab) => tab.getAttribute('tabindex'));
    }

    querySelector(selector) {
        assert.equal(selector, '.task-grid');

        return this.grid;
    }

    querySelectorAll(selector) {
        assert.ok([TAB, TIMELINE_ENTRY].includes(selector));

        return selector === TAB ? this.tabs : this.entries;
    }

    selected() {
        return this.tabs.map((tab) => tab.getAttribute('aria-selected'));
    }
}

function reviewPageOnAPhone() {
    return reviewPage(new FakeView(true));
}

function reviewPage(view) {
    const page = new FakePage();
    page.view = view;
    switchTaskPanes(page, view);

    return page;
}

test('on a phone each pane is a tab panel labelled by its tab and a Tab stop', () => {
    const page = reviewPageOnAPhone();

    assert.deepEqual(page.pane('timeline-pane'), ['tabpanel', 'timeline-tab', '0']);
    assert.deepEqual(page.pane('live-log-pane'), ['tabpanel', 'live-log-tab', '0']);
});

test('beside each other the panes are no tab panels and their headings label them', () => {
    const page = reviewPage(new FakeView(false));

    assert.deepEqual(page.pane('timeline-pane'), [null, 'timeline-title', null]);
    assert.deepEqual(page.pane('live-log-pane'), [null, 'live-log-title', null]);
});

test('narrowing the window to a phone makes the panes tab panels again', () => {
    const page = reviewPage(new FakeView(false));

    page.view.resize(true);

    assert.deepEqual(page.pane('live-log-pane'), ['tabpanel', 'live-log-tab', '0']);
});

test('widening the window past a phone drops the tab panels', () => {
    const page = reviewPageOnAPhone();

    page.view.resize(false);

    assert.deepEqual(page.pane('live-log-pane'), [null, 'live-log-title', null]);
});

test('a review page a visit puts in later fits the width too', () => {
    const page = reviewPage(new FakeView(false));
    const fresh = page.panes.get('live-log-pane');
    fresh.setAttribute('role', 'tabpanel');
    fresh.setAttribute('aria-labelledby', 'live-log-tab');

    page['turbo:load']();

    assert.deepEqual(page.pane('live-log-pane'), [null, 'live-log-title', null]);
});

test('a page without the tabs keeps its panes as rendered', () => {
    const page = new FakePage();
    page.tabs = [];

    switchTaskPanes(page, new FakeView(false));

    assert.deepEqual(page.pane('timeline-pane'), ['tabpanel', 'timeline-tab', '0']);
});

test('Escape in an open round folds it and puts focus on its head', () => {
    const page = reviewPageOnAPhone();
    const round = new FakeRound(page);

    const key = page.press(page.inRound(round), 'Escape');

    assert.equal(round.open, false);
    assert.equal(page.focused, round.summary);
    assert.equal(key.defaultPrevented, true);
});

test('Escape in a folded round stays the browser\'s', () => {
    const page = reviewPageOnAPhone();
    const round = new FakeRound(page);
    round.open = false;

    const key = page.press(page.inRound(round), 'Escape');

    assert.equal(round.open, false);
    assert.equal(page.focused, null);
    assert.equal(key.defaultPrevented, false);
});

test('Escape outside any round stays the browser\'s', () => {
    const page = reviewPageOnAPhone();

    const key = page.press(page.inRound(null), 'Escape');

    assert.equal(key.defaultPrevented, false);
});

test('another key in an open round keeps it open', () => {
    const page = reviewPageOnAPhone();
    const round = new FakeRound(page);

    const key = page.press(page.inRound(round), 'Enter');

    assert.equal(round.open, true);
    assert.equal(key.defaultPrevented, false);
});

function findingButton(id) {
    const button = new FakeTarget([FINDING_BUTTON]);
    button.setAttribute('data-highlight-finding', id);

    return button;
}

function findingLink(href) {
    const link = new FakeTarget([FINDING_LINK]);
    link.setAttribute('href', href);

    return link;
}

test('choosing the live log shows its pane and marks its tab as chosen', () => {
    const page = reviewPageOnAPhone();

    page.click({ target: page.tabs[1] });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
    assert.deepEqual(page.selected(), ['false', 'true']);
});

test('choosing the timeline again shows the timeline pane', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: page.tabs[0] });

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
    assert.deepEqual(page.selected(), ['true', 'false']);
});

test('a finding id in the session summary brings the timeline back, where its entry is', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingButton('F1') });

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
    assert.deepEqual(page.selected(), ['true', 'false']);
});

test('a reported finding without a timeline entry keeps the live log', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingButton('F2') });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
    assert.deepEqual(page.selected(), ['false', 'true']);
});

test('a finding id merged into an act entry brings the timeline back, where that entry is', () => {
    const page = reviewPageOnAPhone();
    page.entries[0].setAttribute('data-finding', 'F1 F2');
    page.click({ target: page.tabs[1] });

    page.click({ target: findingButton('F2') });

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
});

test('a finding link to the Full record keeps the chosen pane, because it opens another page', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingLink('/reviews/1/record#round-1-finding-F1') });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
});

test('a click elsewhere keeps the chosen pane', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: new FakeTarget([]) });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
});

test('choosing a tab makes it the only tab in the Tab order', () => {
    const page = reviewPageOnAPhone();

    page.click({ target: page.tabs[1] });

    assert.deepEqual(page.tabStops(), ['-1', '0']);
});

test('the right arrow on the Timeline tab chooses and focuses the Live log tab', () => {
    const page = reviewPageOnAPhone();

    const key = page.press(page.tabs[0], 'ArrowRight');

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
    assert.deepEqual(page.selected(), ['false', 'true']);
    assert.deepEqual(page.tabStops(), ['-1', '0']);
    assert.equal(page.focused, page.tabs[1]);
    assert.equal(key.defaultPrevented, true);
});

test('the right arrow on the last tab wraps to the first', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.press(page.tabs[1], 'ArrowRight');

    assert.deepEqual(page.selected(), ['true', 'false']);
    assert.equal(page.focused, page.tabs[0]);
});

test('the left arrow on the first tab wraps to the last', () => {
    const page = reviewPageOnAPhone();

    page.press(page.tabs[0], 'ArrowLeft');

    assert.deepEqual(page.selected(), ['false', 'true']);
    assert.equal(page.focused, page.tabs[1]);
});

test('the left arrow on the Live log tab goes back to the Timeline tab', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.press(page.tabs[1], 'ArrowLeft');

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
    assert.equal(page.focused, page.tabs[0]);
});

test('End chooses and focuses the last tab', () => {
    const page = reviewPageOnAPhone();

    page.press(page.tabs[0], 'End');

    assert.deepEqual(page.selected(), ['false', 'true']);
    assert.equal(page.focused, page.tabs[1]);
});

test('Home chooses and focuses the first tab', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.press(page.tabs[1], 'Home');

    assert.deepEqual(page.selected(), ['true', 'false']);
    assert.equal(page.focused, page.tabs[0]);
});

test('an arrow key outside the tabs changes nothing and stays the browser\'s', () => {
    const page = reviewPageOnAPhone();

    const key = page.press(new FakeTarget([]), 'ArrowRight');

    assert.deepEqual(page.selected(), ['true', 'false']);
    assert.equal(page.focused, null);
    assert.equal(key.defaultPrevented, false);
});

test('another key on a tab, Tab and Enter included, stays the browser\'s', () => {
    const page = reviewPageOnAPhone();

    const keys = ['Tab', 'Enter', ' ', 'ArrowDown', 'constructor'].map((name) => page.press(page.tabs[0], name));

    assert.deepEqual(keys.map((key) => key.defaultPrevented), [false, false, false, false, false]);
    assert.deepEqual(page.selected(), ['true', 'false']);
    assert.equal(page.focused, null);
});

test('an arrow key with a modifier on a tab stays the browser\'s', () => {
    const page = reviewPageOnAPhone();

    const keys = ['altKey', 'ctrlKey', 'metaKey', 'shiftKey'].map((modifier) => page.press(page.tabs[0], 'ArrowRight', { [modifier]: true }));

    assert.deepEqual(keys.map((key) => key.defaultPrevented), [false, false, false, false]);
    assert.deepEqual(page.selected(), ['true', 'false']);
});

test('a page without the task grid ignores a finding link', () => {
    const page = reviewPageOnAPhone();
    page.grid = null;

    assert.doesNotThrow(() => page.click({ target: findingButton('F1') }));
});
