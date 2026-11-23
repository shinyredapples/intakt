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
        if (selector === 'a.tour-review') {
            return this.review ?? null;
        }

        return selector === '[data-tour-pause]' ? this.pause : null;
    }
}

class FakePage {
    listeners = {};
    documentElement = { dataset: {} };
    sign = null;
    assigned = [];
    defaultView = { location: { assign: (address) => this.assigned.push(address) } };

    constructor(caption) {
        this.caption = caption;
    }

    querySelector(selector) {
        return selector === '[data-tour-follow]' ? this.sign : null;
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
    constructor(items = {}) {
        this.items = new Map(Object.entries(items));
    }

    getItem(key) {
        return this.items.get(key) ?? null;
    }

    setItem(key, value) {
        this.items.set(key, value);
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

class FakeMark extends FakeElement {
    scrolled = 0;

    scrollIntoView() {
        this.scrolled += 1;
    }
}

// A page that holds elements by id, answering the one selector shape the caption template writes: [id="..."].
class FakeReviewPage extends FakePage {
    constructor(caption, ids) {
        super(caption);
        this.marks = new Map(ids.map((id) => [id, new FakeMark()]));
    }

    querySelector(selector) {
        const id = /^\[id="(.*)"\]$/.exec(selector)?.[1];

        return (id && this.marks.get(id)) || super.querySelector(selector);
    }

    querySelectorAll() {
        return [...this.marks.values()].filter((mark) => mark.hasAttribute('data-tour-focus'));
    }
}

function captionAt(selector, stage = 'reviewers-read') {
    const caption = new FakeCaption();
    caption.setAttribute('data-tour-at', selector);
    caption.setAttribute('data-stage', stage);

    return caption;
}

test('a caption marks the reviewers list of its own round, not another review\'s', () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-a-now-reviewers', 'round-b-now-reviewers']);

    captionTour(page, new FakeStorage());

    assert.equal(page.marks.get('round-a-now-reviewers').getAttribute('data-tour-focus'), 'reviewers-read');
    assert.equal(page.marks.get('round-b-now-reviewers').hasAttribute('data-tour-focus'), false);
});

test('a caption for round A marks nothing on a page for round B', () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-b-now-reviewers', 'round-b-judged']);

    captionTour(page, new FakeStorage());

    assert.equal(page.querySelectorAll().length, 0);
});

test('a new stage moves the mark to the element it talks about and scrolls it into view once', async () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-a-now-reviewers', 'round-a-judged']);
    captionTour(page, new FakeStorage());

    page.caption = captionAt('[id="round-a-judged"]', 'round-ends');
    page.stream('tour-caption');
    await Promise.resolve();
    await Promise.resolve();
    page.listeners['turbo:load']();

    assert.equal(page.marks.get('round-a-now-reviewers').hasAttribute('data-tour-focus'), false);
    assert.equal(page.marks.get('round-a-judged').getAttribute('data-tour-focus'), 'round-ends');
    assert.equal(page.marks.get('round-a-judged').scrolled, 1);
});

test('a selector the page cannot parse marks nothing and breaks nothing', () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-a-now-reviewers']);
    page.querySelector = (selector) => {
        if (selector.startsWith('[id=')) {
            throw new SyntaxError('not a selector');
        }

        return null;
    };

    captionTour(page, new FakeStorage());

    assert.equal(page.querySelectorAll().length, 0);
});

test('a resume moves the mark to the stage that arrived while paused', async () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-a-now-reviewers', 'round-a-judged']);
    const first = page.caption;
    captionTour(page, new FakeStorage());
    page.click(first.pause);
    page.stream('tour-caption');
    page.caption = captionAt('[id="round-a-judged"]', 'round-ends');
    page.caption.pause = first.pause;

    page.click(first.pause);
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(page.marks.get('round-a-judged').getAttribute('data-tour-focus'), 'round-ends');
});

