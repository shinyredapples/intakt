import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeClick, FakeElement, loadController } from './stimulus-controller.mjs';

// The line shows the local time of the drop; one zone keeps the expected words fixed.
process.env.TZ = 'UTC';

const ConnectionController = await loadController('connection');

const DROPPED_AT = Date.UTC(2026, 10, 9, 11, 12, 4);
const HUB = 'http://localhost:7781/.well-known/mercure?match=review%2Frounds%2Fround-1';

class FakeEventSource {
    static CLOSED = 2;
    readyState = 0;
    listeners = new Map();

    addEventListener(type, listener) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    close() {
        this.readyState = FakeEventSource.CLOSED;
    }

    // As a browser's EventSource: a closed one never fires again.
    emit(type, event = {}) {
        if (this.readyState === FakeEventSource.CLOSED) {
            return;
        }
        (this.listeners.get(type) ?? []).forEach((listener) => listener(event));
    }
}

// As Turbo's <turbo-mercure-stream-source>: a changed src closes its EventSource and opens a new one.
class FakeStreamSource extends FakeElement {
    constructor() {
        super();
        this.attributes.set('src', HUB);
        this.es = new FakeEventSource();
    }

    setAttribute(name, value) {
        super.setAttribute(name, value);
        if (this.es) {
            this.es.close();
            this.es = new FakeEventSource();
        }
    }
}

// The notes a held action placed after itself, which the page lists as the shell's querySelectorAll would.
let notes = [];

class FakeNote extends FakeElement {
    remove() {
        notes = notes.filter((note) => note !== this);
    }
}

class FakeButton extends FakeElement {
    constructor(disabled = false) {
        super();
        this.disabled = disabled;
    }

    after(note) {
        this.note = note;
        notes.push(note);
    }
}

function hiddenElapsedTime() {
    const at = new FakeElement();
    at.hidden = true;

    return at;
}

async function connectedShell(t, sources = [new FakeStreamSource()], page = {}) {
    t.mock.timers.enable({ apis: ['setInterval', 'Date'], now: DROPPED_AT });
    notes = [];

    return shellOn(sources, page);
}

// A shell that Stimulus connects to a page, as when Turbo renders it or restores it from its cache.
async function shellOn(sources, { actions = [], elapsed = [], address = 'http://localhost:7781/reviews/round-1' } = {}) {
    globalThis.EventSource = FakeEventSource;
    globalThis.customElements = { whenDefined: async () => undefined };
    globalThis.document = { createElement: () => new FakeNote() };
    const shell = new ConnectionController();
    const found = { 'turbo-mercure-stream-source': () => sources, 'form[method="post"] button[type="submit"]': () => actions, '[data-connection-note]': () => notes };
    shell.element = { baseURI: address, querySelectorAll: (selector) => found[selector]() };
    shell.frozenTargets = elapsed;
    shell.lineTarget = new FakeElement();
    shell.lineTarget.hidden = true;
    shell.titleTarget = new FakeElement('Reconnecting.');
    shell.sinceTarget = new FakeElement();
    shell.countdownTarget = new FakeElement();
    shell.markerTarget = new FakeElement('Offline');
    shell.markerTarget.hidden = true;
    await shell.connect();

    return { shell, source: sources[0] };
}

function lastEventIdAskedBy(source) {
    return new URL(source.getAttribute('src')).searchParams.get('last_event_id');
}

test('a page whose live connection opens shows neither the line nor the Offline marker', async (t) => {
    const { shell, source } = await connectedShell(t);

    source.es.emit('open');

    assert.equal(shell.lineTarget.hidden, true);
    assert.equal(shell.markerTarget.hidden, true);
});

test('a dropped connection shows the reconnecting line with the time of the drop and the Offline marker', async (t) => {
    const { shell, source } = await connectedShell(t);

    source.es.emit('error');

    assert.equal(shell.lineTarget.hidden, false);
    assert.equal(shell.markerTarget.hidden, false);
    assert.equal(shell.titleTarget.textContent, 'Reconnecting.');
    assert.equal(shell.sinceTarget.textContent, '11:12:04');
    assert.equal(shell.countdownTarget.textContent, '5 s');
});

test('a hub that refused the stream before the controller started shows the line', async (t) => {
    const source = new FakeStreamSource();
    source.es.close();

    const { shell } = await connectedShell(t, [source]);

    assert.equal(shell.lineTarget.hidden, false);
});

