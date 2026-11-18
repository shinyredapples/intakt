import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { showEmptyLines } = await import(new URL('assets/column-empty.js', root));

class FakeCard extends FakeElement {
    constructor(project) {
        super();
        this.dataset = { project };
    }
}

// A column as the board renders one: its cards, then its empty-state line, hidden while a card is there.
class FakeColumn extends FakeElement {
    line = new FakeElement('Reviews in flight appear here.');

    moreLink = null;

    constructor(projects) {
        super();
        this.cards = projects.map((project) => new FakeCard(project));
        this.line.hidden = this.cards.length > 0;
    }

    querySelector(selector) {
        if (selector === '.more-link') {
            return this.moreLink;
        }
        assert.equal(selector, '.col-empty');

        return this.line;
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.col-cards > [data-project]');

        return this.cards;
    }
}

class FakePage {
    listeners = new Map();

    constructor(columns) {
        this.columns = columns;
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.col');

        return this.columns;
    }

    showProject(project) {
        this.columns.forEach((column) => column.cards.forEach((card) => (card.hidden = project !== '' && card.dataset.project !== project)));
        this.listeners.get('intakt:project-shown')();
    }
}

function boardWith(projects, moreLink = null) {
    const column = new FakeColumn(projects);
    column.moreLink = moreLink;
    const page = new FakePage([column]);
    showEmptyLines(page);

    return { page, column };
}

test('a column with a card keeps its empty-state line hidden on load', () => {
    const { column } = boardWith(['alpha']);

    assert.equal(column.line.hidden, true);
});

test('a column without a card shows its empty-state line on load', () => {
    const { column } = boardWith([]);

    assert.equal(column.line.hidden, false);
});

test('choosing a project none of the column\'s cards belongs to shows its empty-state line', () => {
    const { page, column } = boardWith(['alpha']);

    page.showProject('beta');

    assert.equal(column.line.hidden, false);
});

test('choosing the project of one of the column\'s cards hides its empty-state line', () => {
    const { page, column } = boardWith(['alpha', 'beta']);

    page.showProject('beta');

    assert.equal(column.line.hidden, true);
});

test('a column with a Show n more link keeps its empty-state line hidden though no card of it is shown', () => {
    const { page, column } = boardWith(['alpha'], new FakeElement('Show 3 more'));

    page.showProject('beta');

    assert.equal(column.line.hidden, true);
});

test('going back to every project hides the empty-state line again', () => {
    const { page, column } = boardWith(['alpha']);
    page.showProject('beta');

    page.showProject('');

    assert.equal(column.line.hidden, true);
});
