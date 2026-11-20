import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadController } from './stimulus-controller.mjs';

const ChartController = await loadController('chart');

function box(left, top, width, height) {
    return { getBoundingClientRect: () => ({ left, top, width, height }) };
}

function chartOf(width) {
    const chart = new ChartController();
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

function shownOver(chart, mark) {
    chart.show({ currentTarget: mark, params: { reading: '3 P1 findings', detail: 'Security reviewer, 2 found by it alone' } });

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

test('leaving the mark hides the tip', () => {
    const chart = chartOf(600);
    shownOver(chart, box(300, 1050, 100, 150));

    chart.hide();

    assert.equal(chart.tipTarget.hidden, true);
});