test('a drop stops the browser retrying on its own', async (t) => {
    const { source } = await connectedShell(t);
    const dropped = source.es;

    dropped.emit('error');

    assert.equal(dropped.readyState, FakeEventSource.CLOSED);
});

test('the line counts the seconds down to the next try', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');

    t.mock.timers.tick(1000);

    assert.equal(shell.countdownTarget.textContent, '4 s');
});

test('the next try opens a new connection five seconds after the drop', async (t) => {
    const { shell, source } = await connectedShell(t);
    const dropped = source.es;
    dropped.emit('error');

    t.mock.timers.tick(4999);
    assert.equal(source.es, dropped);
    t.mock.timers.tick(1);

    assert.notEqual(source.es, dropped);
    assert.equal(shell.countdownTarget.textContent, '5 s');
});

test('the next try asks the hub for the events after the last one the page received', async (t) => {
    const { source } = await connectedShell(t);
    source.es.emit('message', { lastEventId: 'urn:uuid:7c1f&match=other' });
    source.es.emit('message', { lastEventId: '' });
    source.es.emit('error');

    t.mock.timers.tick(5000);

    assert.equal(lastEventIdAskedBy(source), 'urn:uuid:7c1f&match=other');
    assert.deepEqual(new URL(source.getAttribute('src')).searchParams.getAll('match'), ['review/rounds/round-1']);
});

test('a later next try asks only for the events after the newest one received', async (t) => {
    const { source } = await connectedShell(t);
    source.es.emit('message', { lastEventId: 'urn:uuid:first' });
    source.es.emit('error');
    t.mock.timers.tick(5000);
    source.es.emit('open');
    source.es.emit('message', { lastEventId: 'urn:uuid:second' });
    source.es.emit('error');

    t.mock.timers.tick(5000);

    assert.deepEqual(new URL(source.getAttribute('src')).searchParams.getAll('last_event_id'), ['urn:uuid:second']);
});

test('a next try before any event was received asks for no earlier events', async (t) => {
    const { source } = await connectedShell(t);
    source.es.emit('error');

    t.mock.timers.tick(5000);

    assert.equal(lastEventIdAskedBy(source), null);
});

test('Try now opens a new connection at once and starts the countdown again', async (t) => {
    const { shell, source } = await connectedShell(t);
    const dropped = source.es;
    dropped.emit('error');
    t.mock.timers.tick(3000);

    shell.tryNow(new FakeClick());

    assert.notEqual(source.es, dropped);
    assert.equal(shell.countdownTarget.textContent, '5 s');
});

test('Try now while the connection is up does nothing', async (t) => {
    const { shell, source } = await connectedShell(t);
    const open = source.es;
    open.emit('open');

    shell.tryNow(new FakeClick());

    assert.equal(source.es, open);
    assert.equal(shell.lineTarget.hidden, true);
});

test('a connection that opens again hides the line and the marker and stops trying', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(5000);
    const reopened = source.es;

    reopened.emit('open');
    t.mock.timers.tick(60000);

    assert.equal(shell.lineTarget.hidden, true);
    assert.equal(shell.markerTarget.hidden, true);
    assert.equal(source.es, reopened);
});

test('a next try that fails again keeps the time of the first drop', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(5000);

    source.es.emit('error');

    assert.equal(shell.sinceTarget.textContent, '11:12:04');
    assert.equal(shell.countdownTarget.textContent, '5 s');
});

test('the line turns red two minutes after the drop', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');

    t.mock.timers.tick(119000);
    assert.equal(shell.lineTarget.classList.contains('unreachable'), false);
    t.mock.timers.tick(1000);

    assert.equal(shell.lineTarget.classList.contains('unreachable'), true);
    assert.equal(shell.titleTarget.textContent, "Can't reach Intakt.");
});

test('a drop after the connection came back starts a new two minutes', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(120000);
    source.es.emit('open');

    source.es.emit('error');

    assert.equal(shell.lineTarget.classList.contains('unreachable'), false);
    assert.equal(shell.titleTarget.textContent, 'Reconnecting.');
    assert.equal(shell.sinceTarget.textContent, '11:14:04');
});

test('the line stays while another live connection of the page is still down', async (t) => {
    const other = new FakeStreamSource();
    const { shell, source } = await connectedShell(t, [new FakeStreamSource(), other]);
    source.es.emit('error');
    other.es.emit('error');

    source.es.emit('open');

    assert.equal(shell.lineTarget.hidden, false);
    assert.equal(shell.markerTarget.hidden, false);
});

