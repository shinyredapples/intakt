import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tip', 'reading', 'label'];

    connect() {
        this.pointed = null;
        this.focused = null;
        this.dismissed = false;
    }

    pointerEnter({ currentTarget }) {
        this.pointed = currentTarget;
        this.show(currentTarget);
    }

    pointerLeave() {
        this.pointed = null;
        this.follow();
    }

    focusIn({ currentTarget }) {
        this.focused = currentTarget;
        this.show(currentTarget);
    }

    focusOut() {
        this.focused = null;
        this.follow();
    }

    // Escape hides the tooltip without moving focus or the pointer (WCAG 1.4.13); the next trigger shows it again.
    dismiss() {
        this.dismissed = true;
        this.tipTarget.hidden = true;
    }

    // Pointer and focus are tracked apart, as in the toast controller, so one leaving does not hide what the other still holds.
    follow() {
        const bar = this.focused ?? this.pointed;
        if (bar === null || this.dismissed) {
            this.tipTarget.hidden = true;

            return;
        }
        this.show(bar);
    }

    show(bar) {
        this.dismissed = false;
        this.readingTarget.textContent = bar.getAttribute('data-reading');
        this.labelTarget.textContent = bar.getAttribute('data-label');
        this.tipTarget.hidden = false;
        this.place(bar);
    }

    // Centered over the bar's slot, kept inside the chart so a pane that clips its overflow cannot cut it.
    place(bar) {
        const slot = bar.getBoundingClientRect();
        const frame = this.element.getBoundingClientRect();
        const half = this.tipTarget.offsetWidth / 2;
        const center = slot.left + slot.width / 2 - frame.left;
        this.tipTarget.style.left = `${Math.min(Math.max(center, half), frame.width - half)}px`;
        this.tipTarget.style.top = `${slot.top - frame.top}px`;
    }
}
