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

// A caption that names the element its stage talks about, on a page that can find that element and holds a window.
function pointingPage({ stage = 'judge-weighs', last = false, follow = false } = {}) {
    const caption = new FakeCaption();
    caption.setAttribute('data-stage', stage);
    caption.setAttribute('data-tour-at', '#judge');
    if (last) {
        caption.setAttribute('data-tour-last', '');
    }
    const link = new FakeElement();
    link.setAttribute('href', '/reviews/r1');
    caption.querySelector = (selector) => (selector === '[data-tour-pause]' ? caption.pause : selector === 'a.tour-review' ? link : null);
    const target = new FakeElement();
    target.scrolls = 0;
    target.scrollIntoView = () => target.scrolls++;
    const page = new FakePage(caption);
    const window = { visited: [], timers: [], location: { assign: (href) => window.visited.push(href) }, setTimeout: (run) => window.timers.push(run), matchMedia: () => ({ matches: true }) };
    page.defaultView = window;
    page.querySelector = (selector) => (selector === '#judge' ? target : selector === '[data-tour-follow]' && follow ? {} : null);
    page.querySelectorAll = () => [];
    const storage = new FakeStorage();
    captionTour(page, storage);

    return { caption, page, target, window, storage };
}

test('the element the stage talks about is marked and scrolled into view once', () => {
    const { page, target } = pointingPage();

    page.listeners['turbo:load']();

    assert.equal(target.getAttribute('data-tour-focus'), 'judge-weighs');
    assert.equal(target.scrolls, 1);
});

test('a page that follows the tour takes the visitor to the review once the run has started', () => {
    const { window, target } = pointingPage({ follow: true });

    assert.deepEqual(window.visited, ['/reviews/r1']);
    assert.equal(target.getAttribute('data-tour-focus'), null);
});

test('the last stage closes the caption after a moment', () => {
    const { page, window, storage } = pointingPage({ last: true, follow: true });

    assert.deepEqual(window.visited, []);
    assert.equal(window.timers.length, 1);
    window.timers[0]();

    assert.equal(page.documentElement.dataset.tour, 'closed');
    assert.equal(storage.items.get('intakt-tour'), 'closed');
});

test('a paused last stage stays open', () => {
    const { caption, page, window } = pointingPage({ last: true });

    page.click(caption.pause);
    window.timers[0]();

    assert.equal(page.documentElement.dataset.tour, undefined);
});