test('close takes the mark off, and a later update marks nothing', async () => {
    const page = new FakeReviewPage(captionAt('[id="round-a-now-reviewers"]'), ['round-a-now-reviewers']);
    page.documentElement = { dataset: {} };
    captionTour(page, new FakeStorage());

    page.click({ closest: (selector) => (selector === '[data-tour-close]' ? {} : null) });
    page.listeners['turbo:load']();

    assert.equal(page.marks.get('round-a-now-reviewers').hasAttribute('data-tour-focus'), false);
});

class FakeSign extends FakeElement {
    isConnected = true;

    constructor() {
        super();
        this.setAttribute('data-tour-follow', '');
    }
}

function stageOf(round, stage = 'change-arrives', last = false) {
    const caption = new FakeCaption();
    caption.setAttribute('data-round', round);
    caption.setAttribute('data-stage', stage);
    if (last) {
        caption.setAttribute('data-tour-last', '');
    }
    caption.review = new FakeElement();
    caption.review.setAttribute('href', `/reviews/${round}`);

    return caption;
}

function lastStageOf(round) {
    return stageOf(round, 'round-ends', true);
}

// The page Play's redirect reaches: an empty caption until the first stage arrives, and the start line that carries the sign.
function thePagePlayReached(storage = new FakeStorage()) {
    const page = new FakePage(new FakeCaption());
    page.sign = new FakeSign();
    captionTour(page, storage);

    return page;
}

async function aStageArrives(page, caption) {
    page.caption = caption;
    page.stream('tour-caption');
    await Promise.resolve();
    await Promise.resolve();
}

function visit(page, caption) {
    if (page.sign) {
        page.sign.isConnected = false;
    }
    page.sign = null;
    page.caption = caption;
    page.listeners['turbo:load']();
}

test('the page Play reached follows the first stage to its review', async () => {
    const page = thePagePlayReached();
    const sign = page.sign;

    await aStageArrives(page, stageOf('demo-round-1'));

    assert.deepEqual(page.assigned, ['/reviews/demo-round-1']);
    assert.equal(sign.hasAttribute('data-tour-follow'), false);
});

test('a later visit to the demo page does not follow', async () => {
    const page = thePagePlayReached();

    visit(page, stageOf('demo-round-1'));
    await aStageArrives(page, stageOf('demo-round-1', 'reviewers-read'));

    assert.deepEqual(page.assigned, []);
});

test('a demo page loaded without the start line does not follow', () => {
    const page = new FakePage(stageOf('demo-round-1'));

    captionTour(page, new FakeStorage());

    assert.deepEqual(page.assigned, []);
});

test('a closed tour does not follow', async () => {
    const page = new FakePage(new FakeCaption());
    page.sign = new FakeSign();
    page.documentElement.dataset.tour = 'closed';
    captionTour(page, new FakeStorage({ 'intakt-tour': 'closed', 'intakt-tour-round': 'demo-round-1' }));

    await aStageArrives(page, stageOf('demo-round-1'));

    assert.deepEqual(page.assigned, []);
});

test('a paused tour does not follow', async () => {
    const page = thePagePlayReached();
    const paused = stageOf('demo-round-1');
    paused.pause.setAttribute('aria-pressed', 'true');
    page.caption = paused;

    page.stream('projfilter');
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(page.assigned, []);
});

test('the page Play reached does not follow the last stage', async () => {
    const page = thePagePlayReached();

    await aStageArrives(page, lastStageOf('demo-round-1'));

    assert.deepEqual(page.assigned, []);
});

test('a closed tour draws no mark, scrolls nothing and follows nowhere', async () => {
    const page = new FakeReviewPage(stageOf('a', 'reviewers-read'), ['round-a-now-reviewers', 'round-a-judged']);
    page.caption.setAttribute('data-tour-at', '[id="round-a-now-reviewers"]');
    captionTour(page, new FakeStorage());

    page.click(page.caption.close);
    page.sign = new FakeSign();
    const next = stageOf('a', 'judge-weighs');
    next.setAttribute('data-tour-at', '[id="round-a-judged"]');
    page.caption = next;
    page.listeners['turbo:load']();

    assert.equal(page.querySelectorAll().length, 0);
    assert.equal(page.marks.get('round-a-judged').scrolled, 0);
    assert.deepEqual(page.assigned, []);
});

