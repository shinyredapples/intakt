import { Controller } from '@hotwired/stimulus';

// Kept clear of the chart's edge, as in the mocks' charts.js, so a tip over the first or last bar is not cut off.
const GAP = 8;

// Pointer and focus are tracked apart, as the toast controller does, so the tip stays while either still holds a mark; Escape, heard at page scope (WCAG 1.4.13), hides it until the next pointer or focus.
export default class extends Controller {
    static targets = ['tip'];

    connect() {
        this.pointed = null;
        this.focused = null;
        this.dismissed = false;
    }

    pointerEnter({ currentTarget, params }) {
        this.pointed = { mark: currentTarget, params };
        this.dismissed = false;
        this.show(this.pointed);
    }

    pointerLeave() {
        this.pointed = null;
        this.settle();
    }

    focusIn({ currentTarget, params }) {
        this.focused = { mark: currentTarget, params };
        this.dismissed = false;
        this.show(this.focused);
    }

    focusOut() {
        this.focused = null;
        this.settle();
    }

    dismiss() {
        this.dismissed = true;
        this.tipTarget.hidden = true;
    }

    // After a pushed morph, which puts the tip back to hidden and empty while a hovered or focused mark gets no new event; the mark's value is read again, because the push may have changed it. Only the chart's own event counts, since the morph reports every element it touched.
    restore({ target }) {
        if (target !== this.element) {
            return;
        }

        this.pointed = this.stillHeld(this.pointed);
        this.focused = this.stillHeld(this.focused);
        this.settle();
    }

    // Dropped when the morph removed the mark, as when its bar went away.
    stillHeld(held) {
        if (null === held || !held.mark.isConnected) {
            return null;
        }

        const { chartReadingParam: reading, chartDetailParam: detail } = held.mark.dataset;

        return { mark: held.mark, params: { reading, detail } };
    }

    // The pointer is read first when both hold a mark, because the tip then follows what the eye is on.
    settle() {
        const held = this.pointed ?? this.focused;

        if (null === held || this.dismissed) {
            this.tipTarget.hidden = true;

            return;
        }

        this.show(held);
    }

    // The value first and the detail second (DESIGN.md, Charts), placed above the mark so the pointer never covers it.
    show({ mark, params }) {
        const tip = this.tipTarget;
        tip.querySelector('strong').textContent = params.reading;
        tip.querySelector('span').textContent = params.detail;
        tip.hidden = false;

        const chart = this.element.getBoundingClientRect();
        const box = mark.getBoundingClientRect();
        const centered = box.left - chart.left + box.width / 2 - tip.offsetWidth / 2;
        tip.style.left = `${Math.max(0, Math.min(chart.width - tip.offsetWidth, centered))}px`;
        tip.style.top = `${box.top - chart.top - tip.offsetHeight - GAP}px`;
    }
}
