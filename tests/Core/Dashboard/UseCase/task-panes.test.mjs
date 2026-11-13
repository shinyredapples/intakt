import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { switchTaskPanes } = await import(new URL('assets/task-panes.js', root));

const TAB = '.ttabs [data-pane]';
const FINDING_BUTTON = '[data-highlight-finding]';
const FINDING_LINK = 'a.sb-finding';
const TIMELINE_ENTRY = 'td[data-finding]';

class FakeTarget extends FakeElement {
    constructor(matches) {
        super();
        this.matches = matches;
    }

    closest(selector) {
        return selector.split(', ').some((part) => this.matches.includes(part)) ? this : null;
    }
}

class FakePage {
    grid = new FakeElement();
    tabs = [];
    entries = [];

    constructor() {
        this.grid.setAttribute('data-pane', 'timeline');
        for (const [pane, selected] of [['timeline', 'true'], ['log', 'false']]) {
            const tab = new FakeTarget([TAB]);
            tab.setAttribute('data-pane', pane);
            tab.setAttribute('aria-selected', selected);
            this.tabs.push(tab);
        }
        const entry = new FakeElement();
        entry.setAttribute('id', 'round-1-finding-F1');
        entry.setAttribute('data-finding', 'F1');
        this.entries.push(entry);
    }

    addEventListener(type, listener) {
        assert.equal(type, 'click');
        this.click = listener;
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
    const page = new FakePage();
    switchTaskPanes(page);

    return page;
}

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

test('a finding link in the state block brings the timeline back, where its entry is', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingLink('#round-1-finding-F1') });

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
});

test('a reported finding without a timeline entry keeps the live log', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingButton('F2') });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
    assert.deepEqual(page.selected(), ['false', 'true']);
});

test('a finding link without a timeline entry keeps the live log', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: findingLink('#round-2-finding-F1') });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
});

test('a click elsewhere keeps the chosen pane', () => {
    const page = reviewPageOnAPhone();
    page.click({ target: page.tabs[1] });

    page.click({ target: new FakeTarget([]) });

    assert.equal(page.grid.getAttribute('data-pane'), 'log');
});

test('a page without the task grid ignores a finding link', () => {
    const page = reviewPageOnAPhone();
    page.grid = null;

    assert.doesNotThrow(() => page.click({ target: findingButton('F1') }));
});
