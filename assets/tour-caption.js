const CAPTION = 'tour-caption';
const PAUSE = '[data-tour-pause]';
const CLOSE = '[data-tour-close]';
const CLOSED_KEY = 'intakt-tour';
const FOCUS = 'data-tour-focus';
const FOLLOW = '[data-tour-follow]';
// Long enough to read the last line once, short enough that an ended tour never stays on the pages after it.
const LAST_STAGE_MS = 8000;

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and its caption must still close. Listens on the page once, so a caption a live update or a visit puts in later is covered too.
export function captionTour(page, storage) {
    let held = null;

    page.addEventListener('click', (event) => {
        const pause = event.target?.closest?.(PAUSE);
        if (pause) {
            togglePause(pause, held);
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
            event.detail.render = (stream) => Promise.resolve(render(stream)).then(() => pointAt(page, storage));
            return;
        }
        event.preventDefault();
        held = { render: event.detail.render, stream: event.target };
    });
    // A visit Turbo makes runs no module again, so the new page's element is marked on its load.
    page.addEventListener('turbo:load', () => pointAt(page, storage));
    pointAt(page, storage);
}

// Marks the element the stage talks about (the caption names it in data-tour-at), scrolls it into view when it is new, follows the tour from a page that offers it to the review, and closes the tour a moment after its last stage.
function pointAt(page, storage) {
    const caption = page.getElementById?.(CAPTION);
    const at = caption?.getAttribute?.('data-tour-at');
    if (!at) {
        return;
    }
    const review = caption.querySelector('a.tour-review')?.getAttribute('href');
    if (review && page.querySelector?.(FOLLOW) && !caption.hasAttribute('data-tour-last')) {
        page.defaultView?.location.assign(review);
        return;
    }
    const target = page.querySelector?.(at);
    for (const marked of page.querySelectorAll?.(`[${FOCUS}]`) ?? []) {
        if (marked !== target) {
            marked.removeAttribute(FOCUS);
        }
    }
    if (target && target.getAttribute(FOCUS) !== caption.getAttribute('data-stage')) {
        target.setAttribute(FOCUS, caption.getAttribute('data-stage'));
        const still = page.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView?.({ block: 'center', behavior: still ? 'auto' : 'smooth' });
    }
    if (caption.hasAttribute('data-tour-last') && !caption.hasAttribute('data-closing')) {
        caption.setAttribute('data-closing', '');
        page.defaultView?.setTimeout(() => {
            if (!isPaused(page)) {
                close(page, storage);
            }
        }, LAST_STAGE_MS);
    }
}

// Only the newest stage is kept while paused, because it already holds everything the ones before it said.
function togglePause(pause, held) {
    const paused = pause.getAttribute('aria-pressed') !== 'true';
    pause.setAttribute('aria-pressed', String(paused));
    pause.textContent = paused ? 'Resume' : 'Pause';
    if (!paused && held) {
        held.render(held.stream);
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
}
