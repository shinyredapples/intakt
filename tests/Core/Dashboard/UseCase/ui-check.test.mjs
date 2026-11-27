import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { PAGES, jobsFor, measureFloors, report, failed } = createRequire(import.meta.url)('../../../../bin/ui-check');

const floors = { text: 15, target: 44 };

class FakeElement {
    constructor({ tag = 'span', text = '', fontSize = 15, height = 20, width = 100, visibility = 'visible', display = 'block', labels = [], parent = null, value = undefined, attributes = {} } = {}) {
        this.tagName = tag.toUpperCase();
        this.id = '';
        this.classList = [];
        this.childNodes = text === '' ? [] : [{ nodeType: 3, textContent: text }];
        this.textContent = text;
        this.style = { fontSize: fontSize + 'px', visibility, display };
        this.box = { width, height };
        this.labels = labels;
        this.parentElement = parent;
        this.value = value;
        this.attributes = attributes;
    }

    getBoundingClientRect() {
        return this.box;
    }

    getAttribute(name) {
        return this.attributes[name] ?? null;
    }

    // Answers `[name]` and `[name="value"]` selectors over the parent chain as a browser would, so a test fails for what the script measures, not for which DOM call it makes.
    closest(selector) {
        const wanted = [...selector.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)].map(([, name, value]) => ({ name, value }));

        for (let node = this; node; node = node.parentElement) {
            if (wanted.some(({ name, value }) => node.getAttribute(name) !== null && (value === undefined || node.getAttribute(name) === value))) return node;
        }

