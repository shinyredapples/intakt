import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, loadController } from './stimulus-controller.mjs';

const ChartController = await loadController('chart');

class FakeBox extends FakeElement {
    constructor(box, attributes = {}) {
        super();
        this.box = box;
        this.style = {};
        this.offsetWidth = 0;
        for (const [name, value] of Object.entries(attributes)) {
            this.setAttribute(name, value);
        }
    }

    getBoundingClientRect() {
        return this.box;
    }
}

function bar(left, label, reading) {
    return new FakeBox({ left, top: 112, width: 20 }, { 'data-label': label, 'data-reading': reading });
}

function shownChart() {
    const chart = new ChartController();
    chart.element = new FakeBox({ left: 100, top: 100, width: 300 });
    chart.tipTarget = new FakeBox({});
    chart.tipTarget.hidden = true;
    chart.tipTarget.offsetWidth = 80;
    chart.readingTarget = new FakeElement();
    chart.labelTarget = new FakeElement();
    chart.connect();

    return chart;
}

const nov3 = () => bar(240, 'Nov 3', '4');
const nov5 = () => bar(280, 'Nov 5', 'No data');

function shows(chart, reading, label) {
    assert.equal(chart.tipTarget.hidden, false);
    assert.equal(chart.readingTarget.textContent, reading);
    assert.equal(chart.labelTarget.textContent, label);
}

test('the pointer on a bar shows its value first and its label second', () => {
    const chart = shownChart();

    chart.pointerEnter({ currentTarget: nov3() });

    shows(chart, '4', 'Nov 3');
});

test('focus on a bar shows its tooltip', () => {
    const chart = shownChart();

    chart.focusIn({ currentTarget: nov5() });

    shows(chart, 'No data', 'Nov 5');
});

test('the tooltip sits centered over the bar it names', () => {
    const chart = shownChart();

    chart.focusIn({ currentTarget: nov3() });

    assert.equal(chart.tipTarget.style.left, '150px');
    assert.equal(chart.tipTarget.style.top, '12px');
});

test('a tooltip near the edge stays inside the chart', () => {
    const chart = shownChart();

    chart.focusIn({ currentTarget: bar(100, 'Nov 1', '3') });

    assert.equal(chart.tipTarget.style.left, '40px');
});

test('the pointer leaving a bar that has focus keeps its tooltip', () => {
    const chart = shownChart();
    const focused = nov3();
    chart.focusIn({ currentTarget: focused });
    chart.pointerEnter({ currentTarget: focused });

    chart.pointerLeave();

    shows(chart, '4', 'Nov 3');
});

test('the pointer leaving another bar brings back the tooltip of the focused one', () => {
    const chart = shownChart();
    chart.focusIn({ currentTarget: nov3() });
    chart.pointerEnter({ currentTarget: nov5() });

    chart.pointerLeave();

    shows(chart, '4', 'Nov 3');
});

test('blur of a bar under the pointer keeps its tooltip', () => {
    const chart = shownChart();
    const pointed = nov5();
    chart.pointerEnter({ currentTarget: pointed });
    chart.focusIn({ currentTarget: pointed });

    chart.focusOut();

    shows(chart, 'No data', 'Nov 5');
});

test('the tooltip hides once neither pointer nor focus is on a bar', () => {
    const chart = shownChart();
    const both = nov3();
    chart.pointerEnter({ currentTarget: both });
    chart.focusIn({ currentTarget: both });

    chart.pointerLeave();
    chart.focusOut();

    assert.equal(chart.tipTarget.hidden, true);
});

test('escape hides the tooltip while the bar keeps focus and pointer', () => {
    const chart = shownChart();
    const both = nov3();
    chart.pointerEnter({ currentTarget: both });
    chart.focusIn({ currentTarget: both });

    chart.dismiss();

    assert.equal(chart.tipTarget.hidden, true);
});

test('a tooltip hidden by escape stays hidden when one trigger leaves', () => {
    const chart = shownChart();
    const both = nov3();
    chart.pointerEnter({ currentTarget: both });
    chart.focusIn({ currentTarget: both });
    chart.dismiss();

    chart.pointerLeave();

    assert.equal(chart.tipTarget.hidden, true);
});

test('the next pointer after escape shows the tooltip again', () => {
    const chart = shownChart();
    chart.focusIn({ currentTarget: nov3() });
    chart.dismiss();

    chart.pointerEnter({ currentTarget: nov5() });

    shows(chart, 'No data', 'Nov 5');
});

test('the next focus after escape shows the tooltip again', () => {
    const chart = shownChart();
    chart.pointerEnter({ currentTarget: nov3() });
    chart.dismiss();

    chart.focusIn({ currentTarget: nov5() });

    shows(chart, 'No data', 'Nov 5');
});
