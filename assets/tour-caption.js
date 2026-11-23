const CAPTION = 'tour-caption';
const PAUSE = '[data-tour-pause]';
const CLOSE = '[data-tour-close]';
const FOLLOW = 'data-tour-follow';
const LAST = 'data-tour-last';
const CLOSED_KEY = 'intakt-tour';
const CLOSED_ROUND_KEY = 'intakt-tour-round';
const FOCUS = 'data-tour-focus';
const CLOSE_AFTER_MS = 8000;

// Not a Stimulus controller, because a page the hub cannot reach loads no import map and its caption must still close. Listens on the page once, so a caption a live update or a visit puts in later is covered too.
export function captionTour(page, storage) {
    let held = null;
    // Kept out of the DOM, because Turbo caches a page with its attributes and a copy that Back restores must arm its own close.
    const armed = new WeakSet();
    // Only a round this tab has not shown yet reopens a closed tour, because a copy Back restores can hold an older round than the one closed.
    const seen = new Set();
    let closedRound = read(storage, CLOSED_ROUND_KEY);
    let due = null;
    let sign = takeFollow(page);

    const show = () => {
        const caption = page.getElementById?.(CAPTION);
        const round = caption?.getAttribute?.('data-round') || null;
        if (isClosed(page) && round !== null && closedRound === null && seen.size === 0) {
            // A tab closed before the round was kept counts as closed for the round it shows, so a later round still opens it.
            closedRound = round;
            close(page, storage, round);
        }
        if (isClosed(page) && round !== null && round !== closedRound && !seen.has(round)) {
            reopen(page, storage);
            closedRound = null;
        }
        if (round !== null) {
            seen.add(round);
        }
        pointAt(page);
        if (!round || isClosed(page)) {
            return;
        }
        if (caption.hasAttribute(LAST)) {
            armClose(caption, round);
            return;
        }
        followToReview(caption);
    };
    const armClose = (caption, round) => {
        if (armed.has(caption)) {
            return;
        }
        armed.add(caption);
        setTimeout(() => closeIfStillShown(caption, round), CLOSE_AFTER_MS);
    };
    // Tied to the caption element and the round it was armed for, so a round that started in the meantime keeps its caption, and a copy Back restored waits for its own timer.
    const closeIfStillShown = (armedCaption, round) => {
        const caption = page.getElementById?.(CAPTION);
        if (isClosed(page) || caption !== armedCaption || caption.getAttribute?.('data-round') !== round || !caption.hasAttribute(LAST)) {
            return;
        }
        if (isPaused(page)) {
            due = { caption, round };
            return;
        }
        closedRound = round;
        close(page, storage, round);
    };
    // Only the page Play's own redirect reached carries the sign, and a visit to another page disconnects it, so no later page follows.
    const followToReview = (caption) => {
        const review = caption.querySelector?.('a.tour-review')?.getAttribute?.('href');
        if (!sign?.isConnected || !review || isPaused(page)) {
            return;
        }
        sign = null;
        page.defaultView?.location?.assign?.(review);
    };

    page.addEventListener('click', (event) => {
        const pause = event.target?.closest?.(PAUSE);
        if (pause) {
            togglePause(pause, held, show);
            // A pause starts empty, because a stage held on an earlier page would bring back an older stage on resume.
            held = null;
            if (!isPaused(page) && due !== null) {
                const { caption, round } = due;
                due = null;
                setTimeout(() => closeIfStillShown(caption, round), CLOSE_AFTER_MS);
            }
            return;
        }
        if (event.target?.closest?.(CLOSE)) {
            closedRound = page.getElementById?.(CAPTION)?.getAttribute?.('data-round') ?? null;
            close(page, storage, closedRound);
        }
    });
    // Turbo appends a stream to the document element, so only the document sees it.
    page.addEventListener('turbo:before-stream-render', (event) => {
        if (event.target.getAttribute('target') !== CAPTION || !isPaused(page)) {
            // Any update can replace the element a stage points at, so the mark is drawn again after each one.
            const render = event.detail.render;
            event.detail.render = (stream) => Promise.resolve(render(stream)).then(show);
            return;
        }
        event.preventDefault();
        held = { render: event.detail.render, stream: event.target };
    });
    // A visit Turbo makes runs no module again, so the new page's element is marked on its load.
    page.addEventListener('turbo:load', () => {
        sign = takeFollow(page) ?? sign;
        show();
    });
    show();
}

// Taken off the page once read, so the copy Turbo caches and Back restores does not follow again.
function takeFollow(page) {
    const sign = page.querySelector?.(`[${FOLLOW}]`) ?? null;
    sign?.removeAttribute(FOLLOW);

    return sign;
}

// Marks the element the caption names in data-tour-at, which is scoped to the caption's round: a page without that element marks nothing.
function pointAt(page) {
    const caption = page.getElementById?.(CAPTION);
    const at = caption?.getAttribute?.('data-tour-at');
    let target = null;
    try {
        target = at && !isClosed(page) ? (page.querySelector?.(at) ?? null) : null;
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
function togglePause(pause, held, show) {
    const paused = pause.getAttribute('aria-pressed') !== 'true';
    pause.setAttribute('aria-pressed', String(paused));
    pause.textContent = paused ? 'Resume' : 'Pause';
    if (!paused && held) {
        Promise.resolve(held.render(held.stream)).then(show);
    }
}

function isPaused(page) {
    return page.getElementById(CAPTION)?.querySelector(PAUSE)?.getAttribute('aria-pressed') === 'true';
}

function isClosed(page) {
    return page.documentElement?.dataset?.tour === 'closed';
}

// Kept for the tab only, so a new visit to the demo shows the caption again; the round is kept too, so a round that starts later shows its caption.
function close(page, storage, round) {
    page.documentElement.dataset.tour = 'closed';
    try {
        storage.setItem(CLOSED_KEY, 'closed');
        storage.setItem(CLOSED_ROUND_KEY, round ?? '');
    } catch {}
    pointAt(page);
}

function reopen(page, storage) {
    delete page.documentElement.dataset.tour;
    try {
        storage.removeItem(CLOSED_KEY);
        storage.removeItem(CLOSED_ROUND_KEY);
    } catch {}
}

function read(storage, key) {
    try {
        return storage.getItem?.(key) || null;
    } catch {
        return null;
    }
}
