import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { switchTaskPanes } = await import(new URL('assets/task-panes.js', root));

const TAB = '.ttabs [data-pane]';
const TIMELINE_LINK = '[data-highlight-finding], a.sb-finding';

class FakeTarget extends FakeElement {
    constructor(matches) {
        super();
        this.matches = matches;
    }

    closest(selector) {
        return this.matches.includes(selector) ? this : null;
    }
}

class FakePage {
    grid = new FakeElement();
    tabs = [];

    constructor() {
        this.grid.setAttribute('data-pane', 'timeline');
        for (const [pane, selected] of [['timeline', 'true'], ['log', 'false']]) {
            const tab = new FakeTarget([TAB]);
            tab.setAttribute('data-pane', pane);
            tab.setAttribute('aria-selected', selected);
            this.tabs.push(tab);
        }
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
        assert.equal(selector, TAB);

        return this.tabs;
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

    page.click({ target: new FakeTarget([TIMELINE_LINK]) });

    assert.equal(page.grid.getAttribute('data-pane'), 'timeline');
    assert.deepEqual(page.selected(), ['true', 'false']);
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

    assert.doesNotThrow(() => page.click({ target: new FakeTarget([TIMELINE_LINK]) }));
});
