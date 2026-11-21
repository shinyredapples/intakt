const SKELETON = 'board-skeleton';
// Long enough that a board the server answers at once, or one Turbo shows from its cache, never flashes the skeleton.
const SHOW_AFTER_MS = 150;

// Not a Stimulus controller, for the reason assets/column-fold.js gives. The loading state of DESIGN.md (Awkward data and states): while a visit to the board waits for its first data, the page shows the board's static skeleton and marks the main region aria-busy; the board Turbo renders then replaces both.
export function showBoardLoading(page, view) {
    let waiting = null;

    page.addEventListener('turbo:visit', (event) => {
        view.clearTimeout(waiting);
        const template = page.getElementById(SKELETON);
        if (template && new URL(event.detail.url, page.baseURI).pathname === template.getAttribute('data-path')) {
            waiting = view.setTimeout(() => showSkeleton(page), SHOW_AFTER_MS);
        }
    });
    // Fired for a cached preview as well as for the answer, so neither is covered by the skeleton.
    page.addEventListener('turbo:before-render', () => view.clearTimeout(waiting));
}

function showSkeleton(page) {
    const template = page.getElementById(SKELETON);
    const main = page.querySelector('main');
    if (!template || !main) {
        return;
    }
    main.innerHTML = template.textContent;
    main.classList.toggle('wide', true);
    main.setAttribute('aria-busy', 'true');
}