test('a shell that leaves the page stops its countdown', async (t) => {
    const { shell, source } = await connectedShell(t);
    const dropped = source.es;
    dropped.emit('error');

    shell.disconnect();
    t.mock.timers.tick(5000);

    assert.equal(source.es, dropped);
});

test('a try still under way at the next countdown is left to finish', async (t) => {
    const { source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(5000);
    const slow = source.es;

    t.mock.timers.tick(5000);

    assert.equal(source.es, slow);
    assert.notEqual(slow.readyState, FakeEventSource.CLOSED);
});

test('a hub whose handshake takes longer than the countdown is reached', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(5000);
    const slow = source.es;
    t.mock.timers.tick(6000);

    slow.emit('open');

    assert.equal(shell.lineTarget.hidden, true);
    assert.equal(shell.markerTarget.hidden, true);
});

test('Try now leaves a try still under way to finish', async (t) => {
    const { shell, source } = await connectedShell(t);
    source.es.emit('error');
    t.mock.timers.tick(5000);
    const slow = source.es;

    shell.tryNow(new FakeClick());

    assert.equal(source.es, slow);
});

test('a shell that leaves the page before Turbo is ready watches nothing', async (t) => {
    t.mock.timers.enable({ apis: ['setInterval', 'Date'], now: DROPPED_AT });
    notes = [];
    let ready;
    globalThis.customElements = { whenDefined: () => new Promise((resolve) => { ready = resolve; }) };
    const source = new FakeStreamSource();
    const shell = new ConnectionController();
    shell.element = { baseURI: 'http://localhost:7781/reviews/round-1', querySelectorAll: (selector) => (selector === 'turbo-mercure-stream-source' ? [source] : []) };
    shell.frozenTargets = [];
    const connecting = shell.connect();

    shell.disconnect();
    source.es = undefined;
    ready();

    await assert.doesNotReject(connecting);
});

test('a drop disables every action of the page', async (t) => {
    const actions = [new FakeButton(), new FakeButton()];
    const { source } = await connectedShell(t, undefined, { actions });

    source.es.emit('error');

    assert.deepEqual(actions.map((action) => action.disabled), [true, true]);
});

test('a held action says it is connecting to live updates', async (t) => {
    const action = new FakeButton();
    const { source } = await connectedShell(t, undefined, { actions: [action] });

    source.es.emit('error');

    assert.equal(action.note.textContent, 'Connecting to live updates…');
    assert.equal(action.note.getAttribute('role'), 'status');
    assert.equal(action.note.className, 'held-note');
});

test('a held action still says it is connecting just before the first try ends', async (t) => {
    const action = new FakeButton();
    const { source } = await connectedShell(t, undefined, { actions: [action] });
    source.es.emit('error');

    t.mock.timers.tick(4000);

    assert.equal(action.note.textContent, 'Connecting to live updates…');
});

test('a held action says why it waits and what to do after five seconds without a connection', async (t) => {
    const action = new FakeButton();
    const { source } = await connectedShell(t, undefined, { actions: [action] });
    source.es.emit('error');

    t.mock.timers.tick(5000);

    assert.equal(action.note.textContent, 'No live connection, so this waits. Open http://localhost:7781 on the machine running Intakt, or reach it from another device through an SSH tunnel.');
});

test('a page on the default port names the address without a port', async (t) => {
    const action = new FakeButton();
    const { source } = await connectedShell(t, undefined, { actions: [action], address: 'http://localhost/reviews/round-1' });
    source.es.emit('error');

    t.mock.timers.tick(5000);

    assert.match(action.note.textContent, /Open http:\/\/localhost on the machine/);
});

test('a connection that opens again removes the line beside each held action', async (t) => {
    const actions = [new FakeButton(), new FakeButton()];
    const { source } = await connectedShell(t, undefined, { actions });
    source.es.emit('error');
    t.mock.timers.tick(5000);

    source.es.emit('open');

    assert.deepEqual(notes, []);
});

test('an action the page showed disabled gets no connecting line', async (t) => {
    const unavailable = new FakeButton(true);
    const { source } = await connectedShell(t, undefined, { actions: [unavailable] });

    source.es.emit('error');

    assert.equal(unavailable.note, undefined);
});

test('a connection that opens again enables the actions it disabled', async (t) => {
    const actions = [new FakeButton(), new FakeButton()];
    const { source } = await connectedShell(t, undefined, { actions });
    source.es.emit('error');
    t.mock.timers.tick(5000);

    source.es.emit('open');

    assert.deepEqual(actions.map((action) => action.disabled), [false, false]);
});

