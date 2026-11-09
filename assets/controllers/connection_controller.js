import { Controller } from '@hotwired/stimulus';

const SOURCE = 'turbo-mercure-stream-source';
// As in DESIGN.md ("Awkward data and states"): a fixed wait the line can count down, and red after two minutes.
const RETRY_EVERY_S = 5;
const UNREACHABLE_AFTER_MS = 120000;

export default class extends Controller {
    static targets = ['line', 'title', 'since', 'countdown', 'marker'];

    // Waits for Turbo's element, because its EventSource exists only once the element is defined.
    async connect() {
        this.dropped = new Set();
        this.lastEventIds = new Map();
        await customElements.whenDefined(SOURCE);
        this.element.querySelectorAll(SOURCE).forEach((source) => this.watch(source));
    }

    disconnect() {
        clearInterval(this.ticker);
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
    }

    received(source, event) {
        if (event.lastEventId !== '') {
            this.lastEventIds.set(source, event.lastEventId);
        }
    }

    tick() {
        this.countdown -= 1;
        if (this.countdown === 0) {
            this.retryDropped();
        }
        this.show();
    }

    retryDropped() {
        this.countdown = RETRY_EVERY_S;
        this.dropped.forEach((source) => this.reconnect(source));
    }

    // A new EventSource sends no Last-Event-ID header, so the hub is asked in the address for what the page missed.
    reconnect(source) {
        const address = new URL(source.getAttribute('src'), this.element.baseURI);
        if (this.lastEventIds.has(source)) {
            address.searchParams.set('lastEventID', this.lastEventIds.get(source));
        }
        source.setAttribute('src', address.toString());
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
    }
}

function clockTime(moment) {
    const time = new Date(moment);

    return [time.getHours(), time.getMinutes(), time.getSeconds()].map((part) => String(part).padStart(2, '0')).join(':');
}
