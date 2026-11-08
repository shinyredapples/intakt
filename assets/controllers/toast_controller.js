import { Controller } from '@hotwired/stimulus';

// As in the mocks: time to read the line and reach Undo; pointer or focus on the toast holds it longer.
const HIDE_AFTER_MS = 5000;

export default class extends Controller {
    connect() {
        this.pointed = false;
        this.focused = false;
        this.wait();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    pointerEnter() {
        this.pointed = true;
        this.wait();
    }

    pointerLeave() {
        this.pointed = false;
        this.wait();
    }

    focusIn() {
        this.focused = true;
        this.wait();
    }

    focusOut() {
        this.focused = false;
        this.wait();
    }

    dismiss() {
        clearTimeout(this.timer);
        this.element.hidden = true;
    }

    // Pointer and focus are tracked apart, so the pointer leaving does not hide an Undo that still has focus.
    wait() {
        clearTimeout(this.timer);
        if (this.pointed || this.focused) {
            return;
        }
        this.timer = setTimeout(() => this.dismiss(), HIDE_AFTER_MS);
    }
}
