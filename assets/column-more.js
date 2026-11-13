const CROWDED_COLUMN = '.col[data-page-limit]';
const CARD = '.col-cards > [data-project]';
const MORE_BUTTON = '.more-btn';
const PAST_LIMIT = 'data-past-limit';

// Not a Stimulus controller, for the reason assets/column-fold.js gives. The layout's project filter announces each run, so the limit counts only the cards it left shown.
export function showMoreCards(page) {
    page.addEventListener('click', (event) => {
        const button = event.target?.closest?.(MORE_BUTTON);
        if (button) {
            showEveryCard(button.closest('.col'));
        }
    });
    page.addEventListener('intakt:project-shown', () => limitColumns(page));
    limitColumns(page);
}

function limitColumns(page) {
    page.querySelectorAll(CROWDED_COLUMN).forEach(limit);
}

function limit(column) {
    const pageLimit = Number(column.dataset.pageLimit);
    const expanded = column.hasAttribute('data-expanded');
    const cards = [...column.querySelectorAll(CARD)];
    const shown = cards.filter((card) => !card.hidden);
    const more = column.querySelector(MORE_BUTTON);

    cards.forEach((card) => card.removeAttribute(PAST_LIMIT));
    if (!expanded) {
        shown.slice(pageLimit).forEach((card) => card.setAttribute(PAST_LIMIT, ''));
    }
    more.hidden = expanded || shown.length <= pageLimit;
    if (!more.hidden) {
        more.textContent = `Show ${shown.length - pageLimit} more`;
    }
}

function showEveryCard(column) {
    const firstHidden = column.querySelector(`[${PAST_LIMIT}]`);

    column.setAttribute('data-expanded', '');
    limit(column);
    // The pressed button disappears, so the focus goes to the first card it showed.
    firstHidden?.querySelector('a')?.focus();
}
