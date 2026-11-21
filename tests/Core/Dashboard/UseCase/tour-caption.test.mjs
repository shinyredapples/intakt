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

        return (id && this.marks.get(id)) || null;
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
    page.querySelector = () => { throw new SyntaxError('not a selector'); };

    captionTour(page, new FakeStorage());

    assert.equal(page.querySelectorAll().length, 0);
});