test('an action the page showed disabled stays disabled when the connection is back', async (t) => {
    const unavailable = new FakeButton(true);
    const { source } = await connectedShell(t, undefined, { actions: [unavailable] });
    source.es.emit('error');
    t.mock.timers.tick(5000);

    source.es.emit('open');

    assert.equal(unavailable.disabled, true);
});

test('the actions stay disabled while another live connection of the page is still down', async (t) => {
    const action = new FakeButton();
    const other = new FakeStreamSource();
    const { source } = await connectedShell(t, [new FakeStreamSource(), other], { actions: [action] });
    source.es.emit('error');
    other.es.emit('error');

    source.es.emit('open');

    assert.equal(action.disabled, true);
});

test('a shell that leaves the page while offline enables the actions it disabled', async (t) => {
    const action = new FakeButton();
    const { shell, source } = await connectedShell(t, undefined, { actions: [action] });
    source.es.emit('error');

    shell.disconnect();

    assert.equal(action.disabled, false);
});

test('a shell that leaves the page while offline hides the time of the drop beside the elapsed times', async (t) => {
    const at = hiddenElapsedTime();
    const { shell, source } = await connectedShell(t, undefined, { elapsed: [at] });
    source.es.emit('error');

    shell.disconnect();

    assert.equal(at.hidden, true);
});

// Turbo caches its copy of the page before Stimulus disconnects the shell, so the copy keeps what the drop did.
test('a page restored from a copy cached while offline gets its actions back when the connection opens', async (t) => {
    const action = new FakeButton();
    const { source } = await connectedShell(t, undefined, { actions: [action] });
    source.es.emit('error');
    const cached = new FakeButton(action.disabled);
    action.attributes.forEach((value, name) => cached.setAttribute(name, value));
    const restored = new FakeStreamSource();

    await shellOn([restored], { actions: [cached] });
    restored.es.emit('open');

    assert.equal(cached.disabled, false);
});

test('a page restored from a copy cached while offline keeps an action the page showed disabled disabled', async (t) => {
    const unavailable = new FakeButton(true);
    const { source } = await connectedShell(t, undefined, { actions: [unavailable] });
    source.es.emit('error');
    const cached = new FakeButton(unavailable.disabled);
    unavailable.attributes.forEach((value, name) => cached.setAttribute(name, value));
    const restored = new FakeStreamSource();

    await shellOn([restored], { actions: [cached] });
    restored.es.emit('open');

    assert.equal(cached.disabled, true);
});

test('a page restored from a copy cached while offline hides the time of the drop beside the elapsed times', async (t) => {
    const at = hiddenElapsedTime();
    const { source } = await connectedShell(t, undefined, { elapsed: [at] });
    source.es.emit('error');
    const cached = new FakeElement(at.textContent);
    cached.hidden = at.hidden;

    await shellOn([new FakeStreamSource()], { elapsed: [cached] });

    assert.equal(cached.hidden, true);
});

test('a drop shows every elapsed time with the time of the drop', async (t) => {
    const elapsed = [hiddenElapsedTime(), hiddenElapsedTime()];
    const { source } = await connectedShell(t, undefined, { elapsed });

    source.es.emit('error');

    assert.deepEqual(elapsed.map((at) => [at.hidden, at.textContent]), [[false, 'at 11:12'], [false, 'at 11:12']]);
});

test('the elapsed times keep the time of the first drop while the tries fail', async (t) => {
    const at = hiddenElapsedTime();
    const { source } = await connectedShell(t, undefined, { elapsed: [at] });
    source.es.emit('error');
    t.mock.timers.tick(120000);

    source.es.emit('error');

    assert.equal(at.textContent, 'at 11:12');
});

test('a connection that opens again hides the time of the drop beside the elapsed times', async (t) => {
    const at = hiddenElapsedTime();
    const { source } = await connectedShell(t, undefined, { elapsed: [at] });
    source.es.emit('error');
    t.mock.timers.tick(5000);

    source.es.emit('open');

    assert.equal(at.hidden, true);
});

test('a page without a live connection never shows the line', async (t) => {
    const { shell } = await connectedShell(t, []);

    t.mock.timers.tick(5000);

    assert.equal(shell.lineTarget.hidden, true);
    assert.equal(shell.markerTarget.hidden, true);
});