test('the last stage closes the caption 8 s after it shows', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const storage = new FakeStorage();
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, storage);

    t.mock.timers.tick(7999);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(1);

    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour-round'), 'demo-round-1');
});

test('the last stage\'s timer leaves open the caption of a round that started within 8 s', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, new FakeStorage());
    t.mock.timers.tick(3000);

    await aStageArrives(page, stageOf('demo-round-2'));
    t.mock.timers.tick(5000);

    assert.equal(page.documentElement.dataset.tour, undefined);
});

test('a round that starts after the automatic close shows its caption again', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const storage = new FakeStorage();
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, storage);
    t.mock.timers.tick(8000);

    await aStageArrives(page, stageOf('demo-round-2'));

    assert.equal(page.documentElement.dataset.tour, undefined);
    assert.equal(storage.items.size, 0);
});

test('a reload keeps the tour closed for the round it was closed in', () => {
    const page = new FakePage(stageOf('demo-round-1', 'judge-weighs'));
    page.documentElement.dataset.tour = 'closed';

    captionTour(page, new FakeStorage({ 'intakt-tour': 'closed', 'intakt-tour-round': 'demo-round-1' }));

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a reload shows the caption of a round that started after the close', () => {
    const page = new FakePage(stageOf('demo-round-2'));
    page.documentElement.dataset.tour = 'closed';

    captionTour(page, new FakeStorage({ 'intakt-tour': 'closed', 'intakt-tour-round': 'demo-round-1' }));

    assert.equal(page.documentElement.dataset.tour, undefined);
});

test('a resume after the timer fired while paused closes the caption after a fresh 8 s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, new FakeStorage());
    page.click(page.caption.pause);
    t.mock.timers.tick(30000);
    assert.equal(page.documentElement.dataset.tour, undefined);

    page.click(page.caption.pause);
    t.mock.timers.tick(7999);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(1);

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a copy Back restores after the timer fired elsewhere closes after its own 8 s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, new FakeStorage());
    visit(page, null);
    t.mock.timers.tick(8000);

    const restored = lastStageOf('demo-round-1');
    restored.setAttribute('data-closing', '');
    visit(page, restored);
    t.mock.timers.tick(7999);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(1);

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a timer armed before the page was left leaves a copy Back restored open for its own 8 s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, new FakeStorage());
    t.mock.timers.tick(3000);
    visit(page, null);
    t.mock.timers.tick(2000);

    visit(page, lastStageOf('demo-round-1'));
    t.mock.timers.tick(3000);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(4999);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(1);

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a resume armed before the page was left leaves a copy Back restored open for its own 8 s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const page = new FakePage(lastStageOf('demo-round-1'));
    captionTour(page, new FakeStorage());
    page.click(page.caption.pause);
    t.mock.timers.tick(8000);
    page.click(page.caption.pause);
    t.mock.timers.tick(1000);
    visit(page, null);
    t.mock.timers.tick(1000);

    visit(page, lastStageOf('demo-round-1'));
    t.mock.timers.tick(6000);
    assert.equal(page.documentElement.dataset.tour, undefined);
    t.mock.timers.tick(2000);

    assert.equal(page.documentElement.dataset.tour, 'closed');
});

test('a copy of an older round Back restores keeps the newer round closed', async () => {
    const storage = new FakeStorage();
    const page = new FakePage(stageOf('demo-round-1'));
    captionTour(page, storage);
    await aStageArrives(page, stageOf('demo-round-2'));
    page.click(page.caption.close);

    visit(page, stageOf('demo-round-1'));
    assert.equal(page.documentElement.dataset.tour, 'closed');
    visit(page, stageOf('demo-round-2'));

    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour-round'), 'demo-round-2');
});

test('a tab closed before the round was kept stays closed for the round it shows and opens for a later one', async () => {
    const storage = new FakeStorage({ 'intakt-tour': 'closed' });
    const page = new FakePage(stageOf('demo-round-1'));
    page.documentElement.dataset.tour = 'closed';
    captionTour(page, storage);
    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour-round'), 'demo-round-1');

    await aStageArrives(page, stageOf('demo-round-2'));

    assert.equal(page.documentElement.dataset.tour, undefined);
});