        return null;
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

// Answers a selector list of `tag`, `[name]`, `[name="value"]` and one `:not(...)` per part, so a test fails when the script's own selector misses an element.
function matches(element, selector) {
    return selector.split(',').some((part) => {
        if (part.trim() === '*') return true;
        const [, tag, attributes, negated] = part.trim().match(/^([a-z]*)((?:\[[\w-]+(?:="[^"]*")?\])*)(?::not\((.*)\))?$/);
        const wanted = [...attributes.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)];

        return (tag === '' || element.tagName === tag.toUpperCase())
            && wanted.every(([, name, value]) => element.getAttribute(name) !== null && (value === undefined || element.getAttribute(name) === value))
            && (negated === undefined || !matches(element, negated));
    });
}

function pageSelecting(elements) {
    return { ...pageOf([]), document: { body: { querySelectorAll: (selector) => elements.filter((element) => matches(element, selector)) } } };
}

function run(overrides) {
    return { page: 'projects', theme: 'light', width: 390, errors: [], violations: [], floors: [], ...overrides };
}

test('the run for the board at / is named board', () => {
    assert.deepEqual(
        PAGES.filter((page) => page.path === '/').map((page) => page.name),
        ['board'],
    );
});

test('the first-run page at /setup is checked', () => {
    assert.ok(PAGES.some((page) => page.name === 'setup' && page.path === '/setup'));
});

test('every step of the setup wizard and of Add project is checked', () => {
    for (const step of [2, 3, 4, 5]) assert.ok(PAGES.some((page) => page.name === `setup-${step}` && page.path === `/setup?step=${step}`));
    for (const step of [1, 2, 3, 4, 5]) assert.ok(PAGES.some((page) => page.name === `add-project-${step}` && page.path === `/projects/new?step=${step}`));
});

test('the Status page at /lanes is checked', () => {
    assert.ok(PAGES.some((page) => page.name === 'status' && page.path === '/lanes'));
});

test('the History page at /history is checked', () => {
    assert.ok(PAGES.some((page) => page.name === 'history' && page.path === '/history'));
});

test('the Waiting on you page at /waiting is checked', () => {
    assert.ok(PAGES.some((page) => page.name === 'waiting' && page.path === '/waiting'));
});

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
        new FakeElement({ text: 'behind the search', fontSize: 12, parent: new FakeElement({ parent: new FakeElement({ attributes: { inert: '' } }) }) }),
    ];

    assert.deepEqual(measureFloors(floors, pageOf(hidden)), []);
});

test('text under an aria-hidden ancestor stays on screen, so it is measured', () => {
    const count = new FakeElement({ text: '3', fontSize: 12, parent: new FakeElement({ attributes: { 'aria-hidden': 'true' } }) });

    assert.deepEqual(measureFloors(floors, pageOf([count])), ['text 12 px: span "3"']);
});

test('a visible button marked aria-hidden is measured for its text and its height', () => {
    const button = new FakeElement({ tag: 'button', text: 'Go', fontSize: 13, height: 30, attributes: { 'aria-hidden': 'true' } });

    assert.deepEqual(measureFloors(floors, pageOf([button], [button])), ['text 13 px: button "Go"', 'target 30 px: button "Go"']);
});

test('an element without text of its own is no text', () => {
    assert.deepEqual(measureFloors(floors, pageOf([new FakeElement({ fontSize: 12 })])), []);
});

test('the value shown in a field under 15 px is reported, though a field has no text nodes', () => {
    const field = new FakeElement({ tag: 'input', value: 'Visible field text', fontSize: 12 });

    assert.deepEqual(measureFloors(floors, pageOf([field])), ['text 12 px: input "Visible field text"']);
});

test('a field value of exactly 15 px passes', () => {
    assert.deepEqual(measureFloors(floors, pageOf([new FakeElement({ tag: 'textarea', value: 'Notes', fontSize: 15 })])), []);
});

test('a placeholder under 15 px in an empty field is reported', () => {
    const field = new FakeElement({ tag: 'input', value: '', fontSize: 13, attributes: { placeholder: 'Search' } });

    assert.deepEqual(measureFloors(floors, pageOf([field])), ['text 13 px: input "Search"']);
});

test('the chosen option of a select under 15 px is reported', () => {
    const select = new FakeElement({ tag: 'select', value: 'open', fontSize: 14 });

    assert.deepEqual(measureFloors(floors, pageOf([select])), ['text 14 px: select "open"']);
});

test('an empty field without a placeholder is no text', () => {
    assert.deepEqual(measureFloors(floors, pageOf([new FakeElement({ tag: 'input', value: '', fontSize: 12 })])), []);
});

test('the value of a checkbox or radio button is not shown, so it is no text', () => {
    const boxes = [
        new FakeElement({ tag: 'input', value: 'on', fontSize: 12, attributes: { type: 'checkbox' } }),
        new FakeElement({ tag: 'input', value: 'dark', fontSize: 12, attributes: { type: 'Radio' } }),
    ];

    assert.deepEqual(measureFloors(floors, pageOf(boxes)), []);
});

test('a touch target under 44 px tall is reported', () => {
    const button = new FakeElement({ tag: 'button', text: 'Stop', height: 40 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), ['target 40 px: button "Stop"']);
});

test('a touch target of exactly 44 px passes', () => {
    const button = new FakeElement({ tag: 'button', text: 'Stop', height: 44 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), []);
});

test('a touch target a fraction under 44 px is reported, not rounded up to pass', () => {
    const button = new FakeElement({ tag: 'button', text: 'Go', height: 43.6 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), ['target 43.6 px: button "Go"']);
});

test('a touch target just under 44 px is never shown as 44 px', () => {
    const button = new FakeElement({ tag: 'button', text: 'Go', height: 43.999 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), ['target 43.99 px: button "Go"']);
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

test('a link inside a sentence is part of the text, not a touch target', () => {
    const sentence = new FakeElement({ tag: 'p', text: 'Each project has a ' });
    const term = new FakeElement({ text: 'weekly share', height: 20, display: 'inline', parent: sentence });

    assert.deepEqual(measureFloors(floors, pageOf([], [term])), []);
});

test('an inline link with no sentence around it is a touch target', () => {
    const cell = new FakeElement({ tag: 'td' });
    const link = new FakeElement({ tag: 'a', text: 'acme-shop', height: 19, display: 'inline', parent: cell });

    assert.deepEqual(measureFloors(floors, pageOf([], [link])), ['target 19 px: a "acme-shop"']);
});

test('a block link beside text is a touch target', () => {
    const row = new FakeElement({ tag: 'div', text: 'Round 2 ' });
    const link = new FakeElement({ tag: 'a', text: 'Open', height: 30, display: 'inline-flex', parent: row });

    assert.deepEqual(measureFloors(floors, pageOf([], [link])), ['target 30 px: a "Open"']);
});

test('a search result row is a touch target though it is out of the tab order', () => {
    const row = new FakeElement({ tag: 'div', height: 30, attributes: { role: 'option', tabindex: '-1' } });

    assert.deepEqual(measureFloors(floors, pageSelecting([row])), ['target 30 px: div']);
});

test('an element taken out of the tab order without a role is no touch target', () => {
    const panel = new FakeElement({ tag: 'div', height: 30, attributes: { tabindex: '-1' } });

    assert.deepEqual(measureFloors(floors, pageSelecting([panel])), []);
});

test('an element in the tab order is a touch target', () => {
    const region = new FakeElement({ tag: 'div', height: 30, attributes: { tabindex: '0' } });

    assert.deepEqual(measureFloors(floors, pageSelecting([region])), ['target 30 px: div']);
});

test('a hidden touch target is not measured', () => {
    const button = new FakeElement({ tag: 'button', text: 'Cancel', height: 0, width: 0 });

    assert.deepEqual(measureFloors(floors, pageOf([], [button])), []);
});

test('serious and critical axe violations fail the check', () => {
    for (const impact of ['serious', 'critical']) {
        assert.equal(failed([run({ violations: [{ id: 'color-contrast', impact, help: 'Contrast', nodes: ['.meta'] }] })]), true);
    }
});

test('minor and moderate axe violations are reported without failing the check', () => {
    const runs = [run({ violations: [{ id: 'region', impact: 'moderate', help: 'Landmarks', nodes: ['.a', '.b'] }, { id: 'x', impact: 'minor', help: 'X', nodes: ['.c'] }] })];

    assert.equal(failed(runs), false);
    assert.match(report(runs), /projects light 390: axe critical 0, serious 0, moderate 1, minor 1, phone floor findings 0\n {2}moderate region: Landmarks \(2 nodes: \.a, \.b\)/);
});

test('a violation names its first three nodes', () => {
    const runs = [run({ violations: [{ id: 'list', impact: 'serious', help: 'Lists', nodes: ['li.a', 'li.b', 'li.c', 'li.d'] }] })];

    assert.match(report(runs), /serious list: Lists \(4 nodes: li\.a, li\.b, li\.c, \.\.\.\)/);
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
