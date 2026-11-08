import { Controller } from '@hotwired/stimulus';

// As in the mocks: long enough to read the question and click again, short enough that a stray later click does nothing.
const DISARM_AFTER_MS = 4000;

export default class extends Controller {
    static values = { confirm: String };

    click(event) {
        if (this.armed()) {
            this.disarm();
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        this.arm();
    }

    // A button that is moved or cached by the page comes back unarmed, so it never waits armed without its timer.
    disconnect() {
        if (this.armed()) {
            this.disarm();
        }
    }

    armed() {
        return this.element.hasAttribute('data-armed');
    }

    arm() {
        this.label = this.element.textContent;
        this.element.setAttribute('data-armed', '');
        this.element.textContent = this.confirmValue;
        this.timer = setTimeout(() => this.disarm(), DISARM_AFTER_MS);
    }

    disarm() {
        clearTimeout(this.timer);
        this.element.removeAttribute('data-armed');
        this.element.textContent = this.label;
    }
}
