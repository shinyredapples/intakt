const TAB = '.ttabs [data-pane]';
const FINDING_BUTTON = '[data-highlight-finding]';
const FINDING_LINK = 'a.sb-finding';
const TIMELINE_ENTRY = 'li[data-finding]';
const ROUND = 'details.ev';
// The width below which the stylesheet shows the tabs and only one pane.
const PHONE = '(max-width: 760px)';

// Not a Stimulus controller, because a review page the hub cannot reach loads no import map and a phone could never reach its live log. Listens on the page once.
export function switchTaskPanes(page, view) {
    const phone = view.matchMedia(PHONE);
    const fit = () => fitPanes(page, phone.matches);
    phone.addEventListener('change', fit);
    // A visit or a refresh puts the panes in again as the server rendered them, which is the phone's form.
    page.addEventListener('turbo:load', fit);
    page.addEventListener('turbo:render', fit);
    fit();
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
        if (event.key === 'Escape') {
            foldRound(event);

            return;
        }
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

// Escape closes the round the reader opened, and focus goes back to its head so the next Tab goes on from there instead of from a hidden element.
function foldRound(event) {
    const round = event.target?.closest?.(ROUND);
    if (!round?.open) {
        return;
    }
    event.preventDefault();
    round.open = false;
    round.querySelector('summary').focus();
}

// The tabs are hidden while both panes are shown, so the panes are then two sections named by their headings; on a phone each is a tab panel named by its tab and a Tab stop, so a live log with nothing focusable in it yet is still reached.
function fitPanes(page, onPhone) {
    for (const tab of page.querySelectorAll(TAB)) {
        const pane = page.getElementById(tab.getAttribute('aria-controls'));
        if (!pane) {
            continue;
        }
        if (onPhone) {
            pane.setAttribute('role', 'tabpanel');
            pane.setAttribute('aria-labelledby', tab.getAttribute('id'));
            pane.setAttribute('tabindex', '0');
        } else {
            pane.removeAttribute('role');
            pane.setAttribute('aria-labelledby', pane.getAttribute('data-heading'));
            pane.removeAttribute('tabindex');
        }
    }
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
