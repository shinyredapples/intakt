const COLUMN = '.col';
const CARD = '.col-cards > [data-project]';
const EMPTY_LINE = '.col-empty';

// Not a Stimulus controller, for the reason assets/column-fold.js gives. Every column carries its empty-state line, hidden while it has a card, so a column whose cards all belong to projects the layout's filter hides still says what lands there (#2658).
export function showEmptyLines(page) {
    page.addEventListener('intakt:project-shown', () => showFor(page));
    showFor(page);
}

function showFor(page) {
    page.querySelectorAll(COLUMN).forEach((column) => {
        const line = column.querySelector(EMPTY_LINE);
        if (line !== null) {
            line.hidden = [...column.querySelectorAll(CARD)].some((card) => !card.hidden);
        }
    });
}
