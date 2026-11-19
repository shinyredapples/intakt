import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeElement, root } from './stimulus-controller.mjs';

const { copyCommands } = await import(new URL('assets/copy-command.js', root));

const COMMAND = 'intakt account login home';

class FakeCopyButton extends FakeElement {
    constructor() {
        super('Copy');
        this.dataset = { copy: COMMAND };
        this.setAttribute('data-copy', COMMAND);
    }

    closest(selector) {
        return selector === 'button[data-copy]' ? this : null;
    }
}

class FakeSummary extends FakeElement {
    focused = false;

    closest() {
        return null;
    }

    focus() {
        this.focused = true;
    }
}

class FakeWhy extends FakeElement {
    constructor(open) {
        super();
        this.open = open;
        this.summary = new FakeSummary('Why');
    }

    querySelector(selector) {
        return selector === 'summary' ? this.summary : null;
    }
}

class FakeInsideWhy extends FakeElement {
    constructor(why) {
        super();
        this.why = why;
    }

    closest(selector) {
        return selector === 'details.why' ? this.why : null;
    }
}

class FakePage {
    listeners = new Map();
    buttons = [];
    whys = [];

    addEventListener(type, listener) {
        assert.equal(this.listeners.has(type), false);
        this.listeners.set(type, listener);
    }

    querySelectorAll(selector) {
        if (selector === 'button[data-copy]') {
            return this.buttons;
        }
        if (selector === 'details.why') {
            return this.whys;
        }
        assert.equal(selector, 'details.why[open]');

        return this.whys.filter((why) => why.open);
    }

    dispatch(type, event = {}) {
        this.listeners.get(type)(event);
    }
}

class FakeTimers {
    pending = new Map();
    next = 1;
    now = 0;

    setTimeout(callback, delay) {
        assert.equal(delay, 2000);
        const id = this.next++;
        this.pending.set(id, { callback, at: this.now + delay });

        return id;
    }

    clearTimeout(id) {
        this.pending.delete(id);
    }

    runPending() {
        const due = [...this.pending.values()];
        this.pending.clear();
        due.forEach(({ callback }) => callback());
    }

    advance(ms) {
        this.now += ms;
        [...this.pending].filter(([, timer]) => timer.at <= this.now).forEach(([id, { callback }]) => {
            this.pending.delete(id);
            callback();
        });
    }
}

class FakeClipboard {
    written = [];

    constructor(refuses = false) {
        this.refuses = refuses;
    }

    writeText(text) {
        if (this.refuses) {
            return Promise.reject(new Error('Write permission denied.'));
        }
        this.written.push(text);

        return Promise.resolve();
    }
}

// Answers each write only when the test says so, to settle writes in any order.
class FakeSlowClipboard {
    writes = [];

    writeText() {
        return new Promise((resolve, reject) => this.writes.push({ resolve, reject }));
    }
}

function pageWithACommand({ clipboard = new FakeClipboard(), phone = false, whys = [] } = {}) {
    const page = new FakePage();
    page.whys.push(...whys);
    const timers = new FakeTimers();
    const view = {
        navigator: clipboard === null ? {} : { clipboard },
        setTimeout: (callback, delay) => timers.setTimeout(callback, delay),
        clearTimeout: (id) => timers.clearTimeout(id),
        matchMedia: (query) => {
            assert.equal(query, '(max-width: 760px)');

            return { matches: phone };
        },
    };
    copyCommands(page, view);
    const button = new FakeCopyButton();
    page.buttons.push(button);

    return { page, timers, clipboard, button };
}

// The clipboard answers with a promise, so its outcome shows only once the pending reactions ran.
function clipboardAnswered() {
    return new Promise((resolve) => setImmediate(resolve));
}

test('a click on Copy writes the command to the clipboard and says it was copied', async () => {
    const { page, clipboard, button } = pageWithACommand();

    page.dispatch('click', { target: button });
    await clipboardAnswered();

    assert.deepEqual(clipboard.written, [COMMAND]);
    assert.equal(button.textContent, 'Copied');
});

test('the label goes back to Copy once two seconds passed', async () => {
    const { page, timers, button } = pageWithACommand();
    page.dispatch('click', { target: button });
    await clipboardAnswered();

    timers.runPending();

    assert.equal(button.textContent, 'Copy');
    assert.equal(button.hasAttribute('data-copy-message'), false);
});

test('a browser without a clipboard shows that the copy failed', async () => {
    const { page, button } = pageWithACommand({ clipboard: null });

    page.dispatch('click', { target: button });
    await clipboardAnswered();

    assert.equal(button.textContent, 'Copy failed');
});

test('a clipboard that refuses the text shows that the copy failed', async () => {
    const { page, button } = pageWithACommand({ clipboard: new FakeClipboard(true) });

    page.dispatch('click', { target: button });
    await clipboardAnswered();

    assert.equal(button.textContent, 'Copy failed');
});

test('the failure label goes back to Copy once two seconds passed', async () => {
    const { page, timers, button } = pageWithACommand({ clipboard: null });
    page.dispatch('click', { target: button });
    await clipboardAnswered();

    timers.runPending();

    assert.equal(button.textContent, 'Copy');
});

test('a second click restarts the two seconds and the label still goes back to Copy', async () => {
    const { page, timers, button } = pageWithACommand();
    page.dispatch('click', { target: button });
    await clipboardAnswered();
    const first = [...timers.pending.keys()];

    page.dispatch('click', { target: button });
    await clipboardAnswered();

    assert.equal(first.some((id) => timers.pending.has(id)), false);
    assert.equal(timers.pending.size, 1);
    assert.equal(button.textContent, 'Copied');
    timers.runPending();
    assert.equal(button.textContent, 'Copy');
});

