const CAPTION = 'tour-caption';
const PAUSE = '[data-tour-pause]';
const CLOSE = '[data-tour-close]';
const CLOSED_KEY = 'intakt-tour';
const FOCUS = 'data-tour-focus';

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and its caption must still close. Listens on the page once, so a caption a live update or a visit puts in later is covered too.
export function captionTour(page, storage) {
    let held = null;

    page.addEventListener('click', (event) => {
        const pause = event.target?.closest?.(PAUSE);
        if (pause) {
            togglePause(pause, held, page);
            // A pause starts empty, because a stage held on an earlier page would bring back an older stage on resume.
            held = null;
            return;
        }
        if (event.target?.closest?.(CLOSE)) {
            close(page, storage);
        }
    });
    // Turbo appends a stream to the document element, so only the document sees it.
    page.addEventListener('turbo:before-stream-render', (event) => {
        if (event.target.getAttribute('target') !== CAPTION || !isPaused(page)) {
            // Any update can replace the element a stage points at, so the mark is drawn again after each one.
            const render = event.detail.render;
            event.detail.render = (stream) => Promise.resolve(render(stream)).then(() => pointAt(page));
            return;
        }
        event.preventDefault();
        held = { render: event.detail.render, stream: event.target };
    });
    // A visit Turbo makes runs no module again, so the new page's element is marked on its load.
    page.addEventListener('turbo:load', () => pointAt(page));
    pointAt(page);
}

// Marks the element the caption names in data-tour-at, which is scoped to the caption's round: a page without that element marks nothing.
function pointAt(page) {
    const caption = page.getElementById?.(CAPTION);
    const at = caption?.getAttribute?.('data-tour-at');
    let target = null;
    const closed = page.documentElement?.dataset?.tour === 'closed';
    try {
        target = at && !closed ? (page.querySelector?.(at) ?? null) : null;
    } catch {}
    for (const marked of page.querySelectorAll?.(`[${FOCUS}]`) ?? []) {
        if (marked !== target) {
            marked.removeAttribute(FOCUS);
        }
    }
    const stage = caption?.getAttribute?.('data-stage');
    if (target && target.getAttribute(FOCUS) !== stage) {
        target.setAttribute(FOCUS, stage);
        const still = page.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView?.({ block: 'center', behavior: still ? 'auto' : 'smooth' });
    }
}

// Only the newest stage is kept while paused, because it already holds everything the ones before it said.
function togglePause(pause, held, page) {
    const paused = pause.getAttribute('aria-pressed') !== 'true';
    pause.setAttribute('aria-pressed', String(paused));
    pause.textContent = paused ? 'Resume' : 'Pause';
    if (!paused && held) {
        Promise.resolve(held.render(held.stream)).then(() => pointAt(page));
    }
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
    pointAt(page);
}
