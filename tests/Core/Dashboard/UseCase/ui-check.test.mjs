import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { jobsFor, measureFloors, report, failed } = createRequire(import.meta.url)('../../../../bin/ui-check');

const floors = { text: 15, target: 44 };

class FakeElement {
    constructor({ tag = 'span', text = '', fontSize = 15, height = 20, width = 100, visibility = 'visible', hiddenBy = null, labels = [] } = {}) {
        this.tagName = tag.toUpperCase();
        this.id = '';
        this.classList = [];
        this.childNodes = text === '' ? [] : [{ nodeType: 3, textContent: text }];
        this.textContent = text;
        this.style = { fontSize: fontSize + 'px', visibility };
        this.box = { width, height };
        this.hiddenBy = hiddenBy;
        this.labels = labels;
    }

    getBoundingClientRect() {
        return this.box;
    }

    getAttribute() {
        return null;
    }

    closest(selector) {
        assert.equal(selector, '[inert], [aria-hidden="true"]');

        return this.hiddenBy;
    }
}

function pageOf(texts, targets = []) {
    return {
        document: {
            body: {
                querySelectorAll: (selector) => (selector === '*' ? texts : targets),
            },
        },
        getComputedStyle: (element) => element.style,
    };
}

function run(overrides) {
    return { page: 'projects', theme: 'light', width: 390, errors: [], violations: [], floors: [], ...overrides };
}

test('every page is checked in both themes at 1440, 1920 and 390 wide', () => {
    const jobs = jobsFor([{ name: 'projects', path: '/projects' }]);

    assert.deepEqual(
        jobs.map((job) => `${job.name} ${job.theme} ${job.width}x${job.height}`),
        [
            'projects light 1440x900',
            'projects light 1920x1080',
            'projects light 390x844',
            'projects dark 1440x900',
            'projects dark 1920x1080',
            'projects dark 390x844',
        ],
    );
});

test('text under 15 px is reported', () => {
    const found = measureFloors(floors, pageOf([new FakeElement({ text: '2 review rounds', fontSize: 13 })]));

    assert.deepEqual(found, ['text 13 px: span "2 review rounds"']);
});

test('text of exactly 15 px passes', () => {
    assert.deepEqual(measureFloors(floors, pageOf([new FakeElement({ text: 'Projects', fontSize: 15 })])), []);
});

test('text nobody can see is not measured', () => {
    const hidden = [
        new FakeElement({ text: 'collapsed', fontSize: 12, height: 0, width: 0 }),
        new FakeElement({ text: 'only for screen readers', fontSize: 12, height: 1, width: 1 }),
        new FakeElement({ text: 'invisible', fontSize: 12, visibility: 'hidden' }),
        new FakeElement({ text: 'behind the search', fontSize: 12, hiddenBy: new FakeElement() }),
    ];

    assert.deepEqual(measureFloors(floors, pageOf(hidden)), []);
});

test('an element without text of its own is no text', () => {
    assert.deepEqual(measureFloors(floors, pageOf([new FakeElement({ fontSize: 12 })])), []);
});

test('a touch target under 44 px tall is reported', () => {
    const button = new FakeElement({ tag: 'button', text: 'Stop', height: 40 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), ['target 40 px: button "Stop"']);
});

test('a touch target of exactly 44 px passes', () => {
    const button = new FakeElement({ tag: 'button', text: 'Stop', height: 44 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), []);
});

test('a small control counts the height of its label, which takes the tap too', () => {
    const label = new FakeElement({ tag: 'label', height: 44 });
    const toggle = new FakeElement({ tag: 'input', height: 32, labels: [label] });

    assert.deepEqual(measureFloors(floors, pageOf([], [toggle])), []);
});

test('a small control with a small label is reported with the larger of the two', () => {
    const label = new FakeElement({ tag: 'label', height: 36 });
    const toggle = new FakeElement({ tag: 'input', height: 32, labels: [label] });

    assert.deepEqual(measureFloors(floors, pageOf([], [toggle])), ['target 36 px: input']);
});

test('a hidden touch target is not measured', () => {
    const button = new FakeElement({ tag: 'button', text: 'Cancel', height: 0, width: 0 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), []);
});

test('serious and critical axe violations fail the check', () => {
    for (const impact of ['serious', 'critical']) {
        assert.equal(failed([run({ violations: [{ id: 'color-contrast', impact, help: 'Contrast', nodes: 1 }] })]), true);
    }
});

test('minor and moderate axe violations are reported without failing the check', () => {
    const runs = [run({ violations: [{ id: 'region', impact: 'moderate', help: 'Landmarks', nodes: 2 }, { id: 'x', impact: 'minor', help: 'X', nodes: 1 }] })];

    assert.equal(failed(runs), false);
    assert.match(report(runs), /projects light 390: axe critical 0, serious 0, moderate 1, minor 1, phone floor findings 0\n {2}moderate region: Landmarks \(2 nodes\)/);
});

test('a phone floor finding fails the check', () => {
    assert.equal(failed([run({ floors: ['text 13 px: span "2 review rounds"'] })]), true);
});

test('a page that did not load fails the check', () => {
    assert.equal(failed([run({ errors: ['HTTP 500'] })]), true);
});

test('a clean run passes and the summary counts what was found', () => {
    const runs = [run({}), run({ theme: 'dark' })];

    assert.equal(failed(runs), false);
    assert.match(report(runs), /UI check: 2 runs, 0 serious or critical axe violations, 0 phone floor findings, 0 page errors\.$/);
});
