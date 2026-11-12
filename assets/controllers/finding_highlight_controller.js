import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    select({ params: { id } }) {
        const document = this.element.ownerDocument;
        // The finding sits in the round that judged it, which the summary does not know: the latest round's entry is the one asked about.
        const cell = [...document.querySelectorAll('td[data-finding]')].findLast((candidate) => candidate.getAttribute('data-finding') === id);
        const entry = cell?.closest('tr');

        // A finding of a round still running has no timeline entry until the judge marks it.
        if (!entry) {
            return;
        }

        for (const previous of document.querySelectorAll('tr.selected')) {
            previous.classList.remove('selected');
            previous.removeAttribute('aria-current');
        }
        entry.classList.add('selected');
        entry.setAttribute('aria-current', 'true');
        entry.scrollIntoView?.({ block: 'center' });
    }
}
