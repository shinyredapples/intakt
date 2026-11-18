const TAB = '.ttabs [data-pane]';
const FINDING_BUTTON = '[data-highlight-finding]';
const FINDING_LINK = 'a.sb-finding';
const TIMELINE_ENTRY = 'li[data-finding]';

// Not a Stimulus controller, because a review page the hub cannot reach loads no import map and a phone could never reach its live log. Listens on the page once.
export function switchTaskPanes(page) {
    page.addEventListener('click', (event) => {
        const tab = event.target?.closest?.(TAB);
        if (tab) {
            show(page, tab.getAttribute('data-pane'));

            return;
        }
        // A finding's entry is in the timeline, which a phone hides while the live log is chosen, so it must come back before the highlight scrolls to it.
        if (hasTimelineEntry(page, event.target)) {
            show(page, 'timeline');
        }
    });
    page.addEventListener('keydown', (event) => {
        const tab = event.target?.closest?.(TAB);
        const next = tab ? tabFor(page, tab, event) : null;
        if (!next) {
            return;
        }
        event.preventDefault();
        show(page, next.getAttribute('data-pane'));
        next.focus();
    });
}

// The ARIA tabs pattern: Left and Right move to the neighbour and wrap, Home and End go to the first and last tab; a key with a modifier stays the browser's.
function tabFor(page, tab, event) {
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
        return null;
    }
    const tabs = [...page.querySelectorAll(TAB)];
    const at = tabs.indexOf(tab);
    switch (event.key) {
        case 'ArrowLeft':
            return tabs.at(at - 1);
        case 'ArrowRight':
            return tabs[(at + 1) % tabs.length];
        case 'Home':
            return tabs[0];
        case 'End':
            return tabs.at(-1);
        default:
            return null;
    }
}

// A finding of a round still running has no timeline entry until the judge marks it, and hiding the live log would then show nothing in its place.
function hasTimelineEntry(page, target) {
    const button = target?.closest?.(FINDING_BUTTON);
    const link = button ? null : target?.closest?.(FINDING_LINK);
    const finding = button?.getAttribute?.('data-highlight-finding');
    const anchor = link?.getAttribute?.('href')?.replace(/^#/, '');
    if (!finding && !anchor) {
        return false;
    }

    return [...page.querySelectorAll(TIMELINE_ENTRY)].some((cell) => finding
        ? cell.getAttribute('data-finding').split(' ').includes(finding)
        : cell.getAttribute('id') === anchor);
}

// Only the chosen tab stays a Tab stop, so Tab leaves the tabs instead of walking through both.
function show(page, pane) {
    page.querySelector('.task-grid')?.setAttribute('data-pane', pane);
    for (const tab of page.querySelectorAll(TAB)) {
        const chosen = tab.getAttribute('data-pane') === pane;
        tab.setAttribute('aria-selected', String(chosen));
        tab.setAttribute('tabindex', chosen ? '0' : '-1');
    }
}
