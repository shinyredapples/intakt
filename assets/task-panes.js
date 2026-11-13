const TAB = '.ttabs [data-pane]';
const TIMELINE_LINK = '[data-highlight-finding], a.sb-finding';

// Not a Stimulus controller, because a review page the hub cannot reach loads no import map and a phone could never reach its live log. Listens on the page once.
export function switchTaskPanes(page) {
    page.addEventListener('click', (event) => {
        const tab = event.target?.closest?.(TAB);
        if (tab) {
            show(page, tab.getAttribute('data-pane'));

            return;
        }
        // A finding's entry is in the timeline, which a phone hides while the live log is chosen, so it must come back before the highlight scrolls to it.
        if (event.target?.closest?.(TIMELINE_LINK)) {
            show(page, 'timeline');
        }
    });
}

function show(page, pane) {
    page.querySelector('.task-grid')?.setAttribute('data-pane', pane);
    for (const tab of page.querySelectorAll(TAB)) {
        tab.setAttribute('aria-selected', String(tab.getAttribute('data-pane') === pane));
    }
}
