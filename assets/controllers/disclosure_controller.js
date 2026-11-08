import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle({ currentTarget }) {
        const open = this.element.classList.toggle('open');

        currentTarget.setAttribute('aria-expanded', String(open));
    }
}
