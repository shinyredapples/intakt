import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { showIdleProjectOctopus } = await import(new URL('assets/board-octopus.js', root));

const PHONE = '(max-width: 760px)';
let phone = false;
globalThis.matchMedia = (query) => {
    assert.equal(query, PHONE);

    return { matches: phone };
};

// The octopus as the board renders it beside a busy project: hidden, with the idle projects it stands for.
function hiddenOctopus(idleProjects = '["beta"]') {
    const octopus = new FakeElement();
    octopus.hidden = true;
    octopus.dataset = idleProjects === null ? {} : { idleProjects };

    return octopus;
}

class FakePage {
    listeners = new Map();
    filter = { value: '' };

    constructor(octopus) {
        this.octopus = octopus;
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    getElementById(id) {
        if (id === 'projfilter') {
            return this.filter;
        }
        assert.equal(id, 'board-octopus');

        return this.octopus;
    }

    showProject(project) {
        this.filter.value = project;
        this.listeners.get('intakt:project-shown')();
    }

    // A pushed board replaces the octopus with the hidden one the server renders.
    push(target, octopus) {
        const stream = new FakeElement();
        stream.setAttribute('target', target);
        const event = { target: stream, detail: { render: () => (this.octopus = octopus) } };
        this.listeners.get('turbo:before-stream-render')(event);
        event.detail.render(stream);
    }
}

function boardWith(octopus = hiddenOctopus()) {
    phone = false;
    const page = new FakePage(octopus);
    showIdleProjectOctopus(page);

    return page;
}

test('the asleep octopus stays hidden while the filter shows every project', () => {
    const page = boardWith();

    assert.equal(page.octopus.hidden, true);
});

test('choosing an idle project shows the asleep octopus', () => {
    const page = boardWith();

    page.showProject('beta');

    assert.equal(page.octopus.hidden, false);
});

test('choosing a busy project hides the asleep octopus', () => {
    const page = boardWith();
    page.showProject('beta');

    page.showProject('alpha');

    assert.equal(page.octopus.hidden, true);
});

test('a phone shows every project, so the asleep octopus stays hidden there', () => {
    const page = boardWith();
    phone = true;

    page.showProject('beta');

    assert.equal(page.octopus.hidden, true);
});

test('an octopus without idle projects keeps the visibility the server gave it', () => {
    const page = boardWith(hiddenOctopus(null));
    page.octopus.hidden = false;

    page.showProject('beta');

    assert.equal(page.octopus.hidden, false);
});

test('a pushed octopus gets the chosen idle project back', () => {
    const page = boardWith();
    page.showProject('beta');

    page.push('board-octopus', hiddenOctopus());

    assert.equal(page.octopus.hidden, false);
});

test('a push to another target leaves its render alone', () => {
    const page = boardWith();
    page.showProject('beta');
    const stream = new FakeElement();
    stream.setAttribute('target', 'board-headline');
    const render = () => {};
    const event = { target: stream, detail: { render } };

    page.listeners.get('turbo:before-stream-render')(event);

    assert.equal(event.detail.render, render);
});
