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

    emit(type, event = {}) {
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

async function connectedShell(t, sources = [new FakeStreamSource()]) {
    t.mock.timers.enable({ apis: ['setInterval', 'Date'], now: DROPPED_AT });
    globalThis.EventSource = FakeEventSource;
    globalThis.customElements = { whenDefined: async () => undefined };
    const shell = new ConnectionController();
    shell.element = { baseURI: 'http://localhost:7780/reviews/round-1', querySelectorAll: () => sources };
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

test('a page without a live connection never shows the line', async (t) => {
    const { shell } = await connectedShell(t, []);

    t.mock.timers.tick(5000);

    assert.equal(shell.lineTarget.hidden, true);
    assert.equal(shell.markerTarget.hidden, true);
});
