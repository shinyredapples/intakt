import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadController } from './stimulus-controller.mjs';

const ChartController = await loadController('chart');

function box(left, top, width, height) {
    return { getBoundingClientRect: () => ({ left, top, width, height }) };
}

function chartOf(width) {
    const chart = new ChartController();
    chart.connect();
    chart.element = box(100, 1000, width, 300);
    const reading = new FakeElement();
    const detail = new FakeElement();
    const tip = new FakeElement();
    tip.hidden = true;
    tip.offsetWidth = 120;
    tip.offsetHeight = 40;
    tip.style = {};
    tip.querySelector = (selector) => ('strong' === selector ? reading : detail);
    chart.tipTarget = tip;

    return chart;
}

const READING = { reading: '3 P1 findings', detail: 'Security reviewer, 2 found by it alone' };
const OTHER = { reading: '1 P2 finding', detail: 'Correctness reviewer, 0 found by it alone' };

function shownOver(chart, mark) {
    chart.pointerEnter({ currentTarget: mark, params: READING });

    return chart.tipTarget;
}

test('a mark shows its value first and its detail second', () => {
    const tip = shownOver(chartOf(600), box(300, 1050, 100, 150));

    assert.equal(tip.hidden, false);
    assert.equal(tip.querySelector('strong').textContent, '3 P1 findings');
    assert.equal(tip.querySelector('span').textContent, 'Security reviewer, 2 found by it alone');
});

test('the tip sits centered above its mark', () => {
    const tip = shownOver(chartOf(600), box(300, 1050, 100, 150));

    assert.equal(tip.style.left, '190px');
    assert.equal(tip.style.top, '2px');
});

test('a tip over the first mark stays inside the chart', () => {
    const tip = shownOver(chartOf(600), box(100, 1050, 40, 150));

    assert.equal(tip.style.left, '0px');
});

test('a tip over the last mark stays inside the chart', () => {
    const tip = shownOver(chartOf(600), box(660, 1050, 40, 150));

    assert.equal(tip.style.left, '480px');
});

test('the pointer leaving the mark hides the tip', () => {
    const chart = chartOf(600);
    shownOver(chart, box(300, 1050, 100, 150));

    chart.pointerLeave();

    assert.equal(chart.tipTarget.hidden, true);
});

test('focus shows the tip and blur hides it', () => {
    const chart = chartOf(600);

    chart.focusIn({ currentTarget: box(300, 1050, 100, 150), params: READING });
    assert.equal(chart.tipTarget.hidden, false);

    chart.focusOut();
    assert.equal(chart.tipTarget.hidden, true);
});

test('the pointer leaving keeps the tip while the mark still has focus', () => {
    const chart = chartOf(600);
    const mark = box(300, 1050, 100, 150);
    chart.focusIn({ currentTarget: mark, params: READING });
    chart.pointerEnter({ currentTarget: mark, params: READING });

    chart.pointerLeave();

    assert.equal(chart.tipTarget.hidden, false);
    assert.equal(chart.tipTarget.querySelector('strong').textContent, '3 P1 findings');
});

test('blur keeps the tip while the pointer is still over the mark', () => {
    const chart = chartOf(600);
    const mark = box(300, 1050, 100, 150);
    chart.focusIn({ currentTarget: mark, params: READING });
    chart.pointerEnter({ currentTarget: mark, params: READING });

    chart.focusOut();

    assert.equal(chart.tipTarget.hidden, false);
});

test('the pointer leaving another mark goes back to the focused mark', () => {
    const chart = chartOf(600);
    chart.focusIn({ currentTarget: box(300, 1050, 100, 150), params: READING });
    chart.pointerEnter({ currentTarget: box(500, 1050, 100, 150), params: OTHER });
    assert.equal(chart.tipTarget.querySelector('strong').textContent, '1 P2 finding');

    chart.pointerLeave();

    assert.equal(chart.tipTarget.hidden, false);
    assert.equal(chart.tipTarget.querySelector('strong').textContent, '3 P1 findings');
    assert.equal(chart.tipTarget.style.left, '190px');
});

test('Escape hides the tip while pointer and focus stay, until the next trigger', () => {
    const chart = chartOf(600);
    const mark = box(300, 1050, 100, 150);
    chart.focusIn({ currentTarget: mark, params: READING });
    chart.pointerEnter({ currentTarget: mark, params: READING });

    chart.dismiss();
    assert.equal(chart.tipTarget.hidden, true);
    chart.pointerLeave();
    assert.equal(chart.tipTarget.hidden, true);

    chart.pointerEnter({ currentTarget: mark, params: READING });
    assert.equal(chart.tipTarget.hidden, false);
});

test('Escape hides a tip the pointer alone holds while the focus is elsewhere', () => {
    const chart = chartOf(600);
    chart.pointerEnter({ currentTarget: box(300, 1050, 100, 150), params: READING });

    chart.dismiss();
    assert.equal(chart.tipTarget.hidden, true);
    chart.focusOut();
    assert.equal(chart.tipTarget.hidden, true);
});

function markOf(left, reading, detail) {
    return { ...box(left, 1050, 100, 150), isConnected: true, dataset: { chartReadingParam: reading, chartDetailParam: detail } };
}

function morphed(chart) {
    chart.tipTarget.hidden = true;
    chart.tipTarget.querySelector('strong').textContent = '';
    chart.tipTarget.querySelector('span').textContent = '';
    chart.restore({ target: chart.element });
}

test('a pushed morph keeps the tip of a hovered mark, with the value the push gave it', () => {
    const chart = chartOf(600);
    const mark = markOf(300, READING.reading, READING.detail);
    chart.pointerEnter({ currentTarget: mark, params: READING });
    mark.dataset = { chartReadingParam: '4 P1 findings', chartDetailParam: 'Security reviewer, 3 found by it alone' };

    morphed(chart);

    assert.equal(chart.tipTarget.hidden, false);
    assert.equal(chart.tipTarget.querySelector('strong').textContent, '4 P1 findings');
    assert.equal(chart.tipTarget.querySelector('span').textContent, 'Security reviewer, 3 found by it alone');
});

test('a pushed morph keeps the tip of a focused mark', () => {
    const chart = chartOf(600);
    chart.focusIn({ currentTarget: markOf(300, READING.reading, READING.detail), params: READING });

    morphed(chart);

    assert.equal(chart.tipTarget.hidden, false);
    assert.equal(chart.tipTarget.querySelector('strong').textContent, '3 P1 findings');
});

test('a pushed morph that removed the held mark leaves the tip hidden', () => {
    const chart = chartOf(600);
    const mark = markOf(300, READING.reading, READING.detail);
    chart.pointerEnter({ currentTarget: mark, params: READING });
    mark.isConnected = false;

    morphed(chart);

    assert.equal(chart.tipTarget.hidden, true);
});

test('a pushed morph keeps a tip dismissed with Escape hidden', () => {
    const chart = chartOf(600);
    chart.pointerEnter({ currentTarget: markOf(300, READING.reading, READING.detail), params: READING });
    chart.dismiss();

    morphed(chart);

    assert.equal(chart.tipTarget.hidden, true);
});

test('a morph of an element inside the chart does not show the tip on its own', () => {
    const chart = chartOf(600);
    chart.pointerEnter({ currentTarget: markOf(300, READING.reading, READING.detail), params: READING });
    chart.tipTarget.hidden = true;

    chart.restore({ target: chart.tipTarget });

    assert.equal(chart.tipTarget.hidden, true);
});
