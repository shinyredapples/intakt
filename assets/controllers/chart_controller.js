import { Controller } from '@hotwired/stimulus';

// Kept clear of the chart's edge, as in the mocks' charts.js, so a tip over the first or last bar is not cut off.
const GAP = 8;

export default class extends Controller {
    static targets = ['tip'];

    // The value first and the detail second (DESIGN.md, Charts), placed above the mark so the pointer never covers it.
    show({ currentTarget, params }) {
        const tip = this.tipTarget;
        tip.querySelector('strong').textContent = params.reading;
        tip.querySelector('span').textContent = params.detail;
        tip.hidden = false;

        const chart = this.element.getBoundingClientRect();
        const mark = currentTarget.getBoundingClientRect();
        const centered = mark.left - chart.left + mark.width / 2 - tip.offsetWidth / 2;
        tip.style.left = `${Math.max(0, Math.min(chart.width - tip.offsetWidth, centered))}px`;
        tip.style.top = `${mark.top - chart.top - tip.offsetHeight - GAP}px`;
    }

    hide() {
        this.tipTarget.hidden = true;
    }
}
