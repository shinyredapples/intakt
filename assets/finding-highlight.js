const FINDING_BUTTON = 'button[data-highlight-finding]';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and still shows the session summary. Listens on the page once, so a summary a live update puts in later is covered too.
export function highlightFindings(page) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(FINDING_BUTTON);
        if (button) {
            highlight(page, button.getAttribute('data-highlight-finding'));
        }
    });
}

function highlight(page, id) {
    // The finding sits in the round that judged it, which the summary does not know: the latest round's entry is the one asked about.
    // A merged act entry names each finding it stands for, so every one of their ids reaches its row.
    const cell = [...page.querySelectorAll('td[data-finding]')].findLast((candidate) => candidate.getAttribute('data-finding').split(' ').includes(id));
    const entry = cell?.closest('tr');

    // A finding of a round still running has no timeline entry until the judge marks it.
    if (!entry) {
        return;
    }

    for (const previous of page.querySelectorAll('tr.selected')) {
        previous.classList.remove('selected');
        previous.removeAttribute('aria-current');
    }
    // A judged round is folded to its line, which would hide the entry and leave nothing to scroll to.
    const fold = entry.closest('details');
    if (fold) {
        fold.open = true;
    }
    entry.classList.add('selected');
    entry.setAttribute('aria-current', 'true');
    entry.scrollIntoView?.({ block: 'center' });
}
