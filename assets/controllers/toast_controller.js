import { Controller } from '@hotwired/stimulus';

// As in the mocks: time to read the line and reach Undo; pointer or focus on the toast holds it longer.
const HIDE_AFTER_MS = 5000;

export default class extends Controller {
    connect() {
        this.release();
    }

    disconnect() {
        this.hold();
    }

    hold() {
        clearTimeout(this.timer);
    }

    release() {
        this.hold();
        this.timer = setTimeout(() => this.dismiss(), HIDE_AFTER_MS);
    }

    dismiss() {
        this.hold();
        this.element.hidden = true;
    }
}
