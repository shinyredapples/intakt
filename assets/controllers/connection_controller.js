import { Controller } from '@hotwired/stimulus';

const SOURCE = 'turbo-mercure-stream-source';
// As in DESIGN.md ("Awkward data and states"): a fixed wait the line can count down, and red after two minutes.
const RETRY_EVERY_S = 5;
const UNREACHABLE_AFTER_MS = 120000;
// Three countdowns give a slow hub time to answer, while a try whose packets are silently dropped (it never fails) is still replaced, so DESIGN.md's next try keeps coming.
const GIVE_UP_ON_A_TRY_AFTER_S = RETRY_EVERY_S * 3;
// Everything that changes Intakt's state is a POST form, so its submit button is what waits while offline.
const ACTIONS = 'form[method="post"] button[type="submit"]';
// Marked in the page rather than remembered by the controller, because Turbo caches the page with the marks and a new controller restores it.
const HELD = 'data-connection-held';
// A line beside each held action, so a disabled button always says what it waits for; after one countdown without a connection it says why and what to do.
const NOTE = 'data-connection-note';
const EXPLAINED_AFTER_MS = RETRY_EVERY_S * 1000;
const CONNECTING = 'Connecting to live updates…';

export default class extends Controller {
    static targets = ['line', 'title', 'since', 'countdown', 'marker', 'frozen'];

    // Waits for Turbo's element, because its EventSource exists only once the element is defined.
    async connect() {
        this.dropped = new Set();
        this.lastEventIds = new Map();
        this.triedAt = new Map();
        this.secondsCounted = 0;
        this.settle();
        const visit = (this.visit = {});
        await customElements.whenDefined(SOURCE);
        // Turbo may have left the page meanwhile, and its elements no longer hold an EventSource.
        if (this.visit !== visit) {
            return;
        }
        this.element.querySelectorAll(SOURCE).forEach((source) => this.watch(source));
    }

    disconnect() {
        this.visit = null;
        clearInterval(this.ticker);
        this.settle();
    }

    // A page restored from Turbo's cache may carry the held actions and frozen times of a drop no controller watches any more.
    settle() {
        this.releaseActions();
        this.frozenTargets.forEach((at) => (at.hidden = true));
    }

    tryNow() {
        if (this.dropped.size === 0) {
            return;
        }
        this.retryDropped();
        this.show();
    }

    watch(source) {
        const events = source.es;
        events.addEventListener('open', () => this.reconnected(source));
        events.addEventListener('error', () => this.lost(source));
        events.addEventListener('message', (event) => this.received(source, event));
        if (events.readyState === EventSource.CLOSED) {
            this.lost(source);
        }
    }

    // The controller retries instead of the browser, so the line can say when the next try is and Try now can make it.
    lost(source) {
        source.es.close();
        if (this.dropped.size === 0) {
            this.since = Date.now();
            this.countdown = RETRY_EVERY_S;
            this.ticker = setInterval(() => this.tick(), 1000);
            this.holdActions();
            this.freezeElapsedTimes();
        }
        this.dropped.add(source);
        this.show();
    }

    reconnected(source) {
        this.dropped.delete(source);
        if (this.dropped.size > 0) {
            return;
        }
        clearInterval(this.ticker);
        this.lineTarget.hidden = true;
        this.markerTarget.hidden = true;
        this.settle();
    }

    // Only the buttons it disabled are enabled again, so one the page showed disabled stays so.
    holdActions() {
        this.element.querySelectorAll(ACTIONS).forEach((action) => {
            if (!action.disabled) {
                action.disabled = true;
                action.setAttribute(HELD, '');
                action.after(this.heldNote());
            }
        });
    }

    heldNote() {
        const note = document.createElement('p');
        note.className = 'held-note';
        note.setAttribute(NOTE, '');
        note.setAttribute('role', 'status');
        note.textContent = CONNECTING;

        return note;
    }

    releaseActions() {
        this.element.querySelectorAll(ACTIONS).forEach((action) => {
            if (action.hasAttribute(HELD)) {
                action.disabled = false;
                action.removeAttribute(HELD);
            }
        });
        this.element.querySelectorAll(`[${NOTE}]`).forEach((note) => note.remove());
    }

    // Only a page the dashboard's own address serves gets this far, so its host and port (none on a default port) are the ones to open on the machine running Intakt.
    explainHeldActions() {
        const host = new URL(this.element.baseURI).host;
        this.element.querySelectorAll(`[${NOTE}]`).forEach((note) => (note.textContent = explained(host)));
    }

    // The server renders elapsed times and nothing counts them on, so the time of the drop tells how old they are.
    freezeElapsedTimes() {
        this.frozenTargets.forEach((at) => {
            at.textContent = `at ${clockTime(this.since).slice(0, 5)}`;
            at.hidden = false;
        });
    }

    received(source, event) {
        if (event.lastEventId !== '') {
            this.lastEventIds.set(source, event.lastEventId);
        }
    }

    tick() {
        this.secondsCounted += 1;
        this.countdown -= 1;
        if (this.countdown === 0) {
            this.retryDropped();
        }
        this.show();
    }

    // A failed try is replaced at once, a pending one only after three countdowns, so a slow hub still gets to open but a hung try does not stall reconnecting.
    retryDropped() {
        this.countdown = RETRY_EVERY_S;
        this.dropped.forEach((source) => {
            if (this.isWorthReplacing(source)) {
                this.reconnect(source);
            }
        });
    }

    // Seconds counted by the ticker rather than the clock, because a clock correction must not stretch or shorten the wait, and not a count of countdowns, so clicking Try now does not bring the replacement of a pending try closer.
    isWorthReplacing(source) {
        if (source.es.readyState === EventSource.CLOSED) {
            return true;
        }

        return this.secondsCounted - this.triedAt.get(source) >= GIVE_UP_ON_A_TRY_AFTER_S;
    }

    // A new EventSource sends no Last-Event-ID header, so the hub is asked in the address for what the page missed.
    reconnect(source) {
        const address = new URL(source.getAttribute('src'), this.element.baseURI);
        if (this.lastEventIds.has(source)) {
            address.searchParams.set('last_event_id', this.lastEventIds.get(source));
        }
        source.setAttribute('src', address.toString());
        this.triedAt.set(source, this.secondsCounted);
        this.watch(source);
    }

    show() {
        const unreachable = Date.now() - this.since >= UNREACHABLE_AFTER_MS;
        this.lineTarget.classList.toggle('unreachable', unreachable);
        this.titleTarget.textContent = unreachable ? "Can't reach Intakt." : 'Reconnecting.';
        this.sinceTarget.textContent = clockTime(this.since);
        this.countdownTarget.textContent = `${this.countdown} s`;
        this.lineTarget.hidden = false;
        this.markerTarget.hidden = false;
        if (Date.now() - this.since >= EXPLAINED_AFTER_MS) {
            this.explainHeldActions();
        }
    }
}

function explained(host) {
    return `No live connection, so this waits. Open http://${host} on the machine running Intakt, or reach it from another device through an SSH tunnel.`;
}

function clockTime(moment) {
    const time = new Date(moment);

    return [time.getHours(), time.getMinutes(), time.getSeconds()].map((part) => String(part).padStart(2, '0')).join(':');
}
