import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadController } from './stimulus-controller.mjs';

const FindingHighlightController = await loadController('finding_highlight');

class FakeRow extends FakeElement {
    constructor() {
        super();
        this.classList.remove = (name) => this.classes.delete(name);
        this.classList.add = (name) => this.classes.add(name);
    }
}

function summaryOver(entries) {
    const rows = new Map();
    const cells = new Map();
    for (const id of entries) {
        const row = new FakeRow();
        rows.set(id, row);
        cells.set(id, { getAttribute: () => id, closest: (selector) => (selector === 'tr' ? row : null) });
    }
    const controller = new FindingHighlightController();
    controller.element = {
        ownerDocument: {
            querySelectorAll: (selector) => (selector === 'tr.selected' ? [...rows.values()].filter((row) => row.classes.has('selected')) : [...cells.values()]),
        },
    };
    const link = (id) => ({ params: { id } });

    return { controller, rows, link };
}

test('choosing a finding id highlights its timeline entry', () => {
    const { controller, rows, link } = summaryOver(['FF1']);

    controller.select(link('FF1'));

    const row = rows.get('FF1');
    assert.equal(row.classes.has('selected'), true);
    assert.equal(row.getAttribute('aria-current'), 'true');
});

test('choosing a second finding id moves the highlight off the first entry', () => {
    const { controller, rows, link } = summaryOver(['FF1', 'FF2']);
    controller.select(link('FF1'));

    controller.select(link('FF2'));

    assert.equal(rows.get('FF1').classes.has('selected'), false);
    assert.equal(rows.get('FF1').getAttribute('aria-current'), null);
    assert.equal(rows.get('FF2').classes.has('selected'), true);
});

test('a finding id with no timeline entry yet highlights nothing and keeps the earlier highlight', () => {
    const { controller, rows, link } = summaryOver(['FF1']);
    controller.select(link('FF1'));

    controller.select(link('FF9'));

    assert.equal(rows.get('FF1').classes.has('selected'), true);
});
