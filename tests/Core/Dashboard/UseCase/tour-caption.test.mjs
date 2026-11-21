import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { captionTour } = await import(new URL('assets/tour-caption.js', root));

class FakeButton extends FakeElement {
    constructor(selector, text) {
        super(text);
        this.selector = selector;
    }

    closest(selector) {
        return selector.split(', ').includes(this.selector) ? this : null;
    }
}

class FakeCaption extends FakeElement {
    constructor() {
        super();
        this.pause = new FakeButton('[data-tour-pause]', 'Pause');
        this.pause.setAttribute('aria-pressed', 'false');
        this.close = new FakeButton('[data-tour-close]', 'Close');
    }

    querySelector(selector) {
        return selector === '[data-tour-pause]' ? this.pause : null;
    }
}

class FakePage {
    listeners = {};
    documentElement = { dataset: {} };

    constructor(caption) {
        this.caption = caption;
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    getElementById(id) {
        return id === 'tour-caption' ? this.caption : null;
    }

    click(target) {
        this.listeners.click({ target });
    }

    // Shaped as Turbo's before-stream-render event: the stream element is the target, and render draws it unless the default is prevented.
    stream(target) {
        const rendered = [];
        const stream = { getAttribute: (name) => (name === 'target' ? target : null) };
        const event = {
            target: stream,
            defaultPrevented: false,
            detail: { render: (element) => rendered.push(element) },
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        this.listeners['turbo:before-stream-render'](event);
        if (!event.defaultPrevented) {
            event.detail.render(stream);
        }

        return { stream, rendered };
    }
}

class FakeStorage {
    items = new Map();

    setItem(key, value) {
        this.items.set(key, value);
    }

    getItem(key) {
        return this.items.get(key) ?? null;
    }

    removeItem(key) {
        this.items.delete(key);
    }
}

function captionOnAPage() {
    const caption = new FakeCaption();
    const page = new FakePage(caption);
    const storage = new FakeStorage();
    captionTour(page, storage);

    return { caption, page, storage };
}

test('a new stage replaces the caption while it plays', () => {
    const { page } = captionOnAPage();

    const { stream, rendered } = page.stream('tour-caption');

    assert.deepEqual(rendered, [stream]);
});

test('pause holds a new stage back and says it is pressed', () => {
    const { caption, page } = captionOnAPage();

    page.click(caption.pause);
    const { rendered } = page.stream('tour-caption');

    assert.deepEqual(rendered, []);
    assert.equal(caption.pause.getAttribute('aria-pressed'), 'true');
    assert.equal(caption.pause.textContent, 'Resume');
});

test('resume shows the newest stage that arrived while paused', () => {
    const { caption, page } = captionOnAPage();
    page.click(caption.pause);
    page.stream('tour-caption');
    const newest = page.stream('tour-caption');

    page.click(caption.pause);

    assert.deepEqual(newest.rendered, [newest.stream]);
    assert.equal(caption.pause.getAttribute('aria-pressed'), 'false');
    assert.equal(caption.pause.textContent, 'Pause');
});

test('after a resume a new stage replaces the caption again', () => {
    const { caption, page } = captionOnAPage();
    page.click(caption.pause);

    page.click(caption.pause);
    const { stream, rendered } = page.stream('tour-caption');

    assert.deepEqual(rendered, [stream]);
});

test('a new pause forgets a stage held back by an earlier page', () => {
    const { caption, page } = captionOnAPage();
    page.click(caption.pause);
    const older = page.stream('tour-caption');
    page.caption = new FakeCaption();

    page.click(page.caption.pause);
    page.click(page.caption.pause);

    assert.deepEqual(older.rendered, []);
});

test('a paused caption holds back no update of another target', () => {
    const { caption, page } = captionOnAPage();
    page.click(caption.pause);

    const { stream, rendered } = page.stream('projfilter');

    assert.deepEqual(rendered, [stream]);
});

test('close hides the caption for the tab and keeps that for its next pages', () => {
    const { caption, page, storage } = captionOnAPage();

    page.click(caption.close);

    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour'), 'closed');
});

test('a closed caption still closes when the storage refuses the key', () => {
    const caption = new FakeCaption();
    const page = new FakePage(caption);
    captionTour(page, { setItem: () => { throw new Error('storage is full'); } });

    page.click(caption.close);

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a click outside the caption buttons changes nothing', () => {
    const { caption, page, storage } = captionOnAPage();

    page.click({ closest: () => null });
    page.click(null);

    assert.equal(caption.pause.getAttribute('aria-pressed'), 'false');
    assert.equal(page.documentElement.dataset.tour, undefined);
    assert.equal(storage.items.size, 0);
});

test('a page without a caption lets every update through', () => {
    const page = new FakePage(null);
    captionTour(page, new FakeStorage());

    const { stream, rendered } = page.stream('tour-caption');

    assert.deepEqual(rendered, [stream]);
});

// A caption for one round and stage, naming the element its stage talks about by that round's id, as the template builds it.
function stageCaption({ round = 'r1', stage = 'judge-weighs', last = false } = {}) {
    const caption = new FakeCaption();
    caption.setAttribute('data-stage', stage);
    caption.setAttribute('data-round', round);
    caption.setAttribute('data-tour-at', `[id="round-${round}-now-judge"]`);
    if (last) {
        caption.setAttribute('data-tour-last', '');
    }
    const link = new FakeElement();
    link.setAttribute('href', `/reviews/${round}`);
    caption.querySelector = (selector) => (selector === '[data-tour-pause]' ? caption.pause : selector === 'a.tour-review' ? link : null);

    return caption;
}

// A page that shows the given rounds' elements, holds a window with timers the test fires itself, and may be the page a started run's redirect reached (its start line).
function pointingPage({ caption = stageCaption(), rounds = ['r1'], follow = false, played = false, storage = new FakeStorage() } = {}) {
    const targets = new Map(rounds.map((round) => {
        const target = new FakeElement();
        target.scrolls = 0;
        target.scrollIntoView = () => target.scrolls++;
        return [`[id="round-${round}-now-judge"]`, target];
    }));
    const page = new FakePage(caption);
    page.body = {};
    const window = { visited: [], timers: [], location: { assign: (href) => window.visited.push(href) }, setTimeout: (run, ms) => window.timers.push({ run, ms }), matchMedia: () => ({ matches: true }) };
    page.defaultView = window;
    page.started = played;
    page.querySelector = (selector) => targets.get(selector) ?? ((selector === '[data-tour-follow]' && follow) || (selector === 'main .flash-success' && page.started) ? {} : null);
    page.querySelectorAll = (selector) => (selector === '[data-tour-focus]' ? [...targets.values()].filter((target) => target.getAttribute('data-tour-focus') !== null) : []);
    captionTour(page, storage);

    return { caption, page, target: targets.get('[id="round-r1-now-judge"]'), window, storage };
}

// Shaped as a live update that replaces the caption: the new caption is in place once render runs, and the mark is drawn after it.
async function replaceCaption(page, caption) {
    const event = {
        target: { getAttribute: (name) => (name === 'target' ? 'tour-caption' : null) },
        detail: { render: () => { page.caption = caption; } },
        preventDefault() {},
    };
    page.listeners['turbo:before-stream-render'](event);
    await event.detail.render(event.target);
}

test('the element the stage talks about is marked and scrolled into view once', () => {
    const { page, target } = pointingPage();

    page.listeners['turbo:load']();

    assert.equal(target.getAttribute('data-tour-focus'), 'judge-weighs');
    assert.equal(target.scrolls, 1);
});

test('the page Play reached takes the visitor to the review once the run has started', () => {
    const { window, target, storage } = pointingPage({ follow: true, played: true });

    assert.deepEqual(window.visited, ['/reviews/r1']);
    assert.equal(target.getAttribute('data-tour-focus'), null);
    assert.equal(storage.items.size, 0);
});

test('a refused Play does not follow a replay another tab started', async () => {
    const { page, window } = pointingPage({ follow: true, caption: new FakeCaption() });

    await replaceCaption(page, stageCaption({ stage: 'change-arrives' }));

    assert.deepEqual(window.visited, []);
});

test('a Play that failed leaves nothing for a later visit to follow on', () => {
    const storage = new FakeStorage();
    pointingPage({ caption: new FakeCaption(), storage });

    const { window } = pointingPage({ follow: true, storage });

    assert.deepEqual(window.visited, []);
    assert.equal(storage.items.size, 0);
});

test('a plain later visit to the page that offers the tour does not follow it', () => {
    const { window, target } = pointingPage({ follow: true });

    assert.deepEqual(window.visited, []);
    assert.equal(target.getAttribute('data-tour-focus'), 'judge-weighs');
});

test('a later Turbo visit after the page Play reached does not follow the tour', async () => {
    const { page, window } = pointingPage({ follow: true, played: true, caption: new FakeCaption() });
    page.body = {};
    page.started = false;
    page.listeners['turbo:load']();

    await replaceCaption(page, stageCaption());

    assert.deepEqual(window.visited, []);
});

test('a closed tour does not follow from the page Play reached', async () => {
    const { page, window } = pointingPage({ follow: true, played: true, caption: new FakeCaption() });
    page.click(page.caption.close);

    await replaceCaption(page, stageCaption());

    assert.deepEqual(window.visited, []);
});

test('a paused tour does not follow from the page Play reached', async () => {
    const caption = stageCaption({ stage: 'change-arrives' });
    const { page, window } = pointingPage({ follow: true, played: true, caption: new FakeCaption() });
    page.caption = caption;
    page.click(caption.pause);

    page.stream('projfilter');
    await Promise.resolve();

    assert.deepEqual(window.visited, []);
});

test('a closed tour draws no ring, does not scroll and removes the mark it drew', () => {
    const { caption, page, target } = pointingPage();

    page.click(caption.close);
    page.listeners['turbo:load']();

    assert.equal(target.getAttribute('data-tour-focus'), null);
    assert.equal(target.scrolls, 1);
});

test('a caption for one round marks nothing on a page that shows another round', () => {
    const { page } = pointingPage({ caption: stageCaption({ round: 'a' }), rounds: ['b'] });
    const other = page.querySelector('[id="round-b-now-judge"]');

    page.listeners['turbo:load']();

    assert.equal(other.getAttribute('data-tour-focus'), null);
    assert.equal(other.scrolls, 0);
});

test('the last stage closes the caption after a moment', () => {
    const { page, window, storage } = pointingPage({ caption: stageCaption({ last: true }), follow: true, played: true });

    assert.deepEqual(window.visited, []);
    assert.deepEqual(window.timers.map((timer) => timer.ms), [8000]);
    window.timers[0].run();

    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour'), 'closed');
});

test('a paused last stage stays open when its moment passes', () => {
    const { caption, page, window } = pointingPage({ caption: stageCaption({ last: true }) });

    page.click(caption.pause);
    window.timers[0].run();

    assert.equal(page.documentElement.dataset.tour, undefined);
});

test('resume after the moment passed waits a fresh moment and then closes', async () => {
    const { caption, page, window } = pointingPage({ caption: stageCaption({ last: true }) });
    page.click(caption.pause);
    window.timers[0].run();

    page.click(caption.pause);
    await Promise.resolve();

    assert.deepEqual(window.timers.map((timer) => timer.ms), [8000, 8000]);
    window.timers[1].run();
    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('the close of a last stage leaves the caption a new round started within the moment', async () => {
    const { page, window } = pointingPage({ caption: stageCaption({ round: 'r1', stage: 'round-ends', last: true }), rounds: ['r1', 'r2'] });

    await replaceCaption(page, stageCaption({ round: 'r2', stage: 'change-arrives' }));
    window.timers[0].run();

    assert.equal(page.documentElement.dataset.tour, undefined);
});

test('the close of a last stage leaves a replacement caption of the same stage its own full moment', async () => {
    const { page, window } = pointingPage({ caption: stageCaption({ stage: 'round-ends', last: true }) });

    await replaceCaption(page, stageCaption({ stage: 'round-ends', last: true }));
    window.timers[0].run();

    assert.equal(page.documentElement.dataset.tour, undefined);
    assert.deepEqual(window.timers.map((timer) => timer.ms), [8000, 8000]);
    window.timers[1].run();
    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a round that starts after the close shows its caption again', async () => {
    const { page, window, storage } = pointingPage({ caption: stageCaption({ round: 'r1', stage: 'round-ends', last: true }), rounds: ['r1', 'r2'] });
    window.timers[0].run();

    await replaceCaption(page, stageCaption({ round: 'r2', stage: 'change-arrives' }));

    assert.equal(page.documentElement.dataset.tour, undefined);
    assert.equal(storage.getItem('intakt-tour'), null);
    assert.equal(page.querySelector('[id="round-r2-now-judge"]').getAttribute('data-tour-focus'), 'change-arrives');
});

test('a page first showing a later round past its first stage after the close shows that caption again', async () => {
    const storage = new FakeStorage();
    const ended = pointingPage({ caption: stageCaption({ round: 'r1', stage: 'round-ends', last: true }), storage });
    ended.window.timers[0].run();

    const { page } = pointingPage({ caption: stageCaption({ round: 'r2', stage: 'reviewers-read' }), rounds: ['r2'], storage });
    page.documentElement.dataset.tour = 'closed';
    page.listeners['turbo:load']();

    assert.equal(page.documentElement.dataset.tour, undefined);
    assert.equal(page.querySelector('[id="round-r2-now-judge"]').getAttribute('data-tour-focus'), 'reviewers-read');
});

test('a page of the ended round stays closed after the close', () => {
    const storage = new FakeStorage();
    const ended = pointingPage({ caption: stageCaption({ round: 'r1', stage: 'round-ends', last: true }), storage });
    ended.window.timers[0].run();

    const { page } = pointingPage({ caption: stageCaption({ round: 'r1', stage: 'judge-weighs' }), storage });
    page.documentElement.dataset.tour = 'closed';
    page.listeners['turbo:load']();

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a round that starts after the visitor closed the tour stays closed', async () => {
    const { caption, page } = pointingPage({ rounds: ['r1', 'r2'] });
    page.click(caption.close);

    await replaceCaption(page, stageCaption({ round: 'r2', stage: 'change-arrives' }));

    assert.equal(page.documentElement.dataset.tour, 'closed');
});