test('a press just before the deadline with its write pending restarts the two seconds at the press', async () => {
    const clipboard = new FakeSlowClipboard();
    const { page, timers, button } = pageWithACommand({ clipboard });
    page.dispatch('click', { target: button });
    clipboard.writes[0].resolve();
    await clipboardAnswered();
    timers.advance(1900);

    page.dispatch('click', { target: button });
    timers.advance(100);
    assert.equal(button.textContent, 'Copied');
    timers.advance(1900);
    assert.equal(button.textContent, 'Copy');
    assert.equal(timers.pending.size, 0);

    clipboard.writes[1].reject(new Error('Write permission denied.'));
    await clipboardAnswered();
    assert.equal(button.textContent, 'Copy failed');
    timers.advance(1999);
    assert.equal(button.textContent, 'Copy failed');
    timers.advance(1);
    assert.equal(button.textContent, 'Copy');
    assert.equal(button.hasAttribute('data-copy-message'), false);
    assert.equal(timers.pending.size, 0);
});

test('a page Turbo caches before the two seconds passed keeps Copy and no timer', async () => {
    const { page, timers, button } = pageWithACommand();
    page.dispatch('click', { target: button });
    await clipboardAnswered();

    page.dispatch('turbo:before-cache');

    assert.equal(button.textContent, 'Copy');
    assert.equal(button.hasAttribute('data-copy-message'), false);
    assert.equal(timers.pending.size, 0);
});

test('a write still pending when Turbo caches the page leaves Copy and no timer once it settles', async () => {
    const clipboard = new FakeSlowClipboard();
    const { page, timers, button } = pageWithACommand({ clipboard });
    page.dispatch('click', { target: button });

    page.dispatch('turbo:before-cache');
    clipboard.writes[0].resolve();
    await clipboardAnswered();

    assert.equal(button.textContent, 'Copy');
    assert.equal(button.hasAttribute('data-copy-message'), false);
    assert.equal(timers.pending.size, 0);
});

test('an older write that succeeds after a newer one failed keeps Copy failed', async () => {
    const clipboard = new FakeSlowClipboard();
    const { page, timers, button } = pageWithACommand({ clipboard });
    page.dispatch('click', { target: button });
    page.dispatch('click', { target: button });

    clipboard.writes[1].reject(new Error('Write permission denied.'));
    await clipboardAnswered();
    clipboard.writes[0].resolve();
    await clipboardAnswered();

    assert.equal(button.textContent, 'Copy failed');
    assert.equal(timers.pending.size, 1);
    timers.runPending();
    assert.equal(button.textContent, 'Copy');
});

test('a click outside a Copy button copies nothing', async () => {
    const { page, timers, clipboard, button } = pageWithACommand();

    page.dispatch('click', { target: { closest: () => null } });
    page.dispatch('click', { target: null });
    await clipboardAnswered();

    assert.deepEqual(clipboard.written, []);
    assert.equal(button.textContent, 'Copy');
    assert.equal(timers.pending.size, 0);
});

test('Escape on a phone closes the open Why and gives the focus back to its summary', () => {
    const { page } = pageWithACommand({ phone: true });
    const why = new FakeWhy(true);
    page.whys.push(why);

    page.dispatch('keydown', { key: 'Escape', target: new FakeInsideWhy(why) });

    assert.equal(why.open, false);
    assert.equal(why.summary.focused, true);
});

test('Escape on a phone closes the open Why after the focus moved to a control outside it, and leaves the focus there', () => {
    const { page, button } = pageWithACommand({ phone: true });
    const why = new FakeWhy(true);
    page.whys.push(why);

    page.dispatch('keydown', { key: 'Escape', target: button });

    assert.equal(why.open, false);
    assert.equal(why.summary.focused, false);
});

test('Escape on a wide screen leaves the Why open, because every Why shows there', () => {
    const { page } = pageWithACommand();
    const why = new FakeWhy(true);
    page.whys.push(why);

    page.dispatch('keydown', { key: 'Escape', target: new FakeInsideWhy(why) });

    assert.equal(why.open, true);
    assert.equal(why.summary.focused, false);
});

test('another key or a closed Why changes nothing', () => {
    const { page } = pageWithACommand({ phone: true });
    const open = new FakeWhy(true);
    page.whys.push(open);

    page.dispatch('keydown', { key: 'Enter', target: new FakeInsideWhy(open) });

    assert.equal(open.open, true);
    assert.equal(open.summary.focused, false);
});

test('on a phone every Why stays closed as the server rendered it', () => {
    const why = new FakeWhy(false);

    const { page } = pageWithACommand({ phone: true, whys: [why] });
    page.dispatch('turbo:load');
    page.dispatch('turbo:render');

    assert.equal(why.open, false);
});

test('on a wide screen every Why opens when the page loads', () => {
    const why = new FakeWhy(false);

    pageWithACommand({ whys: [why] });

    assert.equal(why.open, true);
});

test('on a wide screen a Why a visit puts in again opens too', () => {
    const { page } = pageWithACommand();
    const why = new FakeWhy(false);
    page.whys.push(why);

    page.dispatch('turbo:render');

    assert.equal(why.open, true);
});

test('Escape on a phone with every Why closed moves no focus', () => {
    const { page } = pageWithACommand({ phone: true });
    const closed = new FakeWhy(false);
    page.whys.push(closed);

    page.dispatch('keydown', { key: 'Escape', target: new FakeInsideWhy(closed) });
    page.dispatch('keydown', { key: 'Escape', target: null });

    assert.equal(closed.open, false);
    assert.equal(closed.summary.focused, false);
});
