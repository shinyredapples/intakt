const TERM = '.term';
const DISMISSED = 'data-dismissed';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and still explains its terms. Listens on the page once, so a term a visit or a live update puts in later is covered too.
// A tip shows while its term has focus or the pointer, so without this a keyboard visitor could not close it without leaving the term (WCAG 1.4.13, dismissible).
export function dismissTermTips(page) {
    page.addEventListener('keydown', (event) => {
        const term = event.key === 'Escape' ? event.target?.closest?.(TERM) : null;
        if (term) {
            term.setAttribute(DISMISSED, '');
        }
    });
    // Leaving the term re-arms it, so the next visit to it shows its tip again.
    page.addEventListener('focusout', (event) => {
        event.target?.closest?.(TERM)?.removeAttribute(DISMISSED);
    });
}
