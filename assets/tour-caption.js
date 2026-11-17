const CAPTION = 'tour-caption';
const PAUSE = '[data-tour-pause]';
const CLOSE = '[data-tour-close]';
const CLOSED_KEY = 'intakt-tour';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and its caption must still close. Listens on the page once, so a caption a live update or a visit puts in later is covered too.
export function captionTour(page, storage) {
    let held = null;

    page.addEventListener('click', (event) => {
        const pause = event.target?.closest?.(PAUSE);
        if (pause) {
            held = togglePause(pause, held);
            return;
        }
        if (event.target?.closest?.(CLOSE)) {
            close(page, storage);
        }
    });
    // Turbo appends a stream to the document element, so only the document sees it.
    page.addEventListener('turbo:before-stream-render', (event) => {
        if (event.target.getAttribute('target') !== CAPTION || !isPaused(page)) {
            return;
        }
        event.preventDefault();
        held = { render: event.detail.render, stream: event.target };
    });
}

// Only the newest stage is kept while paused, because it already holds everything the ones before it said.
function togglePause(pause, held) {
    const paused = pause.getAttribute('aria-pressed') !== 'true';
    pause.setAttribute('aria-pressed', String(paused));
    pause.textContent = paused ? 'Resume' : 'Pause';
    if (!paused && held) {
        held.render(held.stream);
    }

    return paused ? held : null;
}

function isPaused(page) {
    return page.getElementById(CAPTION)?.querySelector(PAUSE)?.getAttribute('aria-pressed') === 'true';
}

// Kept for the tab only, so a new visit to the demo shows the caption again.
function close(page, storage) {
    page.documentElement.dataset.tour = 'closed';
    try {
        storage.setItem(CLOSED_KEY, 'closed');
    } catch {}
}
