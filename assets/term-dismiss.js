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
    // A tip stays closed until both the focus and the pointer have left its term: a pointer resting on it would otherwise reopen the tip on the next Tab.
    page.addEventListener('focusout', (event) => {
        const term = event.target?.closest?.(TERM);
        if (term && !term.matches(':hover')) {
            term.removeAttribute(DISMISSED);
        }
    });
    page.addEventListener('pointerout', (event) => {
        const term = event.target?.closest?.(TERM);
        if (term && !term.contains(event.relatedTarget) && !term.matches(':focus')) {
            term.removeAttribute(DISMISSED);
        }
    });
}
