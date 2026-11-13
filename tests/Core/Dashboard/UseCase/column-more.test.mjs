import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { showMoreCards } = await import(new URL('assets/column-more.js', root));

class FakeLink {
    focused = false;

    focus() {
        this.focused = true;
    }
}

class FakeCard extends FakeElement {
    link = new FakeLink();

    constructor(project) {
        super();
        this.dataset = { project };
    }

    querySelector(selector) {
        assert.equal(selector, 'a');

        return this.link;
    }
}

class FakeMoreButton extends FakeElement {
    constructor(column) {
        super();
        this.column = column;
    }

    closest(selector) {
        if (selector === '.col') {
            return this.column;
        }
        assert.equal(selector, '.more-btn');

        return this;
    }
}

// A column as the board renders one past its page limit: the cards in order, then Show more.
class FakeColumn extends FakeElement {
    constructor(projects) {
        super();
        this.dataset = { pageLimit: '12' };
        this.cards = projects.map((project) => new FakeCard(project));
        this.more = new FakeMoreButton(this);
    }

    querySelectorAll(selector) {
        assert.equal(selector, '.col-cards > [data-project]');

        return this.cards;
    }

    querySelector(selector) {
        if (selector === '.more-btn') {
            return this.more;
        }
        assert.equal(selector, '[data-past-limit]');

        return this.cards.find((card) => card.hasAttribute('data-past-limit')) ?? null;
    }

    pastLimit() {
        return this.cards.filter((card) => card.hasAttribute('data-past-limit')).length;
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
        assert.equal(selector, '.col[data-page-limit]');

        return this.columns;
    }

    click(target) {
        this.listeners.get('click')({ target });
    }

    showProject(project) {
        this.columns.forEach((column) => column.cards.forEach((card) => (card.hidden = project !== '' && card.dataset.project !== project)));
        this.listeners.get('intakt:project-shown')();
    }
}

function boardWith(projects) {
    const column = new FakeColumn(projects);
    const page = new FakePage([column]);
    showMoreCards(page);

    return { page, column };
}

const forty = () => Array.from({ length: 40 }, (unused, index) => (index % 2 === 0 ? 'alpha' : 'beta'));

test('a crowded column shows its first twelve cards and offers the other twenty-eight', () => {
    const { column } = boardWith(forty());

    assert.equal(column.pastLimit(), 28);
    assert.equal(column.cards.slice(0, 12).some((card) => card.hasAttribute('data-past-limit')), false);
    assert.equal(column.more.textContent, 'Show 28 more');
    assert.equal(column.more.hidden, false);
});

test('show more shows every card, hides itself and hands the focus to the first card it showed', () => {
    const { page, column } = boardWith(forty());

    page.click(column.more);

    assert.equal(column.pastLimit(), 0);
    assert.equal(column.more.hidden, true);
    assert.equal(column.cards[12].link.focused, true);
});

test('the page limit counts only the cards of the chosen project', () => {
    const { page, column } = boardWith(forty());

    page.showProject('alpha');

    const alpha = column.cards.filter((card) => card.dataset.project === 'alpha');
    assert.equal(alpha.slice(0, 12).some((card) => card.hasAttribute('data-past-limit')), false);
    assert.equal(alpha.slice(12).every((card) => card.hasAttribute('data-past-limit')), true);
    assert.equal(column.pastLimit(), 8);
    assert.equal(column.more.textContent, 'Show 8 more');
});

test('a chosen project with twelve cards or fewer in the column needs no show more', () => {
    const projects = forty().map((project, index) => (index < 12 ? 'gamma' : project));
    const { page, column } = boardWith(projects);

    page.showProject('gamma');

    assert.equal(column.pastLimit(), 0);
    assert.equal(column.more.hidden, true);
});

test('choosing all projects again limits the column to twelve cards again', () => {
    const { page, column } = boardWith(forty());
    page.showProject('alpha');

    page.showProject('');

    assert.equal(column.pastLimit(), 28);
    assert.equal(column.more.textContent, 'Show 28 more');
});

test('choosing a project never undoes show more', () => {
    const { page, column } = boardWith(forty());
    page.click(column.more);

    page.showProject('beta');
    page.showProject('');

    assert.equal(column.pastLimit(), 0);
    assert.equal(column.more.hidden, true);
});

test('a click outside show more changes no column', () => {
    const { page, column } = boardWith(forty());

    page.click({ closest: () => null });
    page.click(null);

    assert.equal(column.pastLimit(), 28);
    assert.equal(column.more.hidden, false);
});
